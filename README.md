# Deen's Edge Payments for WooCommerce

A WooCommerce payment gateway for [Edge Payment
Technologies](https://tryedge.io). Card entry happens in a hosted, cross-origin
iframe served by Edge, so no card number, CVV or token ever reaches your
WordPress site.

> ### Disclaimer
>
> **This plugin is provided "as is", without warranty of any kind**, express or
> implied, including but not limited to the warranties of merchantability,
> fitness for a particular purpose and non-infringement. In no event shall the
> author be liable for any claim, damages or other liability arising from or in
> connection with this software or its use — including, without limitation, any
> loss of funds, failed, duplicated or mis-charged payments, refunds that do not
> settle, chargebacks, or lost orders.
>
> This plugin takes money from your customers. **Test it against your own store
> and your own Edge account, in sandbox, before switching it on for live
> payments**, and reconcile what your store records against what Edge records.
> Use is entirely at your own risk.
>
> **This is an independent, unofficial plugin.** It is not affiliated with,
> endorsed by, sponsored by or supported by Edge Payment Technologies, inc or
> Automattic, inc. "Edge" and "WooCommerce" are the trademarks of their
> respective owners, used here only to describe compatibility. Do not contact
> Edge or Automattic for support with this plugin.

## Requirements

- WordPress 6.4+, PHP 7.4+
- WooCommerce 9.0+, using the **block checkout**. The classic checkout,
  `order-pay` and "add payment method" are deliberately unsupported — none of
  them can run the hosted form's verification step.
- Store currency **USD**, order total at least 10 cents.
- An Edge account and a secret API key.

## Installation

1. Download the ZIP from [releases](https://github.com/ziyan-junaideen/edge-woocommerce/releases).
2. Upload it via **Plugins → Add New → Upload Plugin**, or unzip into
   `wp-content/plugins/`.
3. Activate, then go to **WooCommerce → Settings → Payments → Edge Payments**.
4. Enable it, paste the secret key from the Edge dashboard's **Developers** tab,
   and save. Saving also registers this site's webhook subscription with Edge.

There are no bundled PHP libraries to install — all HTTP goes through
WordPress's own HTTP API.

If the method does not appear at the checkout, each of these makes it silently
unavailable: the gateway is disabled, the key pair does not validate, the store
currency is not USD, or the cart total is under 10 cents.

Logs are in **WooCommerce → Status → Logs**, channel `edge-payments`.

## Development

Node and PHP versions are pinned in `.tool-versions` (mise). There is no npm
`nvm` step.

```bash
# JavaScript bundle (resources/js → assets/js; never edit assets/ by hand)
npm install
npm run build            # or: npx wp-scripts start  for watch mode

# PHP dev tooling — PHPUnit and PHPCS only, nothing here ships
composer install
vendor/bin/phpunit
vendor/bin/phpcs

# Release ZIP
bin/build-release.sh
```

[`AGENTS.md`](AGENTS.md) is the full developer brief: repository layout, the
Edge API and its invariants, the checkout and refund flows, the local test site,
and what must be verified before a payment change is called done. Read it before
changing anything that touches money.

## Licence

GPL-3.0-or-later — see [`LICENSE`](LICENSE).

Portions were originally released by Edge Payment Technologies, inc under the
MIT License; that notice is retained in [`NOTICE.md`](NOTICE.md).

## Author

**Ziyan Junaideen** — Colombo, Sri Lanka — [www.jdeen.com](https://www.jdeen.com)

Support is best-effort and unpaid.

- Preferred contact: X/Twitter — [@ZiyanJunaideen](https://x.com/ZiyanJunaideen)
- Bugs: [GitHub issues](https://github.com/ziyan-junaideen/edge-woocommerce/issues)
- Email: ziyan@jdeen.com
