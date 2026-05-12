# KennelFlow Core — Playwright E2E

These tests assume a **running WordPress** site with KennelFlow Core (and typically WooCommerce for checkout). [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) is a good fit: `npx wp-env start` then point Playwright at `http://localhost:8888`.

## Install

From the plugin root:

```bash
npm install
npx playwright install chromium
```

## Environment variables

| Variable | Purpose |
|----------|---------|
| `E2E_BASE_URL` | Site origin (default `http://localhost:8888`) |
| `E2E_OWNER_USER` / `E2E_OWNER_PASS` | **Pet Owner** account with a `pending_payment` boarding row and portal access |
| `E2E_PORTAL_PATH` | Path to the page that contains `[kennelflow_dashboard]` (default `/portal/`) |
| `E2E_ADMIN_USER` | Administrator for calendar test |
| `E2E_ADMIN_PASSWORD` or `E2E_ADMIN_PASS` | Admin password |
| `E2E_WP_CLI` | Set to `0` to disable tests that call WP-CLI (default: enabled when `wp` is on `PATH`) |
| `E2E_WP_CLI_PREFIX` | Prefix before `wp`, e.g. `npx wp-env run tests-cli` so options/fixtures run inside the wp-env container |
| `E2E_WP_EVAL_FILE_REL` | Override path to E2E fixture PHP files relative to WordPress root (default: `wp-content/plugins/kennelflow-core/tests/e2e/fixtures/...`) |
| `E2E_KENNELFLOW_VET_BOOKING_PATH` | Path to a page containing `[kennelflow_vet_booking]` (default `/booking/`) |
| `E2E_VET_CLINICIAN_EMAIL` / `E2E_VET_CLINICIAN_LOGIN` | Optional overrides for `provider-booking-wizard.spec.js` fixtures |

## Run

```bash
export E2E_BASE_URL=http://localhost:8888
export E2E_OWNER_USER=owner@example.com
export E2E_OWNER_PASS='secret'
export E2E_ADMIN_USER=admin@example.com
export E2E_ADMIN_PASSWORD='secret'

npm run test:e2e
```

Tests that lack credentials are **skipped** with a clear message.

## Revenue / WP-CLI tests (surge pricing & deposits)

`Simulate High Occupancy Surge Pricing` (in `booking-checkout.spec.js`) and `deposits.spec.js` use **WP-CLI** to set options (`kf_total_kennel_capacity`, `kf_deposit_percentage`), seed `wp_kf_bookings` for occupancy, and set the KennelFlow boarding product price. They load PHP fixtures via `wp eval-file` (paths relative to the WordPress root).

- Install [WP-CLI](https://wp-cli.org/) on your machine, **or** use wp-env and set `E2E_WP_CLI_PREFIX="npx wp-env run tests-cli"` so commands run in the `tests-cli` container.
- **KennelPress** (or any plugin that creates `wp_kf_bookings`) must be active for occupancy seeding.
- **Run order:** `booking-checkout.spec.js` runs **surge** (no payment) first, then **Pay now → confirmed**, so one `pending_payment` booking can satisfy both. **`deposits.spec.js` completes checkout** and needs its **own** `pending_payment` row — if you run the full suite in one go, run deposits **alone** after resetting seed data, or run `npx playwright test tests/e2e/deposits.spec.js` against a fresh `pending_payment` booking.

## Checkout test notes

- KennelFlow sets `kf_bookings.status` to `confirmed` when the WooCommerce order moves to **`processing`** or **`completed`** (`KF_WooCommerce::on_order_status_changed`).
- Some gateways leave COD/BACS orders on **`on-hold`**, which will **not** confirm the booking. Use a gateway that marks the order paid (e.g. Stripe test cards) or adjust WooCommerce “Payment methods” / order status so the test order reaches `processing`.

## Calendar drag test notes

- Needs **at least two resource rows** and a **draggable** `.kf-cal-event` in the visible week.
- A drag that overlaps another **confirmed** booking returns **409**; seed data so the target kennel/time is free, or adjust the week.

## Automated CRM (`kf_daily_crm_sweep`)

`automated-crm.spec.js` seeds `wp_kf_medical_records` with `expiration_gmt` **30 days ahead (UTC)** via `wp eval-file` (`e2e-seed-crm-medical-record.php`), then runs the CRM hook the same way WordPress cron does:

- **Recommended in CI:** `wp eval-file …/e2e-run-crm-sweep.php` — stubs `pre_wp_mail` and runs `do_action( 'kf_daily_crm_sweep' )` (mail is often unset in test environments).
- **Manual / staging with SMTP:** `wp cron event run kf_daily_crm_sweep` (same hook; no in-process mail stub).

The test asserts a new row in `wp_kennelflow_vet_audit_log` (`action` = `crm_medical_30d_reminder`, `entity_type` = `kf_pet`) — **KennelFlow Vet must be active**.

Requires `E2E_OWNER_USER` (and the usual WP-CLI env). The owner must have at least one `kf_pet`.

## Compliance gatekeeper (`compliance-gatekeeper.spec.js`)

Uses **WP-CLI** to back up `kf_required_vaccines`, run `e2e-set-compliance-rule.php` (`kf_required_vaccines` = `[ "Rabies" ]`), and delete `kf_medical_records` for the pet tied to the owner’s **`pending_payment`** boarding row, then:

1. **Hard stop:** portal **Pay now** → checkout; assert **Place order** is disabled **or** a **“Checkout halted”** notice that cites **Rabies** (after submit when the button is enabled).
2. **Happy path:** `e2e-inject-valid-rabies.php` inserts `wp_kf_medical_records` with `expiration_gmt` **+365 days** UTC and `status` **active**; reload checkout; assert the Rabies compliance message is gone; place order → **order-received**; portal row **confirmed**.

**Teardown:** `e2e-compliance-gatekeeper-teardown.php` restores the vaccine snapshot and deletes the seeded medical row by id.

**Reaching checkout** while the pet is non-compliant: the test sets option `kf_compliance_gatekeeper_e2e_allow_noncompliant_checkout` via `e2e-compliance-enable-e2e-bypass.php` so portal **Pay now** and AJAX still add the booking to the cart; **WooCommerce checkout** remains enforced by `KF_Booking_Compliance_Gate`. Staging sites should leave this option **off** (test clears it in `afterAll`).

**Run order:** this test **consumes** the same `pending_payment` booking as `booking-checkout.spec.js` (it confirms the booking at the end). Run it **alone** or reset a `pending_payment` row after other checkout E2E tests.

## KennelFlow Vet booking wizard — provider selection (`provider-booking-wizard.spec.js`)

End-to-end flow on the **public KennelFlow Vet booking wizard** (React): Location → Pet → **Choose Your Provider** → Dates → Room → Add-ons → **Thank you**.

- **WP-CLI fixtures** (in order): `e2e-enable-clinician-selection.php` (`kf_allow_owner_clinician_selection` = on), `e2e-create-test-clinician.php` (user `Dr. E2E Test`, `veterinarian` role, `kf_public_bio` / `kf_specialties`), then **`e2e-teardown-provider-booking-wizard.php`** in `afterAll` (delete clinician, latest `kennelflow_vet_booking` for the owner’s pets, option off).
- **Requires:** KennelFlow + **KennelFlow Vet**, a location with at least one bookable room for the chosen interval, and `E2E_OWNER_USER` / `E2E_OWNER_PASS` with a linked pet.
- **Page:** publish `[kennelflow_vet_booking]` and set `E2E_KENNELFLOW_VET_BOOKING_PATH` if not at `/booking/`.

```bash
npx playwright test tests/e2e/provider-booking-wizard.spec.js
```

## Files

- `booking-checkout.spec.js` — **Surge pricing** (high occupancy, 1.2× boarding line + “High Demand Rate” label); then portal **Pay now** → checkout → portal row **confirmed**.
- `compliance-gatekeeper.spec.js` — **Compliance gatekeeper**: Rabies required → checkout halted → seed Rabies → order **confirmed** (WP-CLI + E2E bypass option for portal only).
- `deposits.spec.js` — deposit checkout total = 20% of standard rate; order meta `_kf_unpaid_balance`; portal **Pay remaining balance** (run with fresh `pending_payment` if the booking was already paid by other tests).
- `admin-calendar-drag.spec.js` — admin **Calendar** screen → drag bar → **PATCH** returns **200**.
- `automated-crm.spec.js` — CRM daily sweep → KennelFlow Vet audit log (WP-CLI fixtures).
- `provider-booking-wizard.spec.js` — KennelFlow Vet **Choose Your Provider** + full boarding submit (WP-CLI seed/teardown).
- `helpers-wp-cli.js` — shared `wp option update`, `wp eval-file` fixtures, order meta reads.
- `fixtures/*.php` — PHP scripts for `wp eval-file` (occupancy seed, boarding price, CRM seed, etc.).
