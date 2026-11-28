=== Paymos Stablecoin Donations ===
Contributors: paymos
Tags: givewp, donations, stablecoins, payments, usdc
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept one-time stablecoin donations through Paymos hosted checkout in GiveWP.

== Description ==

Paymos adds a stablecoin payment method to GiveWP Visual and legacy donation forms. Donors open Paymos checkout from a status page on your site. GiveWP records a confirmed donation and offers its native receipt after the plugin verifies the payment with Paymos.

The plugin requires an HTTPS WordPress site, GiveWP 4.19.1.1 or later, and a Paymos project. Activate the plugin on each site individually. It supports one-time donations.

== Installation ==

1. Install and activate GiveWP, then install and activate Paymos Stablecoin Donations.
2. Open **GiveWP → Settings → Payment Gateways → Paymos**. Choose Sandbox or Live to match GiveWP's payment mode.
3. Click **Connect Paymos** and approve the site and project on the Paymos approval page.
4. Enable Paymos on the Visual or legacy forms where you want to accept stablecoins.

== External services ==

Paymos provides the hosted checkout and merchant API used by this gateway. Read the [Paymos Terms](https://paymos.io/terms) and [Privacy Policy](https://paymos.io/privacy).

**Connection.** When an administrator starts Connect, WordPress sends `client_id=paymos-cms` and `scope=cms:bootstrap` to `https://app.paymos.io/connect/cms/device`. The browser approval link carries the `givewp` plugin identifier, the site URL, a user code, and the admin return URL when it shares the site's origin. During approval WordPress polls `https://app.paymos.io/connect/token` with the device-code grant, `client_id`, and the secret `device_code`. WordPress stores the selected environment's credentials in an encrypted option and discards the short-lived OAuth token.

**Donations.** When the donor opens Paymos checkout, WordPress sends `POST /v1/invoices` to `https://api.paymos.io` with `project_id`, `amount`, `currency`, and a random `external_order_id`. Donor details and GiveWP receipt credentials stay in WordPress. The plugin checks the exact invoice with `GET /v1/invoices/{invoice_id}` and can look up an interrupted request with `GET /v1/invoices` using the project and original reference. These requests carry a key ID, timestamp, and HMAC signature. The signing secret stays on your site.

**Payment and confirmation.** Donors open the hosted invoice at `https://checkout.paymos.io`. Paymos sends signed invoice notifications to the WordPress webhook associated with the connected Paymos project. The plugin verifies the signature, retrieves the exact invoice from the API, and checks its scope and payment before updating GiveWP. The WordPress scheduled reconciliation job also checks pending work through the API.

**Refunds.** Authorized staff can request a full or overpayment refund from **GiveWP → Paymos refunds**. WordPress sends a signed `POST /v1/refunds` with `invoice_id`, `mode`, and a random `external_order_id`. Paymos determines the destination from the original payment. The plugin checks the operation with `GET /v1/refunds/{withdrawal_id}` and, when needed, the invoice-scoped `GET /v1/refunds` list. GiveWP records a full refund after verifying the completed Paymos operation. A refund of the excess preserves the original donation status.

== Frequently Asked Questions ==

= How can a donor resume a donation after a page reload? =

When the browser can save the recovery link, the donor can choose **Resume your previous Paymos donation** on the same form. The link opens the site's status page and expires within 12 hours. The site verifies the original browser and GiveWP binding before showing the status or receipt. A regular new submission starts a separate donation.

= What happens when I disconnect or delete the plugin? =

Disabling the gateway stops new Paymos donations and preserves the credentials needed to check unresolved operations. Deactivating the plugin stops its scheduled checks. Deleting it removes local credentials, browser access to its status pages, and scheduled work. GiveWP keeps its donation history. The plugin keeps minimal references for unresolved payments and refunds so an administrator can review them manually. Manage remote keys and webhooks in the Paymos project.

= Where can I get help? =

Contact [support@paymos.io](mailto:support@paymos.io) for help with Paymos checkout, connection, or refunds.

== Changelog ==

= 1.2.0 =
* fix(givewp): no refunds for tolerance-paid donations, green preflight
* docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
* chore: bundle Paymos PHP SDK v1.13.0
* chore: rebuild canonical CMS package

* Stopped offering and sending refunds for a donation Paymos completed inside its underpayment tolerance (for example 9.95 of 10). Paymos refuses both refund modes for such a payment, so the request ended as Failed; the plugin now keeps it waiting with `refund_amount_unavailable` without a request and hides the refund button for that mode.

= 1.1.1 =
* chore: rebuild canonical CMS package

= 1.1.0 =
* feat(release): automate Odoo 20.0 and GiveWP releases across all 21 ecosystem mirrors
* chore: bundle Paymos PHP SDK v1.11.1
* chore: rebuild canonical CMS package

= 1.0.1 =
* Trusted server-confirmed final `paid` settlement status, preserved 24-decimal NEAR amount formatting, and expanded tolerance refund reconciliation coverage.

= 1.0.0 =
* Added Paymos hosted checkout for one-time donations in GiveWP Visual and legacy forms.
* Added signed invoice verification, recovery of interrupted checkout, and native GiveWP receipts.
* Added full and overpayment refund requests and reconciliation for authorized GiveWP staff.

