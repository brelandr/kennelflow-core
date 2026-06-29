=== KennelFlow Core ===
Contributors: brelandr
Tags: pets, kennel, boarding, hub
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.23
Text Domain: kennelflow-core
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hub foundation for KennelFlow: shared pets/locations, owner-pet linking, contracts for KennelFlow Boarding, Vet, Groom add-ons.

== Description ==

KennelFlow Core registers the shared data model and owner portal building blocks used by KennelFlow Boarding, KennelFlow Vet, GroomPress, and related plugins.

== Try It Live - Preview This Plugin Instantly ==

Try KennelFlow Core in WordPress Playground without installing anything locally. The blueprint installs the plugin from **WordPress.org**, creates a demo location, two pets, a demo pet owner (**demoowner** / **password**), and sets the site homepage to the owner portal with `[ltkf_dashboard]`. Log in as **admin** / **password** to explore **KennelFlow Hub** in wp-admin.

[Preview on WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/brelandr/kennelflow-core/main/blueprint.json)

The same blueprint ships as `blueprint.json` (repository root) and `assets/blueprints/blueprint.json`. WordPress.org also serves a copy from plugin SVN for directory live preview integration.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kennelflow-core`, or install the zip through the Plugins screen.
2. Activate the plugin through the Plugins menu.
3. Configure optional integrations (see External Services below).

= Compatibility =

KennelFlow Core is a **companion hub plugin** meant to run alongside **KennelFlow Boarding** and other KennelFlow add-ons. The word “Core” refers to shared pets, locations, owner↔pet mapping, and REST/contracts those plugins build on—not a requirement bundled with WordPress itself. Reviewers and site owners should treat Boarding (and any other KennelFlow spoke you use) as the product-facing feature set; Core supplies the common foundation they expect.

== User guide ==

Full instructions for using KennelFlow Core together with KennelFlow Boarding and KennelFlow Vet (activation order, admin areas, shortcodes, REST overview, Omni-Booking, troubleshooting) are in the repository file **docs/PLATFORM_GUIDE.md** at the KennelFlow package root.

**Staff Permissions matrix**

Under **Pets → Staff Permissions**, users with the `manage_options` capability can view and edit a grid of **KennelFlow-managed** capabilities per WordPress role (for example `kennelflow_vet_edit_emr`, `kennelpress_override_roster`, `groompress_view_commissions`). Core WordPress capabilities such as `delete_plugins` are not exposed in this UI.

REST: `GET` and `PATCH /wp-json/kennelflow/v1/permissions` (authenticated; `manage_options`). `PATCH` accepts JSON body `role`, `capability`, `grant` (boolean) to add or remove a cap on that role.

**Twilio SMS (optional)**

Configure under **Pets → KennelFlow Settings** when you want outbound SMS. KennelFlow can send messages via Twilio’s API for flows such as waitlist “spot opened” notifications and mobile integrations that trigger SMS (see External Services below).

== External Services ==

This plugin does not send your site data to LandTech or third-party servers by default. Optional integrations use WordPress and other plugins on your own site.

* **WooCommerce (optional)** — When WooCommerce is installed and active, KennelFlow Core can create virtual products, attach booking metadata to orders, apply optional surge pricing and deposit/remaining-balance fees at checkout, and let pet owners pay from the `[ltkf_dashboard]` portal. No order or customer data is sent to external APIs by KennelFlow Core itself; processing follows WooCommerce and your payment gateway. See WooCommerce terms: https://woocommerce.com/terms-conditions/ and privacy: https://automattic.com/privacy/

* **WooCommerce Subscriptions (optional)** — The VIP membership discount (KennelFlow Settings) uses subscription status from WooCommerce Subscriptions on your site only (`wcs_user_has_subscription`); no separate API calls. See: https://woocommerce.com/products/woocommerce-subscriptions/ and WooCommerce privacy as above.

* **Outbound webhooks (optional, admin-configured)** — Under KennelFlow → Webhooks & API, you may enter HTTPS URLs (for example Zapier or Make) and choose which events to send. KennelFlow Core POSTs JSON to those URLs you provide. Data leaves your site only to destinations you configure; review each provider’s terms and privacy policy (e.g. Zapier: https://zapier.com/legal/ and https://zapier.com/privacy/).

* **Twilio SMS (optional)** — Under KennelFlow Settings → Twilio SMS, you may enter Account SID, Auth Token, and a From number. When configured, KennelFlow can send SMS via Twilio’s REST API (`api.twilio.com`) from your WordPress server; credentials and message content are transmitted to Twilio per their service. Terms: https://www.twilio.com/legal/tos — Privacy: https://www.twilio.com/legal/privacy

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. Core features work without it. Checkout, deposits, and portal payment buttons require WooCommerce.

== Screenshots ==

1. KennelFlow Hub — quick links to daily work, configuration, and safety tools.
2. Pets — shared pet registry used by all KennelFlow add-ons.
3. Locations — physical sites referenced by kennels, rooms, and bookings.
4. Calendar — weekly occupancy grid with drag-and-drop rescheduling.
5. Staff & Permissions — role capability matrix for KennelFlow features.
6. Pending Records — review and approve owner-uploaded vaccination documents.
7. Daily Reports — send photo updates to owners during active boarding stays.
8. KennelFlow Settings — booking, VIP discount, and optional Twilio SMS options.
9. Revenue — boarding deposits, balances, and optional surge pricing.
10. Webhooks & API — outbound JSON event notifications to HTTPS endpoints.
11. Data Vault — archived medical records after retention policy runs.
12. Client Migration — import owners and pets from a CSV export.
13. Compliance & Security — record retention period and end-of-retention actions.
14. Compliance Rules — required vaccines for facility and boarding stays.
15. System Health — diagnostics for custom tables and related integrations.
16. Demo Data Sandbox — generate or wipe tagged demo content for staging sites.

== Source Files ==

JavaScript bundles in the `build/` directory and CSS in `assets/dist/` are compiled from human-readable source files in `src/` using webpack/esbuild.

To rebuild from source:

1. Navigate to the plugin directory
2. Run: `npm install`
3. Run: `npm run build`

Source files are available in the plugin's `src/` directory and on the public repository.

== Changelog ==

= 0.3.23 =
* Hub calendar: booking session photos for boarding stays and clinic appointments (Take photo + Choose photo), alongside grooming.

= 0.3.22 =
* Grooming session photos: **Take photo** opens a live in-browser camera (getUserMedia) instead of the file browser; **Choose photo** still picks existing files.

= 0.3.21 =
* Grooming session photos: separate **Take photo** (device camera) and **Choose photo** (gallery/files) buttons on calendar popovers.

= 0.3.20 =
* Grooming session photos: show the popover section whenever Groom Pro is active (not only when the user can upload); display upload buttons or a permission hint.

= 0.3.19 =
* Calendar Check in: reload booking by post ID when index row id changes; include record links in check-in response.

= 0.3.18 =
* Fix calendar Check in fatal error: reference global KennelFlow Boarding/Vet classes with leading `\` inside the Core namespace (`ltkf_update_booking_post_status`).

= 0.3.17 =
* Grooming calendar popover: before/after session photos when KennelFlow Groom Pro is active (upload from device or Media Library via REST).

= 0.3.16 =
* Hub calendar: **Check in** button on appointment popovers (pending/confirmed → checked in).
* Hub calendar: **Add walk-in** toolbar button on clinic calendars (opens booking modal with now + checked-in defaults).

= 0.3.15 =
* Calendar popover appointment links: use wp-admin edit URLs for non-public booking CPTs (not front-end permalinks that show a blank page).

= 0.3.14 =
* Fix calendar REST fatal error: reference global KennelFlow Vet classes with leading `\` inside the Core namespace (record link patient history).

= 0.3.13 =
* Fix PHP parse error in calendar REST SQL (escape empty-string literals inside single-quoted prepare strings).

= 0.3.12 =
* Hub calendar popover: **Edit/View appointment**, **Edit pet**, **Patient history**, and **Edit owner** buttons (capability-aware) on all calendars using the shared grid (staff calendar, boarding, grooming, admin Hub calendar).

= 0.3.11 =
* Hub calendar: spokes can register additional front-end shortcode tags (e.g. `[kennelflow_boarding_calendar]`) for early script enqueue.

= 0.3.10 =
* Add booking modal: pet dropdown shows owner name beside pet name (e.g. "Buddy · Jane Smith") so duplicate pet names are distinguishable. Hub REST exposes `owner_name` on `kf-pets`.

= 0.3.9 =
* Fix calendar crash on load: BookingModal referenced `locationTimezone` before initialization (surfaced when the modal stays mounted for debugging).

= 0.3.8 =
* Calendar troubleshooting: always-on console traces (`[KennelFlow Calendar]`), visible debug panel for site admins on the calendar screen, React error boundary, and “Force open Add booking modal” button. Modal uses a safer body portal fallback.

= 0.3.7 =
* Add booking diagnostics: calendar UI and wp-admin explain when KennelFlow Boarding is missing or outdated (Core alone is not enough). Debug details when WP_DEBUG is on.

= 0.3.6 =
* Add booking modal: render via portal on `document.body` so it is not clipped by theme/admin layout; show loading state while form data loads.
* Calendar grid: display appointment times and day headers in the site timezone (Settings → General).

= 0.3.5 =
* Hub calendar: reliably enqueue React package scripts on front-end and admin; mount calendar on DOM ready with a retry hook; show a loading shell and clearer admin notices when assets fail to load.
* Add booking: always show the toolbar button; modal explains when KennelFlow Boarding is required for intake.

= 0.3.4 =
* Hub calendar access: fall back to `edit_posts` when a spoke-specific calendar cap is required, so vet/boarding staff are not blocked.

= 0.3.3 =
* Fix Staff Calendar fatal: add missing `AdminCalendar::get_shell_week_range_utc()` for `[ltkf_hub_calendar]`.

= 0.3.2 =
* Hub calendar: `ltkf_user_can_view_hub_calendar()` for shortcode, admin, and REST; `[ltkf_hub_calendar]` accepts `booking_kind` and `corner_label` attributes.

= 0.3.1 =
* KennelFlow Hub menu and home screen: front-desk staff can access without `manage_options`; admin-only settings links hidden on the staff dashboard.

= 0.3.0 =
* Owner portal **Book a stay**: select one or more pets (checkboxes); booking link passes `kf_pet_id` or comma-separated `kf_pet_ids` with dates and location.

= 0.2.9 =
* Owner portal: pass `kf_check_availability=1` on **Book boarding online** links so the booking wizard can prefill and load available rooms.

= 0.2.8 =
* Online boarding is enabled by default on new installs; existing sites upgrading from pre-0.2.8 auto-enable once unless already configured.
* Owner portal: when online boarding is on and the pet meets compliance, availability check shows a **Book boarding online** button (links to the booking wizard page).

= 0.2.7 =
* Owner portal: fix namespaced `class_exists()` checks so vaccination upload buttons and compliance status render correctly after waiver signing.
* Boarding compliance: portal and booking wizard use boarding-required vaccines plus signed waiver (not only the general facility vaccine list).
* New installs: seed default required vaccines (Rabies, Bordetella, DHPP) when none are configured.

= 0.2.6 =
* Waiver storage: use global `\KennelFlow_Vet_Protected_Uploads` class from namespaced Core code (fixes waiver signing fatal on sites with KennelFlow Vet active).
* Owner portal: show specific action-required messages (missing/expired vaccines, pending review, boarding waiver) on pet list and boarding reservations.

= 0.2.5 =
* Owner portal waivers: fix PHP fatal when saving signatures on frontend AJAX (undefined FS_CHMOD_FILE when WP_Filesystem was already bootstrapped).
* Waiver storage: centralize filesystem initialization and use WordPress.org-compliant empty index.php stubs.

= 0.2.4 =
* Owner portal: register waiver AJAX handler so boarding agreement signatures save correctly (fixes "Unexpected response from server").

= 0.2.3 =
* Hub calendar: grooming and other views build resource rows from bookings when the REST API returns no explicit resources (fixes empty grooming schedule).
* Hub calendar: show a clear error message when calendar data fails to load instead of a blank grid.
* Hub calendar REST proxy and booking modal: use KennelFlow Boarding namespace (`kennelflow-boarding/v1`) with legacy `kennelpress/v1` fallback.
* Booking wizard REST: PHP 8 global namespace fixes for KennelFlow Boarding controller classes.
* Requires Plugins: dependency slug mapper for legacy plugin folders (`groompress/`, `kennelpress/`, `vetpress/`).
* WordPress.org listing assets: updated banner images.

= 0.2.2 =
* Hub admin calendar REST API: PHP 8 namespace fixes for calendar, booking, and related endpoints.
* Hub bootstrap: admin screens (calendar, permissions, report card) load reliably when Core initializes.
* Demo Data Sandbox: generate or remove demo-tagged pets, users, bookings, and related records.
* WordPress.org listing assets: plugin banner, icons, and admin screenshots.
* Security and WPCS hardening across admin POST handlers, REST routes, and database queries.
* Removed `load_plugin_textdomain()` (WordPress auto-loads translations since 4.6).

= 0.2.1 =
* Confirmed compatibility with WordPress **7.0** (`Tested up to: 7.0`; plugin header and readme metadata).
* Public plugin listing: Plugin URI updated to wordpress.org (`https://wordpress.org/plugins/kennelflow-core/`).
* Ships full Core **0.2.x** codebase (prior SVN releases were stubs); includes LICENSE with plugin distribution.

= 0.2.0 =
* Revenue settings, WooCommerce bridge, deposits, surge pricing, waitlist, and related portal features (see plugin documentation).
* Webhooks & API: optional outbound JSON webhooks for bookings and pet profile events (Action Scheduler delivery when available).
* Staff Permissions screen (React): `kennelflow/v1/permissions` GET/PATCH for KennelFlow-managed role capabilities; optional Twilio SMS settings and related messaging features (see readme and External Services).
