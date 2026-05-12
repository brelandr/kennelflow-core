=== KennelFlow Core ===
Contributors: brelandr, landtechwebdesigns
Tags: pets, kennel, boarding, hub
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
Text Domain: kennelflow-core
License: GPLv2 or later (GNU General Public License v2.0 or later)
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hub foundation for KennelFlow: shared pets and locations, owner↔pet user mapping, and contracts for add-ons (KennelFlow Boarding, KennelFlow Vet, GroomPress, etc.).

== Description ==

KennelFlow Core registers the shared data model and owner portal building blocks used by KennelFlow Boarding, KennelFlow Vet, GroomPress, and related plugins.

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

* **jsDelivr CDN (optional, portal only)** — The owner portal may load Signature Pad from `cdn.jsdelivr.net` for waiver signatures. If you block third-party scripts, copy the library locally and dequeue/replace the handle `signature-pad` as needed. jsDelivr: https://www.jsdelivr.com/terms

* **Outbound webhooks (optional, admin-configured)** — Under KennelFlow → Webhooks & API, you may enter HTTPS URLs (for example Zapier or Make) and choose which events to send. KennelFlow Core POSTs JSON to those URLs you provide. Data leaves your site only to destinations you configure; review each provider’s terms and privacy policy (e.g. Zapier: https://zapier.com/legal/ and https://zapier.com/privacy/).

* **Twilio SMS (optional)** — Under KennelFlow Settings → Twilio SMS, you may enter Account SID, Auth Token, and a From number. When configured, KennelFlow can send SMS via Twilio’s REST API (`api.twilio.com`) from your WordPress server; credentials and message content are transmitted to Twilio per their service. Terms: https://www.twilio.com/legal/tos — Privacy: https://www.twilio.com/legal/privacy

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. Core features work without it. Checkout, deposits, and portal payment buttons require WooCommerce.

== Changelog ==

= 0.2.0 =
* Revenue settings, WooCommerce bridge, deposits, surge pricing, waitlist, and related portal features (see plugin documentation).
* Webhooks & API: optional outbound JSON webhooks for bookings and pet profile events (Action Scheduler delivery when available).
* Staff Permissions screen (React): `kennelflow/v1/permissions` GET/PATCH for KennelFlow-managed role capabilities; optional Twilio SMS settings and related messaging features (see readme and External Services).
