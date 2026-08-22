# Round plan — Manfaa Cashback for WooCommerce

Started **2026-08-22**. PLAN.md §13b is the long-lived record; this file is the
scratch that survives a context loss. Fold it in and delete it when the round
lands.

Owner instruction, verbatim:

> create a plan for WooCommerce plugin build for latest version of WooCommerce.
> Should have product category mapping to synced cashback category options as
> well as option to use general cashback instead. if certain categories are
> set, others become everything else. When plugin enabled, add additional field
> for customer cart to enter Manfaa Code. Plugin settings must have Option to
> show customer Estimated Cashback. Also should have option for when cashback
> will be posted, order confirmed, completed. etc. and cashback reversal on
> cancellation of ordrr

Scope: **Manfaa only.** Shopify is out of scope for this plan (a hosted-app
model, its own plan). Nothing here touches any other project on this server.

Target: **WooCommerce 11.0.1** (wordpress.org, 2026-08-10; requires WordPress
6.9+, tested up to WP 7.0). The plugin requires **WC ≥ 9.0 and PHP ≥ 8.1** —
the Checkout Block field API graduated in 8.9, and 9.0 is the floor at which
HPOS and Action Scheduler are mature; PHP 8.1 for enums and readonly
properties. Both checks are recorded in WC0 below.

Reviewed 2026-08-22 by three adversarial readers (API contract, WooCommerce
internals against the downloaded 11.0.1 source, completeness against the
instruction above): 50 findings, all folded in. The largest: the first draft
put the Manfaa-code field at checkout and attributed that to the owner — the
instruction says **cart**. Fixed in D2.

---

## 1. What exists today (from the 2026-08-22 codebase survey)

Everything a shop needs to record a sale is already live, documented at
https://manfaa.app/docs/, and tested. The plugin is a client of it; **nothing
on the money path changes for this round.**

| The plugin needs | What the API gives | Ability |
|---|---|---|
| Record a sale | `POST /v1/transactions` — `invoice_no`, `customer_ref`, `eligible_amount` (laari), `sale_amount`, optional `lines[]`; `occurred_at` optional (**omitted = now**); **`Idempotency-Key` header mandatory** | `transactions:write` |
| Undo it | `POST /v1/transactions/{id}/reverse` — `reason ∈ customer_refund, till_void, duplicate, other`; whole transaction only | `transactions:reverse` |
| The standing rate, minimum, promotion | `GET /v1/merchants/me/rate` — `cashback_rate_percent`, `min_eligible_laari`, `has_category_overrides`, `pending_decrease`, `active_promotion?` | `rates:read` |
| The category rate card | `GET /v1/merchants/me/product-categories` — `{category_id, category (slug), name_en, name_dv, mode: excluded\|rate, cashback_rate_percent\|null}`; **active rows only, in the merchant's sort order, no `sort` field on the wire** | `rates:read` |
| Confirm who a code belongs to | `GET /v1/customers/lookup?ref=` — `{valid, name}`; `valid:false` = known but cannot earn; 404 `customer_not_found`; **60 misses/merchant/day then 429** | `customers:lookup` |

What the API does **not** have, and this plan adds as small server companions
(§4): a way to read a transaction back, a way to read a token's own abilities,
and an `origin` that says "online".

Facts the plan is built around (file:line in the research notes):

- **The server prices everything.** Cashback = `intdiv(eligible × rate_bp + 9999, 10000)` **per category bucket** (one line per category), totals are sums of the rounded buckets (`CashbackCalculator.php:34`). The plugin sends laari integers and never a computed cashback — except the *display-only* estimate, which must round per bucket the same way.
- **`lines[]` must sum exactly to `eligible_amount`** (`lines_sum_mismatch`); each category at most once; every amount `min:1`; `category: null` is the explicit "everything else" bucket priced at the standing rate; `mode: excluded` categories are sent as lines and priced at 0. If `lines` is omitted the whole amount prices at the standing rate — **excluded categories are then ignored**, which is what `has_category_overrides` exists to warn about.
- **Idempotency**: the server hashes `method|path|raw body`; same key + same bytes → `200` replay with `Idempotency-Replay: true` (not the original 201); same key + **any byte difference → 422 `idempotency_key_reuse_mismatch`**; concurrent → 409 `idempotency_key_in_flight`. Keys are merchant-scoped and **retained 30 days** (daily prune); past that, the `(merchant_id, invoice_no)` unique index is the safety net.
- **`invoice_no` is unique per merchant across all states, including `reversed`** → `409 duplicate_invoice` carries only `meta.transaction_id`.
- **Lifecycle**: `tracked → awaiting_validation → payable_unfunded → confirmed → paid`. The validation window (merchant's `validation_window_days`) runs from `occurred_at`; the sweeper auto-validates. The buyer's balance shows **pending until the merchant's settlement is allocated**.
- **Reversal outcome depends on state, not on the window**: pending **and not yet on a submitted settlement** → in-place `reversed`; `confirmed`/`paid` → `adjustment_created`, cause `already_confirmed`; still pending but the settlement has left draft → `adjustment_created`, cause `locked_in_settlement`. The memo nets against the merchant's next settlement; a paid-out reward is not clawed back from the buyer. `backdated` rows are permanently irreversible (409 `backdated_irreversible`).
- **Backdating**: `occurred_at` older than `validation_window_days + 3` days is silently recorded `backdated_final`. `/v1` never exposes the window, so a client cannot compute that threshold — the plan avoids it entirely by **never sending `occurred_at`** (D5).
- **No partial reversal on `/v1`**, no amend endpoint (amend is merchant-panel/mobile only, `awaiting_validation` only).
- **Customer keying**: 6-digit code (origin `pos`) or a +960 7/9-prefixed phone (origin `api_phone`); `online_link` exists in the origin CHECK and every panel's label map ("Online checkout") but nothing sets it. `POST /v1/transactions` does **not** check customer status — a `valid:false` account still gets credited.
- **Notifications the buyer gets**: `cashback_earned` (SMS + push) when the sale posts; `cashback_confirmed` when the window closes; **nothing on reversal** — they only see the row as *reversed* in Activity.
- **Credentials** — two paths exist: (a) the store **owner** (permission `api_credentials.create` is owner-only by preset; the store must be approved) issues a personal access token in the panel, Settings › API access, 3-step wizard, 10 active max, **default abilities omit `customers:lookup`**; (b) Platform Connect (OAuth2 code + PKCE) — **requires a `client_secret` at exchange and exact-match `https://` redirect URIs, max 10 per client**. A self-hosted plugin has a different callback on every install and cannot hold a secret, so (b) does not fit as built. See D1.
- **Webhooks are vendor-level**: `webhook_endpoints` has no merchant column and the dispatcher selects by `pos_vendor_id`; a self-issued token has no vendor. **No webhook can reach a store under D1**, and under a single "WooCommerce" vendor every store would receive every other store's events. Not used in this plan.
- **Revocation is silent** (next call 401).
- **`sandbox.api.manfaa.app` does not resolve** — in the guide and `apps/merchant/lib/integration.ts`, no DNS, vhost or config (open since the connect round).
- **Repo precedent for non-JS code**: `mobile/` is a top-level directory outside the pnpm workspace with its own toolchain and a `download/public/` distribution path. The `/app/` nginx location whitelists only `html/png/apk` types.

---

## 2. Decisions

### 2.1 Decided by the owner on 2026-08-22 (answers to the WC0 questions)

| | Decision |
|---|---|
| D1 | **OAuth first.** "Connect with Manfaa" (public client, PKCE) is the primary path and ships BEFORE the plugin; paste-a-token stays as a collapsed "Use an API token instead" advanced option. |
| D2 | Cart **and** checkout, one session value. |
| D5 | Items after discounts, **no shipping, no GST by default** — exposed as a *Cashback awarding policy* setting with a second choice *items after discounts including GST*. The plugin has a **top-level admin menu** ("Manfaa Cashback"), not a WooCommerce sub-tab. |
| D6 | Default post on **Completed**; partial refund = do nothing (until the `/v1` amend). |
| Redirect policy | **Self-registration at consent.** The plugin sends its own callback; the consent screen shows "Connecting shop.example.mv"; the owner's approval binds that exact URL into the code and records it on the credential (`connected_from`). No pre-registration step. |
| Sandbox | **Dropped** from the docs and `integration.ts` for now. |
| Reversal push | **Yes** — `cashback_reversed` customer push, WC2 (server). |

The detailed reasoning below is kept as written; where a row above differs, the row wins.


**D1 — SUPERSEDED (owner, 2026-08-22): OAuth first. The public-client grant below (was WC4) is now WC1; the token paste is the advanced fallback.**
Connect was built for a *platform* with one server and a few fixed callbacks. A WordPress plugin is the opposite: one codebase on thousands of servers, each with its own callback, none able to keep a secret. Making it fit needs a **public-client** grant (no secret, PKCE mandatory, and a redirect-URI policy for per-install callbacks) — real server work and a security decision, so it is **WC4**. Meanwhile the panel's wizard already issues the token the plugin needs. WC1 makes that a two-click path: the plugin's **Get a token** button deep-links to `merchant.manfaa.app/settings/api-access?partner=…&abilities=transactions:write,transactions:reverse,rates:read,customers:lookup` (the prefill must name `customers:lookup` — the wizard's own default omits it), the owner copies the token once, pastes it, and **Test connection** reads back exactly which abilities it carries via the new `GET /v1/me`. Honest about the trade: it is paste-a-key, not OAuth. Only the store **owner** on an **approved** store can issue one; the go-live checklist says so.

**D2 — The buyer enters their 6-digit Manfaa code on the cart (the owner's word), carried into checkout, in both the Block and classic storefronts. Billing-phone matching is an opt-in fallback, default OFF.**
On a current WooCommerce install the Cart and Checkout are **Blocks** by default, and the Cart Block has no "additional fields" API (its field locations are address/contact/order — checkout only). So the code entry is a **custom inner block — "Manfaa code + estimate" — registered into the cart *and* checkout order-summary areas with `force: true`** so it appears without the merchant editing any page; it owns its input, so it can confirm the code live, and it writes the code to the WooCommerce session via `extensionCartUpdate`. The classic cart gets the same panel via `woocommerce_after_cart_table` (AJAX to the same session), the classic checkout via `woocommerce_checkout_before_order_review`; both checkouts prefill from the session. At order creation the code is written once to `_manfaa_code` (Block: `woocommerce_store_api_checkout_update_order_from_request`; classic: `woocommerce_checkout_create_order`). The plugin deliberately does **not** use the additional-checkout-fields API: it cannot host the live lookup, `inputmode` is rejected, and it auto-renders the value into every email and the admin shipping section. With `customers:lookup`, the field confirms the code as it is completed and shows the account holder's first name; `valid:false` shows "This Manfaa account cannot earn cashback right now" (and a setting decides whether to post anyway); a miss says so. Validation is advisory and **never blocks checkout**; it is throttled per session because lookups and posting share the token's 120 req/min budget and the 60-miss/day lookup budget. Phone fallback, when on, normalises the billing phone (`960[79]\d{6}` → `+960…`, or `[79]\d{6}`) and posts nothing if it does not fit.

**D3 — The estimate is computed from synced rates with the server's rounding per category bucket, labelled "Estimated", and deliberately conservative.**
Standing rate and category rates only; promotions are **not** added (their per-customer cap is unknowable client-side; under-promising is the safe error). Amounts are summed per Manfaa category *first*, then rounded — never per cart item, because a sum of per-item ceilings over-estimates. Below the merchant's minimum it shows the shortfall. Rates cache for 1 hour with a **Sync now** button; `pending_decrease` is ignored until effective.

**D4 — Category mapping lives in the plugin's settings, maps WooCommerce product categories → synced Manfaa categories, and follows the owner's rule: mapped categories are priced as mapped, everything unmapped goes to the `category: null` bucket.**
Two modes: *General rate* (no `lines[]` — whole amount at the standing rate; **shown with a warning when `has_category_overrides` is true**, because General pays the standing rate on goods the merchant excluded in the panel) and *Per category* (lines built per order). A product in several WooCommerce categories that map to different Manfaa categories is priced by the Manfaa category that **appears first in the synced list** (the server orders by the merchant's own sort; the plugin stores the position with each mapping at sync, since a re-sync can reorder). Manfaa `excluded` categories can be mapped — those lines earn 0, which is the point. The plugin only *reads* Manfaa categories; the merchant creates them in the panel, then syncs. A mapped category that the merchant later deactivates disappears from the sync and its mapping row is flagged.

**D5 — Eligible amount = sum of line totals after coupon discounts, excluding shipping and tax; `sale_amount` = the order total; `occurred_at` is never sent (the server stamps now).**
Items-only matches PLAN-marketplace Q1 ("fee/cashback base is items only, never delivery"). Per item: `get_total()` (post-discount, pre-tax) rounded to 2 dp **first**, then ×100 to laari (cart math carries 6 dp; `(int)(x*100)` is forbidden); summed per Manfaa bucket; zero buckets dropped; `eligible_amount` = sum of buckets, so the partition identity holds. An order whose eligible amount is 0 (free, 100 % coupon) is **not posted** — one note. Omitting `occurred_at` does three things at once: the request body is immutable, so a retry with the same `Idempotency-Key` can never hit `reuse_mismatch`; a parked job re-run a week later is still "now", so nothing can ever become `backdated_final`; and the refund-exposure window starts when the plugin actually posts, which is the moment the merchant chose.

**D6 — Posting and reversal: a status-driven setting, default post on `completed`; reverse on `cancelled`, `refunded` and trash; partial refunds do nothing by default.**
WooCommerce has no "confirmed" status: the owner's "order confirmed" moment is **`processing`** (payment received) — offered as *Processing (paid)*. `on-hold` is not offered (payment unconfirmed). Default *Completed* (fulfilled) is the marketplace precedent (credit on delivered + verified). Refunds before the merchant **submits** the settlement containing that sale reverse in place; later ones become a credit memo — that boundary is settlement cadence, not the validation window, and the guide says so plainly. Partial refunds have no honest mapping on `/v1` today, so the setting is *do nothing* (default) or *reverse the whole sale*; a `/v1` amend is queued for WC2. **A reversed sale is final**: `invoice_no` is unique across all states, so an order that is cancelled, reversed, then re-completed posts nothing and says why in a note.

### 2.2 Defaults taken — low-stakes, each a small change if wrong

- Repo location: **`plugins/woocommerce/manfaa-cashback/`** (new top-level dir, mirrors `mobile/` as a non-pnpm platform, its own `package.json` for `@wordpress/scripts`). PLAN.md §3 gets a line. Slug and text domain `manfaa-cashback`, PSR-4 `Manfaa\Cashback\`.
- Distribution: `scripts/build-woocommerce-plugin.sh` → `download/public/woocommerce/manfaa-cashback-<ver>.zip` + `manfaa-cashback.zip` (latest) + `manifest.json`; the nginx `/app/` types block gains `application/zip zip; application/json json;` (today a zip there would be served as `text/html` and WordPress would reject it); Cloudflare purge after replacement. wordpress.org is **not** in scope.
- `invoice_no` = `<prefix><order number>` where the prefix **defaults to a 6-character site-derived token plus `-`** (so two stores on one merchant never collide on small order ids; `get_order_number()` is just the id unless a numbering plugin filters it). A `409 duplicate_invoice` is *adopted*: the plugin fetches the transaction by id, records its real state, and writes a note that the sale already existed — never a blind "posted".
- Idempotency keys are deterministic per order and action: `woo:<site-hash>:order:<id>:create` and `…:reverse`. **The exact request bytes are frozen into order meta at enqueue time and resent verbatim on every retry**; the key is never reused with a different body. `200 + Idempotency-Replay: true` is success.
- All HTTP goes through **Action Scheduler** (bundled in WooCommerce): hooks only enqueue; the request runs off the request thread with retry (1m, 5m, 30m, 2h, 12h — comfortably inside the 30-day key retention) then parks as *needs attention* with an admin notice and an order note — never silently dropped. `$unique` is **not** used (on the WC 9.x floor it is hook-only and would drop other orders' jobs); dedupe is by order meta set synchronously in the hook.
- Store currency must be **MVR**; otherwise the plugin refuses to post, hides the estimate, and says why in settings.
- Reversal reasons: `cancelled → till_void`, `refunded → customer_refund`, `trashed → other`. Permanent deletion of an order with a pending reverse is logged, not blocked.
- Orders placed before activation are **not** posted when their status changes (setting, default on) — no back-fill flood.
- Settings: WordPress Settings API, one option `manfaa_cashback`, `option_page_capability_manfaa_cashback → manage_woocommerce` so a Shop Manager can save; the token field is masked and the sanitiser keeps the stored ciphertext when the mask or an empty value comes back; **Test connection** and **Sync now** are `wp_ajax_` handlers (nonce + `manage_woocommerce`), not form saves.
- Token at rest: encrypted with `sodium_crypto_secretbox`, key derived in this order — `MANFAA_CASHBACK_KEY` from `wp-config.php` if defined (documented in the go-live checklist) → HKDF of `AUTH_KEY.AUTH_SALT` **only when they are real constants** → `wp_salt()` with an admin notice that the key then lives in the database (WordPress stores generated salts in `wp_options`, the very table this protects against). A key fingerprint is stored beside the ciphertext; a mismatch (rotated salts) shows *Reconnect Manfaa* rather than retrying with garbage.
- Logging through `wc_get_logger()` source `manfaa-cashback`; never the token, never the buyer's name.
- Translations: English and **Dhivehi** (RTL-safe panel and estimate). Dhivehi strings need a native check, as everywhere in this repo.

---

## 3. What the buyer and the merchant will see

**Buyer**: on the cart, a small *Manfaa* panel — "Have a Manfaa code?" with a 6-digit input, and (display option on) **"Estimated cashback MVR 12.50"** or the shortfall line below the minimum. With lookup on, a tick and "Cashback goes to Aishath" as the sixth digit lands; a wrong code says so without blocking anything. The same panel on checkout, prefilled. After the order reaches the posting status: the SMS/push the platform already sends, then *confirmed* when the window closes; the app shows it **pending until the store settles** — copy never promises "available in 2 days". **On a reversal the buyer is not notified** (no such notification exists today); it appears as *reversed* in their Activity.

**Merchant, in WordPress**: *WooCommerce › Manfaa Cashback*; an order-list column and an order metabox showing the plugin state (§4 table) with the amount and Manfaa transaction id; a *Refresh status* action; order notes for every post, replay, adoption, reversal, memo and refusal. In the Manfaa panel the sale appears under Transactions labelled *Online checkout* once `origin` lands.

---

## 4. Architecture

```
plugins/woocommerce/manfaa-cashback/
├── manfaa-cashback.php            header (Requires Plugins: woocommerce; WC requires at least: 9.0;
│                                  WC tested up to: 11.0; Requires PHP: 8.1), PHP-7.4-parseable
│                                  bootstrap that gates on WC_VERSION at plugins_loaded and bails
│                                  with a notice; FeaturesUtil::declare_compatibility for
│                                  'custom_order_tables' and 'cart_checkout_blocks' inside
│                                  before_woocommerce_init
├── src/
│   ├── Plugin.php                 boot, hooks, Action Scheduler handlers
│   ├── Api/Client.php             bearer, Idempotency-Key, error envelope → typed exceptions
│   ├── Api/RateCard.php           /me, /rate, /product-categories; 1h transient; Sync now
│   ├── Money/Laari.php            2-dp round then ×100; never floats in comparisons
│   ├── Money/Estimator.php        per-bucket intdiv(x*bp+9999,10000) — the server's rule
│   ├── Pricing/LineBuilder.php    order → eligible_amount + lines[]; zero buckets dropped
│   ├── Pricing/CategoryMap.php    Woo term → Manfaa slug; first-in-synced-list precedence
│   ├── Storefront/Session.php     the code in WC()->session; Store API update callback
│   ├── Storefront/Panel.php       classic cart + classic checkout rendering (same panel)
│   ├── Storefront/Lookup.php      REST /wp-json/manfaa/v1/lookup; nonce; per-session throttle
│   ├── Storefront/StoreApi.php    endpoint data (estimate) + update callback (code)
│   ├── Orders/Poster.php          trigger → freeze body → enqueue → POST; adoption via GET
│   ├── Orders/Reverser.php        cancelled / refunded / trashed → reverse; outcome handling
│   ├── Orders/Status.php          plugin state machine (table below); Refresh; daily sweep
│   ├── Orders/Meta.php            HPOS-safe order meta
│   ├── Admin/Settings.php         Settings API page + ajax handlers
│   ├── Admin/OrderColumn.php      HPOS + legacy column hooks; metabox on wc_get_page_screen_id
│   └── Support/{Log,Crypto}.php
├── assets/src/                    the inner block (React, @wordpress/scripts), classic JS
├── languages/                     en (source), dv
├── tests/                         PHPUnit (WP + WC test lib); HTTP captured via pre_http_request
└── readme.txt
```

**Hooks, by concern**

- *Code entry + estimate, Blocks*: PHP `register_block_type` for `manfaa/panel` with `parent` = cart and checkout order-summary blocks; JS `registerCheckoutBlock({ metadata: { name: 'manfaa/panel', parent: [CART_ORDER_SUMMARY, CHECKOUT_ORDER_SUMMARY] }, component, force: true })`; the input calls `extensionCartUpdate({ namespace: 'manfaa', data: { code } })` → PHP `woocommerce_store_api_register_update_callback` writes the session; the estimate comes from `woocommerce_store_api_register_endpoint_data` (endpoint `CartSchema::IDENTIFIER`, namespace `manfaa`, `estimate_laari` + `shortfall_laari`) on `woocommerce_blocks_loaded`, rendered as a `TotalsItem`.
- *Code entry + estimate, classic*: `woocommerce_after_cart_table` and `woocommerce_checkout_before_order_review` render the panel; `woocommerce_cart_totals_after_order_total` / `woocommerce_review_order_after_order_total` render the estimate row; an admin-ajax endpoint sets the session and returns fragments.
- *Persisting the code*: Block checkout `woocommerce_store_api_checkout_update_order_from_request($order, $request)`; classic `woocommerce_after_checkout_validation($data, $errors)` (6 digits or empty) and `woocommerce_checkout_create_order($order, $data)` — both write `_manfaa_code` from the session, before the single save.
- *Posting*: `woocommerce_order_status_<trigger>($order_id, $order, $transition)` → set `_manfaa_state = queued` synchronously, freeze the body, `as_enqueue_async_action('manfaa_cashback_post', [$order_id], 'manfaa')`. Re-entry is a no-op once `_manfaa_state` is past `queued`.
- *Reversal*: `woocommerce_order_status_cancelled`, `woocommerce_order_status_refunded`, `woocommerce_trash_order($order_id)` → set `_manfaa_reverse_state = queued` synchronously, freeze reason + body, enqueue. **Not** `woocommerce_order_fully_refunded` — a full refund fires it *and then* `…status_refunded`, which would enqueue twice. `woocommerce_order_partially_refunded($order_id, $refund_id)` → the partial policy. One reverse per order, ever.
- *Admin*: `manage_woocommerce_page_wc-orders_columns` / `…_custom_column($column, $order)` (HPOS) and `manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column($column, $post_id)` (legacy); metabox on `wc_get_page_screen_id('shop-order')`, callback accepting `WC_Order|WP_Post`.

**Plugin order state — the table every test and every note hangs off**

| `_manfaa_state` | Set when | Column | Note |
|---|---|---|---|
| `skipped_no_code` | trigger fired, no code, phone fallback off or unusable | — | "No Manfaa code on this order" |
| `skipped_pre_activation` | order predates activation | — | (none) |
| `skipped_currency` | store not MVR | — | (none, settings notice) |
| `skipped_zero` | eligible amount is 0 | — | "Nothing eligible for cashback" |
| `queued` | body frozen, job enqueued | *Posting…* | — |
| `posted` | `201 created` or `200 replay` | *MVR x · pending* | "Cashback MVR x recorded (#id)" |
| `posted_zero` | `200 below_minimum` / `recorded_ineligible` | *MVR 0 · below minimum* / *· store ineligible* | reason in note |
| `adopted` | `409 duplicate_invoice` → `GET /v1/transactions/{id}` | per fetched state | "Sale already existed on Manfaa as #id (state)" |
| `needs_attention` | `422 customer_not_found`, `validation_failed`, `unknown_category`, `inactive_category`, `idempotency_key_reuse_mismatch`; retries exhausted | *Needs attention* | the error, verbatim code |
| `disconnected` | `401`; `403 forbidden_ability`; `422 no_effective_rate` | *Reconnect Manfaa* | admin notice; scheduling stops |
| `reversed` | reverse `outcome: reversed` | *Reversed* | "Cashback reversed — the buyer is not notified; it shows as reversed in their app" |
| `adjusted` | `outcome: adjustment_created` | *Credit memo* | by cause: `already_confirmed` → "already confirmed — netted against the store's next settlement"; `locked_in_settlement` → "already on a submitted settlement — credited back on the next one" |
| `reverse_refused` | `409 backdated_irreversible` / `invalid_state` | *Reverse refused* | the code; not retried |
| `final_reversed` | re-completion after `reversed` | *Reversed (final)* | "Cashback already reversed — cannot re-earn" |

`429` and `409 idempotency_key_in_flight` retry with the same key honouring `Retry-After`; they are not states. Status refresh (`GET /v1/transactions/{id}`) updates the *pending / confirmed / paid / reversed* half of the column on demand and by a daily sweep over orders still pending and younger than 60 days.

**Server-side companions (tiny, alongside WC1; own branch off `main`, not `marketplace`)**

- `GET /v1/me` → `{ merchant: {id, name}, abilities: [...] }` from the token itself. Lets *Test connection* say exactly what the pasted token can do. Any ability.
- `GET /v1/transactions/{id}` → `TransactionResource` with `lines`, merchant-scoped exactly as `reverse()` scopes. Makes adoption honest and status refresh possible. Ability `transactions:write`.
- `origin` on `POST /v1/transactions`: `['sometimes', 'in:online_link']` — a closed whitelist (a free `origin` would let a till post `marketplace` rows, which skip settlement). Phone-keyed + `online_link` records `online_link` (origin is the channel; the ref kind is on the row anyway). Pest: accepted, rejected for any other value, label renders in the panels.
- Prefilled credential wizard: `apps/merchant/app/(app)/settings/api-access` reads `?partner=&abilities=`.
- Own: `api/routes/api/v1.php`, `api/app/Http/Controllers/V1/{MeController,TransactionsController}.php`, `api/app/Domain/Cashback/ApiCreditService.php`, `docs/openapi.yaml`, `docs/integration-guide.md`, `apps/merchant/app/(app)/settings/api-access/**`. Test DB `manfaa_test_2`. Sequenced after whatever marketplace round is touching `apps/merchant`.

---

## 5. Rounds

House convention per round: build → PHPUnit green (WP + WC test lib, HTTP captured) → manual pass on a `wp-env` store against the live API with a test merchant (Block **and** classic cart/checkout, HPOS **on**) → adversarial review → fix → next. Server changes: Pest green, pint, `systemctl restart manfaa-queue.service`, docs rebuilt with `node scripts/build-docs.mjs`.

### WC0 — This plan and the decisions (2026-08-22)

- [x] Survey: API contract, connect flow, cashback lifecycle, panel conventions (four readers).
- [x] WooCommerce target pinned: 11.0.1 (readme: requires WP 6.9, tested up to 7.0); field API graduated 8.9 (`functions.php:45` deprecation to `woocommerce_register_additional_checkout_field`); WC 11.0 added a `_doing_it_wrong` for fields registered before `after_setup_theme` — `woocommerce_init` satisfies it.
- [x] Adversarial review of this plan: 50 findings folded in (this revision).
- [x] **Owner confirmed/amended D1–D6** (2026-08-22, table in §2.1): OAuth first; cart + checkout; awarding-policy setting with GST choice + top-level menu; Completed default; self-registering callback; sandbox dropped; reversal push yes.

### WC1 — Server: the public client ("Connect with Manfaa"), and the plugin's companions — DONE (2026-08-22)

Pulled forward from WC4 by the owner. One registered public client, *Manfaa for WooCommerce*, that any store can connect without anyone at Manfaa touching a registry.

- [x] `pos_vendors.public_client` (bool). A public client has **no secret** (`client_secret_hash` null; rotate is refused), PKCE stays mandatory, and `redirect_uris` is ignored: the callback arrives with the request.
- [x] Redirect policy for public clients: `https://` on a public host (same `EndpointUrlGuard` as webhooks — no private ranges, no bare IPs, no localhost), no fragment, ≤ 255 chars. The **exact** URL is bound into the authorization code (already: `oauth_authorization_codes.redirect_uri`), so the exchange must present the same string.
- [x] `api_credentials.connected_from` (string, nullable): the callback's origin (`https://shop.example.mv`), set at exchange. Shown in the panel's credential table ("Connected from shop.example.mv") and in the admin registry.
- [x] Replacement rule for public clients: re-authorising from the **same origin** replaces that origin's live grant; a **different origin** is an additional connection (a merchant with two WooCommerce stores keeps both), subject to the store's credential cap.
- [x] Consent screen: `callback_host` in the `GET merchant/connect/authorize` payload; the panel shows it under the application name for public clients ("This will connect **shop.example.mv**").
- [x] `POST /v1/connect/token`: `client_secret` optional; refused as `invalid_client` when a confidential client omits it, and **refused when a public client sends one** (a plugin that thinks it has a secret is misconfigured). Response unchanged.
- [x] Admin registry: "Public client (a plugin)" switch at registration (create-only; callbacks field hidden); `has_secret:false`, no rotate button; rotate → 409 `public_client`. The WooCommerce client is seeded by `php artisan manfaa:register-woocommerce-client` (idempotent) with abilities `transactions:write transactions:reverse rates:read customers:lookup webhooks:manage`. **Registered on production: `client_id mfa_gewk290rpqxqol48uais1cqs`** — the plugin ships with this.
- [x] `GET /v1/me` — store name, status, the token's abilities, `connected_from`, currency, rate summary (`standing_rate_percent`, `minimum_eligible_amount`, `has_category_overrides`). The plugin's *Test connection* reads this one call.
- [x] `GET /v1/transactions/{id}` — own merchant only (404 otherwise), full `Transaction` + lines; what `409 duplicate_invoice` adoption reads.
- [x] `origin: online_link` accepted on `POST /v1/transactions` (whitelist: `pos` default, `online_link`); the panels already label it.
- [x] Pest: every refusal of the public flow (secret sent, private host, http, fragment, mismatched redirect at exchange, second origin = second grant, same origin = replace, cap), `/v1/me` abilities match the token, `/v1/transactions/{id}` cross-merchant 404, origin whitelist. Existing `PlatformConnectTest` untouched and green.
- [x] Docs: the Connect tag gains "Public clients (plugins)"; `/v1/me`, `/v1/transactions/{id}`; sandbox rows removed from the guide and `integration.ts`.
- [x] PLAN.md §13b entry; numbering shifted (plugin = WC2, partial refunds/badge/Dhivehi/reversal push = WC3, distribution = WC4).

Proof: `tests/Feature/Connect/PublicClientTest.php` (12) + `tests/Feature/V1/MeAndReadBackTest.php` (4); existing `PlatformConnectTest` untouched; full API suite green. Migration applied, queue restarted, merchant + admin panels rebuilt, docs rebuilt; live probes: a secret from the public client → `401 invalid_client`, no secret + bad code → `400 invalid_grant`.

### WC2 — The plugin, end to end (owner's instruction, verbatim items; was WC1) — BUILT (2026-08-22), awaiting the owner's live pass

Amendments from the owner's answers: **top-level admin menu "Manfaa Cashback"**; *Connection* leads with **Connect with Manfaa** (OAuth, WC1) with the token paste collapsed under "Use an API token instead"; a **Cashback awarding policy** setting — *Items after discounts, excluding shipping and GST* (default) | *Items after discounts, including GST* — feeding `LineBuilder` and the estimate alike.

**Settings (WooCommerce › Manfaa Cashback)**

- [x] *Connection*: API base URL (default `https://api.manfaa.app/api`), token (masked, encrypted at rest per §2.2), **Test connection** — `GET /v1/me` + `/rate` + `/product-categories`; shows store name, rate, minimum, category count, **and the token's abilities**, warning per missing one: no `transactions:reverse` → "refunds will not reverse cashback — you will pay for refunded sales"; no `customers:lookup` → "codes are not confirmed on the cart". **Get a token** → prefilled panel wizard (D1).
- [x] *Cashback pricing*: **General rate** (with the `has_category_overrides` warning) | **Per category** — synced Manfaa categories (name, mode, rate, position) each with a multi-select of WooCommerce product categories; footer: *"Products in unmapped categories earn the general rate. A product in two mapped categories is priced by the one listed first."* **Sync now**. Mapping rows whose category vanished from the sync are flagged.
- [x] *Cart & checkout*: the Manfaa panel is on when the plugin is enabled (owner's instruction); label; confirm the code live (on if the ability exists); post to a `valid:false` account (off); **fall back to billing phone** (off, with the wrong-person warning).
- [x] *Display*: **Show estimated cashback** (on); wording.
- [x] *Posting*: **Post cashback when the order is**: Processing (paid — WooCommerce's "confirmed" moment) | Completed (default) | a custom registered status. **Reverse on cancellation, full refund and trash** (on). **On partial refund**: do nothing (default) | reverse the whole sale. **Only orders placed after activation** (on). **Invoice prefix** (default site token).
- [x] *Status*: last sync, last successful post, needs-attention count, link to the log.

**Storefront**

- [x] `manfaa/panel` inner block on the Cart Block and Checkout Block order summaries, `force: true`; classic cart and classic checkout panels; one session value; prefill on checkout.
- [x] Live lookup through the plugin's own REST route (nonce, per-session throttle); tick + first name; `valid:false` copy; miss copy; 429/timeout → "we'll check it when the order posts". Never blocks.
- [x] Estimate via Store API endpoint data (Blocks) and totals hooks (classic): per-bucket rounding from the cached rate card; shortfall copy; hidden when not MVR or no rate synced.
- [x] Code persisted to `_manfaa_code` at order creation on both paths.

**Posting + reversal**

- [x] `LineBuilder` per D5; `origin: online_link` once the server accepts it (omitted until then).
- [x] `Poster`: frozen body, deterministic key, every row of the state table; adoption through `GET /v1/transactions/{id}`; `401`/`403`/`no_effective_rate` stop scheduling with the *Reconnect* notice.
- [x] `Reverser`: cancelled/refunded/trashed → one reverse per order; outcomes and both memo causes noted; `backdated_irreversible`/`invalid_state` recorded, not retried; re-completion after reversal → `final_reversed`.
- [x] Order column (HPOS + legacy), metabox, *Refresh status*, daily sweep.
- [x] Partial refund policy; `woocommerce_order_partially_refunded`.

**Server companions** — `GET /v1/me`, `GET /v1/transactions/{id}`, `origin: online_link`, prefilled wizard; openapi + guide for each.

**Proof**

- [x] PHPUnit: `Laari` at `0.01` boundaries and on 6-dp cart totals; `Estimator` equals the server's fixture — **fruits 30,000 (excluded) / veggies 25,000 @ 2.00 % / default 45,000 @ 5.00 % → 0 + 500 + 2,250 = 2,750** (`MixedBasketTest.php:74-87`) — plus a two-items-in-one-bucket case where per-item rounding would differ; `LineBuilder` partition always sums to eligible with zero buckets dropped; mapping precedence and re-sync reorder; frozen body byte-identical across retries; every status transition enqueues exactly once (a full refund fires both refund hooks — test that); **every row of the state table**; HPOS on.
- [x] Pest (server, done in WC1): `/v1/me` abilities match the token; `/v1/transactions/{id}` refuses another merchant's id (404) and returns lines; `origin` whitelist; `online_link` label in the panels.
- [ ] **Manual (owner): a real WordPress store over https**, Block and classic, real test merchant — post on completed, see `cashback_earned` arrive on the test customer's phone; cancel while pending → `reversed`; refund after that sale's settlement is **submitted** → `adjustment_created` (`locked_in_settlement`).
- [x] Docs: guide gains *"WooCommerce plugin"* under §4.1 "Online stores" — install, owner-issued token, settings, what the buyer sees (two-stage pending; no reversal notification), the in-place-vs-memo boundary; rebuilt and live on both hosts.
- [x] PLAN.md §3 tree + §13b entry.


**Built 2026-08-22** at `plugins/woocommerce/manfaa-cashback/` — 30 files, no runtime dependencies, no build step (the inner block is written against the globals WooCommerce loads). Zip published at `https://manfaa.app/app/woocommerce/manfaa-cashback.zip` (+ versioned + `manifest.json`) by `scripts/build-woocommerce-plugin.sh`; the `/app` page links it; nginx `/app/` now serves `zip`/`json` types.

Proof: **42 PHPUnit tests, green on BOTH order datastores** (`vendor/bin/phpunit`, `MANFAA_HPOS=1 vendor/bin/phpunit`) — money/estimator fixtures, every row of the state table reachable from a trigger, frozen-body retries under one key, adoption, refusals, disconnects, one-reverse-per-order across cancel → trash → re-complete, full refund firing both hooks, partial-refund policy, webhook signature/timestamp/dedupe, rate-changed resync, Store API data, lookup nonce + throttle, Connect end-to-end (PKCE challenge matches the verifier, no secret sent), token paste, crypto key mismatch, settings sanitiser. HTTP-level check on the dev store (WP 6.9.1 + WC 11.0.1): Blocks cart enqueues the script + settings; `/wc/store/v1/cart` carries `extensions.manfaa` and the update callback stores the code; classic cart/checkout render the panel, the estimate row and the stored code; settings screen, order column and metabox render; the published zip installs on a clean slot and boots.

Found by the HPOS run and fixed: a full refund reaches the reversal trigger twice on two different order objects, and the second one predated the first one's save — both triggers now read their guard meta fresh and check `as_has_scheduled_action`. Found by the live check: `woocommerce_blocks_loaded` fires at `plugins_loaded` 10, so the plugin boots at 5.

Dev environment (gitignored): `plugins/woocommerce/dev-site/` (WordPress + WC 11.0.1) on a **private, socket-only MariaDB** under `plugins/woocommerce/.tools/mariadb/` — the system MariaDB is deliberately read-only for another project's legacy database. Start it with `mariadbd --defaults-file=plugins/woocommerce/.tools/mariadb/my.cnf &` as `manfaa`; wp-cli is `plugins/woocommerce/.tools/wp`.

**What the owner's live pass needs** (cannot be done from this box — the plugin's callback must be an https site Manfaa can redirect to): a WordPress store over https, the zip, *Connect with Manfaa* as the store owner (merchant 1 or 2), one order with a test customer's code → Completed → cashback pending on the phone; cancel → reversed. Dhivehi strings, the product badge and the `/v1` amend are WC3.
### WC3 — Partial refunds, product badge, Dhivehi, reversal push (was WC2) — DONE (2026-08-22), Dhivehi native review + dv goldens pending

- [x] **Server: `/v1` amend** — `PATCH /v1/transactions/{id}` with `eligible_amount`, `sale_amount`, `lines`; same rules as the panel amend (`awaiting_validation` only, never backdated). Then the partial-refund policy gains *reduce the sale to the refunded remainder* (amend while pending; afterwards the chosen fallback, noted).
- [x] Product page badge "Earn up to MVR x cashback" (display sub-option, default off), from the product's mapped category rate.
- [x] Dhivehi translation DRAFTED (`languages/manfaa-cashback-dv.{po,mo}` + the block script JSON) — **native review still owed**; RTL: the panel mirrors on a `dv` locale even without a core pack (`Estimate::rtl()`); RTL check of the panel in both carts and both checkouts.
- [x] **Owner said yes (2026-08-22)**: `cashback_reversed` customer push on the platform — "Your MVR x cashback from <store> was reversed after a refund." English, like every customer notification.
- [x] Proof (goldens in dv still owed): Pest for the amend refusals `not_amendable_state` / `backdated_irreversible` / `lines_sum_mismatch`; PHPUnit for partial refund → amend body; goldens of the panel in dv.


**Built 2026-08-22.** Server: `PATCH /v1/transactions/{id}` (AmendmentService, `transactions:write`, idempotent, 409 `not_amendable_state`/`backdated_irreversible` with `meta.state`), the `cashback_reversed` push (push-only — no SMS bill for bad news; sent from `ReversalService` on BOTH outcomes, once per sale, never for a zero sale; seeded template "Your {{amount}} cashback from {{store}} was reversed{{reason}}."; the app routes a tap to Activity). Plugin 0.2.0: partial-refund policy *Reduce the cashback to what the buyer kept* (`Amender` — net-of-refund `LineBuilder`, one frozen body per refund under `…:amend:<refund id>`, a refund that empties the order becomes a reversal, `not_amendable_state` → note and the cashback stands), product badge (`Badge`, off by default, per-bucket rate, "up to"), Dhivehi strings + RTL. Proof: API suite 1773 green (9 new); plugin 46 PHPUnit green on both datastores (4 new). Published: zip 0.2.0 + manifest, customer app 1.0.29+30, docs (*Partial refunds* under §4.1, plugin bullet).
### WC4 — Distribution, updates, go-live (was WC3) — DONE (2026-08-22)

- [x] `scripts/build-woocommerce-plugin.sh` → versioned zip + latest + `manifest.json` in `download/public/woocommerce/`; nginx types for `zip`/`json`; Cloudflare purge; link from the guide and the `/app` page.
- [x] In-WordPress updates (`Support\Updater`: manifest read twice a day, cached, only `https://manfaa.app/` packages honoured; *Check for updates* row link; View details from the manifest): the plugin checks `manifest.json` (no wordpress.org). Version discipline as for the APKs: code changed → version changed.
- [x] Go-live checklist (guide §8 *WooCommerce plugin — go-live*) in the guide: owner-issued token with all four abilities, MVR currency, posting status chosen, `MANFAA_CASHBACK_KEY` in `wp-config.php`, one end-to-end test order, where pending money shows for the buyer.
- [x] Sandbox: **dropped** (owner, 2026-08-22) — removed from the guide and `integration.ts` in WC1. The base-URL field stays so it can return.
- [x] Proof: the zip installs on a clean slot (WC2); **a lowered install (0.2.9) saw 0.3.0 in the live manifest and upgraded through WordPress's own upgrader from the CDN-served zip**; served `Content-Type: application/zip` (WC2); a bumped manifest is offered as an update; the served zip has `Content-Type: application/zip`.


**Done 2026-08-22.** Plugin 0.3.0 published (the first version that updates itself; anyone on 0.1/0.2 — only the owner — installs it once by hand). 4 updater tests; plugin suite 50 green on both datastores.

**Whole plan status:** WC0–WC4 built. Owner-side: the live Connect pass on an https store, Dhivehi native review, dv visual check. Future (not planned): the `/v1` amend for line-level category re-splits on partial refunds of mixed baskets is already supported (the plugin sends `lines[]` net of refunds); a wordpress.org listing was never in scope.
### (was WC4) "Connect with Manfaa" — now WC1 above

- [ ] Server: `pos_vendors.public_client`; `POST /v1/connect/token` skips `client_secret` for public clients, PKCE stays mandatory; redirect-URI policy for per-install callbacks — **the decision**: (a) exact match with per-store self-registration from the panel (OAuth 2.1), or (b) a registered host *pattern* with the consent screen showing the exact callback host. Tests on every refusal as in `PlatformConnectTest`.
- [ ] Plugin: **Connect with Manfaa** → PKCE verifier in a short-lived transient keyed by `state`; consent at `merchant.manfaa.app/connect`; callback `wp-json/manfaa/v1/connect/callback`; token stored as in WC1. Paste-a-token stays.
- [ ] Consent screen shows the connecting store's host so a merchant can refuse a mismatch.
- [ ] Proof: Pest — public client refused with a secret, accepted without; PKCE mismatch; callback policy; PHPUnit — state mismatch refused, verifier single-use.

---

## 6. Risks and the mitigation each carries

| Risk | Mitigation |
|---|---|
| Double-crediting on retries or re-saves | Deterministic key + frozen bytes; `_manfaa_state` re-entry no-op; the server's `(merchant, invoice_no)` index behind both |
| A full refund enqueues twice | Only `…status_refunded` is hooked, never `…fully_refunded`; meta set synchronously before enqueue |
| Silent `backdated_final` | `occurred_at` is never sent — impossible by construction |
| Retry collides with the raw-body hash | Body frozen at enqueue; `reuse_mismatch` → needs attention, never retried |
| Crediting the wrong person | Code-keyed (D2); phone fallback off; live name confirmation; `valid:false` surfaced |
| Estimate disagrees with the posted amount | Same formula per *bucket*, same rate card, promotions excluded — can only under-promise |
| Lookup budget or the 120/min token budget burned by checkout traffic | Per-session throttle + nonce; advisory only |
| Token in `wp_options` backups | Encrypted with a key that is *not* in the database when `MANFAA_CASHBACK_KEY` or real salts exist; fingerprint detects rotation |
| Enabling the plugin posts 400 old orders | "Only orders placed after activation" default on |
| Two stores on one merchant reuse order numbers | Site-token prefix by default; adoption fetches the real state and notes it |
| Revoked token / store suspended | 401/403 stop scheduling with a notice — no silent loop |
| Merchant deactivates a mapped category | Sync flags the row; `inactive_category` parks with the category named |
| Order trashed instead of cancelled | `woocommerce_trash_order` reverses with reason `other` |

---

## 7. Standing blockers / owner asks

- **D2 — cart and checkout (as planned) or cart only?** The instruction says cart; the plan puts the same panel on checkout too so a buyer who skipped it can still enter the code.
- **D1**: paste-a-token for WC1 with OAuth as WC4 — confirm, or pull WC4 forward.
- **D6**: default posting status `completed`; partial refunds do nothing until the `/v1` amend lands — confirm.
- **D5**: eligible = items after discounts, no shipping, no tax — confirm, or say "include GST".
- Buyer notification on reversal: none exists; want one (server work, WC2 ask)?
- Sandbox host: stand it up or drop it from the docs (WC3).
- A test merchant on production (or the sandbox, once it exists) with a test customer on a real phone, for the manual pass.
- Dhivehi plugin strings need a native reviewer, same as the apps.
