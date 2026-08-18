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

products                          the DEFINITION — owned by the merchant
  merchant_id, category_id
  name, name_dv, description, sku
  cashback_rate_bp                nullable → falls back to the store rate
  allow_substitutions             bool — the "No substitutions" chip
  sort, created_at, updated_at

branch_products                   the LISTING — owned by the branch (§2.3)
  branch_id, product_id
  price_laari                     integer — a branch may price differently
  compare_at_laari                nullable — the struck-through "MVR 85.00"
  stock_qty, low_stock_at         nullable stock_qty = untracked
  state                           draft | active | out_of_stock | archived
  unique (branch_id, product_id)

product_images
  product_id, path, sort          first is the card image
```

The split is the whole point: **a product is described once and stocked per
shop.** Stock is physical — it sits on a shelf in one building — so a chain
with two shops cannot honestly publish one number, and a merchant should
still not have to type the product twice. Merchants with one branch (which
is every merchant today) never see the distinction: the panel creates the
listing alongside the definition.

Edits split by kind (owner decision 2026-08-18, §11.1 Q3): **price, stock
and availability apply instantly** — a shop that cannot reprice or mark
something out of stock without waiting a day will oversell — while **name,
images and description queue through the existing MR9 review**, because
those are the public claims a shopper judges the product by.

### 2.3 The branch is the storefront

Owner, 2026-08-18: *"Isn't each branch treated as separate merchant for
customer side, cart and market?"* — yes, and the plan now says so.

**A customer shops at a BRANCH.** It appears in Market as its own listing,
it gets its own subcart, and it fulfils its own order. This is how every
outlet-based marketplace works, and here it falls out of three facts that
are true whether we like them or not:

- **Stock is physical.** It is on a shelf in one building. One number
  across two shops is a lie the customer discovers at the door.
- **Fulfilment is physical.** Accept, prepare, hand over, pickup code —
  every one of those is an act by a specific shop.
- **Delivery terms are already per branch** (§2.4), so a merchant-level
  storefront would have to advertise terms it cannot honour.

The merchant app agrees: `Orders.png` labels orders **"Island Mart — Malé"**
and **"Horizon Bookstore — Hulhumalé"**.

What follows:

- Market lists branches. The card reads **brand + island**
  ("Island Mart — Malé"), never a bare branch id.
- The **cart's subcart is per branch**, and `suborders.branch_id` is the
  fulfilment key (§2.6).
- **This deletes the branch-selection rule** that an earlier draft put in
  the cart (§2.4.1a, now withdrawn). The customer chooses the shop, we do
  not guess for them — one less piece of magic to explain when it guesses
  wrong.
- **Deduplication in browse**: a brand with two branches serving the same
  island must not appear twice in one list. Default to the branch with the
  better terms for the customer's address (free-delivery threshold, then
  ETA), with the others reachable from the store page.
- **Cashback rate stays per MERCHANT.** It is a commercial agreement with a
  business, not with a building, and splitting it per branch would be a
  different negotiation than the one we have.
- Cashback-side Discovery still lists **merchants**, unchanged. The two
  products list different things on purpose: one is "which businesses give
  cashback", the other is "which shops can send me rice today".

### 2.7 Order amendments — the shop reduces, never adds

Owner, 2026-08-18: a store picking an order finds the eggs are gone. It must
be able to drop that line, or cut the quantity, with the difference refunded
to the customer's wallet and the change visible to them.

**The shop may only reduce.** Quantities go down or to zero; nothing may be
added and no price may rise. The customer authorised a specific amount at
checkout and paid it to us — anything upward is a new order, not an edit.
That single rule is what keeps this safe.

`suborder_items.qty` is what was **ordered** and never changes;
`fulfilled_qty` is what the shop will actually supply. The gap between them
*is* the amendment, which is what lets the customer's screen show both
numbers rather than quietly rewriting history.

**When.** From `accepted` until `ready` / `out_for_delivery` — the window in
which someone is physically picking the order. Never after the goods are
handed over, and never after cashback has been credited (§5.4c), which is the
same boundary stated twice.

**Removing everything is a rejection, not an amendment.** If the last line
would go to zero the shop is told to reject the suborder instead, so the
customer gets the rejection notice and the full-refund path (§5.4b) rather
than an order for nothing.

**A reason is required** — out of stock, damaged, customer request, other.
The customer is told what changed and why; "your order changed" with no
cause is worse than a phone call.

Every amendment is a row with an actor, so a shop that habitually cuts
orders is visible to an admin rather than a matter of opinion.

### 2.4 Delivery rules — per branch, per destination island

Owner, clarified 2026-08-18:

> *"Shops in Male deliver to both Male and Hulhumale. But for Male they
> require smaller minimum than delivery to Hulhumale. And shops in Hulhumale
> require smaller minimum to deliver to Hulhumale than to Male. So each
> branch should have function to add delivery islands among supported
> islands by platform and order minimum for each for free delivery."*

So the rule is owned by the **branch**, not the merchant — a chain with a
Malé shop and a Hulhumalé shop has two different sets of terms, and each
shop's own island is the cheap one. And the number the merchant sets per
island is the **order minimum that earns free delivery**.

```
branch_delivery_rules
  branch_id                       merchant_branches — NOT merchant_id
  zone_id                         destination island, from the platform list
  free_delivery_over_laari        the number the merchant sets per island
  delivery_fee_laari              charged below that threshold
  order_minimum_laari             nullable — below this the branch will not
                                  deliver at all (the "Min MVR 200" chip)
  eta_min/eta_max                 minutes to THAT island
  unique (branch_id, zone_id)
```

A row exists only for an island the branch serves; adding a row *is* "add a
delivery island". The platform's island list is the existing `zones` table
(live: Malé, Hulhumalé, K Maafushi, HDh Kurinbi, HDh Kulhudhuffushi), so
enabling a new island for everyone is one zone row.

Worked example, exactly the owner's case:

| Branch          | → Malé            | → Hulhumalé        |
|-----------------|-------------------|--------------------|
| Island Mart, Malé      | free over 25   | free over 500  |
| Horizon, Hulhumalé     | free over 500  | free over 25   |

#### 2.4.1 Two consequences of going per-branch

**(a) ~~The cart must choose a branch.~~ WITHDRAWN** — superseded by §2.3.
The customer picks the shop themselves, because the shop is what they are
browsing. `suborders.branch_id` is still required; nothing guesses it.

**(b) The store page is address-dependent.** The `Delivery MVR 25 · 30–60
min · Min MVR 200` chips in `Market View.png` are not properties of the
branch alone — they are properties of *branch → your address*. So:

- with a delivery address chosen, the chips show that branch's terms to
  that island, and the cart's progress bar counts toward that island's
  threshold;
- with no address yet, show the terms for the customer's current zone if we
  have a location fix, otherwise the branch's own island, with a quiet
  "set your address for exact delivery terms" line;
- a branch that does not deliver to the chosen island is shown as
  **pickup only**, never hidden — the customer may still collect.

### 2.5 Addresses

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

### 2.6 Cart and orders

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

suborders                         one per SERVING BRANCH — the unit of fulfilment
  order_id, merchant_id
  branch_id                       which shop fulfils it (§2.3)
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

suborder_items                    product_id, name snapshot, unit_price_laari
  qty                             what the customer ORDERED — immutable
  fulfilled_qty                   what the shop can actually supply;
                                  defaults to qty, 0 = removed entirely
  line_total_laari, cashback_laari

suborder_amendments               the shop reducing an order (§2.7)
  suborder_id, merchant_user_id
  reason                          out_of_stock | damaged | customer_request | other
  note, refund_laari, created_at

suborder_amendment_lines
  amendment_id, suborder_item_id
  qty_before, qty_after, refund_laari
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

### 3.5 Seeing an amended order

The owner's requirement, verbatim: *"strike through + refunded amount and
also if instead of full removal if partial remove, then show changes."*

- **Line removed entirely** — the row stays, struck through, greyed, with
  **`Refunded MVR 78.00`** on the end. The item is not deleted from the
  screen: the customer ordered it, and a row that silently vanishes reads as
  a bug or a swindle.
- **Quantity reduced** — the row stays at full strength with the change
  shown inline: **`×3 ~~×2~~`** → `×2`, the old line total struck through
  beside the new one, and `Refunded MVR 34.00`.
- **A per-store banner** naming the cause: *"Island Mart changed this order —
  1 item out of stock. MVR 78.00 refunded to your wallet."*
- **The cashback figure updates too**, with its old value struck through, so
  the number promised at checkout and the number now earned are both on
  screen. Hiding the reduction would be the one thing that turns a shortage
  into a grievance.
- **The refund is a wallet line** in Activity, linked back to the order, so
  the money is traceable from either end.
- Push on amendment (`order_amended`, §8), because a customer who is out
  when their order changes cannot see a screen.

### 3.6 Orders live in **Activity**, not a new tab

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

**Editing while picking** (§2.7): each item row on an accepted order carries
a quantity stepper down to zero and an out-of-stock action. The screen shows
the running refund as they go — *"Refunding MVR 78.00"* — so the shop sees
the customer's side of the decision before they commit it, and a reason is
required to save. Taking the last line to zero offers Reject instead.

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
images and bulk edit, category mapping, the **per-branch delivery matrix**
(§2.3) — one grid per branch, a row per island it serves — prep times,
marketplace opt-in and KYB upload,
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

### 5.4b Refunds — to the wallet

A store rejecting a paid order returns that suborder's `subtotal_laari` to
the customer's **Manfaa wallet**, immediately and without an admin
(owner decision, §11.1 Q2). Withdrawal to a bank account then travels the
customer payout path that already exists, so the platform keeps exactly one
outbound money route rather than growing a second one that would need its
own reconciliation.

The credit is a ledger posting like any other and appears in Activity beside
the order it came from. It is never a silent adjustment to the order total.

### 5.4c What an amendment does to the money

Recomputed from `fulfilled_qty`, with one deliberate exception:

```
fulfilled_items_laari = Σ (unit_price_laari × fulfilled_qty)
refund_laari          = items_laari − fulfilled_items_laari      → customer wallet

delivery_laari        UNCHANGED  ← the exception, and it matters
cashback_laari        = ceil(fulfilled_items_laari × cashback_rate_bp)
order_fee_laari       = ceil(fulfilled_items_laari × order_fee_bp)
order_fee_gst_laari   = ceil(order_fee_laari × gst_bp)          ← follows the fee down

payable_to_merchant_laari
  = fulfilled_items_laari + delivery_laari − cashback_laari
    − order_fee_laari − order_fee_gst_laari
```

**Every derived figure is recomputed, never patched.** An amendment
recalculates the whole suborder from `fulfilled_qty` and the rates already
frozen on it — cashback, the service charge, the GST on that charge, and the
merchant's payable. Nothing is adjusted by a delta, because a delta applied
to a rounded figure drifts: two ceilings do not add up to the ceiling of the
sum, and §10's money law is that the ceiling is computed once on the final
base. The frozen `order_fee_bp` is what makes this safe to redo — a fee
change on the platform can never reach back into an order that is already
being picked.

**Delivery never moves.** If dropping an item takes the basket back under
the free-delivery threshold, the customer does **not** suddenly owe a
delivery fee. They met the terms with the order they placed; a shortage in
the shop's own stock is not a reason to charge them more. The same holds for
the order minimum — falling under it after an amendment does not cancel
anything.

**The customer must never owe more after a change they did not make.** That
sentence is the whole rule; the frozen delivery fee is just its first
consequence.

**Cashback falls with the items**, because it is earned on what was actually
bought. This has to be *shown*, not merely applied — the customer was
promised a figure at checkout and will notice a smaller one. Both the refund
and the reduced cashback appear on the order.

**We take less too, automatically** (owner, 2026-08-18). The service charge
recomputes on the fulfilled items and its GST follows, so the platform's cut
shrinks with the merchant's sale rather than being charged on goods nobody
received. A merchant who refunds a customer must not also be paying us 2% of
the refund — that would make honesty about a stock shortage cost them money,
which is precisely the behaviour we do not want to price.

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
| `order_amended`           | customer | push + SMS |
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
4. **Profile sheet** — fulfilment modes, prep times, and the store's
   marketplace description. Then, **per branch**, the islands it delivers to
   and the free-delivery minimum for each (§2.4). A store with no delivery
   rules on any branch is pickup-only, which is a valid way to open.
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

## 11. Decisions and remaining defaults

### 11.1 Settled by the owner, 2026-08-18

**Q1 — Fee and cashback base: ITEMS ONLY, never delivery.**
Delivery is a merchant recovering a cost, not revenue. Taxing it would
charge a store more for delivering further, and would pay the customer
cashback on a courier. §5.1 already reads this way; it is now a decision
rather than an assumption.

**Q2 — Refunds: CREDIT THE MANFAA WALLET.**
When a store rejects a paid order, the customer's money returns to their
Manfaa wallet immediately — no admin action, no waiting. Withdrawal to their
bank goes through the payout system they already use for cashback, so there
is exactly one outbound money path to reconcile rather than two.

Implications for MP5/MP8: a rejection posts a wallet credit for that
suborder's `subtotal_laari`, the parent order recalculates its total, and
the credit is a ledger posting like any other — visible in Activity, never
a silent adjustment.

**Q3 — Product edits: PRICE AND STOCK INSTANT, THE REST GATED.**
Operational fields (`price_laari`, `stock_qty`, `state`) apply on the spot —
a shop that cannot reprice or mark something out of stock without waiting a
day will simply oversell. Name, images and description are public claims and
queue through the existing MR9 change-request machinery, exactly as the
store profile does. This resolves the provisional answer in §2.2.

**Q4 — Netting: NO. Two ledgers, two screens.**
A merchant's cashback settlement debt is never netted against their
marketplace payout. Netting money owed against money due is where
reconciliation bugs hide, and a merchant reading one combined figure cannot
check either half. §5.4 stands as written.

**Q5 — Delivery semantics: settled earlier the same day.** Per branch, per
destination island, and the number the merchant sets is the free-delivery
threshold (§2.3, §2.4).

### 11.2 Defaults taken — say the word to change any of them

These are decided but low-stakes; each is a small change if wrong.

- **Below the free-delivery threshold** the branch charges
  `delivery_fee_laari` and still takes the order. `order_minimum_laari` is
  nullable and defaults to null, so a branch only refuses small orders if it
  deliberately sets a floor.
- **GST on the marketplace fee** follows the existing platform-fee
  treatment, computed on the fee itself (`order_fee_gst_laari`).
- **Ratings** — the customer who placed it, after `delivered`, once per
  suborder. Merchants cannot reply in v1.
- **Brand deduplication in browse** — where two branches of one brand serve
  the same island, list the one with the better terms for the customer's
  address (free-delivery threshold, then ETA); the others are reachable from
  the store page.
- **Cashback rate stays per merchant**, not per branch (§2.3).
- **A brand-new marketplace store starts unrated** — no invented stars, and
  the rating chip is absent rather than showing 0.0.

## 12. Phasing

Each round ships end-to-end and green, in the house style.

| Round | Scope |
|-------|-------|
| **MP1** | Foundations: migrations, `marketplace_enabled` kill switch, opt-in + KYB, admin review queue. No shopping yet. |
| **MP2** | Catalogue: product definitions on the merchant, `branch_products` listings with per-shop price and stock. Merchant web CRUD + app quick edits. |
| **MP3** | Delivery and addresses: per-branch/per-island rules, `customer_addresses`, zone resolution, and branch storefronts (§2.3). |
| **MP4** | Browse and cart: Market tab, store page, floating cart, collapsible multi-vendor cart with minimum warnings. |
| **MP5** | Checkout and orders: the four steps, receipt-first payment, order + suborder creation, Order Received. |
| **MP6** | Fulfilment: merchant Orders tab and details, accept/reject, **amendments with wallet refunds** (§2.7, §5.4c), statuses, pickup codes, notifications. |
| **MP7** | Customer tracking: Activity unification, per-store statuses, Track order. |
| **MP8** | Money: marketplace fee with overrides, cashback on validation, **Merchant Settlements** with xlsx export/import. |
| **MP9** | Customer web + merchant web marketplace parity. |
| **MP10** | AI search: query parser, pgvector, hybrid ranking, "Why these?". |
| **MP11** | Ratings, favourites, tablet polish. |

MP1–MP3 are invisible to customers and can ship behind the kill switch while
the rest is built.

---

## 13. MP1 — foundations (in progress, branch `marketplace`)

Everything here is invisible to customers and ships behind the kill switch.
No shopping, no catalogue, no orders: this round is the switch, the
enrolment, and the review queue that lets a store *become* a vendor.

**Server**

- [x] Platform settings: `marketplace_enabled` (0/1, default **0**) and
      `marketplace_fee_bp` (default **200** = 2.00%, wire name
      `marketplace_fee_percent` per the PERCENT_KEYS rule).
- [x] Migration: `merchant_marketplace_profiles` (§2.1).
- [x] Migration: `merchant_kyb_documents` (§2.1).
- [x] Models + casts, `Merchant::marketplace()` relation.
- [x] `marketplace.manage` permission, owner-tier (named `marketplace.manage`,
      not `store.marketplace`).
- [x] `EnsureMarketplaceEnabled` middleware — every marketplace route
      refuses with a machine-readable code while the switch is off, so a
      hidden button that still answers cannot exist (§10).
- [x] Expose `marketplace_enabled` on `/mobile/v1/config` and both panel
      bootstraps.
- [x] Merchant API: read enrolment state, opt in (business type, fulfilment,
      prep times), upload/replace/delete KYB documents, submit for review.
- [x] Admin API: KYB queue — list pending, show one with its documents,
      approve, reject with a reason.
- [x] Notification keys `marketplace_approved` / `marketplace_rejected`,
      seeded, push + SMS (merchant moments text the store, 2026-08-18).
- [x] Document storage on the private disk, streamed through an
      AUTHENTICATED route rather than a signed URL — a signed link can be
      forwarded, and an identity document should stop at the account that
      owns it. Replacing a paper deletes the file it supersedes.

**Proof**

- [x] Tests: the switch refuses every marketplace route when off; a
      non-owner cannot enrol; a store cannot submit KYB twice; approval
      moves the state and notifies; rejection carries its reason; documents
      are unreadable without auth.
- [x] Full API suite green, pint clean.

**Shipped 2026-08-18.** 18 marketplace tests; full API suite 1426 green;
live behind the switch (`features.marketplace: false`, every marketplace
route 404 to an authenticated caller).

Two things learned in the build, worth carrying into MP2:

- `EnrolmentService` reads the profile with a QUERY, never through
  `$merchant->marketplace`. A relation is a snapshot of whenever it was
  first touched, and enrolment moves several times inside one flow — the
  cached null made "submit" report every document missing when the honest
  answer was "you have not opted in". `NotEnrolledException` now separates
  those two sentences.
- The kill switch outranks permissions, so `RoleMatrixTest` turns it on.
  With it off the routes 404 for everyone, and a permission gate behind a
  404 can never be the first answer — which is correct, and had to be said
  somewhere the matrix could see it.

**Not in MP1:** any customer-visible surface, the catalogue, delivery rules,
the panels' UI. Those are MP2+.

---

## 14. MP2 — the catalogue (in progress)

Product definitions on the merchant, listings on the branch (§2.2, §2.3),
and the edit split the owner settled (§11.1 Q3). Still no shopper-facing
surface: this round is what a vendor stocks, not what a customer sees.

**Server**

- [x] Migration + seed: `marketplace_categories`, the platform-curated tree
      from `Market View Tablet.png` (Rice & Grains … Others).
- [x] Migration: `products` (definition) and `product_images`.
- [x] Migration: `branch_products` (listing — price, stock, availability).
- [x] Migration: `merchant_change_requests` gains `product_id` and the
      `product_update` kind, so gated product edits reuse MR9 rather than
      growing a second review queue.
- [x] Models and relations.
- [x] `ChangeKind::ProductUpdate` — label, governing permission.
- [x] Merchant API: browse categories; create / update / archive a product;
      upload and remove images; set a branch's price, stock and availability.
- [x] **The edit split, enforced server-side**: `price_laari`, `stock_qty`
      and `state` apply instantly; `name`, `description` and images queue
      through MR9 on a store already selling. Fail-closed, like the profile
      gate — an unrecognised key is gated, never waved through.

**Proof**

- [x] Tests: a listing prices per branch; stock is per branch; an instant
      field applies on a live store; a gated field queues and leaves the
      product untouched; a pre-approval store writes straight through;
      archiving hides a product without deleting order history.
- [x] Full API suite green, pint clean.

**Shipped 2026-08-18.** 17 catalogue tests; full API suite **1443 green**;
live behind the switch.

**One rule changed during the build, and it is a better one.** The gate asks
about the PRODUCT, not merely the store: a product no branch lists as
`active` has never been in front of a shopper, so its name is not yet a
public claim and nothing queues. Gating from the moment of creation would
mean a vendor loading a 124-line catalogue queues 124 review requests for
typos in things nobody can buy — which teaches everyone to rubber-stamp the
queue, and a queue only works if it is read. The moment a shelf carries the
product, every word about it is gated.

Also learned: the MR9 shape constraint predates products and had to be
widened, and my first attempt widened it WRONGLY — requiring a branch on
every branch-shaped change. Deleting a branch NULLS `branch_id` on the
history that referenced it, and that history is exactly what must outlive
the branch. The suite caught it; the constraint now only ever says which
kinds may NOT carry a branch.

**Not in MP2:** the panels' UI, the customer-facing Market. MP4 onward.

---

## 15. MP3 — delivery rules and addresses (in progress)

The per-branch, per-island matrix (§2.4) and the addresses it is measured
against (§2.5). Still nothing a shopper can see: this is what a branch
promises and where a customer says to bring it.

**Server**

- [x] Migration: `branch_delivery_rules` — a row per island a branch serves,
      carrying the free-delivery threshold, the fee below it, an optional
      floor, and the ETA to THAT island.
- [x] Migration: `customer_addresses`, with the zone resolved from the pin
      rather than typed.
- [x] Models and relations.
- [x] `DeliveryQuote` — given a branch, a destination zone and a basket
      value, answer: does this branch serve there, what is the fee, is it
      waived, is the minimum met, how far short.
- [x] Merchant API: read and write one branch's matrix (add an island,
      change its numbers, stop serving it).
- [x] Customer API: address CRUD, default address, zone resolution.

**Proof**

- [x] Tests: the owner's worked example (§2.4) priced both directions; a
      branch that does not serve an island; the threshold waiving the fee
      exactly at the boundary; an unset floor never refusing; an address
      outside every zone; a customer cannot read another's addresses.
- [x] Full API suite green, pint clean.

**Shipped 2026-08-18.** 14 delivery tests; full API suite **1457 green**;
live behind the switch.

Decisions taken in the build:

- **The threshold is met AT the number, not past it.** "Free delivery over
  500" that still charges at exactly 500 reads as broken whatever the
  wording says. Pinned by a test at 49999 / 50000 / 50001.
- **`DeliveryQuote` is the only place the arithmetic lives.** The store
  page's chips, the subcart's fee line and the progress bar all read one
  object, so the three cannot drift apart.
- **A null zone is an honest answer, not an error.** The Maldives is bigger
  than the islands we have drawn; an address outside all of them saves fine
  and simply cannot be quoted for delivery yet.
- **The typed island decides nothing.** It is kept verbatim as the
  customer's own words, but the zone comes from the pin — the island a
  courier drives to and the island a rule prices against must be the same
  one, and free text cannot guarantee that.
- Validation bug found and fixed: `sometimes` + `required_with` cancel each
  other, so a lone `lat` reached the database constraint as a 500. The
  constraint is the backstop; validation is the front stop, and the customer
  deserves the 422.

**Not in MP3:** carts, orders, any customer-facing Market surface.

---

## 16. MP4 — browse and cart, server side (in progress)

The first round a shopper could feel, though nothing is drawn yet: the
storefronts they browse and the multi-vendor cart that prices itself.

The cart is SERVER-SIDE because it prices itself, and pricing is our job —
delivery thresholds, per-branch minimums and cashback projections all have
to agree with what checkout will charge, and a client that computed them
would be a second opinion waiting to disagree.

**Server**

- [x] Migration: `carts`, `cart_items` keyed on the LISTING
      (`branch_products`), because that is the thing being bought.
- [x] Customer browse: nearby/served branch storefronts with their terms for
      the shopper's chosen address; one branch's products by category.
- [x] Cart API: add, change quantity, remove, empty.
- [x] `CartPricer` — one pass producing the exact shape the cart screen
      renders: a subcart per branch with items, delivery quote, minimum
      state and projected cashback, then the totals beneath.
- [x] Cashback projection per line: the product's own rate when it has one,
      otherwise the store's standing rate. Items only, never delivery
      (§5.1).
- [x] Stale-price and stock handling: a listing that moved or sold out must
      not silently price the old way at checkout.

**Proof**

- [x] Tests: two branches of two merchants price as two subcarts; a subcart
      short of its minimum says how short; delivery waived per island; a
      product whose price changed re-prices; an out-of-stock line is flagged
      not silently dropped; cashback follows the per-product override.
- [x] Full API suite green, pint clean.

**Shipped 2026-08-18.** 15 cart tests; full API suite **1472 green**; live
behind the switch.

Decisions taken in the build:

- **The cart re-prices from the live listing, every read.** The price stored
  on the item exists only so the screen can SAY a price moved
  (`price_changed`, `price_was_laari`). A basket that quietly bills
  yesterday's price is worse than one that admits the change.
- **A sold-out line is flagged, never dropped.** A row that vanishes reads
  as a bug, and a shopper cannot act on what they cannot see. Adding one is
  refused at the door instead, with `item_unavailable`.
- **`can_checkout` is one boolean over every subcart**, so the button can be
  disabled while the screen still names WHICH shop is short.
- **`address_id` may be passed to price against another address.** The
  checkout's address step changes the delivery terms of every subcart at
  once, and the screen has to show that before committing to it.
- **Browsing is open, the cart needs an account.** A shopper should see what
  a shop sells before signing in; a basket belongs to somebody.

**Not in MP4:** checkout, orders, payment (MP5), and the Flutter/web
surfaces that render all of this — the shape is fixed and tested, the
drawing is not done.

---

## 17. MP5 — checkout, orders and receipt-first payment (in progress)

The cart becomes an order: one payment the customer makes to us, and one
suborder per shop that fulfils it (`Order Received.png`, `Payment Step.png`).

**Server**

- [x] Migration: `orders`, `suborders`, `suborder_items` (§2.6), with
      `fulfilled_qty` already present for the amendments MP6 will apply.
- [x] `MF-<year>-<seq>` for the order and `MF-<seq>-<nn>` per suborder,
      generated under the same advisory-lock discipline as settlement
      references.
- [x] `CheckoutService` — validate the cart, FREEZE every rate and price
      onto the order, and refuse anything the cart said could not be checked
      out.
- [x] Receipt-first payment: platform bank accounts, exact amount, receipt
      upload, `awaiting_proof → proof_submitted → verified`.
- [ ] Admin: verify or refuse a payment proof — **deferred to MP6**, where
      it sits beside the fulfilment queue an admin actually works from.
- [x] Customer: read own orders with per-store status.

**Proof**

- [x] Tests: a three-shop cart becomes one order and three suborders; every
      rate is frozen so a later platform change cannot restate it; a cart
      below a shop's minimum is refused; the cart empties only on success;
      references are unique under concurrency; a customer cannot read
      another's order.
- [x] Full API suite green, pint clean.

**Shipped 2026-08-18.** 15 checkout tests, 79 marketplace tests in total;
full API suite **1487 green**; live behind the switch.

Four snapshots, each stopping a different kind of history rewrite:

- **Every rate frozen** — cashback bp, fee bp, delivery. Proven by a test
  that moves the platform fee to 9% after placement and asserts the order
  does not budge. This is also what makes MP6's amendments safe: they
  recompute against the frozen rates, never today's.
- **The address**, so editing it later cannot move where last month's order
  went.
- **The product name**, so a rename cannot rewrite what somebody ordered.
- **`fulfilled_qty` seeded equal to `qty`**, so the amendment gap MP6 opens
  starts closed.

And one refusal worth keeping: **the basket survives a failed checkout.** A
checkout that failed halfway and took the cart with it is the worst outcome
available, so placement is one transaction and the cart empties only on
success. Refusals name the shop — "Horizon Bookstore — Malé needs MVR 30.00
more" rather than "checkout failed".

GST is wired but zero, matching `CreditRecorder`: the platform has no GST
treatment anywhere yet, and inventing one here would put a number on an
invoice that nothing else in the system agrees with.

**Not in MP5:** merchant fulfilment and amendments (MP6), cashback crediting
(MP8).

---

## 18. MP6 — fulfilment and amendments (in progress)

The shop's half of an order (`Orders.png`, `Order Details.png`) and the
reduction flow the owner asked for (§2.7, §5.4c).

### A finding that shapes this round

**There is no customer wallet.** A customer's payable balance is derived, not
stored: `EligibilityQuery` sums CONFIRMED transactions with cashback and no
payout item. There is no ledger a refund could simply be added to.

Two ways to honour "refund to the wallet", and neither belongs in a
fulfilment round:

- Write the refund as a transaction so it flows into payout batches. It
  would work mechanically and corrupt every report — a refund is not
  cashback, and it would land in "cashback issued", in merchant
  reconciliation, and in the ledger as something it is not.
- Build a real customer wallet and teach the payout path to draw from it.
  That is a change to a LIVE money system and deserves the round that is
  about money.

So MP6 records the **obligation** — `customer_refunds`, one row per amended
line, `pending` — created in the same transaction as the recompute, so an
amendment can never reduce an order without owing the customer the
difference. **MP8 decides how it is paid** and settles them. The seam is
deliberate: half a wallet is worse than none.

**Server**

- [x] Merchant: list orders by state, show one, accept, reject with a reason.
- [x] State machine: accepted → preparing → ready → out_for_delivery →
      delivered, with pickup codes for collection.
- [x] Amendments: reduce a line or remove it, recompute the whole suborder
      from `fulfilled_qty` against the FROZEN rates, and record the refund.
- [x] Removing the last line offers rejection instead (§2.7).
- [x] Admin: verify or refuse a payment proof (deferred from MP5).
- [x] Notifications — done in MP7, beside the screen they link into.

**Proof**

- [x] Tests: accept/reject; a reduction recomputes cashback, fee and payable
      against frozen rates; delivery never moves; the refund obligation
      matches the arithmetic; the last line cannot be amended to nothing;
      a shop cannot touch another shop's order; states cannot skip.
- [x] Full API suite green, pint clean.

**Shipped 2026-08-18.** 14 fulfilment tests, 93 marketplace tests in total;
full API suite **1501 green**; live behind the switch.

What the tests pin, beyond the happy path:

- **A reduction recomputes against the FROZEN rates.** The test doubles the
  platform fee after placement, then amends, and asserts the recompute still
  uses 2%.
- **Delivery never moves.** A basket over the free-delivery threshold, cut
  back under it, still pays nothing for delivery. The customer met the terms
  with the order they placed; a shortage on the shop's shelf is not their
  fault. This is the general rule — *the customer must never owe more after
  a change they did not make* — in its first concrete form.
- **The state machine refuses shortcuts.** "Ready" before "accepted" is not
  a shortcut, it is a lie about what happened to somebody's shopping.
- **Amending to nothing sends you to reject instead**, so the customer gets
  the rejection notice and their delivery fee back rather than an order for
  nothing.
- **A refused payment goes back for another receipt, not to the bin.** A
  wrong screenshot is a fixable mistake; cancelling would throw away a
  basket somebody built.

---

## 19. MP7 — one timeline, and the messages that reach a phone

Shipped 2026-08-18. 11 tests; full API suite **1512 green**; live behind the
switch.

**The Activity feed merges in SQL, not in PHP.** A union over
`(kind, id, at)` from orders and transactions, paged, then hydrated per kind.
Fetching both lists and sorting in memory would silently drop whichever
source is denser — a customer who shops a lot at the till would stop seeing
their orders — and the bug would only appear for the busiest people.

**A multi-vendor order shows its shops, not a summary word.** In an order
spanning three stores the shops ARE the status: one word would hide that two
are confirmed and one is not.

**Seven order notifications**, each a moment the customer cannot see for
themselves. The SMS policy is per-key and deliberate:

| Moment | Push | SMS | Why |
|---|---|---|---|
| `order_placed` (to the shop) | ✓ | ✓ | Merchant moments text the store (2026-08-18) |
| `order_accepted` | ✓ | — | Good news; they are probably holding the phone |
| `order_ready` / `out_for_delivery` / `delivered` | ✓ | — | Progress, not money |
| `order_rejected` | ✓ | ✓ | Costs them the whole order |
| `order_amended` | ✓ | ✓ | Costs them goods they paid for |

`preparing` sends nothing at all: it is the shop's own bookkeeping, and
interrupting a phone for it is how people learn to ignore us.

Both channels still decide for themselves whether they have anywhere to
deliver — a customer with no device and no number is sent nothing, and that
is ordinary rather than an error. There is a test for it.

---

## 20. MP8 — marketplace cashback joins the one engine

Shipped 2026-08-18. 10 tests; full API suite **1522 green**; live behind the
switch.

A delivered order becomes an ordinary `transactions` row: one wallet, one
Activity feed, one payout, and a shopper who never has to learn that Manfaa
has two kinds of cashback.

### The trap this round existed to avoid

`SettlementBuilder::eligibleTransactions` bills a merchant for every payable
row they have. Pushing marketplace cashback through the engine naively would
therefore **charge a shop twice for one sale**: once by deducting the
cashback from their payout, and again on their monthly settlement.

So two rules, both enforced at the source rather than in callers:

- **`fee_laari` is zero** on a marketplace row. Our cut was the marketplace
  fee, already deducted; the standard cashback fee on top is the same double
  charge in a different column.
- **`origin = 'marketplace'` is excluded from `eligibleTransactions`.** That
  one clause is the whole boundary between the two ledgers, which is why it
  lives where a settlement decides what it may pick up. Two tests guard it
  from both sides: a marketplace reward forced into the receivable state is
  still not billed, and an ordinary till sale still is.

### And one honesty fix in the state machine

A marketplace reward never passes through `payable_unfunded`. That state
means "the merchant owes us this", and for an order we were paid for up
front it is a lie in the data — anyone summing it to ask what merchants owe
would over-count. `awaiting_validation → confirmed` is now permitted, and
`ValidationSweeper` is the only caller that takes it, only for that origin.
The store's validation window still applies: a marketplace return is as real
as a till one, and the shopper is paid on the clock they already know.

Crediting is idempotent by a **unique index on `suborder_id`**, not a flag —
a retried job, a double-tapped Delivered and a replayed queue message all
lose at the database rather than paying somebody twice. After an amendment
it credits what was actually supplied, not what was ordered.

**Still owed (MP9):** settling `customer_refunds`, and Merchant Settlements
with the xlsx export/import.

---

## 21. MP9 — the customer wallet, and money leaving the platform

Shipped 2026-08-19. 21 tests; full API suite **1543 green**.

The owner's design, with one change: **the refund destination is not chosen
at cart time.** At checkout a customer has no idea whether they will need a
refund, and it locks in an answer for circumstances weeks away they cannot
predict — on the one screen where a decision costs a completed order.
Refunds credit the wallet instantly, and the balance is always withdrawable,
so nothing is lost by defaulting. If a choice is ever wanted it belongs at
REFUND time, when they actually have the information.

### The shape

```
refund (amendment / rejection)  →  wallet credit     instant, automatic
customer requests withdrawal    →  customer_payouts  admin queue → bank API
```

`customer_refunds` stays the REASON — the audit trail of why a wallet moved.
`customer_wallets` + entries is the balance, built like `merchant_wallets`:
a column plus one entry per movement, never a bare number that can drift.

**Cashback payouts are untouched.** `EligibilityQuery` → `PayoutBatchBuilder`
→ xlsx → bank is live and reconciled; the wallet starts with refunds only.

### `internal_ref` is the spine

Minted before the payout row exists, **unique in our table**, and sent
unchanged on every attempt. That permanence is the whole of what makes a
retry safe, and it is why the upstream can recognise a repeat instead of
moving money twice.

Four answers, each interpreted rather than guessed at:

| Upstream says | We record | Why |
|---|---|---|
| 200 `success` + trx_id | **sent** | Done |
| 200 `pending_approval` + approval_id | **pending_approval** | Parked, alive, **never re-sent**. The approval id is a queue record, NOT a bank reference, and is never stored as `trx_id` |
| 409, `existing.status = success` | **sent**, adopting `existing.trx_id` | Already paid. Reading this as a failure and retrying is the textbook double payment |
| 409 with a code proving no debit | **failed**, money returned | Only a short ALLOW-list of codes qualifies |
| Anything else, incl. no answer | **failed**, money stays committed | A timeout may mean the bank paid while we stopped listening. A human looks |

The balance is debited when a withdrawal is **requested**, not when the bank
moves it — leaving it spendable while a transfer queues is how the same
laari leaves twice. It returns only on a proven failure or a cancellation.

### Admin-editable, because the tunnel does not exist yet

Base URL, profile segment and debited account are rows, not constants: all
four profiles seeded **inactive**, `auto_transfer_enabled` **off**, and an
`auto_max_laari` ceiling above which a person always looks. The API key is
NOT in the database — `x-api-key` is the whole of the upstream's
authentication, and a secret an admin session can read is a leaked bank. The
panel is told only whether one is configured.

`from_account` is sent explicitly even though `/bml/transfer` ignores it:
"whatever the default is today" is not something to reconcile a bank
statement against.

---

## 22. MP10 — Merchant Settlements

Shipped 2026-08-19. 14 tests; full API suite **1557 green**.

Its own menu, as the owner asked — and kept apart from the settlements
screen that already exists for a reason worth repeating: there a merchant
pays US the cashback they granted at their till, here we pay THEM for
marketplace orders they fulfilled. One screen showing both directions is a
screen nobody can check.

**The workflow is the customer payout one, unchanged**: build → approve →
export xlsx → bank → import the filled sheet. Whoever does the transfers
should not have to learn a second process because the money is going to a
shop. Same column shape, same empty reference box at the end.

### When a shop gets paid

On delivery **plus the store's own validation window** — the same clock the
customer's cashback confirms on, so a return handled inside the window
settles before anybody is paid for it. A window the merchant already
understands, rather than a second one invented for the marketplace.

### What stops a double payment, in three places

- **`suborders.payout_item_id`.** Unlinked is unpaid, linked is claimed. A
  concurrent build loses loudly rather than paying an order twice.
- **`internal_ref`, unique per item** (owner requirement). It is the bank's
  idempotency key AND the payout key printed on the sheet — one string
  identifies a transfer in our table, in the bank's, and on the paper in
  between.
- **An item already `sent` is skipped on import.** Re-uploading a sheet is
  something people do; refusing it teaches them to work around us, so a
  completed batch accepts a re-import and simply changes nothing.

A key we never issued is **reported, never guessed at** — a mistyped row
must not silently mark the wrong shop paid. Money waiting on missing bank
details is surfaced on the batch rather than quietly absent, and its orders
stay unclaimed so they fall into the next run. Cancelling a batch releases
everything it claimed.

The bank API path is available per item with the same interpretation rules
as the customer side: a parked transfer is never re-sent, and its
`approval_id` is never filed as a bank reference.
