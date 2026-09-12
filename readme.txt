=== Deen's Edge Payments for WooCommerce ===
Contributors: ziyanjunaideen
Tags: woocommerce, payment gateway, edge, credit card, payments
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments through Edge Payment Technologies in WooCommerce. Independent plugin, provided as is without warranty.

== Description ==

**Please read the Disclaimer section below before installing this plugin. It is
provided as is, without warranty of any kind, and it handles money.**

This plugin adds Edge Payment Technologies as a payment method on the
WooCommerce block checkout. Card details are entered in a hosted form served by
Edge and displayed in a cross-origin iframe, so no card number, CVV or token
ever passes through your WordPress site or is stored in your database.

What it does:

* Adds an Edge card payment method to the WooCommerce block checkout.
* Keeps card entry inside Edge's hosted iframe. Your server never sees a PAN.
* Confirms the payment, then waits for the real outcome before completing the
  order. An order is only completed by a read of the payment from Edge, never
  optimistically at checkout.
* Recovers from a decline. A declined card leaves you on the checkout with the
  form unlocked so you can try another card, against the same order.
* Handles webhooks from Edge, signature-verified and de-duplicated, so an order
  settles correctly whether or not the shopper is still on the page.
* Supports full and partial refunds from the WooCommerce order screen.

Requirements:

* WooCommerce 9.0 or later, with the **block checkout**. The classic checkout,
  the order-pay page and "add payment method" are deliberately not supported,
  because none of them can run the hosted form's verification step.
* An Edge account, and a secret API key from the Edge dashboard.
* Store currency set to **US dollars**. Other currencies are not supported.
* An order total of at least 10 cents.

The plugin has no bundled PHP libraries. All HTTP goes through WordPress's own
HTTP API.

== Disclaimer ==

**This plugin is provided "as is", without warranty of any kind**, express or
implied, including but not limited to the warranties of merchantability,
fitness for a particular purpose and non-infringement. In no event shall the
author be liable for any claim, damages or other liability, whether in an
action of contract, tort or otherwise, arising from, out of or in connection
with this software or its use — including, without limitation, any loss of
funds, failed, duplicated or mis-charged payments, refunds that do not settle,
chargebacks, or lost orders.

This plugin takes money from your customers. **You are responsible for testing
it against your own store and your own Edge account, in sandbox, before you
switch it on for live payments**, and for reconciling what your store records
against what Edge records. Use of this plugin is entirely at your own risk.

**This is an independent, unofficial plugin.** It is not affiliated with,
endorsed by, sponsored by or supported by Edge Payment Technologies, inc or
Automattic, inc. "Edge" and "WooCommerce" are the trademarks of their
respective owners and are used here only to describe what this plugin is
compatible with. Do not contact Edge or Automattic for support with this
plugin; see the Support section below.

== Installation ==

1. Upload the plugin ZIP through **Plugins > Add New > Upload Plugin**, or
   unzip it into `wp-content/plugins/`.
2. Activate it through the **Plugins** screen. WooCommerce must already be
   active.
3. Go to **WooCommerce > Settings > Payments** and open **Edge Payments**.
4. Tick **Enable**, paste your secret API key from the **Developers** tab of
   the Edge dashboard, and save.

Saving the settings also registers this site's webhook subscription with Edge,
so payments settle even when the shopper closes the tab.

If the payment method does not appear at the checkout, check all of the
following, because each one makes the gateway silently unavailable: the gateway
is enabled, the key pair validates, your store currency is USD, and the cart
total is at least 10 cents.

Use a sandbox key pair and test orders first. Never test with live credentials
or real card details.

== Frequently Asked Questions ==

= Is this the official Edge plugin? =

No. It is an independent plugin written by Ziyan Junaideen and is not
affiliated with, endorsed by or supported by Edge Payment Technologies.

= Does card data touch my server? =

No. Card entry happens in a payment form hosted by Edge and shown in a
cross-origin iframe. Your WordPress site never receives a card number, a CVV,
or a token standing in for either, and none of it is written to your database.
Your Edge secret key stays in PHP and is never sent to the browser.

= Does it work with the classic checkout? =

No, and that is deliberate rather than an omission. The gateway refuses the
classic checkout, the order-pay page and the "add payment method" screen,
because none of them can run the hosted form's verification step — allowing
them would let an order be submitted with no card verification having happened
at all. You need the WooCommerce checkout block.

= Which currencies are supported? =

US dollars only.

= Can I refund from WooCommerce? =

Yes. Full and partial refunds both work from the standard WooCommerce refund
form on the order screen. Note that WooCommerce treats a refund as final as
soon as it is submitted, while Edge settles it asynchronously — if a refund
later fails, the plugin adds a loud order note telling you to reconcile it, but
it cannot undo the restocking WooCommerce has already done.

= Why is my order stuck on hold? =

"On hold" means Edge has accepted the payment and is still processing it. The
order completes when Edge reports the outcome, by webhook or by the checkout's
own polling. If it stays on hold, check the payment in the Edge dashboard and
the WooCommerce logs under the `edge-payments` channel.

= Where are the logs? =

**WooCommerce > Status > Logs**, channel `edge-payments`. Keys, card data and
customer details are redacted.

== Support ==

Support is best-effort, unpaid, and offered by one person in his own time.
There is no service level of any kind. Please do not contact Edge Payment
Technologies or Automattic about this plugin.

* Preferred: X/Twitter — [@ZiyanJunaideen](https://x.com/ZiyanJunaideen)
* Bug reports: [GitHub issues](https://github.com/ziyan-junaideen/edge-woocommerce/issues)
* Email: ziyan@jdeen.com

Ziyan Junaideen, Colombo, Sri Lanka — [www.jdeen.com](https://www.jdeen.com)

== Changelog ==

= 2.4.0 =
* Renamed to "Deen's Edge Payments for WooCommerce" and released as an
  independent, unofficial plugin.
* Relicensed to GPL-3.0-or-later. The original MIT notice is retained in
  NOTICE.md.
* The checkout now waits for the payment outcome instead of sending the shopper
  straight to the thank-you page, so a declined card can be retried on the same
  order without starting a new checkout.
* Webhook deliveries are verified with Edge's v3 `edge-signature` HMAC. The
  legacy `x-hub-signature` header is no longer accepted.
* Full and partial refunds from the WooCommerce order screen.
* Payment demands are sent with real line items.
