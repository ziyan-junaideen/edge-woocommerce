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
  class-wc-edge-refund-outcome.php   pure: what a refund response meant, and which refund in a
                                     listing is ours. The judgement calls, so they are testable
  class-wc-edge-refund-service.php   orchestration: refund(), full and partial
  class-wc-edge-rest-controller.php  POST /wp-json/edge/v1/checkout-intent
  class-wc-edge-subscription-reconciler.php
                                     reconciles this site's Edge *webhook* subscription (nothing to
                                     do with WooCommerce Subscriptions)
  class-wc-edge-webhook-signature.php
                                     pure: verifies the v3 `edge-signature` HMAC on a delivery
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
tests/unit/                          the suite (303 tests as of 2026-09-09)
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

**On a 401 against the local backend**, the merchant's tokens have been rotated
out from under the site. Mint a fresh set from the ept repo:

```bash
cd /Volumes/Dev/Work/Edge/edge/ept
mise exec -- mix core.generate_merchant_tokens_for "Edge"
```

The last five lines are the four tokens:

```
Edge:
Live/Publishable: ept_live_b…
Live/Secret: ept_live_s…
Sandbox/Publishable: ept_sandbox_b…
Sandbox/Secret: ept_sandbox_s…
```

Two things to know before running it. It **rotates**: every run invalidates the
previous set, so capture the output rather than re-running to look again, and
expect to re-enter the keys anywhere else that holds them. And the local backend
uses the **live** pair — `AGENTS.local.md` records an `ept_live_b…` token for
`api.tryedge.test:4001`, and the seeded orders there are `_edge_mode: live`. That
is the dev merchant, not production; what separates them is the API host, not the
key prefix. The "never test against live credentials" rule is about
`api.tryedge.io`.

Put the pair in the gateway settings, then reconcile the webhook subscription —
the stored one belongs to the previous merchant:

```bash
cd /Users/jdeen/Studio/my-wordpress-website
studio wp eval 'delete_option( "wc_edge_webhook_subscriptions" );
  $g = WC()->payment_gateways->payment_gateways()["edge"];
  $r = WC_Edge_Subscription_Reconciler::reconcile( $g );
  echo is_wp_error( $r ) ? $r->get_error_message() : "ok", "\n";'
```

Read the result back **through the API**, never by printing
`wc_edge_webhook_subscriptions` — that option holds the webhook signing secret
and does not store the event list anyway.

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
   signature-verified (`edge-signature`), deduplicated and serialised through
   `wp_edge_webhook_events`, and only then move the order.

Subscribed events are `transaction.payment_demands.created`, `.updated`,
`.succeeded` and `.failed`, plus `transaction.refund_demands.updated` and
`.failed` — the codes the backend actually emits and this plugin acts on.

`payment_demands.refunded` and `.disputed` are still listed as subscribable but
have no emit site: payment demands **lost their `refunded` processor state**
entirely (ept `8693770ba`, migration `20260827052053`), and refund accounting is
now derived from the refund demands themselves. The `case 'refunded':` branch in
`WC_Edge_Webhook_Controller::transition()` is therefore unreachable for anything
new; it is kept only so a replayed historical delivery still lands somewhere.

`transaction.refund_demands.created` is emitted but deliberately **not**
subscribed to. Edge records it inside the transaction that creates the refund —
before the HTTP response this plugin is still waiting on has been rendered — so
it is the delivery most likely to arrive before WordPress has written down which
refund it just made. `.updated` and `.failed` carry every outcome that matters.

### Webhook signatures

Deliveries are signed with **delivery version v3** and nothing else is accepted.
Every merchant on the backend is on v3 (`merchants.webhook_delivery_version`,
verified 2026-09-09: 39 of 39).

```
edge-signature: t=<unix seconds>,v3=<lowercase hex sha256>
```

The signed payload is `"<timestamp>.<raw body>"`, HMAC-SHA256 keyed with the
subscription's `secret_key` (`ept lib/req/plugin/webhook_signature.ex`). Verify
against `WP_REST_Request::get_body()`, **not** the parsed body — JSON key order
is not stable, so re-encoding a decoded payload does not reproduce the bytes that
were signed. The timestamp is inside the signed payload, and
`SIGNATURE_TOLERANCE` (300s) is what stops an intact delivery being replayed
later.

Unknown tokens in the header are skipped rather than treated as malformed: Edge
documents that a future scheme would be rolled out by emitting `v4` alongside
`v3` for a migration window.

The legacy `x-hub-signature` — a constant `base64(sha1(secret_key))`, with the
body not an input — is **no longer accepted**. It was a bearer token rather than
a signature. Anything still sending it will be rejected, which is intended.

`WC_Edge_Subscription_Reconciler` reconciles the existing
`webhook_subscriptions` resource rather than creating one, because saving
settings must be repeatable; creating on every save would leave a trail of
duplicates all delivering the same events.

### Refunds

`$this->supports` includes `'refunds'`, and the WooCommerce refund form drives
`WC_Gateway_Edge::process_refund()` -> `WC_Edge_Refund_Service::refund()`. Full
and partial refunds both work; the decisions worth being wrong about live in the
pure `WC_Edge_Refund_Outcome`.

Routes are `GET/POST /v2/refund_demands` and `GET /v2/refund_demands/{id}`.
**There is no confirm step** — unlike a payment demand, creating a refund demand
starts it. There is no cancel or void either.

The create document:

```json
{ "data": {
    "type": "refund_demands",
    "attributes": {
      "reason": "custom",
      "reason_note": "Customer changed their mind.",
      "amount_cents": 1000,
      "idempotency_key": "..."
    },
    "relationships": {
      "payment_demand": { "data": { "id": "<uuid>", "type": "payment_demands" } }
    }
} }
```

- `reason` is **required**. This plugin always sends `custom`: WooCommerce's
  refund reason is free text and cannot be mapped onto Edge's enum without
  guessing. The text itself goes in `reason_note` (**not** `refund_note`), which
  the backend caps at 500 characters.
- `amount_cents` is optional upstream — omitting it refunds the whole remaining
  balance — but this plugin **always sends it**. `wc_create_refund()` always has
  a concrete amount, so the omit path would only ever fire on a malformed call,
  where refunding everything is the worst available default.
- Never send `amount_currency`. It is inherited from the payment demand and a
  value that disagrees is a 422.
- The payment demand must be in `processor_state: succeeded`. The plugin does
  not read that field: `assert_refundable()` approximates it with
  `get_date_paid() || is_paid()`, which catches the ordinary on-hold order
  without a round trip. An order moved to Completed by hand passes the local
  check and is refused by Edge with a 422 instead.

Partial refunds accumulate against the payment total. `pending`, `processing` and
`succeeded` refunds each **reserve** their amount; a `failed` refund releases its
reservation. Exceeding the balance is a 422 (`greater than the unrefunded
amount`, or `has already been fully refunded`), and the cap is serialised behind
a `SELECT ... FOR UPDATE` on the payment demand.

Refund states are `pending -> processing -> succeeded | failed`. There is no
`errored` state any more (ept `d965ef2f6`).

**Refund idempotency is not payment idempotency.** Both are a body attribute,
`data.attributes.idempotency_key`, and neither is an HTTP header — but a refund
key replayed with a *different* payment, amount, reason or note is **rejected
with 422** (`has already been used for a different refund request`), where a
payment demand would silently return the original. Refunds therefore do not carry
the trap described below for `payment_demands`. A matching replay returns the
original refund, still **201**, and enqueues no second job, event or delivery.
Keys are scoped to the merchant.

The key comes from `WC_Edge_Fingerprint::for_refund()` over the payment demand,
mode, order id, **WooCommerce refund row id** and amount. The refund row id is
what makes two deliberate partial refunds of the same amount distinct while an
in-call retry stays identical. `reason_note` is deliberately excluded: the shared
`normalise()` lowercases ordinary strings, and Edge compares the note exactly, so
including it would let two notes differing only in case collide as a 422.

`process_refund()` is handed an order id, an amount and a reason — never the
`WC_Order_Refund`. `WC_Edge_Payments::claim_refund()` hooks
`woocommerce_create_refund`, which fires with the object *before* it is saved and
before the gateway is called, so the same object handle has an id by the time
`claimed_refund()` reads it back. The claim is single use. If it is missing —
something other than the refund form called the gateway — the refund is refused
rather than guessed at, because a key attached to the wrong refund is worse than
no refund.

Meta written: `_edge_refund_demand_id`, `_edge_refund_idempotency_key` and
`_edge_refund_state` on the `WC_Order_Refund`, `_edge_refund_failed` on both the
refund row and its order, and `_edge_refund_pending` on the order — a list keyed
by idempotency key, written *before* the request goes out, holding refunds sent
but not yet accounted for.

Note that `WC_Order_Refund` extends `WC_Abstract_Order`, not `WC_Order`, so it
has **no `set_transaction_id()`**. Calling it is a fatal. Ids go in meta.

Two limits are known and deliberate:

- **`_edge_refund_pending` is a read-modify-write of one serialised order meta
  value, with no lock.** Two refunds started on the same order at the same moment
  can lose one another's record, and two concurrent refunds get different
  WooCommerce row ids and so different idempotency keys — meaning both can be
  accepted while balance remains. `pending_duplicate()` catches the sequential
  case only. Closing this properly needs a unique constraint, the way
  `WC_Edge_Attempt_Store` does it for concurrent checkout prepares.
- **Nothing reconciles the webhook subscription on upgrade.**
  `WC_Edge_Subscription_Reconciler::reconcile()` runs only from
  `process_admin_options()`, so a site that had 2.1.0 keeps a subscription
  carrying just the four `payment_demands` codes until someone re-saves the
  gateway settings. Until then refunds still go out and succeed, but a refund
  that *fails* is never reported. This is accepted because the plugin has not
  shipped refunds; if that changes, it needs a version-stamped one-time
  reconcile.

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
- A refund is only **`pending`** when `process_refund()` returns `true`, and by
  then WooCommerce has already treated it as final: it marks the row refunded,
  **restocks the line items, revokes downloads, fires the refunded actions (which
  email the customer) and can move the order to `refunded`**
  (`wc-order-functions.php`). None of that is undone when the refund later fails,
  and `get_remaining_refund_amount()` keeps counting the failed row. The failure
  webhook therefore reports loudly rather than correcting silently, and the note
  tells the merchant to delete the refund row to put the balance and stock back.
  Waiting for a terminal state instead would block an admin request on Edge's job
  pipeline for an unbounded time.
- A 2xx from `create()` is **not** proof a refund exists.
  `WC_Edge_API_Client::decode()` answers an empty body with a bare `stdClass` and
  throws on a truncated one carrying the 2xx status, so anything that leaves us
  unable to name the refund is ambiguous — never a failure. A failure sends the
  merchant round again with a fresh key; ambiguity is resolved by replaying the
  same key, then by looking the key up in
  `GET /v2/refund_demands?filter[payment_demand]=...`.
- Never fall through from "an earlier refund was found unaccounted for" into a
  new refund request. WooCommerce **deletes** its refund row whenever the gateway
  returns a `WP_Error`, so the merchant's retry arrives with a new row id and
  therefore a new idempotency key. Sending that while an unaccounted refund is
  still reserving its amount is how a partial refund gets paid twice — the
  backend's balance cap does not catch it while there is balance left.
- `_edge_refund_pending` may only be cleared on **positive knowledge**: a refund
  was named, or a listing that was actually fetched does not contain the key.
  "Could not look" and "not there" are different answers, which is why
  `look_up()` returns `read` alongside `found`. In particular, a refusal that
  arrives *after* an unclear reply says nothing about what the first request did,
  so `send()` tracks whether it has ever been unsure and refuses to treat a late
  4xx as proof that nothing exists.

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
  literal (`EdgeWooCommerce/2.3.0`, twice); `ClientFactoryTest` derives it from
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

For a refund change, also cover: a partial refund, a second partial that settles
the balance, a full refund, an over-refund (which has to be provoked from outside
WooCommerce — once WooCommerce believes an order is fully refunded,
`wc_create_refund()` refuses before the gateway ever runs), a refund on an order
Edge has not confirmed, a replayed idempotency key, a key replayed with a changed
note, a refund that reaches `failed`, and a refund event delivered before
WordPress has recorded which refund it made.

Two things make that practical against the local backend:

- **Forcing a failed refund.** `Core.EdgeAuthorizeRefundJob` declines the refund
  when the payment method's `last_four` is `"5126"` and succeeds otherwise, so a
  refund can be driven to `failed` by setting that column on the demand's payment
  method for the duration of the test. Put it back afterwards.
- **Getting a refundable payment without the hosted form.** The card iframe is
  cross-origin and cannot be driven from the agent side, so bind a disposable
  order to a payment demand that is already `succeeded` — set `_edge_demand_id`,
  `_edge_mode`, `_edge_amount_cents` and `_edge_currency` and call
  `payment_complete()`, which is the state `adopt_attempt()` would have left. List
  the merchant's demands with `GET /v2/payment_demands`; the local database is
  reset from time to time, so demands referenced by older orders will 404.
