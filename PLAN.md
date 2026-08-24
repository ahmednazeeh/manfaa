# Rewards — Implementation Plan

Maldives-wide cashback marketplace. Laravel API, Next.js panels, PostgreSQL, Flutter later.

**Status:** pre-build, greenfield.
**Companion document:** the design review at `docs/design-review.md` (or the published artifact) holds the reasoning behind the rules below. This file is the build plan.

---

## 1. Decisions log

Settled. Treat as constraints, not proposals.

| Area | Decision |
|---|---|
| Integration model | POS systems and web merchants **POST** to our API. No polling — on-prem tills sit behind NAT. |
| Delivery failures | The vendor's responsibility. No platform-side reconciliation obligation. |
| Store onboarding | We do the integration work for physical stores ourselves, free. |
| Eligible amount | **The merchant supplies it.** They POST the eligible invoice total plus the invoice number. How they derive it — tax, service charge, excluded categories — is their policy, defined in their agreement, not modelled by the API. |
| Platform fee | Tiered on the cashback rate (see §4). Merchant pays cashback + fee. |
| Credit limits | None. Exposure bounded by the 15-day clock and automatic suspension at day 16. |
| Merchant wallet | A settlement *method* on the same path as bank transfer — and, since the owner decision of 2026-08-24, **pre-fundable**: merchants top up by receipt-first bank transfer (auto-matched like settlements), and validated cashback auto-settles from the balance hourly, oldest first, while it lasts. Still not a risk product: Manfaa never pays merchants out, and a wallet is only ever spent on the merchant's own settlements. |
| Non-payment | Customers are told the reward is unsettled and that the merchant is suspended. Factual wording only (§9.4). |
| Regulatory | Out of scope for the build; licences obtained if and when required. |
| Customer promise | Nothing promised before Confirmed. Pending is always shown as conditional. |
| Payout rails | Domestic bank transfer, free at any amount. |
| Frontend | **Three Next.js apps** from day one — merchant, admin, customer web — plus shared packages. |
| Missing-transaction claims | **Merchant-mediated, not self-serve** (decision 2026-08-14): the customer contacts the store, which credits the missed sale via the manual path. No public claim form — the merchant holds the sales records and funds the reward, and the platform carries no claim-spam surface. Claims domain stays dormant behind `FEATURE_CUSTOMER_CLAIMS` for a future formal disputes channel. |
| Backdated credits | **No admin approval, immediately payable, merchant-irreversible** (decision 2026-08-14 late): a credit older than the validation window skips on_hold entirely — it goes straight to payable_unfunded with the 15-day clock starting NOW, and the merchant/vendor CANNOT reverse it (admin adjustment only). The entry form warns before submit: "older than the validation window — once credited it cannot be reversed and becomes payable immediately". Applies to the manual path AND /v1 (one rule in CreditRecorder). on_hold remains for fraud/velocity only. |
| Settlement flow | **Receipt-first, merchant-driven** (decision 2026-08-14 late): no settlement exists without a payment receipt. Merchant flow: select transactions → see amount due + platform bank account (copy button) + reference → transfer at their bank → upload slip + enter bank ref → SUBMIT creates the settlement directly in payment_review. Admin reviews the slip: Match (allocate/confirm) or Reject → rejected settlement cancels, lines release, merchant simply creates a new one. Admin-side recording remains as a fallback. No more merchant dead-end at awaiting_payment. |
| Customer surface | Full customer web app ships in v1. Flutter consumes the same API later. |
| Vendor auth | Per-merchant **Sanctum tokens** with abilities now; OAuth consent flow deferred to a later phase. |
| Store self-signup | **Open self-signup with admin approval** (decision 2026-08-15): email + password + Maldivian mobile verified by SMS OTP → setup wizard (logo, channel, curated category, terms & exclusions, cashback %) → Save moves the store to **pending review**; superadmin approval queue activates it. Quitting mid-wizard resumes on next login. Store is invisible publicly until approved. Admin-created merchants remain possible. |
| Store channel | `is_online` bool becomes **channel enum: in_store / online / both**. Display copy never says "both" — it reads "In Store & Online" (localised). Card layout: logo left, then name, cashback %, channel label. |
| Store categories | **Superadmin-curated list** — admin CRUD on store categories; stores pick from the list only (no free text). Distinct from per-store PRODUCT categories below. |
| Product-category rates | **Line-item pricing** (decision 2026-08-15): stores define their own product categories with per-category overrides (excluded, or a rate). Credit form and /v1 accept optional `lines: [{category, amount}]`; each line prices at its category's rate (excluded → 0, unlisted → standing rate), per-line ceiling rounding then sum per §4; the split is stored on the transaction. Lines must sum to the eligible amount. No lines supplied → whole amount at the standing rate (back-compatible). |
| API wire format | **Percent strings on the wire, basis points inside** (decision 2026-08-15): `rate_bp`/`fee_bp` never appear in a request or response. They become `cashback_rate_percent` / `platform_fee_percent` as 2-decimal STRINGS ("2.00", "0.75") — the same idiom the payload already uses for `cashback_mvr`. Requests accept a string or number with at most 2 decimals and convert via integer math; storage, ledger and every computation stay integer bp. NO basis-point field reaches a body anywhere, including the admin knobs: a platform setting holding a rate is named `_percent` and carries the same 2-decimal string (`prompt_discount_rate_percent`), while the `platform_settings` row it writes stays `prompt_discount_rate_bp`. |
| Per-sale rate override | POST /v1/transactions and the manual credit accept an optional `cashback_rate_percent` for THAT sale (decision 2026-08-15). Validated against the active fee schedule ceiling, and **never below the rate that would otherwise apply** — the publicly advertised rate is a promise, so an override may only boost (mirrors the promotion floor). A lower value is refused with `rate_below_advertised`. The applied rate is frozen on the row as always. Because it only BOOSTS, a value **equal** to the advertised rate is a no-op — the sale prices as if the field were absent, so a live promotion still prices it under its own per-customer cap (echoing `active_promotion.cashback_rate_percent` back must not silently convert a capped offer into an uncapped one). A sale that grants no cashback either way — suspended-merchant ingestion, below minimum — IGNORES the field rather than being refused, because §7 ingestion and §9.2 both outrank it. On the merchant panel it is a **manager** decision like every other rate; staff key sales in at the store's own terms. |
| occurred_at | **Optional; defaults to now** (decision 2026-08-15). Accepts ISO 8601 with an offset, or a plain `YYYY-MM-DD HH:MM:SS` / `YYYY-MM-DDTHH:MM:SS` which is read as **Maldives time** (the only sensible reading for a Maldivian till) rather than rejected. Future-dated still refused; the backdated rule is unchanged. |
| Prompt-payment discount | **5% off the PLATFORM FEE** (never the customer's cashback) when a merchant settles EVERYTHING outstanding and every line is under 10 days old since it became payable (decision 2026-08-15). Eligibility is re-checked at submit, never trusted from the preview. Ledger: DR Platform fee revenue / CR Merchant receivable — a sales discount on our own revenue, and the credit participates in allocation as covered funds so the batch settles in full. Rounding: CEILING in the merchant's favour. Rate (500bp) and window (10 days) are platform settings, tunable without deploy. Applies to wallet settlement too. |
| Staff roles | Three tiers (decision 2026-08-15): **Owner** (everything), **Manager** (rates, promotions, settlements, branches — NOT bank account, staff management, or API credentials), **Staff** (credit entry + read-only). |
| Store logos | **Every logo is served through an authorising controller** (decision 2026-08-15): files live on a private disk, and `GET /api/merchants/{slug}/logo` serves one publicly while the store is `active`, or to the store's own users / any admin otherwise — everyone else gets the same 404 an unknown slug gets. Rejected the alternative (private before approval, republished to the public disk on activation) as the more complex of the two correct options: it needs a publish step on every transition and lets a file's location and the store's status disagree. Previously logos sat on the public disk at the guessable `/storage/merchants/{id}/logo.png`, publishing the branding of every store that had merely started the wizard. |
| Suspended-store writes | **Suspension freezes the commercial offer** (decision 2026-08-15, follows §7): a suspended (or closed) store cannot change its standing rate, create or publish promotions, or edit the product-category rate card — 409 `store_not_trading`. It creates no cashback, so any offer it set would be unhonourable, and a published promotion is immutable (§7) and would spring live on reinstatement. Everything that ENDS a suspension stays open: settling, receipts, profile, branches, staff, and every read. |

**Open decision (category-terms resolution instant):** product-category
overrides currently resolve at SUBMISSION time from the mutable category row,
while standing rates/promos/fee schedules resolve at occurred_at — a merchant
can edit a category and backdate-reprice, and a retroactive exclusion zeroes a
delayed vendor retry. Fix would be effective-dated category history mirroring
merchant_rates. Behaviour as-built is documented in openapi.yaml; decide
whether to add history or accept submission-time semantics.

**Flagged assumption (promo × category precedence):** during a live promotion,
excluded product categories STAY excluded; every non-excluded line prices at
max(promotion rate, its category rate) — a promotion never pays less, and
exclusions always hold. Change here if wrong.

### Assumptions taken (flagged, change if wrong)

- **Write-off horizon:** unsettled rewards move to `written_off` **90 days past due**. Nothing sits Pending indefinitely.
- **Currency:** `currency` column exists on every monetary row; validation enforces `MVR` only in v1. USD is a config change, not a migration.
- **Reversals:** sending reversals is a **contractual obligation** in the vendor integration agreement, not best-effort. Without this, refunded sales silently keep their cashback and every error runs in the merchant's favour.
- **Minimum eligible transaction:** MVR 50 (`5000` laari).
- **Refund/validation window:** 3 days default, configurable per merchant category. The 15-day settlement clock starts when this window closes.

---

## 2. Stack

| Layer | Choice | Notes |
|---|---|---|
| API | Laravel 13 (13.25+), PHP 8.3 | Sanctum 4.3 for auth. Queues on Redis. Scheduler for the clock jobs. |
| Database | PostgreSQL 16 | `bigint` money, `timestamptz` everywhere, `jsonb` for payload snapshots. |
| Frontend | Next.js 16.3+, React 19, Tailwind 4 | Metronic starter (`typescript/nextjs`) pins 16.1.6 — bump to latest 16.x on scaffold. |
| UI kit | Metronic 9.5.0 (TS/Next variant) | Radix UI, TanStack Query + Table, react-hook-form + zod, ApexCharts, `next-themes`, react-i18next. |
| Monorepo | pnpm workspaces + Turborepo | |
| Mobile | Flutter, later | Consumes the same customer API as the web app. |

**Theme:** light-first with dark mode via `next-themes` (already in the starter). Never define a colour only inside a dark-mode block.

**Localisation:** English and Dhivehi from the first component. `react-i18next` is in the starter. Thaana is RTL — use logical CSS properties (`padding-inline-start`, not `padding-left`) throughout. Fonts `Faruma.ttf` and `DAM_Kalhi.otf` are in `/home/ubuntu` — self-host them, do not link a CDN.

---

## 3. Repo layout

```
/var/www/rewards/
├── api/                       Laravel 12
│   ├── app/
│   │   ├── Domain/            pure domain logic, no framework deps
│   │   │   ├── Money/         Laari value object, rounding, tier resolution
│   │   │   ├── Ledger/        journal posting, account resolution
│   │   │   ├── Cashback/      transaction state machine
│   │   │   ├── Settlement/    batch build, allocation
│   │   │   └── Payout/        batch build, approval
│   │   ├── Models/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/
│   │   │   └── Middleware/    Idempotency, MerchantScope
│   │   └── Jobs/              escalation ladder, webhooks, payout generation
│   ├── database/migrations/
│   └── tests/
│       ├── Unit/Money/        rounding + tier fixtures
│       └── Feature/Ledger/    every posting rule balances
├── apps/
│   ├── merchant/              Next.js — merchant panel
│   ├── admin/                 Next.js — superadmin panel
│   └── web/                   Next.js — customer-facing
├── packages/
│   ├── ui/                    Metronic components, theme, i18n, RTL primitives
│   ├── api-client/            typed fetch client + zod schemas, generated from OpenAPI
│   └── config/                eslint, tsconfig, tailwind preset
├── plugins/
│   └── woocommerce/
│       └── manfaa-cashback/   the WooCommerce plugin (PHP 8.1, no build step, PHPUnit via wp-phpunit);
│                              dev-site/ and .tools/ beside it are gitignored (2026-08-22)
├── docs/
│   ├── design-review.md
│   └── openapi.yaml           written before the API implementation
└── PLAN.md
```

The three apps share `packages/ui` and `packages/api-client`. Only `apps/admin` may call admin endpoints; enforcement is server-side by guard, never by hiding routes.

---

## 4. Money rules

**Non-negotiable, and the first thing to build.**

### Representation

- Every monetary value is an **integer count of laari** in a `bigint`. Never float, never decimal-backed-by-float. Column names carry the unit: `eligible_laari`, `cashback_laari`, `fee_laari`.
- Every rate is an **integer count of basis points**. 2% is `200`. Never decimals, never percentages.
- Every monetary row carries `currency`.

### Computation

```
cashback_laari = intdiv(eligible_laari * rate_bp + 9999, 10000)
fee_laari      = intdiv(eligible_laari * fee_bp  + 9999, 10000)
```

Integer division with `+ 9999` gives **ceiling** — every fractional laari rounds
UP to the next laari. Customer-favourable, and it eliminates fractional edge
cases entirely: any nonzero eligible amount yields at least 1 laari of cashback
and 1 laari of fee.

**Round at the line, then sum. Never recompute against a batch aggregate.**

### Fee tiers

| Customer cashback | `rate_bp` | Platform fee | `fee_bp` | Merchant all-in |
|---|---|---|---|---|
| 0.50 – 0.99% | 50 – 99 | 0.25% | 25 | 0.75 – 1.24% |
| 1.00 – 1.99% | 100 – 199 | 0.50% | 50 | 1.50 – 2.49% |
| 2.00 – 4.99% | 200 – 499 | 0.75% | 75 | 2.75 – 5.74% |
| 5.00 – 20.00% | 500 – 2000 | 1.00% | 100 | 6.00 – 21.00% |

Reject any `rate_bp` below 50, above 2000, or non-integer — otherwise 4.995% falls into no tier. Warn in the rate-change UI about the 499 → 500 cliff: +0.01pp cashback costs the merchant +0.26pp all-in.

**Note — schedule ceiling governs sellability:** the table above is the *structural* range and the static fallback map. The rates a merchant can actually set are bounded by the **active fee tier schedule's own ceiling** (its last band's `to_bp`): a rate above it has no priced fee and is refused with error code `rate_not_priced` until the admin publishes a wider schedule and it takes effect. The seeded 50–1000 schedule row is never rewritten (append-only law), so widening the structural cap to 20% does not by itself make rates above 10% sellable.

### Caps

```
// normal
cashback_laari = intdiv(eligible_laari * rate_bp + 9999, 10000)   // ceiling
fee_laari      = intdiv(eligible_laari * fee_bp  + 9999, 10000)   // ceiling

// when a promotional cap clips the reward, the fee follows the reward granted
cashback_laari = min(cashback_laari, cap_remaining_laari)
fee_laari      = intdiv(cashback_laari * fee_bp + rate_bp - 1, rate_bp)   // ceiling
```

Also needed beyond the promotional cap: per-transaction maximum and per-customer-per-day maximum on the standing rate.

### Properties to preserve

- **No rounding residue exists** — the merchant pays the sum of rounded lines; customer and platform receive exactly those integers. No rounding-difference ledger account. This holds only while rounding is per-line and partial settlements allocate oldest-first.
- **Reversals reverse stored integers, never recompute.** Recomputing from a since-changed rate drifts the ledger by the rate delta.
- **Rate is frozen onto the transaction row** at `occurred_at`, never resolved at receipt time. A retried POST can arrive hours late.

### Test fixture (must pass before anything else ships)

| Invoice | Eligible | Cashback @200bp | Fee @75bp | Due |
|---|---:|---:|---:|---:|
| INV-1001 | 100,000 | 2,000 | 750 | 2,750 |
| INV-1002 | 50,000 | 1,000 | 375 | 1,375 |
| INV-1003 | 200,000 | 4,000 | 1,500 | 5,500 |
| INV-1004 | 80,000 | 1,600 | 600 | 2,200 |
| **Batch** | **430,000** | **8,600** | **3,225** | **11,825** |

= MVR 4,300.00 / 86.00 / 32.25 / 118.25.

---

## 5. Data model

Money columns are `bigint`. Timestamps are `timestamptz`. Enums are PHP backed enums stored as `varchar` with a check constraint — not PG enum types, which are painful to alter.

### Merchant side

- **`merchants`** — name, slug, status (`active` / `suspended` / `closed`), business_reg_no, tin, settlement_method (`bank` / `wallet`), bank_name, bank_account, validation_window_days, min_eligible_laari, eligibility_basis (free text, mirrors the agreement — displayed to customers, never used in computation).
- **`merchant_branches`** — merchant_id, name, address, lat, lng.
- **`merchant_users`** — own auth guard. merchant_id, name, email, role (`owner` / `staff`).
- **`merchant_rates`** — merchant_id, rate_bp, effective_from, effective_to (null = current), created_by. **Append-only history.** Sale-time resolution reads this table, never a column on `merchants`.
- **`promotions`** — merchant_id, rate_bp, starts_at, ends_at, min_purchase_laari, max_cashback_per_customer_laari, branch scope, status. Immutable once published.
- **`pos_vendors`** — name, contact, integration status.
- **`api_credentials`** — merchant_id, pos_vendor_id, token hash, abilities, last_used_at, revoked_at. Per-merchant, independently revocable.

### Customer side

- **`customers`** — own auth guard. customer_code (6-digit, displayed + QR), phone (unique, verified), name, email, status, payout_bank, payout_account, payout_account_name, kyc_status.

### Earning

- **`transactions`**
  - merchant_id, branch_id, customer_id, promotion_id (nullable)
  - origin: `pos` / `manual` / `online_link` / `api_phone` / `card_linked` (last reserved)
  - invoice_no, external_ref, idempotency_key
  - **eligible_laari** (as supplied by the merchant — the only figure used in computation), sale_laari (nullable, reference only), currency
  - **rate_bp, fee_bp** — frozen at `occurred_at`
  - cashback_laari, fee_laari, fee_gst_laari
  - state (see §6), reason_code
  - occurred_at, received_at, clock_start_at (when the validation window closed), due_at
  - **unique (merchant_id, invoice_no)**
- **`transaction_events`** — append-only. transaction_id, from_state, to_state, actor_type, actor_id, reason_code, meta jsonb, created_at. **Never mutate a state column without writing one of these.**
- **`adjustments`** — post-confirmation corrections. Never edit history.
- **`claims`** — customer missing-transaction claims. merchant_id, customer_id, claimed_date, claimed_amount_laari, receipt_no, evidence_path, state, resolved_by, resolution_note, resulting_transaction_id.

### Settlement

- **`settlements`** — merchant_id, reference (`ST-2026-00832`), state (§6), funding_method, sale_total_laari, cashback_total_laari, fee_total_laari, fee_gst_total_laari, amount_due_laari, amount_received_laari, due_at.
- **`settlement_lines`** — settlement_id, transaction_id, snapshot of cashback/fee/gst laari. Frozen once the settlement leaves Draft.
- **`settlement_payments`** — settlement_id, amount_laari, method, bank_ref, slip_path, state, matched_by, matched_at.
- **`merchant_wallets`** / **`wallet_transactions`** — balance_laari, movements.

### Payout

- **`payout_batches`** — reference, period_start, period_end, cutoff_at, state (§6), total_laari, customer_count, approved_by_first, approved_by_second.
- **`payout_items`** — batch_id, customer_id, amount_laari, bank, account, state, failure_reason.

### Ledger

- **`ledger_accounts`** — code, name, type (`asset` / `liability` / `income` / `expense`), scope (`global` / `merchant` / `customer`), owner_id.
- **`ledger_journals`** — reference_type, reference_id, description, posted_at.
- **`ledger_entries`** — journal_id, account_id, debit_laari, credit_laari, currency.

**Invariant, enforced by test and by a daily job: every journal sums to zero, and the sum of all entries per currency is zero.**

---

## 6. State machines

### Transaction

Customer-facing status is deliberately simpler than internal state.

| Customer sees | Internal state | Meaning |
|---|---|---|
| Pending | `tracked` | POST received, not yet validated |
| Pending | `awaiting_validation` | In refund window — 15-day clock not started |
| Pending | `payable_unfunded` | Settlement clock running |
| Pending | `on_hold` | Fraud or dispute review |
| Confirmed | `confirmed` | Settlement received and allocated |
| Paid | `paid` | Included in a completed payout |
| Reversed | `reversed` | Voided at the till, duplicated, or invalidated pre-confirmation |
| Unpaid | `written_off` | Merchant never settled — 90 days past due |

Reversal is permitted only up to `confirmed`. After that, corrections are `adjustments`.

### Settlement

`draft → awaiting_payment → payment_review → settled`
with branches to `partially_settled` and `cancelled`.

Lines freeze on leaving `draft`.

### Payout batch

`draft → approved → processing → sent → completed`
with a `partially_failed` branch.

**Two distinct approvers required** to move `draft → approved`. Enforce in the domain layer, not the UI.

---

## 7. Settlement rules

- **Allocation: oldest-first, fully.** A partial payment confirms whole transactions in age order, leaving the remainder Pending. Never pro-rata — it produces half-confirmed rewards and reintroduces rounding residue.
- **Overpayment** becomes a merchant credit applied to the next batch (wallet balance). Never an automatic refund.
- **No tolerance band.** A payment either covers lines fully (oldest-first) or the uncovered lines stay Pending. Exception — **the forgiveness rule:** if the remaining unpaid balance on a batch is under MVR 1 (`< 100` laari), forgive it: allocate all remaining lines, book the shortfall as DR Platform-funded rewards / CR Merchant receivable. The customer's cashback confirms in full; the platform absorbs the sub-laari gap. Never book settlement shortfalls to bad debt — that account is reserved for the 90-day merchant-default write-off.
- **Batch due date** = the earliest line's due date, not the batch creation date.
- **Locked batches:** a reversal POSTed against a line inside a non-draft settlement cannot reverse it. It becomes a credit adjustment on the next batch, and the API returns a distinct error code so vendors can tell it apart from a failure.
- **Wallet settlement runs the same path** — same states, same ledger entries, only the funding source differs. Do not build a second code path.

### The clock

| Day | Action |
|---|---|
| 0 | Validation window closes; transaction → `payable_unfunded`; `due_at` set to +15d |
| 10 | Reminder |
| 13 | Urgent reminder |
| 15 | Payment due |
| 16 | **Automatic suspension** — cashback creation stops |
| +90 | Unsettled rewards → `written_off`, customer notified |

Every step is a scheduled job writing a recorded outcome, so notice can be evidenced later. Suspension must be automatic — it is the *only* credit control, and a manual suspension makes the exposure bound fictional.

Suspension stops cashback **creation**, not ingestion. A suspended merchant's till keeps POSTing; accept and record those as ineligible with a reason code and a distinct response so the cashier sees something truthful.

It also **freezes the offer** (decision 2026-08-15): while suspended or closed, a store cannot change its standing rate, create or publish a promotion, or edit its product-category rate card — the panel answers 409 `store_not_trading`. A store that cannot create cashback cannot honour a rate, a rate change would still fire `merchant.rate_changed` and move the till's quoted percentage, and a promotion is immutable once published (§7) so it would go live the moment the store is reinstated — after, and because of, the settlement conversation that reinstatement turns on. Everything a suspended merchant needs in order to STOP being suspended is deliberately untouched: settle, upload receipts, fix the profile, run branches and staff, read every screen.

---

## 8. Ledger postings

Every event below posts one balanced journal. This table is the specification — implement it directly.

| Event | Debit | Credit |
|---|---|---|
| Cashback accrues (→ Pending) | Merchant Receivable (total) | Customer Cashback Liability (cashback), Platform Fee Revenue (fee), Fee GST Payable (gst) |
| Bank settlement received | Settlement Cash | Merchant Receivable |
| Wallet top-up | Settlement Cash | Merchant Wallet Balance |
| Wallet settlement | Merchant Wallet Balance | Merchant Receivable |
| Reward confirmed | — *(no entry; liability already recognised)* | — |
| Payout sent | Customer Cashback Liability | Settlement Cash |
| Reversal (pre-confirmation) | Customer Cashback Liability, Platform Fee Revenue, Fee GST Payable | Merchant Receivable |
| Write-off (90d past due) | Bad Debt Expense (fee + gst), Customer Cashback Liability (cashback) | Merchant Receivable |
| Platform-funded reward (referral) | Platform-Funded Rewards Expense | Customer Cashback Liability |
| Settlement shortfall forgiven (< MVR 1) | Platform-Funded Rewards Expense | Merchant Receivable |

Notes:
- Revenue is recognised on **accrual**, matching the receivable, with collection risk handled through bad debt. Recognising at settlement instead would leave the accrual entry unbalanced.
- **Reward confirmation posts nothing.** The liability was recognised at accrual; confirmation is a state change only. This is correct and worth not "fixing" later.
- Whether GST reverses on write-off depends on tax treatment — flagged, resolve with an accountant before the first write-off actually fires.
- Referral rewards bypass merchant receivable entirely and have their own path to `confirmed`.

---

## 9. API

`docs/openapi.yaml` is written **before** the implementation and is the design artefact.

### 9.1 Auth

Two modes:

- **Panels and customer web** — Sanctum stateful cookie auth. `SANCTUM_STATEFUL_DOMAINS` covers the three app subdomains under one parent domain.
- **POS vendors** — Sanctum personal access tokens, one per merchant, with abilities (`transactions:write`, `transactions:reverse`, `rates:read`). Independently revocable so a merchant switching POS vendor never forces rotation across other merchants.

Three guards, three tables: `admin_users`, `merchant_users`, `customers`. The populations are disjoint and carry different fields; do not force them into one table with a type column.

### 9.2 Vendor-facing (`/api/v1`)

```
POST /v1/transactions
  Idempotency-Key: <uuid>          required
  Authorization:   Bearer <merchant token>

  { "invoice_no": "INV-1001",         // required
    "customer_ref": "482917",         // required
    "eligible_amount": 118000,        // required — laari. The merchant's own
                                      // eligible total. We never recompute it.
    "sale_amount": 125000,            // optional — laari, full invoice total.
                                      // Never used in any computation; kept for
                                      // dispute answers, merchant analytics and
                                      // eligible-ratio monitoring.
    "occurred_at": "2026-08-14T11:04:22+05:00",
    "branch_id": "..." }

POST /v1/transactions/{id}/reverse
  { "reason": "customer_refund", "occurred_at": "..." }

GET  /v1/merchants/me/rate         // for the till display
GET  /v1/customers/lookup?ref=482917   // returns masked name for cashier confirmation
```

Rules baked into the contract:

- **Idempotency keys required on every write.** A repeated key returns the original result. Backed by `(merchant_id, invoice_no)` uniqueness, which catches the different failure of one sale arriving as two distinct requests.
- **Rate resolved at `occurred_at`**, frozen onto the row. Reject future-dated sales; route anything older than 3 days to review rather than into a live batch.
- **The till's displayed rate is advisory.** Server recomputes authoritatively. Rate *decreases* take effect only at 00:00 next day, which guarantees a stale till cache can only under-promise.
- **Published error semantics** — which failures are retryable, which terminal, what a duplicate invoice returns, what an unknown customer ref returns, what a suspended merchant returns. Vendors code against day-one behaviour and never revisit it.
- **Below-minimum sales return 200**, recorded with `cashback_laari = 0` and reason `below_minimum`. Do not reject the POST.
- **`eligible_amount` is taken at face value.** We never recompute or second-guess it — the merchant owns their own eligibility policy. This is what keeps the contract to two required fields and stops two POS vendors deriving different numbers from the same bill.

### 9.3 Outbound webhooks

Signed, with published verification. Events: `merchant.rate_changed`, `merchant.suspended`, `merchant.reinstated`, `transaction.reversed`. Without these, vendors cache indefinitely and tills quote offers we won't honour.

### 9.4 Customer-facing messaging

Non-payment wording is factual only:

- ✅ "Store A has not settled this transaction. Their cashback offers are suspended and we are pursuing the amount."
- ❌ "Store A refused to pay your cashback."

"Refused" asserts intent and invites a dispute. The escalation ladder is what evidences the claim that they were asked.

### 9.5 Sandbox

Test merchants and fixture data, or every vendor debugs against production and mints real cashback doing it.

---

## 10. Frontends

All three from the Metronic TS/Next starter, sharing `packages/ui`.

### `apps/merchant`

- Dashboard — current offer, platform fee, all-in cost per transaction
- Transactions list, filterable, with state and reason codes
- **Outstanding by age bucket** (0–5 / 6–10 / 11–15 / overdue), total payable
- Settlement builder — select transactions or "Settle All" → batch → payment instructions
- Settlement history with line detail
- Wallet (top-up, balance, movements)
- Rate change + promotion builder, with the tier-cliff warning
- Performance — customers acquired, new vs repeat, average transaction, sales attributed. *This is the sales pitch; do not defer it.*

### `apps/admin`

- Merchant onboarding and status
- **Settlement matching queue** — search, partial-match suggestions, slip review, audit trail. Someone works this daily; design it as a first-class screen.
- **Payout batch** — build, review, dual approval, bank file export, result file import, failure re-queue
- Claims queue with SLA and origin tagging
- Fraud/hold queue
- Ledger explorer and daily reconciliation report
- Manual adjustment tool with mandatory reason codes

### `apps/web` (customer)

- Sign up, phone OTP, customer code + QR
- **Balance: Confirmed as the headline.** Pending shown separately and always as conditional — never summed into one figure.
- Transaction history with per-item reason ("Store A settles within 15 days")
- Payout account registration and verification
- Merchant discovery — featured, increased, nearby, online
- Claim submission

Notification copy: "You'll earn MVR 25.00 once Store A confirms", not "You earned MVR 25.00".

---

## 11. Fraud controls

Cheap now, near-impossible to retrofit.

- `(merchant_id, invoice_no)` unique — the defence against vendor retry loops.
- Velocity rules → `on_hold`: one customer earning many times at one merchant in a day; a transaction far outside a merchant's normal distribution.
- **Manual-entry merchants are the exposed surface** — no POS, no invoice to verify against, the cashier is the only control. Rate-limit manual credits, require an invoice number, flag repeat credits to the same customer.
- Phone-number recycling — numbers get reassigned and a typo credits a stranger. Confirm against a masked name at the till; let the customer reject an unrecognised transaction.
- Negative balances — a reversal after payout offsets against future earnings. Never pursue the customer for cash. Cap how long a negative balance persists.
- **Eligible-ratio monitoring.** Because the merchant defines their own eligible amount, one can advertise 5% cashback while sending an eligible total that is a fraction of each bill — the customer earns MVR 12 on a MVR 1,250 purchase and our brand carries the disappointment. Where `sale_amount` is supplied, track `eligible / sale` per merchant and alert on outliers or sudden drops. Onboarding control, not an API control: the merchant's `eligibility_basis` is displayed to customers, and the app always shows the amount the cashback was computed on.

---

## 12. Phases

**v1 launch = Phases 0 through 3.**

### Phase 0 — Foundation and ledger

No integrations. The riskiest logic, built first and in isolation.

- Monorepo scaffold; three Next apps from the Metronic starter; `packages/ui`, `packages/api-client`, `packages/config`
- Laravel skeleton, PostgreSQL, Sanctum, three guards
- **Money primitives** — laari value object, rounding, tier resolution, cap handling, with the §4 fixture as a test
- **Ledger core** — accounts, journals, entries, balance invariant test
- Merchants, branches, rate history, customers
- Transaction state machine + `transaction_events`
- Manual credit path (§8 of the original spec) — the only earning path in this phase

**Exit criterion:** a few hundred real transactions reconcile to the laari. Nothing else ships until this holds.

### Phase 1 — Settlement and payout

- Settlement batches, oldest-first allocation, tolerance matching, partial and overpayment
- Wallet as an alternate funding source on the same path
- The clock: escalation ladder, automatic suspension, write-off job
- Payout batches with dual approval, bank file export/import, failure re-queue
- Merchant panel and admin panel screens for all of the above
- Daily reconciliation job

### Phase 2 — Public API

- `docs/openapi.yaml` first
- `/v1` transactions, reversals, rate read, customer lookup
- Idempotency middleware, per-merchant Sanctum tokens with abilities
- Outbound webhooks
- Sandbox with fixture merchants
- Vendor integration guide

### Phase 3 — Customer web and promotions

- `apps/web` — auth, balance, history, payout account, claims
- Promotions engine with server-resolved rates and caps
- Claims queue in admin
- Merchant discovery screen
- Dhivehi translation pass

### Phase 4 — Growth

- Online link attribution (click ID → server-side postback, same inbound contract as POS)
- Referrals, on their own ledger account and origin type from the first line of code
- Flutter app against the existing customer API

### Later

- OAuth consent flow for vendors
- Card-linked offers — schema already reserves `origin = card_linked`
- Multi-vendor marketplace
- Tourist product — do not hard-code MVR-only, local-account-required, or monthly-cycle assumptions that would block this

---

## 13. Cross-cutting rules

- **Timestamps stored UTC, business rules evaluated in UTC+5.**
- **Payout cutoff:** the 24th at 23:59. Rewards confirmed after it roll to next month. Freeze reversals against Confirmed rewards after the cutoff, or a late reversal lands mid-payout-run.
- **Minimum payout:** MVR 100. Below that, carry forward. Always allow a full payout on account closure regardless.
- **No silent state mutation.** Every transition writes an event with actor and reason.
- **Immutability.** Confirmed is near-final; corrections are adjustments, never edits.
- **RTL from the first component.** Logical CSS properties only.
- **Light-first with dark mode.** Tokens defined on `:root`; dark mode redefines tokens only.

---

## 13b. Build queue (status — 2026-08-15, autonomous run complete)

Phases 0–3 plus the post-launch rounds are BUILT, DEPLOYED and LIVE at
manfaa.app / merchant. / admin. / api. — **1022 tests, 21,368 assertions green**,
twelve adversarial review rounds (50+ serious findings confirmed and fixed).

### Done beyond the phases
Merchant module (Credit Customer with QR scan + masked-name lookup, settings,
three-tier roles) · platform settings (bank accounts, effective-dated fee tiers
with a coverage invariant, operational parameters, admin management) · public
storefront (merged searchable /discover, /store/{slug} pages, logo + initials
avatars, marketplace teaser) · 20% cap with schedule-driven sellability and
percent inputs · store self-signup → setup wizard → superadmin approval,
channel enum, curated categories · product-category overrides with line-item
pricing · receipt-first settlements with private slips and admin reject ·
backdated credits immediately payable and irreversible · admin hold-review
queue · human reason labels everywhere (typed exhaustive maps + audit script) ·
merchant self-serve API credential wizard · MsgOwl SMS · claims
feature-flagged off (merchant-mediated).

Since the queue last emptied: settlement picker sums + age presets, the 5%
prompt-payment fee discount (PLAN §1), and the developer documentation, built
by scripts/build-docs.mjs and served statically by nginx so it survives an app
or PHP outage. The narrative guide is folded into the spec description by
scripts/merge-guide-into-spec.py, so **/docs/ is one document** — guide and
endpoint reference under a single Scalar sidebar. The panel links there
(lib/integration.ts); /docs/integration-guide redirects to it.

### The store/API batch (was #23–#30) — DONE

Percent strings on the wire (basis points never appear in a request or a
response) · per-sale cashback override, manager-and-above only · optional and
normalised `occurred_at` · five named request examples and named response
examples on POST /v1/transactions, integers hand-derived · the credit form's
category split auto-sums into the eligible total · Rakuten-structure landing:
public header search, curated category icon rail, graphic hero, store shelves
including Recently added · empty curated shelves suppressed rather than shown
as "nothing here" boxes.

### The storefront/Dhivehi round — DONE

Category icons are UPLOADED artwork (superadmin, admin.manfaa.app › Settings ›
Store categories), served public and immutable-cached from
`/api/store-categories/{slug}/icon?v=…`; a curated lucide glyph name stays
underneath as the fallback so the rail is never a row of blank tiles. SVG is
refused — it is a scriptable document served from our own origin.

Landing rebuilt on the rakuten/islebooks shape: copy on one side, an animated
customer-app mockup on the other (code → sale → "Ka-ching, you earned …"),
which mirrors under RTL. The three-stop gradient panel is gone — it fought
every card below it — and how-it-works moved directly under the hero as one
compact left-to-right row. Store card media is square, so an uploaded square
logo fits instead of letterboxing.

Dhivehi: money reads "1,249.60 ރުފިޔާ", never "MVR" (a MoneyLocale context in
@manfaa/ui, so no call site knows the language); Noto Sans Thaana is
self-hosted (~8 KB, was relying on the reader having Faruma installed) and
`html[lang=dv]` scales the root size so type, leading and spacing grow
together; percentages and amounts carry Unicode LTR isolates, which is what
turned "%2" back into "2%". Stores collect a Thaana name at signup (editable
in profile, never touches the slug) and every storefront surface shows it.
Merchant PRODUCT categories now REQUIRE a Dhivehi name — they print on the
customer's own receipt lines.

### The storefront-UX round — DONE

Customer payouts (list + detail, each opening onto the invoices and stores
it paid for). Landing split by audience: a signed-in customer gets
categories then the shelves, a visitor gets hero → how it works → cashback
is real money → search + categories → shelves, with the bands alternating so
sections stop running into one another. Search everywhere is one component
with suggestions (stores with logos and rates, categories with their
artwork) that always falls through to /discover. Navbar gained an active
underline, a route to becoming a merchant, and calls the customer's own
screen "My cashback" rather than "Dashboard". Merchant cards lead with the
name, then the rate as the largest thing on the card, then channel and
category badges, distance, and a Terms link (stretched-link pattern, so the
card stays one target and Terms is still a real second destination).

API: lined sales report `effective_cashback_rate_percent` beside the base
rate, the rate endpoint flags `has_category_overrides`, every money field
carries its `*_mvr` twin, and the docs work the categorised call end to end
inline — Scalar's request and response selectors are independent, so an
example pair could otherwise be read as a mismatch.

### The correction / signed-in round — DONE

Merchants can fix a sale while it is still in the validation window:
**Correct amount** (AmendmentService reverses the accrual with the STORED
integers, then posts fresh against the FROZEN rate — a correction never
re-prices a sale) and **Cancel sale**, both manager-and-above, both refused
on a backdated credit because that path is irreversible by design. Lined
sales amend line by line. A new merchant's validation window now defaults to
2 days (`new_merchant_validation_window_days`, clamped by the platform
ceiling — the `Merchant::creating` hook applies the setting, so the column
default never fires and lowering the shared ceiling would have moved every
existing store).

The credit form HIDES the eligible-amount field entirely under Split by
category and fills it behind the scenes from the typed rows — a derived
number that is also editable is a number two people will disagree about.
The till shows the customer's **real name**, unmasked: masking never was the
enumeration defence (the miss budget and the identical blocked-vs-missing
responses are), and a cashier who cannot read the name cannot check it.

Signed-in customers get a banner (earned, pending, the buttons they actually
use) instead of the visitor hero, then the category rail, then shelves —
Highest cashback and Recently added among them. The merchant panel menu is
regrouped Till / Money / Marketing / Store / Account.

### Featured offers — DONE

Curated banners at the top of /discover (admin.manfaa.app › Settings ›
Featured offers), ready for the app to reuse. A banner is **one of two
kinds, never a blend** — the blend was built first and failed on both
counts, with the copy cutting into the artwork and the artwork cropping away
the half of itself that carried the message:

- **Image banner** — the uploaded picture is the whole card, edge to edge,
  with nothing printed over it. Exactly **1200 × 675 px (16:9)**, stated in
  the admin and enforced on upload: another shape is refused at the door
  rather than centre-cropped, so what a designer hands over is what the
  storefront shows. Whatever the artwork says is the admin's to keep
  current, including the percentage — said plainly where it is uploaded.
- **Text banner** — no artwork. The storefront lays out a designed card from
  the words, with the store's logo and **live** cashback percentage read
  fresh on every render, so this kind can never quote a rate the shop has
  moved off.

Uploading artwork makes an offer an image banner; removing it turns it back
into a text one. Either kind carries Thaana twins for headline, blurb and
badge, and mirrors under RTL. An offer for a suspended store never reaches
the storefront under either kind.

Artwork lands on a private disk and is served from
`/api/store-offers/{id}/image?v=…` with a content hash, raster only. The
admin list states the kind and WHY each offer is or is not visible — live /
switched off / scheduled / ended / store not trading — because "saved but
invisible" is a normal state and an admin should not have to guess which.
There is no delete; deactivation retires a campaign and keeps it on the
record.

### Payouts on a chosen date, and maps — DONE (deploy pending)

**Verified before anything was touched:** a reward confirmed after a batch's
cutoff was never LOST. `EligibilityQuery` has a cutoff but no lower bound, so
the next batch always reached back and swept it up. The defect was the delay —
and that the cutoff was hard-wired to the 24th, so "a batch on 27 August" could
not be asked for at all. The owner's own scenario is now a named test.

The cutoff is a **date the admin picks**, defaulting to today, so payouts can
run weekly or on any cadence. Reference `PB-YYYYMMDD`; a period starts where
the previous batch's cutoff ended. A future cutoff is still refused — a batch
built ahead of itself would silently miss confirmations still to come. §3.1 and
§3.2 of the round plan contradicted each other on *today* specifically (end of
today is in the future until midnight); resolved in the controller, which
records end-of-day for a past date and *now* for today, leaving the domain
guard untouched.

**Dual approval is gone.** The rule cost nothing to enforce and everything to
satisfy on a platform with one admin.

**The bank file is an xlsx transfer sheet** — Idempotency Key, Customer Name,
Customer Phone, Customer Account Name, Customer Account Number, Amount Owed,
Transfer Reference Number left blank. The key is `MNF` + a Postgres sequence,
minted at build time rather than from the row id, which does not exist until
after the insert. Amount Owed is a NUMERIC cell, never a formatted string, or
the finance team's own SUM lies. Customer name and phone join the bank details
already snapshotted, so a re-export says what the first export said. §14's
"bank bulk payout file format" is answered on an interim basis by this sheet;
the real BML format stays another `BankFileFormatter`.

**Settlement takes three routes to one ledger path**: upload the filled sheet
(matched on the key, which must belong to *this* batch; a filled reference pays
that row, a blank one is untouched, so a half-filled sheet can be uploaded
again), settle one customer, or settle all under one shared reference — a bulk
transfer is one bank transaction covering many payees, so a single reference is
the honest record. All three post through `ItemResultService`; marking an item
failed stays a deliberate UI act, never a spreadsheet column.

**Maps.** Merchants pin at signup — search, drag, locate me — and the same
picker replaces the typed coordinate boxes in branch settings. The pin is
*asked for*, not demanded: required to leave the step, deliberately absent from
`missingRequirements()`, because gating approval on it would make any store
already in `pending_review` instantly un-approvable. Shoppers get a map beside
the Near you list; the list stays the default and is the only thing that works
without a key or a location grant. Discovery entries had to start publishing
branch coordinates first — they existed inside the service and were stripped on
the way out, so the map could not have placed one pin. The shared package holds
the loader and nothing else: Tailwind does not scan it through its pnpm
symlink, so styled markup there would ship with its classes missing.

`leaflet`, `react-leaflet` and `@types/leaflet` dropped from all three apps —
zero imports, inherited template weight.

### Platform connect — DONE (2026-08-19)

"IsleBooks would like to record sales and accrue cashback — Authorise /
Deny." OAuth 2.0 authorization code with PKCE, for platforms that serve many
merchants rather than one store. What comes out is the SAME `api_credentials`
row and the same Sanctum token the panel mints today — the flow is only a
delivery mechanism, and the value is that the key is never displayed, so it
cannot be pasted into a support chat or a screenshot.

TWO TIERS, on the owner's instruction: a platform credential lets its holder
put a consent screen in front of ANY shopkeeper on Manfaa, so **a superadmin
registers the platform** (`/settings/platform-clients` → client_id +
client_secret shown once, exact-match https callbacks, an ability CEILING, and
a `connect_enabled` flag that is off until reviewed). A developer without a
registration is not blocked — the per-merchant key still works and reaches
exactly one shop.

**The token does not expire** (owner decision — an accounting integration that
dies at three in the morning is the worse outcome). Every other control is
sized to that:

- the CODE expires in 60s and is single-use, because it is the only part that
  travels through a browser redirect;
- re-authorisation REPLACES the previous grant, since with no expiry a shop
  reconnecting every few months would accumulate tokens it had forgotten;
- rotating a platform secret cuts every grant it ever produced — rotation
  happens because a secret leaked, and leaving the grants alive would mean
  rotating changed nothing for whoever holds them;
- the merchant sees it as "Connected app" in Settings › API access, and
  revokes it there.

Pressing Authorise needs `api_credentials.create`, not merely `.view` — it IS
issuing a key, just without anybody seeing it. A lapsed session no longer
swallows the request: `?next=` carries the consent URL through login.

Suite: `tests/Feature/Connect/PlatformConnectTest.php`, 21 tests — unknown
client, disabled client, scope beyond the ceiling, redirect mismatch (a
registered-but-different URI included), wrong secret, verifier mismatch, code
reuse, code past its minute, a code presented by a rival platform, staff
without the permission, re-authorisation replacing, rotation cutting.

Docs: guide §2.1 and `POST /v1/connect/token` in the reference.

Three things the suite caught on the way, none of them about connect:

- **Settlement auto-match never worked past the match.** `matchPayment()`
  documents `$actor === null` as "an automatic match against the bank's own
  history", and `matched_by` honoured it — but the allocation loop below read
  `Actor::admin($actor->id)` straight off the null. Every auto-matched
  settlement died at the moment it tried to allocate. `Actor::system()` is
  what the ledger has for this and is now what it uses.
- **Tests were calling the live bank gateway.** Transfer tests carry the real
  URL (`http://10.99.0.1:3005`) because that is what the code reads, and a
  targeted `Http::fake(['*/faisanet/history*' => …])` lets every OTHER url
  through to the network. It only showed as slowness — 41 minutes, the suite
  sitting in SYN-SENT — because the tunnel happened to be down. With it up,
  the suite would have been talking to the bank. `tests/TestCase.php` now
  calls `Http::preventStrayRequests()`; the transfers suite went from hanging
  to 3.4 seconds.
- **`marketplace.enrol` gated four routes but no test row**, so RoleMatrixTest
  — whose whole job is "a permission nothing checks is not a permission" —
  failed correctly. Staff holds `marketplace.manage` (the order queue is
  counter work); Manager and up hold `.enrol` (committing the business, and
  uploading the owner's papers, is not a cashier's call).

The bank-account immutability test was rewritten to the owner's 2026-08-19
reversal: the account number is always updatable in place.

### One set of docs, on both hosts — DONE (2026-08-19)

`https://api.manfaa.app/` served Laravel's stock welcome page: 70KB of
Tailwind boilerplate on the host a POS developer hits first. It now redirects
to `/docs/`, and the API host serves the docs directory from **the same files
on disk** manfaa.app serves — verified byte-identical across both hosts.

The intermediate step is worth recording because it was the wrong instinct: a
hand-written developer landing, then a generated one. Both were a SECOND
telling of the endpoints, the abilities and the limits, in their own design.
Owner's call, and correct: duplicate pages with different designs cost more
every time an endpoint is added or a doc is updated than they ever save a
reader. There is now one reference, one guide, one spec, reachable at the same
paths on either host.

| Path | api.manfaa.app | manfaa.app |
|---|---|---|
| `/` | → `/docs/` | marketing site |
| `/docs/` | reference | reference |
| `/docs/integration-guide.html` | guide | guide |
| `/docs/integration-guide` | → `.html` | → `.html` |
| `/docs/openapi.yaml` · `openapi.docs.yaml` | spec | spec |
| `/api/v1/docs` · `/api/v1/openapi.yaml` | → canonical `/docs/…` | — |

Four broken paths fixed on the way:

- `api.manfaa.app/docs/integration-guide` redirected to `/docs/`, which that
  host did not serve — straight into a Laravel 404.
- `manfaa.app/docs/integration-guide` opened the API REFERENCE. A path named
  integration-guide now opens the integration guide.
- The merchant panel's **"Integration guide"** button pointed at `/docs/`,
  right while the whole guide was folded into the spec description and wrong
  the moment that stopped (same day, MERGE_ONLY). Webhooks, retry expectations
  and the go-live checklist live only in the guide now.
- `SANDBOX_GUIDE_URL` anchored `#description/sandbox-fixtures`; the heading is
  the spec's own `Sandbox` now, so the anchor silently dropped the reader at
  the top of the page.

`api/routes/web.php` registers nothing, and `resources/views/` is empty — the
front door is static, so it survives PHP being down.

**OWNER DECISION NEEDED — the sandbox host does not exist.**
`sandbox.api.manfaa.app` does not resolve, yet the guide's environment table
names it and `SANDBOX_API_BASE_URL` in the merchant panel defaults to it.
`docs/openapi.yaml` meanwhile lists two servers with the SAME url, one
labelled Production and one Sandbox. Left alone deliberately: pointing the
sandbox constant at `api.manfaa.app` would invite vendors to run test
transactions against production, which is far worse than a host that fails to
resolve. Either stand the host up, or say sandbox is per-arrangement and strip
the URLs.

**Cloudflare rewrites the email address in the docs.** Scrape Shield's email
obfuscation turns `integrations@manfaa.app` into a JS-decoded blob, so anyone
reading without JavaScript — or copying from a text view — sees
`[email protected]`. It is the only byte that differs between the two hosts.
A dashboard setting, not a code change.

### Brand marks a superadmin can replace — DONE (2026-08-19)

The platform's logos were committed files — `default-logo.svg`,
`default-logo-dark.svg`, `mini-logo.svg` — duplicated across apps/web and
apps/merchant and changeable only by a deploy. They are now five uploads on
Settings › Appearance, beside the accent colour, because they are the same
decision: what the platform looks like.

FIVE slots, no more: `landscape_light`, `landscape_dark`, `square_light`,
`square_dark`, `favicon`. One set covers every surface.

The load-bearing design is that **`/api/brand/{slot}` always answers an
image** — the uploaded one when set, the packaged default from
`api/resources/brand/` otherwise. That guarantee is what lets three
frontends point an `<img>` at it with no fallback branch, no "has a brand
been set?" query and no loading state. Freshness is an ETag over the bytes,
not a cache-busting token a built frontend could not know: a replacement is
live on the next page load, with no rebuild of anything.

Shared `<BrandMark>` lives in `@manfaa/ui` — one component, both apps. Light
and dark are two `<img>` rather than a themed `src`, because a themed src
cannot resolve on first render and the logo would visibly swap after
hydration.

| Surface | Mark |
|---|---|
| manfaa.app header | landscape (was plain text) |
| manfaa.app/login | square — the card is 400px and a wordmark crowds it |
| manfaa.app/dashboard | landscape expanded, square collapsed (`default-logo`/`small-logo`) |
| merchant dashboard | same pair |
| merchant login · signup · landing · legal · pitch panel | landscape, with "Merchant" kept as the violet suffix |
| all three panels | favicon via `metadata.icons`; the `app/favicon.ico` file convention removed so there is one source |

SVG is refused for every slot. It is a document that may carry script and
would be served from our own origin on manfaa.app, merchant. and admin.
alike — the widest stored-XSS surface the platform has. The packaged
defaults are SVG because we wrote them; the response carries `nosniff` and a
`default-src 'none'; sandbox` CSP regardless.

Owner's call on the merchant auth lockup (asked, 2026-08-19): the brand mark
REPLACES the M + "Manfaa", and "Merchant" stays. Adding the mark above the
existing lockup — the literal reading — rendered "Manfaa" twice.

**Sizing, and two hazards the round uncovered.** The marks were first sized
at 22–26px, which was right for the old SVG wordmark (93.2×22, aspect
4.24:1) and wrong for an uploaded PNG: the owner's is 3.05:1 with up to 16%
transparent padding, so at the same HEIGHT roughly a third less ink landed on
screen and it read as small. Now sized against the real containers (70px
sidebar header, 64px public header) with `object-contain` and a `max-w` cap,
so an unusual aspect cannot blow out the layout.

The sidebar then drew BOTH marks at once. `demo1.css` hides `.small-logo`
while the sidebar is expanded, but BrandMark put its own `dark:block` on the
SAME element and won the cascade. The collapse class now goes on a wrapper
span and the light/dark classes stay on the images — different elements,
nothing to fight over. Verified by computed style, collapsed and expanded.

**A foreign service worker was running on manfaa.app.** Cache
`storefront-shell-v1`, never in this repository — from another tenant's
storefront, registered during an earlier port/vhost mixup (the 3000–3002
hazard). It intercepted every request on the origin and cached FAILURES, so a
404 served during the seconds a Next.js service restarts was stored forever
and the app could not load its own chunks: "This page couldn't load … until
hard refresh". curl never reproduced it — curl has no service worker; it was
found with `caches.keys()` in the page, holding a stored 404 for the exact
chunk. `apps/*/public/sw.js` is now a kill switch that unregisters itself and
empties every cache; confirmed clearing a live poisoned browser (workers 0,
caches [], chunk 200). Also fixed alongside: `.next` was root-owned from
building as root while the services run as www-data (EACCES on every
prerender-cache write, 218 in three days).

Suite: `tests/Feature/Brand/BrandAssetTest.php`, 13 tests — every slot serves
before any upload, no session needed (these are the login-page logos),
replace deletes the file it replaced, a row whose file is gone still serves a
logo, reset returns to the default, ordinary admins refused, SVG refused,
non-image refused, undersized refused, ico accepted for the favicon, and the
ETag changing on upload.

`api/routes/web.php` now registers nothing and `resources/views/` is empty.
ExampleTest was rewritten from Laravel's scaffolding assertion (`/` is 200)
to the decision it replaced: the API host has no page of its own.

### The apps draw the real logo now — DONE (2026-08-19)

Both apps drew their own brand: `ManfaaMark`, a hand-painted CustomPaint "M",
plus the word "Manfaa" as a Text beside it. That was a THIRD drawing of the
logo after the web panels and the launcher icon, and it meant replacing the
brand needed a code change and a store release.

Headers now render the platform's landscape mark, light or dark by theme;
boot and sign-in render the square one. `ManfaaWordmark` and
`MerchantWordmark` became that image — the merchant lockup keeping "Merchant"
as its violet suffix, the same shape the web panel settled on.

Three layers, in the order a widget gets them:

1. **Bundled** — `assets/brand/*.png` in each APK (~248KB). A first launch on
   a plane still has a logo, and no header is ever blank.
2. **Cached** — bytes on disk from the last run, read into memory by
   `BrandAssetCache.load()` BEFORE `runApp`, so a widget reads them
   synchronously and the header never flashes the bundled mark then swaps.
3. **Fetched** — refreshed from `/api/brand/{slot}` when the cache is older
   than 24 hours.

The refresh is off the critical path: `load()` returns as soon as the disk
cache is in memory and the network call runs after, bumping a `ValueNotifier`
only when bytes actually changed. Boot never waits on a logo, and a dead
network costs nothing. `BrandRefreshOnResume` asks again when the app returns
to the foreground — phones are pocketed, not restarted, so boot alone would
leave a week-old app showing a week-old mark.

Refusals that matter: a response whose content-type is not `image/` is
dropped (a captive portal answering 200 with a login page would otherwise put
hotel wifi in the header), and a failed fetch keeps whatever the slot had.

Suite: `packages/manfaa_core/test/brand_assets_test.dart`, 10 tests — nothing
cached before first fetch, all four slots cached after, a second process
reading disk without touching the network, refetch once past its life, no
refetch while fresh, a replaced logo repainting, an unchanged one NOT
repainting, a failed network keeping the old mark, a non-image refused, and
every slot naming an asset both apps actually bundle.

**The customer golden harness was blind to images.** It had no precache step,
so `Image.asset` never decoded before the shot and every customer golden
painted an empty box where the logo belongs — invisible while the wordmark
was drawn text, obvious the moment it became an image. The merchant harness
has carried that step since its bank marks landed; the customer one now does
too. All 345 mobile tests green; goldens regenerated across both apps.

**Sizing, the same lesson as the web headers.** The marks were first placed
at `size * 1.25` — 27.5px — which suited the hand-painted mark they replaced
and not an uploaded logo carrying ~16% transparent padding, leaving ~23px of
ink beside a 40px avatar. The row's height is set by that avatar, so the mark
is now `size * 1.8` (39.6px) and sits level with it.

The merchant tablet rail was worse than small: it is 96px wide and a
landscape mark at 34px is already ~93px across, so it could not have grown
without overflowing. It uses the SQUARE mark now, exactly as the collapsed
web sidebar does.

**The /app page carried its own copy of the version.** The binaries were
replaced and the page still advertised v1.0.17 / v1.0.13, which reads exactly
like a deploy that did not happen — the numbers beside each download were
hand-typed and had to be remembered every release. `scripts/deploy-apks.sh`
now publishes both APKs, reads `versionName` and size back OUT of the file it
just published, rewrites the page from that, and purges Cloudflare. The page
can no longer claim a version that is not there.

Shipped: customer 1.0.18+19, merchant 1.0.14+15 — page, binaries and build
outputs verified identical after deploy.

### The admin nav follows the marketplace switch — DONE (2026-08-20)

With `marketplace_enabled` off, three admin screens could only ever answer
403 — their routes already carry `EnsureMarketplaceEnabled` — yet the nav
still offered them. They now hide with the switch:

| Nav item | Route |
|---|---|
| Merchant settlements | `/admin/merchant-settlements` |
| Marketplace KYB | `/admin/marketplace/kyb` |
| Order payments | `/admin/marketplace/payments` |

`marketplaceOnly` on a NavItem, mirroring the existing `superadminOnly`, fed
by `useMarketplaceEnabled()` (5-minute staleTime — a launch switch, not a
live value). Undefined while loading counts as ON, so the nav does not
flicker items away on every page load.

**Store reviews was on the owner's list and is deliberately NOT hidden.** It
is `StoreReviewController` — the superadmin approval queue for self-signed-up
stores — not a marketplace screen. Hiding it with the marketplace off would
have removed the queue that approves every merchant on the platform, cashback
included. Zones is likewise ungated server-side and stays.

The flag is compared with `Number(value) === 1` rather than `=== 1`: the
api-client's `PlatformSettingValueSchema` is `z.union([z.number().int(),
z.string()])`, so a string is contract-legal, and this box has already been
bitten once by a numeric arriving as a string through the Redis cache. Read
wrongly it would hide working menus silently — the kind of bug nobody
reports, because they assume the screen was removed on purpose.

### The bank's success shape — FIXED (2026-08-20)

The first real transfer through the tunnel moved MVR 200.00 and was filed as
`unrecognised_response`, leaving a PAID payout sitting in Needs review with no
bank reference (item 3, `MNF000003`, batch PB-20260819).

`TransferClient` accepted only `status === 'success'`. The upstream sends
`"success": true` with `"status": "completed"` — for plain profiles AND for
dual control. The success branch could therefore never match, on any profile;
faisanet4 was incidental. The 409 duplicate path carried the same invented
spelling, so a repeat of an already-paid transfer read as "a previous attempt
failed".

**The tests are why this survived to production.** Seventeen places faked
`['status' => 'success', 'trx_id' => …]` — a shape we invented and the bank
has never sent. Green suite, broken money path. Five tests now use the real
bodies verbatim; verified load-bearing by restoring the old rule, under which
four of them fail.

Dual control's trap, handled explicitly: a COMPLETED faisanet4 transfer
carries an `approval_id` exactly as a parked one does. Only `pending_approval`
separates them, so the parked test runs first and keys on that flag —
otherwise the fix itself would have parked paid transfers.

**Reconciled by re-sending** (owner's call), which is safe precisely because
the upstream is idempotent on `internal_ref`: it answered 409 with the payment
it had already made. `duplicate: true` in the new audit line is the proof no
second payment was made; the row adopted reference `805351758` and the batch
completed.

Two things that made that safe to interpret, both added here:

- **`Payout item answered by the bank`** — one log line per bank call carrying
  `duplicate`, the outcome and the reference. `wasDuplicate` existed on
  TransferResult and was recorded nowhere, so "adopted the existing payment"
  and "made a second one" were indistinguishable afterwards.
- **`applyPaid` clears `failure_reason` and `error_code`.** The reconciled row
  stayed `paid` while still reading "Needs review: the bank answered in a way
  we do not recognise", which would send somebody chasing a settled payment.

### Telling shops their customers were paid, and a flag that never arrived — DONE (2026-08-20)

**`customers_paid`.** A merchant settles cashback to the platform weeks
before the customers who earned it are paid, and until now the one party who
funded the money never learned it landed. When a payout run closes, each
store whose customers it reached gets one push: how many of their customers
were paid and how much.

Batch-level, hooked in `ItemResultService::conclude()` — the single point
where a pass closes, so both the API sweep and a marked-up sheet go through
it. Only `paid` items count: telling a shop their customers were paid when
the transfer bounced is worse than silence. Addressed to staff holding
`wallet.view`, since it is news about the shop's money.

**Push only.** `smsToMerchantContact()` defaults to TRUE, so it would have
texted every merchant on every payout run. Every other merchant moment is
something the shop must act on; this one is news with nothing to do about it,
and an SMS here is a recurring bill for that.

Four exhaustive match maps had to be completed for the new key — label,
description, push title, audience — which is the catalogue doing its job.

**The marketplace flag never reached a running app.** `configProvider` is a
plain FutureProvider, fetched once and cached for the process, so flipping
`marketplace_enabled` did nothing until the app was killed. (The ETag cache
is in-memory, so a true restart was always fine — the staleness was resume.)
`BrandRefreshOnResume` became the general `OnAppResume`, and both apps now
re-read the brand marks AND invalidate `configProvider` when they come back
to the foreground. A conditional GET that usually answers 304.

Shipped: customer 1.0.19+20, merchant 1.0.15+16.

### Webhooks a merchant can own — DONE (2026-08-22)

**The gap.** Webhook endpoints were per POS vendor, registered by an admin,
and the dispatcher found them through the credential's vendor. A token the
merchant issued themselves has no vendor, so a store with its own shop, an
ERP, or a plugin could never be told its rate changed or a sale was reversed
— and nothing on *Settings › API access* let them set one up. That made a
plugin with "no manual webhook setup" impossible.

**Merchant-owned endpoints.** `webhook_endpoints` now has EITHER
`pos_vendor_id` OR `merchant_id` (a CHECK enforces exactly one owner), plus
`api_credential_id`, `label` and `created_by_merchant_user_id`. The
dispatcher's entitlement query is the union: the merchant's own endpoints, or
the vendors behind its live credentials. One `MerchantEndpointService` holds
the rules for both doors — cap of 5 active per store, the same SSRF URL guard
as the admin registry, `whsec_` secret shown exactly once.

Two doors: the panel (owner-only, mirrors the credential wizard — add,
remove, **Send test**, with a once-only secret handover) and
`GET/POST /v1/webhooks`, `DELETE /v1/webhooks/{id}` under the new
`webhooks:manage` ability. A credential sees and removes only endpoints it
registered itself; re-registering the same URL from the same credential
replaces rather than stacks; **revoking the credential switches its
endpoints off** (`CredentialService::revoke` → `deactivateForCredential`),
while panel-made endpoints outlive every token. `webhook.test` is a real
signed delivery to one endpoint (6/min per endpoint), not subscribable, never
sent to vendor endpoints.

Credentials issued here are the same `ApiCredential` rows the admin creates
for POS vendors — same abilities, same revocation — the only difference is
`pos_vendor_id` is null, which is exactly why the old dispatcher could not
reach them.

**Docs.** The guide's §6 is split into *Webhooks — POS vendors* and
*Webhooks — Merchants* with a comparison table and a `/v1/webhooks` example;
the spec gained both as tags (every event is listed under both, `webhook.test`
under Merchants only), the three operations, `MerchantWebhookEndpoint` /
`WebhookRegisterRequest` schemas, the ability row and the
`webhook_not_found` / `endpoint_cap_reached` codes.

**Build trap found on deploy.** The `manfaa` service user's `$HOME` is the
repo root, and Next 16 refuses to infer a workspace root that contains
`$HOME` — it fell back to `apps/merchant`, could not resolve the pnpm-linked
`next`, and the aborted build had already wiped `.next`, so the merchant
panel was down for ~5 minutes. `turbopack.root` is now pinned to the
monorepo in all three `apps/*/next.config.mjs`.

15 new tests (`tests/Feature/Webhooks/MerchantEndpointTest.php`); full API
suite 1745 green. Migration applied, queue restarted, panel rebuilt, docs
rebuilt and verified live.

### Connect with Manfaa for software that cannot keep a secret — DONE (2026-08-22)

**Why.** The owner chose OAuth-first for the WooCommerce plugin
(PLAN-woocommerce §2.1). Platform connect was built for a confidential
platform — one server, a secret, a fixed callback list. A plugin is the
opposite: one codebase on thousands of stores, each with its own callback,
none able to hide a secret from the shop owner who can read every file.

**Public clients.** `pos_vendors.public_client`: no secret (rotate → 409),
no callback list, PKCE the only proof. The plugin sends its own callback;
`assertRedirect` runs it through the webhook SSRF guard (https, public
host, no fragment, ≤255); the consent screen shows *"This will connect
shop.example.mv — if that is not your website, press Deny"*; the exact URL
is bound into the code as before. A public client that SENDS a secret is
refused `invalid_client` — that caller is misconfigured or is not the
plugin. The grant records `api_credentials.connected_from`
(`https://shop.example.mv`); "re-authorising replaces" became per origin,
so a merchant with two WooCommerce stores keeps both, and the panel's
credential row says which store each one is.

**Companions.** `GET /v1/me` (store, the token's real abilities,
`connected_from`, rate summary — what a plugin's *Test connection* reads);
`GET /v1/transactions/{id}` (own merchant only, either writing ability —
what `409 duplicate_invoice` adoption fetches); `origin: online_link` on
`POST /v1/transactions` (code-keyed only; phone-keyed stays `api_phone`,
because that value marks *how* the customer was matched).

**Sandbox dropped** (owner): the non-existent `sandbox.api.manfaa.app` is
gone from the spec's servers, the guide's environment table, the panel's
guide card and `integration.ts`; the spec's *Sandbox* section is now
*Testing*, and the guide's §1 is "the fixture set the examples use".

`php artisan manfaa:register-woocommerce-client` seeded the one public
client on production — **`mfa_gewk290rpqxqol48uais1cqs`**, the id the
plugin ships with. 16 new tests; full suite green; docs live.

### Manfaa Cashback for WooCommerce — BUILT (2026-08-22), owner's live pass pending

The plugin the owner asked for, at `plugins/woocommerce/manfaa-cashback/`
(plan and decisions in PLAN-woocommerce.md). A buyer enters their Manfaa
code on the cart or at checkout — a real inner block on the Cart and
Checkout Blocks, a PHP panel on the classic pages — sees the estimated
cashback in the totals, and the sale is posted when the order reaches the
status the merchant chose (Completed by default), reversed on cancel,
full refund or trash. **Connect with Manfaa** is the public-client OAuth
flow shipped in the previous entry: no key is ever copied, and the plugin
registers its own webhook afterwards.

Settings are a top-level menu: connection (Connect, token fallback, test,
sync), pricing (general rate or per-category mapping with the first-in-
synced-list rule and orphan flags), the awarding policy (items after
discounts, ex- or inc-GST), cart/checkout and display options, posting
status and reversal policy, invoice prefix. Orders carry a Manfaa column
and a metabox with Retry and Refresh status; a daily sweep refreshes
pending sales.

Money: 2-dp-then-laari, per-bucket ceiling rounding identical to the
server's, lines that always sum to `eligible_amount`. Posting: the body is
frozen at the status hook and re-sent byte-identical under a deterministic
`Idempotency-Key`; `409 duplicate_invoice` is adopted by reading the sale
back; the state table in PLAN-woocommerce §4 is implemented row for row.

42 PHPUnit tests green on both order datastores (HPOS caught a
double-reverse on full refunds — fixed). Published at
manfaa.app/app/woocommerce/ and linked from /app; guide §4.1 gained *The
WooCommerce plugin*. Awaiting the owner's pass on a real https store.

### The store's minimum reaches the marketplace, and a mislabelled ceiling — DONE (2026-08-22)

The owner asked what "Default validation window" and "New merchant
validation window" were, whether merchants set their own, and whether the
minimum eligible sale was enforced.

**Two settings, one wrong label.** `default_validation_window_days` is the
CEILING a store may raise its own window to (Merchant panel › Settings ›
Preferences validates `max:` it); `new_merchant_validation_window_days` is
what a new store starts on. The admin copy described the ceiling as the
thing applied to new merchants — the other setting's job. Relabelled
*Maximum validation window* with copy that says who sets what. Merchants
do set their own (0 … ceiling), and the minimum eligible sale too.

**The minimum was enforced everywhere except the marketplace.**
`CreditRecorder` zeroes any sale under `min_eligible_laari`
(`below_minimum`, still recorded) — the POS API, the panel's credit
screen, the WooCommerce plugin, amendments and claims all go through it.
`CartPricer` never looked at it: a MVR 20 basket at a MVR 50-minimum
store earned cashback online while the same sale at the till would not.
Now the per-shop items total is judged against the store's minimum at
pricing (cashback 0, `below_cashback_minimum` + `cashback_shortfall_laari`
on the subcart so the cart says *"Add MVR x more to earn cashback here"*
on web and in the app) and the minimum is FROZEN on the suborder
(`cashback_min_laari`) so a partial fulfilment that drops the supplied
items under it zeroes the cashback too — online exactly as at the till.

**Found on the way: re-pricing lost the category rates.** Checkout priced
each line with its override (excluded = 0, category rate, else standing)
but stored only the standing rate, so an amend re-priced every line at
the standing rate — an excluded category started paying the moment a shop
dropped a unit. Each line now freezes its own `cashback_rate_bp`;
recompute uses it (rows from before fall back to the suborder's rate).

3 new tests in `FulfilmentTest`; full suite 1764 green. Migration
applied, admin + web rebuilt, customer app 1.0.28+29 built for the hint.

### Partial refunds, and the push that closes the loop — DONE (2026-08-22)

WC3 of the WooCommerce plan. **`PATCH /v1/transactions/{id}`** lets an
integration reduce a pending sale to what the buyer kept — the same
`AmendmentService` the panel uses, re-pricing at the terms frozen on the
row, refusing a confirmed or backdated sale with the panel's own codes.
The plugin's new recommended partial-refund policy uses it: one frozen
body per refund, a refund that empties the order becomes a reversal, and
a sale that had already confirmed keeps its cashback with a note saying
so — a plugin should not quietly take a whole reward back over a small
return.

**`cashback_reversed`** (owner decision): the other end of
`cashback_earned`. Sent from `ReversalService` on both outcomes — in
place or credit memo, the customer's balance drops the same way — once
per sale, never for a sale that earned nothing, and push-only: a text
per reversal would be a recurring bill for telling someone money they had
not yet received is not coming. The reason is a phrase with its own
leading space so the template stays a sentence when it is empty.

Also in the plugin: the product badge ("Earn up to MVR x Manfaa
cashback", off by default) and a drafted Dhivehi translation with RTL
mirroring on a `dv` locale (needs the native reviewer, like the apps).

API suite 1773 green; plugin 46/46 on both datastores. Plugin 0.2.0
published; customer app 1.0.29 routes the new push.

### The plugin updates itself — DONE (2026-08-22)

WC4, the last WooCommerce round. `Support\Updater` reads
`manfaa.app/app/woocommerce/manifest.json` twice a day (cached; a failed
fetch is remembered for an hour so a broken CDN does not cost a request
per admin page), and when the version there is newer, WordPress's own
updater shows the row and installs the zip the manifest names — only a
`https://manfaa.app/` package is ever honoured. Same one-click, same
rollback, same *View details* as any other plugin. Version discipline is
the APKs': the build script refuses a zip whose header, constant and
readme disagree.

Proof was done for real: a 0.2.9 install on the dev store saw 0.3.0 in
the live manifest and upgraded through the WordPress upgrader from the
CDN-served zip. The guide's §8 gained the merchant's own go-live
checklist for the plugin (MVR, https, all five permissions,
`MANFAA_CASHBACK_KEY`, pricing mode, posting status, one end-to-end
order, where pending money shows).

Plugin 0.3.0 published; suite 50/50 on both datastores. WC0–WC4 done;
the owner's live pass and the Dhivehi review remain.

### The money reads stop re-pricing the board on every visit — DONE (2026-08-23)

Phase 2 of the dashboard speed work (phase 1 was client caches). A new
`MerchantMoneyCache`: every expensive money read — the home tallies, the
outstanding summary both dashboards poll, the settle-ALL preview — is
cached in Redis under a key embedding a PER-MERCHANT VERSION. Anything
that moves that merchant's money bumps the version (one INCR, deferred
to after commit), orphaning every cached read at once; the TTL (300s) is
only the reaper and bounds clock-driven drift (a row ageing across a
bucket boundary, a discount ageing over midnight).

Bump points: every transaction state change and creation
(TransitionService — the §5 choke point), settlement submit / cancel /
reject / wallet-settle / add-line / remove-line (inside each builder
transaction, so afterCommit defers correctly), the credit-memo path
(no state change), and amendments. Explicit id-selection previews stay
live-priced — a quote is never cached; 422 "nothing to settle" is never
cached (the closure throws before the write). The home payload caches
only the merchant-scoped tallies, never the whole response, which varies
by the caller's permissions; the tally key embeds the business date so
midnight cuts over instantly.

The file's own rules, from this box's history: plain arrays only (never
a serialized model), (int)-cast the version (Redis hands numerics back
as strings), bumps after commit. Proven live: second read 0 queries,
bump → recompute. Suite 1779 green with 4 new cache tests.

### Free IsleBooks POS for merchants who move money through Manfaa — DONE (2026-08-23)

The partner programme (owner): a merchant that keeps its cashback rate at
1.00%+ for the WHOLE month, has no overdue settlements, and puts MVR
200,000 of earning sales — or MVR 5,000 of cashback — through Manfaa gets
that month's IsleBooks invoice waived.

Only what EARNED counts (owner: "doesn't earn for us"): excluded-category
lines, below-minimum sales and reversals contribute nothing; marketplace
orders do. Months are calendar months in business time, evaluated after
close (scheduler on the 3rd, 06:00) so late refunds land in their month —
and there is no clawback, a later reversal just reduces the month it
happens in. First month needs no proration: IsleBooks' first month is
already free. The programme self-polices — faking volume costs real
platform fees, faking cashback pays real customers.

`PosWaiverEvaluator` writes one auditable row per merchant-month
(`pos_waiver_evaluations`, re-runnable). IsleBooks' invoice job reads
`GET /v1/connect/waivers?month=` (client-credential Basic, its own
merchants only, lazy-evaluates missing rows) and applies a 100% discount
line — the policy lives in Manfaa alone. `GET /v1/merchants/me/pos-waiver`
(rates:read) and the panel's `GET /api/merchant/pos-waiver`
(settlements.view) feed the dashboard's "Free IsleBooks POS" card: last
month's verdict and this month's progress on whichever track is closer,
with rate and overdue check marks — the visible nudge to route more
sales through Manfaa.

A qualified month tells the shop itself: `pos_waiver_earned` goes to
staff with settlements.view — push AND SMS, per the every-merchant-
moment-texts rule (2026-08-18) — sent by the evaluator once per
merchant-month (`notified_at` stamp survives re-runs; a month that
later re-evaluates unqualified keeps its stamp — news is not unsent).
Body names the track that cleared: "{{amount}} {{track}} through
Manfaa" ("in sales" / "in cashback").

9 new tests; suite 1788 green. First real run: 2026-07, 2 merchants, 0
qualified. IsleBooks side shipped 2026-08-23 (their `ManfaaWaiverService`
+ `generateInvoice`: monthly renewals zeroed as their own visible line,
before credit; fails open to full price; annuals never waived — commit
`ea77a273` in their repo, MANFAA_TASKS.md §M8). Deferred: the app-side
card (Dart, rides the next APK).

### The referral round — DONE (2026-08-23)

Customer referrals (owner spec, §12 Phase 4 pulled forward): the
referrer's own `customer_code` IS the referral code, typed once at
signup (optional, immutable, unknown/inactive codes silently ignored —
signup never fails over a referral). When the referred customer's
validated spend — SUM(eligible_laari) over payable_unfunded/confirmed/
paid, reversals never — reaches the threshold, the REFERRER is paid
instantly to their wallet (`WalletService::credit type 'referral'`,
idempotent per referred customer, ever; no time limit, no clawback).
Trigger: afterCommit hook in `TransitionService` on entry to
payable_unfunded/confirmed, O(1) for never-referred customers; daily
06:30 `manfaa:award-referral-bonuses` safety net (also picks up a
lowered threshold). Push `referral_bonus_earned` (push-only — per-
channel SMS switches still don't exist; friend's name MASKED in push
and wallet ledger, same as the friends list). Superadmin-only settings
(403 for plain admins — `SUPERADMIN_KEYS` in PlatformSettingsController):
`referral_enabled` 1, `referral_reward_laari` 5000, `referral_spend_
threshold_laari` 1000000.

Surfaces: `GET /api/customer/referrals` on both doors (web cookie +
mobile sanctum) — config, code, share_url, stats, friends list with
masked names and spend CAPPED at the threshold (a referrer never learns
more than the milestone). apps/web `/referrals` + nav, signup `?ref=`
prefill, `/r/{code}` short link (RELATIVE Location — nginx doesn't
rewrite Host, an absolute redirect stranded users on localhost:3300).
Admin: three rows on Settings → Platform. Flutter: Refer & earn screen
under Profile, signup field, push tap-route to wallet, 'Referral bonus'
wallet label, en+dv throughout — shipped as customer v1.0.32+33.

Review round (3 lenses, 9 findings, 1 blocker): the ledger posting
(`Postings::platformFundedReward`) CR'd liability 2100 which nothing
relieves — first bonus would have broken the 02:00 Reconciler forever;
the bonus is now fully off-ledger like every other wallet credit
(posting kept, unreferenced, for a future deliberate design). Masking
leaks fixed, any-admin config gated to superadmin, web badge no longer
shows today's config as historical fact. Suite 1807 green.

DECIDED (owner, 2026-08-23, same day): tightened to confirmed/paid only
— a bonus is minted only by spend a merchant actually FUNDED; the
transition hook narrowed to entry-to-confirmed. Also same day: typed
referral-code field added to WEB signup (link-prefill alone left the
told-out-loud code appless), and a one-line "Refer a friend — earn
MVR X" promo on both dashboards (web `ReferralPromo`, app
`ReferralPromoCard` — self-padding so the hidden state is pixel-
identical, goldens unchanged; visible state pinned by widget tests).
Customer app v1.0.33+34 shipped with the promo.

### The self-referral device guard — DONE (2026-08-24)

Owner: device collision = ZERO reward, no tolerance, no review queue.
`customer_devices` stores HMAC-SHA256(APP_KEY, raw) only — never raw
ids. Sightings: app sends `X-Device-Id` (Android SSAID; iOS `ifv:`) +
`X-Device-Ref` (`kc:` Keychain UUID that survives reinstall — review
caught that sending only one leaves the reinstall hole open) on every
authed request; web signup/login plants+records the `mfa_did` cookie
(400d, unencrypted-by-design random ref); every customer push
registration also records `fcm:<token>` — a durable same-install trail
that works retroactively on builds that predate the headers.
`ReferralService::award` stamps `referral_disqualified_at` +
`device_collision` inside the locked transaction: no credit, no push,
permanent, sweep skips it; paid bonuses never clawed. Both referral
pages carry the one-line prohibition; disqualified friends render a
muted badge, spend hidden. Cap: 30 distinct hashes/customer.
12+ new tests; suite 1825 green. Shipped: customer v1.0.36+37.

KNOWN-OPEN PATHS (accepted client-supplied-identity limits, owner
sign-off pending — recorded in DeviceIdentity's docblock): fresh
browser signup with an app-only referrer; stripped headers on a rooted
device; second Android work-profile/clone (different SSAID); evidence
appearing only after payout (no clawback by design). Backstops: the
economics (bonus needs MVR 10,000 of merchant-FUNDED spend) and the
payout-account collision check, which remains AVAILABLE TO BUILD if
farming appears. OWNER TO-DO: ToS wording for the prohibition.

Same day, merchant app polish (v1.0.23+24): bottom-bar active pill
violet like the customer app's (rail already was); pill gained 10dp
horizontal clearance + FittedBox so the stadium curve stops clipping
long middle labels ("Transactions"); single-tint card washes —
Outstanding lavender, Credit customer mint, Wallet blue (light 40%,
dark 13%, `cardWash()`); merchant suite 140 green, goldens redone.

### Merchant wallet: top-up + auto-settlement — DONE (2026-08-24)

Reverses the "not pre-funding" doctrine (§ table above; owner decision).
Owner calls: hourly batching per merchant, oldest-first settle-what-fits,
toggle default ON, minimum top-up a superadmin setting (MVR 100).

Top-up = the settlement receipt flow reused: `POST /api/merchant/wallet/
top-ups` (multipart amount ≥ `wallet_top_up_min_laari`, platform bank
account, slip, optional bank_ref; `wallet.top_up` permission, approved
stores, throttle 5/min, ≤3 pending) → `wallet_top_ups` row (mirrors
settlement_payments) → `WalletTopUpVerifier` polls bank history
(ReadWalletTopUpReceipt + PollWalletTopUp, same bounded loop) → on match
`WalletTopUps::credit()` — THE one crediting seam (auto + admin) →
`WalletFunding::recordTopUp` + journal + `wallet_top_up_received`.
Admin queue `/api/admin/wallet-top-ups` (list/match/reject/slip) with
its own panel screen. Review-driven hardening: top-ups match on
REFERENCE or SLIP OCR ONLY — the payer-name rungs were an oracle once
the merchant chooses the amount (blocker); `BankCreditClaim::spent()`
now also sees hand-matched settlement refs and wallet movements, with a
`pg_advisory_xact_lock` per bank row across all three verifiers, so one
transfer can never fund two things. `TransferEvidence` extracted so the
verifiers share one rule set. Wallet payload carries the platform bank
accounts (a store with nothing payable can still top up), pending AND
recently-rejected top-ups with reasons.

Auto-settle: `merchants.auto_settle_from_wallet` (default true; writing
it needs `wallet.settle`, not just preferences.update). `manfaa:auto-
settle-wallets` hourly at :10 → `WalletAutoSettler`: per approved
merchant with balance and payable_unfunded lines, greedy oldest-first
by undiscounted (cashback+fee+gst) ≤ balance, one
`createAndSettleFromWallet` as Actor::system (WalletFunding now takes
an Actor) — prompt discount applies, leftover stays; one
`wallet_auto_settled` push+SMS, and none when §7 credits netted the
batch to zero. Surfaces: panel wallet page (Top up dialog reusing the
wizard's bank pieces, pending list, toggle), admin queue + platform
setting, merchant app wallet screen + top-up screen (v1.0.25+26).
Suite 1894 green; three lenses, 10 findings, all fixed.

### Superadmin reports + Excel export — DONE (2026-08-24)

admin.manfaa.app › Reports (superadmin only): Cashback, Payouts,
Earnings; period presets computed in BUSINESS time, merchant filter,
summary tiles, 50-row preview, Export .xlsx. `GET /api/admin/reports/
{cashback|payouts|earnings}` (+ `/export`, audit row per export), from/
to ≤ 366 days, row cap → 422 `report_too_large`. PhpSpreadsheet
workbook: Summary first, real numeric money cells (laari/100,
'#,##0.00'), real dates, frozen header, autofilter, SUM totals.

Cashback/Transactions carries fee, GST, discount, forgiveness and an
EXACT per-transaction `collected`: the settlement's STAMPED
discount_rate_bp (live rate is 10%; the 8 first batches were stamped
5% — never the current setting), GST relief at the same rate, rounding
remainder + forgiveness on the batch's last allocated line, so Σ over a
settlement == amount_received to the laari (verified on all 8 live
settlements). Payouts periodise on the transaction_events 'paid' row;
four sheets + Wallet withdrawals; Batches keyed on id — the reference
is only partially unique (cancelled rebuilds reuse it; live data had
3× PB-20260816) and keying on it double-counted. Earnings is LEDGER-
derived (4100 fee revenue − prompt discounts − forgiveness = net fee
income; 2300 GST as a liability; 5100 referral rewards and 5900 bad
debt as expenses) with accrued-vs-collected by funding method, By
account, By merchant, Postings.

Review (3 lenses, 13 findings, 0 blockers, all fixed): draft/cancelled
batches were counted as owed; a QUOTED-but-withheld prompt discount
was reported as granted (now spreads min(posted, quoted)); By merchant
ignored §7 adjustment reversals; collected fees now net of discount;
export cache-control private/no-store; temp workbook cleanup on the
failure path; export ceiling measured against the 256M pool and split
from the preview cap. Suite 1940+ green.

### Queue (updated 2026-08-17) — the mobile programme

The mobile API round is DONE and reviewed (PLAN-mobile-api.md: M1–M5, two
adversarial passes, 31 defects fixed, suite 1166, committed on branch
`mobile-api`). The Customer App foundation R0 is DONE (PLAN-customer-app.md:
scaffold, identity, credentials, tokens/theme, en+dv/RTL, api client, shell,
Home real against /customer/home; analyze clean, tests 17/17). FCM is live
(`PUSH_DRIVER=fcm`, credential verified against Google).

**Remaining, in order. One workflow round each, with the adversarial gate.**

#### Customer app (details per round in PLAN-customer-app.md §5)
- [x] **R1 — OTP auth end to end** (2026-08-17; reviewed, 5 blockers fixed). Backend first: mobile OTP endpoints that
      MINT TOKENS (reuse OtpService + the per-phone/per-IP limiter
      conventions; security-reviewed like M1 — it mints bearer credentials;
      SIM-swap mitigation: payout-account changes demand a fresh OTP). Then
      the app's onboarding flow replaces the debug password path.
- [x] **R2 — Home polish** (2026-08-17). Brightness bump on the fullscreen QR
      (screen_brightness plugin), nearest-stores hook in the empty state,
      per-store pending clock ("Store A confirms within N days"), payout nag
      wired to its screen, skeleton loading.
- [x] **R3 — Activity** (2026-08-17). Earned|Paid segmented history, cursor infinite
      scroll, payout detail, localized reason keys.
- [x] **R4 — Discover + store page** (2026-08-17; map view still key-blocked). Shelves, featured offers (two-kinds
      banner rule), boosted "8% — usually 5%", categories, search, Near you
      list. Map view BLOCKED on Maps-key restriction (below).
- [x] **R5 — Profile** (2026-08-17): devices, push, and the payout-account
      screen with its fresh-OTP gate all done. (Notifications on/off → R6.)
- [~] **R6 — Polish** (2026-08-17): dark-mode fix, notifications toggle,
      accessibility, locale×theme test matrix DONE. OWED — I cannot do these:
      native Thaana review (app strings + templates + push titles together)
      and pixel goldens (deferred to the macOS build box).
- [ ] **R7 — Release.** Icons/splash, store listings en+dv, Codemagic
      signing both stores, version-gate values, privacy policy URL + Play
      data-safety forms.

#### Merchant app (after customer R5; scope agreed 2026-08-16: the TILL, not
the panel)
- [ ] Register `mv.manfaa.merchant` (Android+iOS) in Firebase project
      manfaa-6e1b4 — same project, same server key; only client configs are new.
- [ ] `mobile/merchant` scaffold on `manfaa_core`/`manfaa_ui` (they exist for
      this). Navigation built FROM `/merchant/me` permissions — a cashier
      with only credits.create sees one screen, nothing greyed out.
- [ ] Till round: scan customer QR (camera) + type-in fallback, credit with
      idempotency keys, backdated acknowledgement UX, today's credits, void.
- [ ] Settlement round: outstanding buckets, Settle All, exact amount,
      receipt from camera, rejection reasons; settlement pushes land here.
- [ ] Offline credit queue draining with stored Idempotency-Keys (the API
      side is built and tested; this is the client half).

#### Backend follow-ups (small, from the reviews)
- [x] Inline `throttle:n,1` shared one bucket per caller — fixed 2026-08-23
      by `App\Http\Middleware\ThrottlePerRoute` over the 'throttle' alias:
      buckets are per method|domain|uri (and the authed key carries the
      MODEL CLASS, closing the Customer-7/MerchantUser-7 collision). All
      70+ inline declarations inherited it, no route edits; named limiters
      untouched. Gotcha: `withoutMiddleware(ThrottleRequests::class)` in
      tests no longer matches — disable BOTH classes. 3 tests pin it.
- [ ] Per-channel `active` switch on notification templates: push is free
      per message, SMS is not — `cashback_earned` should be able to push
      without starting an SMS bill.
- [ ] "Contact the store" path for the customer app (claims stay OFF —
      decision 2026-08-14; the app needs the merchant's phone from the
      store page, nothing more).
- [ ] Consolidate the suspended-store refusal onto
      EnsureMerchantApproved:trading (pre-existing item).
- [ ] Merge branch `mobile-api` → main once the owner has read the diff
      (the working tree IS being served; the merge is bookkeeping, but
      main must not drift behind production).

#### Operational (not code — owner or console actions)
- [ ] **Rotate the Firebase service-account key** (passed through a chat
      transcript). Swap = one .env line + `systemctl restart manfaa-queue`.
- [ ] **APNs .p8** into Firebase Cloud Messaging — until then iOS registers
      and receives NOTHING, silently. Needs the Apple Developer account.
- [ ] Apple Developer + Play Console accounts; Codemagic hooked to the repo
      with signing + PROJECT_BUILD_NUMBER.
- [ ] Restrict the Google Maps key (shared with avasprint, unrestricted —
      long-flagged) and mint per-platform mobile keys before app R4.
- [ ] Push live-fire test on a real handset once a build installs.
- [ ] Older standing items: rotate admin/test-merchant/Cloudflare/origin
      credentials; MsgOwl sender id; SPF/DKIM/DMARC; real BML bank file
      format; category artwork ×9; backup audit for the OTHER databases on
      this host (archive_mode is still off).


### Open decisions awaiting the owner
1. **Category-terms resolution instant** (documented above §1): product-category
   overrides resolve at submission time from a mutable row while every other
   rate resolves at occurred_at. Add effective-dated history, or accept?
2. **Promo × category precedence** — flagged assumption above; confirm or change.
3. **Prompt-discount and frozen lines**: "everything outstanding" currently
   counts lines frozen on an earlier unpaid batch, so a merchant with one
   stale batch open cannot earn the discount until it clears. Faithful to the
   rule as written; the softer reading would ignore frozen lines.
4. **Defaulter path back** after a 90-day write-off: manual reinstatement only
   (as-built) or automatic once cleared?

### Operational to-dos (not code)
- Rotate: admin + test-merchant passwords, Cloudflare token, origin key
  (all appeared in a chat transcript). Confirm Cloudflare SSL = Full (strict).
- Replace the MsgOwl sender id (currently IsleBooks') with a Manfaa id.
- Native review of the Dhivehi machine-draft locales (customer + merchant apps;
  apps/admin is intentionally English-only — no i18n runtime). Now covers the
  rebuilt hero copy and the demo panel strings as well.
- Upload real category artwork for the nine curated store categories; until
  then each falls back to its curated glyph.
- SPF/DKIM/DMARC before any outbound email.
- Bank bulk payout file format (§14) — the xlsx transfer sheet is the interim
  answer and unblocks real runs; swap in the true BML format when it is known.
- **Nightly DB backup now exists** (`/usr/local/bin/manfaa-db-backup.sh`, cron
  03:25, 14-day retention in `/var/backups/manfaa`). It was written on
  2026-08-16 after a mis-scoped `migrate:fresh` destroyed the live database —
  the SECOND database lost that way on this host (ScorePath, 2026-07-26).
  Postgres still runs `archive_mode = off`, so there is no recovery between
  nightly dumps, and other databases on this box may still have no backup at
  all. Worth an audit.
- Restrict the Google Maps API key by HTTP referrer. It is shared with
  avasprint and is currently unrestricted — confirmed by a Static Maps call
  succeeding under a `manfaa.app` referrer — so it can be lifted from page
  source and billed to that project.
- Consolidate the suspended-store refusal onto EnsureMerchantApproved:trading
  (CredentialController currently emits the same code independently).
- Config caches stay OFF on this box (tests + production share the checkout;
  TestCase hard-fails if one exists). Re-evaluate at scale.

## 14. Open items

### Blocking Phase 0

| Item | Note |
|---|---|
| **Domain structure** | Sanctum stateful auth requires all apps under one parent domain — e.g. `rewards.mv`, `merchant.rewards.mv`, `admin.rewards.mv`, `api.rewards.mv`. Needed for `SANCTUM_STATEFUL_DOMAINS` and the cookie domain. Placeholder until confirmed, centralised in one config. |
| **Merchant onboarding model** | Self-serve signup, or admin-created? Since we do the integrations ourselves, **admin-created is assumed** — our team onboards, sets the rate, issues credentials. Removes a signup/verification flow from `apps/merchant` in v1. |

### Blocking Phase 1

| Item | Note |
|---|---|
| **Bank bulk payout mechanism and file format** | Hard constraint on the payout batch schema. Establish what the bank actually offers before designing it — guessing means rework. |
| **GST treatment of the platform fee** | Applies or not; quoted inclusive or exclusive. `fee_gst_laari` exists regardless, but the settlement total and the merchant tax invoice depend on the answer. |
| **GST reversal on write-off** | If a merchant never pays, does recognised GST reverse? One line in the §8 write-off posting. Accountant question. |

### Deferrable, but decide before the phase that needs them

| Item | Needed by | Note |
|---|---|---|
| Admin role model | Phase 1 | Dual payout approval requires at least two distinct admin roles. Simple role enum is sufficient; needs to exist before the approval gate. |
| SMS OTP provider | Phase 3 | Customer phone verification. Local bulk SMS (Ooredoo / Dhiraagu) vs. an international provider. |
| Branch-level rates | Phase 3 | Base rate is merchant-level in the current model; promotions carry branch scope. Confirm branches never need their own standing rate. |
| Customer KYC threshold for payout | Phase 3 | `kyc_status` column exists with no policy attached. |
| Per-transaction and per-day caps on the standing rate | Phase 3 | Promotional caps are specified; standing-rate caps are not. |
| Cold-start merchant wedge | Before launch | Density beats breadth. Target the POS vendor with the deepest penetration in Malé/Hulhumalé F&B and groceries — one integration, many merchants. |
