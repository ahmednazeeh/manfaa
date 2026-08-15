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
| Merchant wallet | A settlement *method* (alternative to bank transfer), not pre-funding, not a risk product. |
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
manfaa.app / merchant. / admin. / api. — **1005 tests, 21,164 assertions green**,
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

Curated image banners at the top of /discover (admin.manfaa.app › Settings ›
Featured offers), ready for the app to reuse. An offer carries **artwork,
words and a schedule and nothing else**: the percentage, logo and category on
the rendered banner are read live from the store, so a banner can never
advertise a rate the shop has moved off, and an offer for a suspended store
never reaches the storefront. Headline, blurb and badge each take a Thaana
twin and the card mirrors under RTL.

Artwork uploads to a private disk and is served from
`/api/store-offers/{id}/image?v=…` with a content hash, raster only. The admin
list states WHY each offer is or is not visible — live / switched off / needs
artwork / scheduled / ended / store not trading — because "saved but
invisible" is the normal state of a new offer and an admin should not have to
guess which. There is no delete; deactivation retires a campaign and keeps it
on the record.

### Queue: EMPTY. Next work needs a product decision — see below.

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
- Bank bulk payout file format (§14) — blocks real payout runs.
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
