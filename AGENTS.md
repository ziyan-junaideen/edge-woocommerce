# AGENTS.md

WooCommerce payment gateway for **Edge Payment Technologies**. A PHP plugin plus
a small React bundle for the WooCommerce Blocks checkout.

The v2 API migration is **finished** (merged as #8, 2026-08-15). This is a
maintenance codebase, not a port in progress — do not write compatibility shims
for the old direct-card/token flow, and do not treat the legacy behaviour as
something still to be removed. It is gone.

The plugin speaks Edge v2 directly and has **no production PHP dependencies**.
The Edge PHP SDK was dropped in favour of internal code over WordPress's HTTP
API. Composer is a development tool here (PHPUnit, PHPCS) and never ships.

## Keeping this file current

This file records facts that drift — paths, commands, versions, URLs, event
names. Treat it as a starting point, not as authority.

- **The repo wins.** If anything here contradicts the code, the code is right.
  Check the claim, then fix this file in the same change rather than leaving the
  next agent to trip over it.
- **Update it when you change the ground it describes**: adding, renaming or
  removing a file under `includes/` or `resources/`, changing a build or test
  command, changing an override constant, a REST route, a webhook event name, a
  settings field, or the set of places the version lives.
- **Only verified claims belong here.** Do not record plans, TODOs or intentions.
  If a line says the tree passes PHPCS, that is because someone ran PHPCS. When
  you cannot verify something, say so in the line itself rather than asserting
  it.
- **`CLAUDE.md` includes this file with `@AGENTS.md`.** Shared facts belong here;
  only Claude Code-specific workflow belongs there. Do not duplicate content
  across the two — a fact in both places is a fact that will disagree with
  itself.
- **No credentials in this file.** This repo is public. Passwords, API keys and
  tokens live in `AGENTS.local.md`, which is gitignored; this file may point at
  them but must never quote them. The same goes for code, tests and commit
  messages.

## Repo Guidelines

- Commit to main unless specified otherwise.

## Layout

```
edge-gateway.php                     bootstrap: includes, gateway + blocks registration,
                                     HPOS/blocks compatibility, settings upgrade, attempt
                                     adoption, daily cleanup cron
includes/
  class-wc-edge-api-client.php       Edge JSON:API client over wp_remote_request(); GET/POST/PATCH
                                     only (the API has no DELETE routes)
  class-wc-edge-api-exception.php    the one API exception; callers branch on get_status_code()
  class-wc-edge-attempt-store.php    custom table wp_edge_checkout_attempts: holds the demand made
                                     before an order exists; the unique constraint is what makes
                                     concurrent prepare calls safe
  class-wc-edge-cart-items.php       cart -> whole-line integer cents; read() touches WooCommerce,
                                     normalise() is pure and is where the tests bite
  class-wc-edge-client-factory.php   resolves API root, dashboard host, browser SDK URL, TLS policy
                                     and user agent; hands out clients for a secret key
  class-wc-edge-countries.php        ISO 3166-1 alpha-2 -> alpha-3 table
  class-wc-edge-fingerprint.php      stable hash of everything a demand depends on; what the
                                     attempt/idempotency key is derived from
  class-wc-edge-logger.php           WooCommerce log channel "edge-payments", with redaction
  class-wc-edge-mode.php             parses ept_{live|sandbox}_{b|s}... keys; mode is derived from
                                     the key, never stored as a separate flag
  class-wc-edge-money.php            decimal strings -> integer cents without ever touching a float
  class-wc-edge-order-mapper.php     builds the JSON:API request documents; pure, so the exact bytes
                                     sent to Edge are unit-testable
  class-wc-edge-payment-service.php  orchestration: prepare() and confirm()
  class-wc-edge-rest-controller.php  POST /wp-json/edge/v1/checkout-intent
  class-wc-edge-subscription-reconciler.php
                                     reconciles this site's Edge *webhook* subscription (nothing to
                                     do with WooCommerce Subscriptions)
  class-wc-edge-webhook-controller.php
                                     POST /wp-json/edge/v1/webhook — the authoritative outcome
  class-wc-edge-webhook-store.php    custom table wp_edge_webhook_events: dedupe + serialise delivery
  class-wc-gateway-edge.php          WC_Payment_Gateway subclass: settings, is_available(),
                                     process_payment()
  blocks/class-wc-edge-payments-blocks.php
                                     AbstractPaymentMethodType — enqueues the block script and the
                                     hosted SDK, passes settings via getSetting('edge_data')
resources/js/frontend/index.js       block checkout payment method (source)
assets/js/frontend/blocks.js         built bundle — generated, never edit
webpack.config.js                    externalises @woocommerce/* to wc.wcBlocksRegistry etc.
bin/build-release.sh                 release ZIP: sources + built JS, no vendor/, no prefixing
bin/build_i18n.sh                    JSON translations; needs a global `wp` binary
tests/bootstrap.php                  WordPress shims, including a fake wp_remote_request()
tests/unit/                          the suite (232 tests as of 2026-09-09)
```

`assets/`, `languages/`, `vendor/`, `node_modules/`, `dist/` and `composer.lock`
are ignored build artefacts. Do not hand-edit them or treat them as source.

## Build

Node and PHP are pinned in `.tool-versions` (nodejs 20.11.0, php 8.3.33). There
is **no system `php`** and **no system `wp`** on this machine — use Studio's PHP
binary and `studio wp`. Composer comes from mise.

```bash
# JS
mise x node@20.11.0 -- npm install
mise x node@20.11.0 -- npx wp-scripts build     # or `npm run build`
mise x node@20.11.0 -- npx wp-scripts start     # watch mode

# PHP dev tooling (PHPUnit + PHPCS only — nothing here ships)
/Users/jdeen/.local/share/mise/installs/php/8.3.33/bin/composer install
/Users/jdeen/.studio/php-bin/8.3.32-studio-1/php vendor/bin/phpunit
/Users/jdeen/.studio/php-bin/8.3.32-studio-1/php vendor/bin/phpcs

# Release ZIP
bin/build-release.sh
```

`npm run build` is `wp-scripts build` and nothing more. Translations are a
separate script — `npm run build:all` or `npm run i18n:build` — and both shell
out to a global `wp`, so they fail here. Use `npx wp-scripts build` unless you
specifically need the `.pot`.

Rebuilding the JS is enough to see frontend changes on the local site; the
plugin is symlinked in, so there is no copy step.

## Verification

```bash
PHP=/Users/jdeen/.studio/php-bin/8.3.32-studio-1/php

$PHP vendor/bin/phpunit          # or: composer run-script test
$PHP vendor/bin/phpcs            # or: composer run-script lint
$PHP vendor/bin/phpcbf           # composer run-script lint:fix
composer validate --strict --no-check-lock
find . -path ./vendor -prune -o -path ./node_modules -prune -o \
  -name '*.php' -type f -print0 | xargs -0 -n1 $PHP -l
```

- The PHPUnit suite loads **no WordPress**. The classes under test either avoid
  WordPress functions or have them shimmed in `tests/bootstrap.php`, which also
  stands in for the HTTP API so `WC_Edge_API_Client` can be asserted on the exact
  arguments it hands `wp_remote_request()`. `phpunit.xml` sets `failOnWarning`,
  `failOnRisky` and `beStrictAboutOutputDuringTests` — a stray `echo` fails the
  run.
- `phpcs.xml` is WordPress-Extra and **the tree passes it clean**. Keep it that
  way. PHPCS prints a deprecation notice about the comma-separated `text_domain`
  property; that is expected output, not a failure.
- Add coverage for anything non-trivial in payment state, request construction,
  money conversion, fingerprinting/idempotency, and webhook handling. Prefer
  putting the logic worth being wrong about in a pure method so it can be tested
  without WordPress — that is why `WC_Edge_Cart_Items`, `WC_Edge_Order_Mapper`,
  `WC_Edge_Money`, `WC_Edge_Mode` and `WC_Edge_Fingerprint` are shaped as they
  are.

### CI

`.github/workflows/php-composer.yml` runs the PHP job on a **7.4 and 8.3**
matrix. `composer.json` requires `^7.4 || ^8.0` and the plugin header says
`Requires PHP: 7.4`, so **no PHP 8-only syntax** — no `match`, enums, promoted
constructor properties, named arguments or nullsafe operator.

Two guardrails will fail the build, and both encode decisions worth keeping:

- no `\Edge\` or `\GuzzleHttp\` reference and no `vendor/autoload.php` in
  `edge-gateway.php` or `includes/` — nothing loads an autoloader any more, so
  either would be a dead name at runtime;
- no `encryptedCard`, `card_pan_token`, `card_cvv_token` or `initializeForm` in
  the built bundle — card handling must stay inside the hosted iframe.

`bin/build-release.sh` repeats the same checks against the staged ZIP.

## Local test site

A WordPress Studio site at `/Users/jdeen/Studio/my-wordpress-website`, with this
repo symlinked in as `wp-content/plugins/edge-woocommerce`. WP 7.1, WooCommerce
11.0.0, PHP 8.3, SQLite.

- URL: http://localhost:8881/
- Admin credentials: see `AGENTS.local.md`, or run `studio status`
- Auto-login: http://localhost:8881/studio-auto-login?redirect_to=%2Fwp-admin%2F
- Gateway settings: WooCommerce → Settings → Payments → Edge Payments
- Test product: "Edge Test Product", $25.00 (post id 12)

All WP-CLI goes through `studio wp`, and **it must run with the site directory as
the working directory** — from anywhere else it fails with "The specified
directory is not added to Studio."

```bash
cd /Users/jdeen/Studio/my-wordpress-website

studio status                      # URL, credentials, versions
studio start --skip-browser
studio wp plugin list
studio wp eval 'echo WC()->version;'
studio config set --debug-log --debug-display
cat /Users/jdeen/Studio/my-wordpress-website/wp-content/debug.log
```

Useful checks:

```bash
# is the gateway registered and available?
studio wp eval '$g = WC()->payment_gateways->payment_gateways(); var_dump(isset($g["edge"]), $g["edge"]->is_available());'

# does the blocks checkout actually offer it?
curl -s http://localhost:8881/wp-json/wc/store/v1/cart | python3 -m json.tool | grep -A3 payment_methods
```

Note that `is_available()` is false unless the gateway is enabled, the key pair
validates, the currency is USD and the cart total clears the 10-cent minimum.

## Edge API

### Sources of truth, in order

1. `/Volumes/Dev/Work/Edge/edge/ept` — the Phoenix/Elixir backend. Authoritative.
   - `openapi.json` — full v2 spec
   - `priv/openapi/description.md` — the narrative docs, including the canonical
     test-card table
   - `lib/core_http/views/*.ex` — the real field and relationship definitions
   - `lib/core_http/controllers/*.ex` — request handling, including `confirm`
   - `assets/js/edge.js` — the browser SDK source
2. `docs.tryedge.io` — published docs.
3. `edge-php-sdk` @ `/Volumes/Dev/Work/Edge/edge-php-sdk` — **no longer a
   dependency**. Kept only as a reference for how the v2 client was shaped; that
   code is on the `edg-4012-publish-latest-changes-to-composer-package` branch,
   not `main` (which is the obsolete v1 client).

Never infer an API field from this plugin or from the SDK. Where they disagree
with the backend, the backend wins.

### Hosts

|  | Production | Local dev |
|---|---|---|
| API | `https://api.tryedge.io/v2/` | `https://api.tryedge.test:4001/v2/` |
| Hosted payment form | `https://dashboard.tryedge.io` | `https://dashboard.tryedge.test:4001` |
| Browser SDK | `https://assets.tryedge.io/assets/js/edge.js` | served from the dashboard host, unminified |

The SDK URL is deliberately the undigested path. Edge's developer page hands out
a content-hashed `edge-<digest>.js?vsn=d`, which changes on every deploy; a
previous version of this plugin hard-coded one and broke.

The dev publishable token is in `AGENTS.local.md`. Secret keys are never recorded
in this repo — take one from the Edge dashboard's Developers tab and put it in the
gateway settings.

### Environment overrides

All of these are `wp-config.php` constants, never stored settings — an
admin-editable API host would send the merchant's secret key to an arbitrary
origin and turn the gateway into an SSRF primitive.

| Constant | Effect |
|---|---|
| `EDGE_API_BASE_URI` | API root |
| `EDGE_DASHBOARD_HOST` | hosted form origin handed to the browser SDK |
| `EDGE_BROWSER_SDK_URL` | where `edge.js` is loaded from |
| `EDGE_DISABLE_TLS_VERIFY` | waives TLS verification, and only for a non-`tryedge.io` host |
| `EDGE_WEBHOOK_CALLBACK_URL` | overrides the callback URL registered with Edge (tunnels) |

There is one filter, `woocommerce_edge_gateway_icon`.

`WC_EDGE_TESTING` is the unit-test guard: a class that the suite loads directly
opens with `if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) )`,
while a class that genuinely needs WordPress guards on `ABSPATH` alone. Which
guard a file uses is a statement about whether it is unit-testable — keep them
honest.

### JSON:API

`Content-Type` and `Accept` must both be `application/vnd.api+json`. Auth is
`Authorization: Bearer <secret key>`. Writes are
`{"data": {"type": ..., "attributes": {...}, "relationships": {...}}}`.

Resource identifiers need their **real** plural type — `"customers"`,
`"consumer_addresses"`, `"payment_demands"`, `"webhook_subscriptions"` — never a
placeholder like `"string"`.

### The checkout flow, as built

The gateway is **block checkout only**, deliberately.
`is_supported_checkout_surface()` refuses the classic checkout, `order-pay` and
`add-payment-method`, because none of them can run the hosted-form verification.
Setting `has_fields = false` would not achieve that on its own: it removes the
classic card fields but still lets the gateway be selected and submitted with no
verification having happened at all.

1. The block checkout calls `POST /wp-json/edge/v1/checkout-intent`. It requires
   a valid `X-WP-Nonce`, a WooCommerce session and a non-empty cart, and refuses
   if the browser's claimed `cart_hash` disagrees with the server's.
2. `WC_Edge_Payment_Service::prepare()` fingerprints the payment facts
   (`WC_Edge_Fingerprint`), claims a row in `wp_edge_checkout_attempts`
   (`WC_Edge_Attempt_Store`), then creates the customer, consumer address and
   an **unconfirmed** payment demand from `WC_Edge_Order_Mapper` documents.
3. The response carries the demand id, the publishable key and the SDK/dashboard
   config. The secret key never leaves PHP.
4. Browser: `new Edge(publishableKey, { formFactor: 'inputs' })`, then
   `client.mountPaymentForm(containerId, demandId)`.
5. On `onPaymentSetup`, the block awaits `client.verifyPaymentMethod()`. Only
   `payment_method_verified` proceeds; `payment_method_error`,
   `payment_method_failed`, `payment_method_changed` and timeout become checkout
   errors. The demand id goes back to PHP as `edge_demand_id`.
6. `woocommerce_store_api_checkout_order_processed` fires
   `WC_Edge_Payments::adopt_attempt()`, which copies `_edge_demand_id`,
   `_edge_attempt_key`, `_edge_mode`, `_edge_amount_cents` and `_edge_currency`
   onto the order through the CRUD API and marks the attempt adopted. From here
   the order is the binding — never a value from the browser.
7. `process_payment()` validates the submitted id is UUID-shaped, re-reads the
   demand, revalidates the binding and the amount, `PATCH`es
   `payment_demands/{id}/confirm`, sets the transaction id, and puts the order
   **on-hold**. Confirming means Edge accepted the payment for processing, not
   that it succeeded, so the order is never completed here.
8. `POST /wp-json/edge/v1/webhook` is the authoritative outcome. Deliveries are
   signature-verified (`x-hub-signature`), deduplicated and serialised through
   `wp_edge_webhook_events`, and only then move the order.

Subscribed events are `transaction.payment_demands.created`, `.updated`,
`.succeeded` and `.failed` — the only codes the backend actually emits.
`payment_demands.refunded`, `.disputed` and the `refund_demands` terminal states
are documented but appear in no emit site, so subscribing to them would imply a
reliability the backend does not offer.

`WC_Edge_Subscription_Reconciler` reconciles the existing
`webhook_subscriptions` resource rather than creating one, because saving
settings must be repeatable; creating on every save would leave a trail of
duplicates all delivering the same events.

### Invariants that break silently

- `POST /v2/payment_methods` **does not exist**. The hosted form creates and
  attaches the payment method. No card number, CVV, PAN token or CVV token ever
  passes through WordPress.
- The confirm body must carry `"attributes": {}` as a JSON **object**. An empty
  PHP array encodes to a list and the server rejects it;
  `WC_Edge_API_Client::confirm()` substitutes a `stdClass` for exactly this
  reason.
- Edge's idempotency is keyed on the **value alone**, not on the request body.
  Re-posting a key with the amount changed returns the original demand at the
  original amount, with no error — confirmed against the API (2500 → 9999
  returned 2500). So the key can never be a per-order constant; it is derived
  from `WC_Edge_Fingerprint`, which is why a shopper who edits their cart cannot
  be charged the previous total. `cart_hash` alone is not enough: WooCommerce
  builds it from rows with the product object stripped, so a renamed product,
  an edited SKU or a calculated fee never reaches it — hence the separate
  `itemisation_hash`.
- Line item `amount_cents` is the **per-unit** price, not the line total; the
  backend derives the line as `amount_cents * quantity`. It is also the price
  **before** any coupon.
- Money is integer cents. `amount_cents` and `amount_currency`; USD only
  (`WC_Edge_Money::CURRENCY`), minimum 10 cents
  (`WC_Edge_Money::MINIMUM_CENTS`). Never multiply by 100 in a float, and never
  reach for `wc_add_number_precision()` — it is typed `?float`.
- `WC_Edge_API_Exception::get_status_code()` of **0** means the request never got
  a response: the outcome is unknown, not failed. Never blindly retry a confirm
  on it — read the demand back first (see
  `WC_Edge_Payment_Service::resolve_ambiguous_confirm()`).
- Not every failure body is a JSON:API errors document. The API answers
  401/403/404/405 with plain text and 403 with an empty body, so `get_errors()`
  can be empty while `getMessage()` still reads sensibly.
- A publishable key is accepted as a bearer token by the API but with different
  permissions, which fails confusingly and much later.
  `WC_Edge_Client_Factory::client()` throws rather than allow it.
- HPOS is declared, and claimed only because it has been exercised. All order
  reads and writes go through the CRUD API — never `update_post_meta()`.

### Test cards

Canonical table lives in `priv/openapi/description.md` in the ept repo. Common
ones:

| Number | Result |
|---|---|
| `4005519200000004` | Visa approval, frictionless 3DS |
| `5406004444444443` | Mastercard approval |
| `370000999999990` | AmEx approval |
| `6011450103333333` | Discover approval |
| `4124939999999990` | Generic decline |
| `4444333322221111` | Insufficient funds |
| `370000000000002` | Incorrect CVC |
| `5100060000000002` | 3DS challenge prompt |
| `370000000100018` | 3DS frictionless failure |
| `4012000077777777` | Not enrolled in 3DS |

Not `4242 4242 4242 4242` — that is Stripe's. It appears nowhere in this
codebase; do not reintroduce it.

## Conventions

- **Never edit `assets/js/`.** It is webpack output. Change `resources/js/` and
  rebuild.
- **No production Composer dependencies.** Vendoring libraries into a WordPress
  plugin collides with other plugins vendoring different versions of the same
  thing; Guzzle was the offender here, and dropping it also removed the
  PHP-Scoper build step that existed only to make shipping it safe. All HTTP goes
  through `WC_Edge_API_Client`, which is `wp_remote_request()` underneath. Adding
  a runtime dependency means bringing that machinery back — don't, without a very
  good reason.
- Sanitise input (`sanitize_text_field`, `absint`, `wp_kses_post`), unslash
  before sanitising, escape at output (`esc_html`, `esc_attr`, `esc_url`).
- Protect state-changing endpoints with nonces and session/capability checks.
- Text domain is `edge-gateway` in both PHP and JS.
- Frontend settings go through the Blocks integration. Never interpolate secrets
  or unescaped JSON into an inline script.
- Log through `WC_Edge_Logger`, never directly. Do not log keys, full API
  payloads, iframe events, card data or customer PII, and remove broad console
  event loggers before you finish.
- Prefer small gateway helpers with explicit responsibilities over a large
  `process_payment()`. Translate failures into WooCommerce notices without
  leaking internal response bodies.
- Country conversion must use the relevant billing/shipping country and survive
  empty or invalid WooCommerce values without an uncaught exception.
- Version lives in **five** places and must stay in sync: the `edge-gateway.php`
  plugin header, the `WC_EDGE_VERSION` constant just below it, `package.json`,
  the `@version` docblock on `WC_Gateway_Edge`, and the `WC_EDGE_VERSION` shim in
  `tests/bootstrap.php`. `ApiClientTest` additionally pins the user agent as a
  literal (`EdgeWooCommerce/2.1.0`, twice); `ClientFactoryTest` derives it from
  the constant. Per-file `@since` tags record when a class was introduced and do
  not move.

## Definition of done

A payment change is not done because the bundle builds. Before calling it
finished, confirm that:

- PHPUnit and PHPCS both pass, and the PHP syntax lint is clean on 7.4 syntax;
- secrets and card data are on the correct side of the iframe boundary;
- the JSON:API documents match the backend views, not this plugin's assumptions;
- retrying, double-submitting or reloading cannot double-charge;
- order and transaction state is right, and completion still comes from the
  webhook;
- failures produce something useful to the shopper;
- anything that changed in this file's territory is reflected above.

For manual end-to-end work, use a sandbox key pair and a disposable order, and
cover at least: success, decline, an expired or invalid iframe state, double
submit, page reload mid-checkout, an API failure, and a delayed or duplicated
webhook. Never test against live credentials or real card data.
