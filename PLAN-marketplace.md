# PLAN — Manfaa Marketplace (multi-vendor)

Owner round opened 2026-08-18. Design references:
`/home/ubuntu/Merchant App Flutter Manfaa/Manfaa MarketPlace/` — all twelve
read before this was written (RULE ZERO).

Customer app: `Market View.png`, `Market View Tablet.png`,
`AI Product Search.png`, `Cart Page Collapsible By Merchant.png`,
`Cart Page Expanded.png`, `Delivery Details Step.png`, `Payment Step.png`,
`Order Received.png`, `Customer App Order Tracking.png`.
Merchant app: `Orders.png`, `Order Details.png`, `products.png`.

---

## 0. What this is

Manfaa becomes two products sharing one account, one wallet and one cashback
engine:

1. **Cashback** (today) — the customer shops in a physical store, the till
   credits them, the merchant settles what they owe us monthly.
2. **Marketplace** (this plan) — the customer buys *through* Manfaa, the
   platform collects the money, and we pay the merchant what is left after
   cashback and our fee.

The money flows in **opposite directions** in the two products. That is the
single most important fact in this document and most of the design follows
from it: in cashback the merchant owes us, in marketplace we owe the
merchant. They must never be silently netted (§5.4).

**Marketplace is optional for merchants** (opt-in + KYB, §9) and can be
**switched off platform-wide by a superadmin** (§10), which hides every trace
of it on all four surfaces.

---

## 1. Design language

The mockups arrive in several colourways (teal, green). Manfaa's system —
coral-M / ink / violet, compact sizing, stadium nav — **wins every conflict**.
What we take from the mockups is *structure*, not palette.

Fixed by the owner:

- **No change to existing bottom-bar styling** on either app.
- **Customer app**: add a **Market** item. Final bar —
  `Home · Discover · Market · Activity · Profile` (as in
  `Customer App Order Tracking.png`, which is drawn in the real Manfaa
  system and is therefore the authority for the bar).
- **No Cart tab.** The cart is a **floating bar** above the nav
  (`Market View.png`), not a fifth destination. Two mockups
  (`Cart Page Collapsible By Merchant.png`, `AI Product Search.png`) show a
  Cart tab — those bars are **superseded**.
- **Merchant app**: **Orders** joins the bar. Final bar —
  `Dashboard · Orders · Credit · Settlements · More`. **Transactions moves
  into More.** Products lives under More (`products.png` shows More active).

Adaptation notes:

- Per-item "2% cashback" pills reuse the existing rate badge, in violet.
- Status tones map to the existing `StatusTone` set: New/Under review →
  pending, Confirmed/Paid → confirmed, Rejected/Cancelled → closed,
  minimum-not-met → attention.
- Order minimum progress bars are the attention tone until met, then
  confirmed — never green-on-green with the cashback figure beside it.
- The merchant app's "Quick actions only — use desktop for advanced order
  management" banner is kept verbatim in intent: **the app accepts and
  fulfils; the web panel manages the catalogue.**

---

## 2. Data model

New tables. Everything money-shaped is **integer laari**, every rate is
**integer basis points**, every percentage the user types is an **exact
string** (§10 money laws — unchanged and non-negotiable).

### 2.1 Vendor enrolment

```
merchant_marketplace_profiles
  merchant_id            unique
  state                  not_enrolled | pending_kyb | active | suspended
  business_type          sole_prop | partnership | pvt_ltd | cooperative
  fulfilment             delivery | pickup | both
  prep_time_min/max      minutes, for the "30–60 min" chip
  order_fee_bp           NULL = platform default (§5.2 override)
  rating_avg / count     denormalised, from delivered orders only
  enrolled_at, suspended_at, suspended_reason
```

```
merchant_kyb_documents
  merchant_id, kind (business_registration | owner_id | bank_letter | tin_cert)
  path, original_name, mime, size
  state (pending | accepted | rejected), reject_reason
  reviewed_by, reviewed_at
```

### 2.2 Catalogue

```
marketplace_categories            platform-curated tree (Rice & Grains, Oil…)
  id, parent_id, slug, name_en, name_dv, icon, sort, active

products
  merchant_id, category_id
  name, name_dv, description, sku
  price_laari                     integer
  compare_at_laari                nullable — the struck-through "MVR 85.00"
  cashback_rate_bp                nullable → falls back to the store rate
  stock_qty, low_stock_at         nullable stock_qty = untracked
  state                           draft | active | out_of_stock | archived
  allow_substitutions             bool — the "No substitutions" chip
  sort, created_at, updated_at

product_images
  product_id, path, sort          first is the card image
```

Products are a **public claim**, so edits to name/price/image on an enrolled
store flow through the existing MR9 change-request gate? **No** — see §11
open question Q3. Provisional answer: price and stock are operational and
apply instantly; name, images and description are claims and are gated.

### 2.3 Delivery rules (the per-island matrix)

Owner: *"Allow stores in Male region and Hulhumale region to set delivery
free minimum for each island."*

Modelled generally, so it covers every reading of that sentence and what a
real marketplace needs:

```
merchant_delivery_rules
  merchant_id
  zone_id                         destination island (zones table, live: 5)
  delivers                        bool — false = we do not serve that island
  delivery_fee_laari              integer
  free_delivery_over_laari        nullable — basket value that waives the fee
  order_minimum_laari             integer — the "Min MVR 200" chip
  eta_min/eta_max                 minutes, per destination
  unique (merchant_id, zone_id)
```

A Malé shop can therefore say: Malé — fee 25, free over 300, minimum 200;
Hulhumalé — fee 60, free over 500, minimum 500; elsewhere — `delivers=false`.
The zone comes from the **delivery address**, resolved by the existing
`ZoneAssigner` against the address pin.

> **Confirm before build:** whether "delivery free minimum 25 / 500" means
> *free-delivery threshold* (modelled as `free_delivery_over_laari`) or a
> *flat fee per island* (`delivery_fee_laari`). Both columns exist either
> way; only the wizard copy and defaults change.

### 2.4 Addresses

`customers` has no address today. New:

```
customer_addresses
  customer_id, label (Home | Work | Office | custom)
  recipient_name, phone
  zone_id                         resolved from the pin, not typed
  island, area_magu, building, apartment_floor
  lat, lng, delivery_note
  is_default
```

Matches `Delivery Details Step.png` exactly, including "Pick on map" and
"Use my location" — both already solved by our own tile proxy and the
existing `PinPickerMap`.

### 2.5 Cart and orders

The cart is **server-side** (it prices itself, and pricing is our job):

```
carts            customer_id, updated_at
cart_items       cart_id, product_id, qty, unit_price_laari (snapshot)
```

Orders are **two levels**, because the customer pays once and three stores
fulfil separately (`Order Received.png`):

```
orders                            the payment, one per checkout
  reference                       MF-2026-1084
  customer_id, address_id (nullable — all-pickup order)
  items_laari, delivery_laari, total_payable_laari
  cashback_total_laari            projected at placement, credited per suborder
  payment_method                  bml | mib
  payment_state                   awaiting_proof | proof_submitted | verified | refused
  receipt_path, verified_by, verified_at
  state                           placed | under_review | partly_confirmed | confirmed
                                  | completed | cancelled
  placed_at

suborders                         one per merchant — the unit of fulfilment
  order_id, merchant_id
  reference                       MF-1084-01
  fulfilment                      delivery | pickup
  items_laari, delivery_laari, subtotal_laari
  cashback_laari, cashback_rate_bp
  order_fee_bp, order_fee_laari, order_fee_gst_laari
  payable_to_merchant_laari       the §5.1 arithmetic, frozen at confirmation
  state                           new | accepted | preparing | ready | out_for_delivery
                                  | delivered | rejected | cancelled
  reject_reason, pickup_code
  accepted_at … delivered_at

suborder_items                    product_id, name snapshot, qty, unit_price_laari,
                                  line_total_laari, cashback_laari
```

Snapshots are deliberate: a price edited next week must not restate last
week's order.

---

## 3. Customer surfaces

### 3.1 Market tab (`Market View.png`)

Store page: logo, verified tick, rating, ETA, delivery fee, order minimum,
a store-wide cashback banner. Category chips → sort → filter → 2-col product
grid with Add buttons and per-item cashback pills. Favourites (heart) are
in scope as a simple `product_favourites` table.

Tablet (`Market View Tablet.png`): left category rail, 4-col grid, richer
floating bar. Fits the existing MR7 adaptive layer — `kExpandedMinWidth`
gives us the rail, `kWideContentWidth` the grid.

### 3.2 Floating cart

Above the nav, on Market surfaces only: item count, basket total, **"Earn
MVR 2.68"**, distance to the store's minimum with a progress bar, and View
Cart. This replaces a Cart tab by owner decision.

### 3.3 Cart (`Cart Page Collapsible By Merchant.png` / `…Expanded.png`)

- Header "My Cart (3 stores)".
- Total-cashback card at the top.
- **One collapsible card per merchant, collapsed by default** (owner).
  Collapsed shows: logo, name, fulfilment chip, item count, subtotal,
  delivery fee, earn amount, and the minimum status.
- Expanded shows line items with qty steppers and delete, then
  Items / Delivery fee / Cashback, then the minimum progress bar.
- **Per-subcart warning when the store's minimum is not met** (owner):
  attention tone, "Add MVR 30.00 more to reach the minimum order",
  `MVR 120.00 / 150.00`, tappable back into that store. Checkout is blocked
  while any subcart is short, and the button says which store is short.
- "Different stores, separate orders" explainer.
- **Order summary is expandable** (owner) — the sticky footer's Total
  Payable and You'll earn both open into a breakdown.

### 3.4 Checkout

`Address → Delivery → Payment → Review → Received`, exactly the stepper in
the mockups.

Payment (`Payment Step.png`) is **receipt-first bank transfer**, the same
pattern as merchant settlements: exact amount, BML/MIB account cards with
copy buttons, upload receipt image, and the honest line *"Your order is
confirmed after payment proof is submitted and verified."* Reuses the
settlement receipt machinery wholesale — including the compact bank-logo
cards and white MIB backing from MR11.

`Order Received.png` then shows the order under review with per-store
statuses and the projected cashback.

### 3.5 Orders live in **Activity**, not a new tab

`Customer App Order Tracking.png` is explicit: *"Track your marketplace and
cashback orders in one place."* The existing Activity tab gains Active /
Completed / Cancelled filters and renders two card kinds — a marketplace
order (grouped by store, with Track order / pickup code) and a cashback
transaction (as today). One timeline, two sources.

---

## 4. Merchant surfaces

### 4.1 App — Orders (`Orders.png`, `Order Details.png`)

Tabs New / Preparing / Ready / Completed, two stat tiles (New orders,
Awaiting action), search, filter. Cards carry the customer name, store and
branch, item count, value, fulfilment, **payment state** and the cashback
figure, with View order + Accept (or Reject).

Order details: store/customer/fulfilment/payment/cashback/value grid, the
customer block with **Call** and **Open map** (our own map, our own label —
the fix from 2026-08-18), items with substitution flags, the money
breakdown, and Accept / Reject with a required reason.

Push on every new order, reusing the merchant-staff channel — and, per the
2026-08-18 decision, **SMS as well**, since a new order is exactly the kind
of thing a shop must act on.

### 4.2 App — Products (`products.png`, under More)

Search, Add product, All/Active/Draft/Out-of-stock tabs, two stat tiles,
rows with edit / visibility-toggle / overflow. Quick edits only: price,
stock, visibility. The banner tells the truth — catalogue work belongs on
desktop.

### 4.3 Merchant web panel — the full estate

Everything the app has, plus what it deliberately lacks: catalogue CRUD with
images and bulk edit, category mapping, the **per-island delivery matrix**
(§2.3) as an editable grid, prep times, marketplace opt-in and KYB upload,
and order management with printable pick lists.

### 4.4 Customer web

Full marketplace parity — browse, cart, checkout, order tracking — on
`manfaa.app`. The panels share `@manfaa/api-client`, so the contracts are
written once.

---

## 5. Money

### 5.1 The arithmetic, per suborder

```
items_laari                     Σ line totals
+ delivery_laari                per §2.3, waived by free_delivery_over
= subtotal_laari                what the customer pays for this store

cashback_laari    = ceil(items_laari × cashback_rate_bp)      ← items only, never delivery
order_fee_laari   = ceil(items_laari × order_fee_bp)          ← §5.2
order_fee_gst     = ceil(order_fee_laari × gst_bp)

payable_to_merchant_laari
  = items_laari + delivery_laari − cashback_laari − order_fee_laari − order_fee_gst
```

Ceiling rounding via the existing `intdiv(x*bp + 9999, 10000)`. Cashback and
fee are charged on **items, not delivery** — delivery is the merchant's cost
recovery, not revenue we should tax. *(Confirm: §11 Q1.)*

### 5.2 The marketplace fee

- Platform default **2.00%**, stored as bp in platform settings, editable by
  superadmin, effective-dated like the existing fee tiers.
- **Per-merchant override**: `merchant_marketplace_profiles.order_fee_bp`.
  Null means "use the default", so a default change moves every
  non-overridden store at once.
- The rate in force is **frozen onto the suborder** at confirmation. A fee
  change must never restate an order already placed.

### 5.3 Cashback on marketplace orders

Credited **after the store validates the order** — the wording already in
the mockups (*"Cashback is credited after the store validates your order"*).
Mechanically this is the existing cashback engine with a new origin:
`origin = 'marketplace'`, the suborder as the source document, and the same
validation window, confirmation and payout path the customer already knows.
One wallet, one Activity feed, one payout.

### 5.4 Settlement — the direction reverses

Cashback settlements (merchant → platform) and marketplace payouts
(platform → merchant) stay **two separate ledgers with two separate
screens**, per the owner. Netting them is tempting and is deliberately not
done in v1: a merchant reading one number that silently mixes "what I owe"
and "what I am owed" cannot check our arithmetic, and neither can we.
*(Revisit: §11 Q4.)*

### 5.5 Admin → **Merchant Settlements** (new menu)

Mirrors the customer payout system exactly, because that machinery already
works and the owner asked for the same Excel feature:

| Customer payouts (today)     | Merchant settlements (new)      |
|------------------------------|---------------------------------|
| `PayoutBatchBuilder`         | `MerchantPayoutBatchBuilder`    |
| `BankFileExporter` → .xlsx   | same exporter, merchant columns |
| `TransferSheetImporter`      | same importer                   |
| approve → export → import → mark paid / failed / settle all | identical |

A batch gathers every **delivered, validated** suborder not yet paid,
groups by merchant, and produces one bank row per merchant with
`payable_to_merchant_laari` summed. Same states, same approval gate, same
reconciliation. The merchant sees the batch and its lines in their own
Settlements screen, so both sides read the same document.

---

## 6. AI structured search

`AI Product Search.png` shows the shape: a natural-language query
("best jasmine rice under MVR 100"), an **AI Search** panel that restates
what it understood as removable chips (Jasmine rice · Under MVR 100 ·
High rating · Fast delivery), then ranked results with a "Why these?"
explainer and an Ask AI Assistant fallback.

**Recommended architecture — the LLM parses, Postgres retrieves.**

Do *not* let a model retrieve or invent products. It turns a query into a
filter; our own database answers it. That keeps results correct, cheap and
explainable, which is exactly what those chips promise.

1. **Query understanding — Claude Haiku 4.5** (`claude-haiku-4-5-20251001`)
   with a forced structured-output tool call returning
   `{terms[], category, max_price_laari, min_rating, fulfilment, sort}`.
   Fast, cheap at this size, and — the deciding factor — it handles
   **Dhivehi and Thaana queries** and transliteration that Postgres FTS
   cannot. Cache parsed queries by normalised string; most searches repeat.
2. **Retrieval — Postgres, which we already run.**
   - `pg_trgm` + `tsvector` full-text for names and descriptions.
   - **`pgvector`** for semantic matching — but **not in the first
     release**, and not on trust. See §6.1: the vendor question is settled
     by a test, not by a price list.
   - Hybrid score: text match, vector distance, then deterministic business
     ranking — price, rating, delivery ETA, stock — which is what "Why
     these?" honestly explains.
3. **Fallback** — if the model is unreachable or slow, fall straight through
   to FTS with the raw string. Search must never 500 because an API blinked.
4. **Ask AI Assistant** — a conversational refinement over the same tool,
   deferred to a later round.

Cost note: a Haiku call per *uncached* search at typical volumes is
negligible next to one delivery, but the cache and the FTS fallback are what
keep it that way at scale.

### 6.1 On embeddings, and Voyage specifically

Checked 2026-08-18. Voyage is now **Voyage AI by MongoDB**, and the current
generation is **voyage-4 / -4-lite / -4-large** (January 2026) — the
`voyage-3` named in an earlier draft of this plan is superseded. Pricing is
$0.02–$0.12 per million tokens with **200M free tokens** on the voyage-4
generation, and a 33% batch discount.

**Cost is not the deciding factor.** A product row is perhaps 100 tokens; a
catalogue of 100,000 products across every store we could plausibly sign is
~10M tokens — inside the free allocation, once. Query embeddings are ~10
tokens each. Whichever vendor we pick, this is not where the money goes.

**The deciding factor is Dhivehi**, and it is undocumented. Voyage's model
page describes "multilingual retrieval quality" but publishes **no language
list**, and Dhivehi (Thaana, ~350k speakers) is the kind of low-resource
language that multilingual embedding sets routinely omit. This matters more
than it sounds: a model that has not learned Thaana does not fail loudly —
it returns vectors that are near-noise, so semantic search confidently
retrieves *wrong* products. That is worse than full-text search returning
nothing, because nothing is honest.

**So: test before buying.** The same discipline as the OSM and Google Maps
questions on 2026-08-18 — embed a set of real Dhivehi product names with
their English equivalents and unrelated controls, and check that the
translation pairs sit closer together than the controls do. If Thaana is not
represented, that shows up immediately. A morning's work, before a vendor,
a key or a re-embedding pipeline exists.

**Recommendation: ship MP10 without vectors.** The Claude parser already
solves the part Postgres cannot — it reads a Dhivehi or transliterated query
and returns English structured filters — and `pg_trgm` + `tsvector` answers
those filters well at the catalogue size we will actually have for the first
year. Add pgvector when the catalogue is big enough for semantic recall to
beat filtering, and pick the provider then, on evidence.

**When that day comes, the shortlist:** Voyage voyage-4-lite (cheapest,
strong retrieval), OpenAI `text-embedding-3-small` (widest published
language coverage), Cohere `embed-multilingual` (explicitly multilingual by
design), or **self-hosted BGE-M3** on our own server — no per-call cost, no
catalogue leaving our infrastructure, and worth a look precisely because we
already run the box.

---

## 7. Order lifecycle

```
customer places order
  → payment proof uploaded            order.payment_state = proof_submitted
  → admin/automated verify            = verified
  → each suborder: new
      merchant accepts                → accepted → preparing → ready
        delivery                      → out_for_delivery → delivered
        pickup                        → (pickup code shown) → delivered
      merchant rejects (reason)       → rejected  → refund path
  → on delivered + validated          cashback credited to customer
  → suborder enters the next merchant payout batch
```

Rejection and partial fulfilment need a refund path — the customer paid the
platform for three stores and one refused. **Open question Q2.**

---

## 8. Notifications

Reusing the catalogue and the 2026-08-18 SMS default (merchant moments text
the store):

| Key                       | To       | Channels |
|---------------------------|----------|----------|
| `order_placed`            | merchant | push + SMS |
| `order_accepted`          | customer | push |
| `order_rejected`          | customer | push + SMS |
| `order_ready_for_pickup`  | customer | push |
| `order_out_for_delivery`  | customer | push |
| `order_delivered`         | customer | push |
| `marketplace_payout_paid` | merchant | push + SMS |
| `kyb_approved` / `kyb_rejected` | merchant | push + SMS |

---

## 9. Merchant opt-in and KYB

Marketplace is **off** for every merchant until they opt in. The flow:

1. **Opt in** from merchant web or app (Settings → Marketplace).
2. **Business type** — sole proprietorship / partnership / private limited /
   cooperative.
3. **KYB documents** — business registration, owner ID, bank letter, TIN
   certificate. Uploaded, then reviewed by an admin in a queue that reuses
   the store-review sheet.
4. **Profile sheet** — fulfilment modes, prep times, the per-island delivery
   matrix (§2.3), and the store's marketplace description.
5. Admin approves → `state = active` → the store appears in Market and its
   Products/Orders surfaces unlock.

A merchant who never opts in sees **no marketplace UI at all** — not a
disabled tab, not an upsell in their nav. The same rule as §10, applied per
merchant.

---

## 10. The superadmin kill switch

One platform setting, `marketplace_enabled`, default **off** until launch.
When off it hides, on every surface:

- the customer app's **Market** bottom-bar item and the floating cart;
- the customer web marketplace and every route under it;
- the **order tracking** section in Activity, app and web
  (cashback history stays — it is not marketplace);
- every merchant setting, menu and screen related to marketplace, app and
  web — Orders tab, Products, delivery matrix, opt-in;
- the admin Merchant Settlements menu and KYB queue.

Enforced **server-side first**: the flag is on `/mobile/v1/config` and the
panels' bootstrap, and every marketplace route refuses with a clear code
when it is off. A hidden button that still answers is not hidden.

---

## 11. Open questions for the owner

1. **Fee and cashback base** — charged on items only, or on items +
   delivery? Plan assumes **items only**.
2. **Refunds** — one store of three rejects after the customer has paid the
   platform. Refund to bank (manual, slow), or credit to the Manfaa wallet
   (instant, keeps the money in the ecosystem)? Wallet credit is
   recommended, with bank refund on request.
3. **Product edits** — do name/image/description changes on a live store go
   through the MR9 review queue like other public claims, or apply
   instantly? A catalogue of 124 products cannot realistically be reviewed
   item by item; recommendation is **instant, with admin takedown**.
4. **Netting** — should a merchant's cashback settlement debt be netted
   against their marketplace payout? Plan says no for v1.
5. **Delivery semantics** — confirm the reading in §2.3.
6. **GST** on the marketplace fee — assumed to follow the existing platform
   fee treatment.
7. **Ratings** — the mockups show 4.7 (1,248). Who can rate, and when?
   Assumed: the customer, after `delivered`, once per suborder.

---

## 12. Phasing

Each round ships end-to-end and green, in the house style.

| Round | Scope |
|-------|-------|
| **MP1** | Foundations: migrations, `marketplace_enabled` kill switch, opt-in + KYB, admin review queue. No shopping yet. |
| **MP2** | Catalogue: products, categories, images, stock. Merchant web CRUD + app quick edits. |
| **MP3** | Delivery matrix and addresses: per-island rules, `customer_addresses`, zone resolution. |
| **MP4** | Browse and cart: Market tab, store page, floating cart, collapsible multi-vendor cart with minimum warnings. |
| **MP5** | Checkout and orders: the four steps, receipt-first payment, order + suborder creation, Order Received. |
| **MP6** | Fulfilment: merchant Orders tab and details, accept/reject, statuses, pickup codes, notifications. |
| **MP7** | Customer tracking: Activity unification, per-store statuses, Track order. |
| **MP8** | Money: marketplace fee with overrides, cashback on validation, **Merchant Settlements** with xlsx export/import. |
| **MP9** | Customer web + merchant web marketplace parity. |
| **MP10** | AI search: query parser, pgvector, hybrid ranking, "Why these?". |
| **MP11** | Ratings, favourites, tablet polish. |

MP1–MP3 are invisible to customers and can ship behind the kill switch while
the rest is built.
