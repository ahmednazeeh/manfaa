# Manfaa Vendor Integration Guide

For POS developers integrating a till or web shop in the Maldives with the
Manfaa cashback platform. This guide walks the whole integration against the
**sandbox**; the OpenAPI contract in [`openapi.yaml`](openapi.yaml) is the
authoritative reference for every field and code.

| Environment | Base URL |
|---|---|
| Sandbox | `https://sandbox.api.manfaa.app/api` |
| Production | `https://api.manfaa.app/api` |

Everything below uses the sandbox. Nothing you do there mints real cashback.

**The model in one paragraph:** your till **POSTs each eligible sale** to us
with the customer's 6-digit code; we compute cashback at the merchant's rate,
run the settlement clock, and pay the customer. There is no polling — tills
sit behind NAT, so all traffic is inbound to us, plus signed webhooks from us
to you so your cached rate is never stale.

**Money:** every amount is an **integer count of laari** (1/100 rufiyaa).
MVR 1,180.00 is `118000`. Never send a float — a fractional JSON number is
rejected. Responses carry both the integer (`cashback_laari: 2360`) and a
display string (`cashback_mvr: "23.60"`) for the receipt printer.

**Rates:** every rate on this API is a **2-decimal percent string** — 2% is
`"2.00"`, the platform fee `"0.75"`, the same idiom as `cashback_mvr`. Basis
points are how the platform stores and computes rates internally; they
**never appear in a request or a response**, so there is no `rate_bp` or
`fee_bp` field anywhere. Sending a rate, you may use a string (`"2"`,
`"2.5"`, `"2.50"`) or a JSON number (`2`, `2.5`) with at most two decimals —
a string is safer, since it cannot be reshaped by your JSON library's float
handling. Read one by splitting on the `.` (or into a decimal type); never
parse it to a binary float and multiply.

*(This is a clean break, not a versioned migration: no vendor is integrated
yet, so `rate_bp`/`fee_bp` are simply gone rather than deprecated. If you
built against an earlier draft of this guide, rename the fields — the
numbers are the same, expressed as percent.)*

---

## 1. Sandbox fixtures

Run `php artisan manfaa:sandbox` on the sandbox host (our team runs it for
you at onboarding — the output below is what you receive). It is safe to
re-run and prints the same token every time.

| Fixture | Value |
|---|---|
| Merchant | **Sandbox Store** (`sandbox-store`) — minimum eligible sale `5000` laari (MVR 50), validation window 3 days |
| Rate | `"2.00"` now, with a **scheduled decrease** to `"1.50"` at the next 00:00 UTC+5 — so `GET /v1/merchants/me/rate` always shows a `pending_decrease` |
| Branch | Sandbox Branch (id printed by the command) |
| POS vendor | Sandbox POS |
| Token | Printed by the command. Carries **all four abilities**. Sandbox-only. |

Published test customers:

| Code | Name | Phone | Behaviour |
|---|---|---|---|
| `111111` | Aisha Mohamed | +960 711-1111 | Earns normally. Lookup: `valid: true`, `"Ais*** Moh***"` |
| `222222` | Hassan Ibrahim | +960 722-2222 | Earns normally |
| `333333` | Mariyam Saeed | +960 733-3333 | **Suspended** — lookup answers `200` with `valid: false`; use it to test your blocked-customer handling |

Export the printed token for the examples below:

```sh
export MANFAA_TOKEN="<token printed by manfaa:sandbox>"
export MANFAA_API="https://sandbox.api.manfaa.app/api"
```

---

## 2. Authentication

Every request carries a bearer token:

```
Authorization: Bearer <token>
```

Tokens are **one per merchant per POS vendor** and independently revocable —
a merchant switching vendors never invalidates your tokens for other
merchants. Store the token server-side or in the till's secure storage; it
is shown exactly once (sandbox excepted).

There are two ways to get one, and they produce the same credential:

- **Manfaa issues it at onboarding** — the usual path when our team does the
  integration work for a physical store.
- **The merchant issues it themselves** — the store's account OWNER opens
  Settings › API access in the merchant panel, names you as the integration
  partner, ticks the permissions you need, and hands you the token. This is
  the fastest route when the store already has a POS provider: no ticket, no
  waiting on us.

Ask the merchant for exactly the abilities you use, no more — the token
cannot be widened later, and a narrower one is a shorter conversation when
something goes wrong. If a token leaks, the merchant revokes it on that same
screen and issues a replacement; revocation takes effect on the very next
request and never touches their other credentials.

Each token carries **abilities**, and every endpoint requires exactly one:

| Ability | Grants |
|---|---|
| `transactions:write` | `POST /v1/transactions` |
| `transactions:reverse` | `POST /v1/transactions/{id}/reverse` |
| `rates:read` | `GET /v1/merchants/me/rate` |
| `customers:lookup` | `GET /v1/customers/lookup` |

Auth failures are the one place you will see a plain body instead of the
error envelope (§5) — they come from the auth layer, before the API proper:

- Missing/invalid/revoked token → `401` `{"message": "Unauthenticated."}`
- Valid token, missing ability → `403` `{"message": "Invalid ability provided."}`

Tokens are merchant-scoped. Every resource you touch belongs to the token's
merchant; another merchant's transaction id answers `404`, deliberately
indistinguishable from a nonexistent one.

**Rate limit:** 120 requests/minute per token. Exceeding it returns `429`
`{"message": "Too Many Attempts."}` with a `Retry-After` header — wait and
retry (with the same `Idempotency-Key` if it was a write).

---

## 3. Idempotency — required on every write

Both write endpoints require an `Idempotency-Key` header. Generate a **fresh
UUID v4 per logical operation** (per sale, per reversal) and persist it with
the sale in your local queue *before* sending, so a crash-and-restart retries
with the same key.

| Situation | Result |
|---|---|
| No header | `422` `idempotency_key_required` |
| Fresh key | Processed normally |
| Same key, **same** body | `200` replay of the original result, byte-identical body, with header `Idempotency-Replay: true` |
| Same key, **different** body | `422` `idempotency_key_reuse_mismatch` — never reuse a key across different sales; fix your key generation |
| Same key, first request still in flight | `409` `idempotency_key_in_flight` — transient; retry with the same key |

Keys are scoped to your merchant credential. Replays of transaction creation
are permanent; replays of reversals are guaranteed for at least 7 days.

**The retry rule:** on a network failure, a timeout, or any `5xx`, retry with
the **same** key — that is what makes retries safe. Every documented `4xx` is
terminal: fix the request (and use a *fresh* key for the corrected resend,
because the corrected body will not match the old key) or stop.

Independently of idempotency keys, `(merchant, invoice_no)` is unique: the
same sale arriving as two *distinct* requests (different keys — e.g. two
tills, or a re-queued job that regenerated its key) is rejected
`409 duplicate_invoice` with the existing transaction id in
`error.meta.transaction_id`. Treat that as "already recorded", not a failure.

---

## 4. Endpoints

### 4.1 Record a sale — `POST /v1/transactions`

Ability: `transactions:write`. Send the sale as soon as it completes:

```sh
curl -s -X POST $MANFAA_API/v1/transactions \
  -H "Authorization: Bearer $MANFAA_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_no": "INV-1001",
    "customer_ref": "111111",
    "eligible_amount": 118000,
    "sale_amount": 125000,
    "occurred_at": "2026-08-14T11:04:22+05:00"
  }'
```

- `eligible_amount` is **your merchant's own eligible total** in laari, taken
  at face value — how it is derived (GST, service charge, exclusions) is the
  merchant's agreement with Manfaa, never recomputed by us.
- `sale_amount` is optional, reference-only, never used in computation.
- `occurred_at` is **optional — omit it and we record the sale as of now**,
  which is what a till posting a sale as it rings it up means anyway. When
  you do send it, two shapes are accepted:
  - **ISO 8601 with an offset** — `2026-08-14T11:04:22+05:00`,
    `2026-08-14T06:04:22Z`, `2026-08-14T11:04:22+0500`. Read exactly as sent.
  - **A plain wall clock with no offset** — `2026-08-14 11:04:22` or
    `2026-08-14T11:04:22` — read as **Maldives time (UTC+5)**, so it means
    the instant `2026-08-14T06:04:22Z`. If your till writes `date('Y-m-d
    H:i:s')` off its own clock, just send that.

  Anything else (`15/08/2026 11:04`, a date with no time, seconds omitted)
  is `422 validation_failed`. The cashback rate applied is the merchant's
  rate effective *at this instant*.
- `cashback_rate_percent` (optional) overrides the rate **for this sale
  only** — see the box below.
- `branch_id` (optional) must be one of the merchant's branch ids from
  onboarding.

**`201` — created, cashback accrued** (2.00% of 1,180.00, rounded up per
line, customer-favourable):

```json
{
  "status": "created",
  "reason": null,
  "transaction": {
    "id": 84521,
    "origin": "pos",
    "invoice_no": "INV-1001",
    "state": "awaiting_validation",
    "reason_code": null,
    "backdated": false,
    "currency": "MVR",
    "eligible_laari": 118000,
    "sale_laari": 125000,
    "cashback_rate_percent": "2.00",
    "platform_fee_percent": "0.75",
    "cashback_laari": 2360,
    "cashback_mvr": "23.60",
    "fee_laari": 885,
    "fee_mvr": "8.85",
    "fee_gst_laari": 0,
    "occurred_at": "2026-08-14T06:04:22+00:00",
    "received_at": "2026-08-14T06:04:23+00:00"
  }
}
```

**Store `transaction.id` against the invoice** — a reversal addresses it.

`cashback_rate_percent` and `platform_fee_percent` are the terms **frozen**
on this row at `occurred_at`. They never change afterwards, even if the
merchant reprices tomorrow — and a reversal reverses the stored integers,
never a recomputation.

Print the conditional wording on the receipt: the customer *will earn*
MVR 23.60 once the merchant confirms — nothing is promised before then.

**`200` — recorded, no cashback.** This endpoint never rejects a well-formed
sale for business reasons; the two no-cashback outcomes come back as `200`
with a distinct `status` so the cashier always sees something truthful:

*Below the merchant's minimum* (sandbox: `eligible_amount` under `5000`):

```sh
curl -s -X POST $MANFAA_API/v1/transactions \
  -H "Authorization: Bearer $MANFAA_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"invoice_no":"INV-1002","customer_ref":"111111","eligible_amount":4999,"occurred_at":"2026-08-14T11:20:00+05:00"}'
```

```json
{
  "status": "below_minimum",
  "reason": "below_minimum",
  "transaction": {
    "id": 84524,
    "state": "reversed",
    "reason_code": "below_minimum",
    "eligible_laari": 4999,
    "cashback_rate_percent": "2.00",
    "platform_fee_percent": "0.75",
    "cashback_laari": 0,
    "cashback_mvr": "0.00",
    "fee_laari": 0,
    "...": "other transaction fields as above"
  }
}
```

Not an error — do not retry. Show "no cashback on this sale" at the till.

*Merchant suspended* (settlement overdue past day 16 — you will have
received the `merchant.suspended` webhook first). The sandbox merchant is
active, so this one is not reproducible against `sandbox-store`; the shape
is:

```json
{
  "status": "recorded_ineligible",
  "reason": "merchant_suspended",
  "transaction": {
    "id": 84523,
    "state": "reversed",
    "reason_code": "merchant_suspended",
    "cashback_laari": 0,
    "fee_laari": 0,
    "cashback_rate_percent": "2.00",
    "platform_fee_percent": "0.75",
    "...": "other transaction fields as above"
  }
}
```

Keep POSTing while suspended — sales are still recorded — but stop
advertising cashback until the `merchant.reinstated` webhook. Accrual resumes
automatically; already-recorded ineligible sales are **not** retro-credited.

**`201` with `reason: "backdated_final"`** — a sale older than the merchant's
validation window + 3 days is **backdated**. It is accepted, but it skips the
refund window entirely: it arrives in `state: "payable_unfunded"` with
`"backdated": true`, the merchant's 15-day settlement clock starts at the
moment you POST it (not at `occurred_at`), and **it can never be reversed
through this API** — `POST /v1/transactions/{id}/reverse` answers
`409 backdated_irreversible` in every state, and no credit adjustment is
created either. Corrections are admin adjustments, arranged with Manfaa.

Flush your offline queue promptly: a queue that drains a week late turns
ordinary sales into irreversible ones. Branch on the `backdated` boolean, not
on `reason_code` — later transitions rewrite `reason_code`, but `backdated`
is permanent.

#### Per-sale rate override — `cashback_rate_percent`

A sale may carry its own rate: a staff discount day, a launch hour, a
goodwill boost for one customer. Send `cashback_rate_percent` on the create
request and it prices **that sale only** — the merchant's standing rate is
untouched, and nothing about the next sale changes.

```sh
curl -s -X POST $MANFAA_API/v1/transactions \
  -H "Authorization: Bearer $MANFAA_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_no": "INV-1006",
    "customer_ref": "111111",
    "eligible_amount": 118000,
    "cashback_rate_percent": "5.00"
  }'
```

With the sandbox merchant's standing 2.00%, that sale is credited at 5.00%:

| | Rate | Worked integer | Result |
|---|---|---|---:|
| Cashback | `"5.00"` (500 bp internally) | ceil(118000 × 500 / 10000) | **5900** laari |
| Platform fee | `"1.00"` — the fee tier of the **applied** rate | ceil(118000 × 100 / 10000) | **1180** laari |

```json
{
  "status": "created",
  "reason": null,
  "transaction": {
    "id": 84526,
    "state": "awaiting_validation",
    "eligible_laari": 118000,
    "cashback_rate_percent": "5.00",
    "platform_fee_percent": "1.00",
    "cashback_laari": 5900,
    "cashback_mvr": "59.00",
    "fee_laari": 1180,
    "fee_mvr": "11.80",
    "...": "other transaction fields as above"
  }
}
```

Two rules, and both are refusals rather than silent adjustments:

- **An override may only raise the rate.** Below the rate the sale would
  otherwise earn — the standing rate, or a live promotion covering this sale
  — it is `422 rate_below_advertised`, with the applicable rate in
  `error.meta.advertised_cashback_rate_percent`. The advertised rate is a
  public promise to the customer; a till must not be able to quietly pay
  less than the storefront says.

  Because it only ever *raises*, an override **equal** to the rate the sale
  already earns is accepted and changes nothing at all: the sale prices
  exactly as if you had not sent the field. That matters during a
  promotion. If you echo `active_promotion.cashback_rate_percent` from
  `GET /v1/merchants/me/rate` back into the sale, the promotion still
  prices it — including its `max_cashback_per_customer_laari` cap, which is
  part of the offer the store published. Only a value strictly above the
  promotion is a decision to pay more than the published offer, and that
  sale is then priced by your number alone: no promotion is stamped on it,
  and it consumes none of the customer's promotional headroom.

  ```json
  {
    "error": {
      "code": "rate_below_advertised",
      "message": "cashback_rate_percent 1.50% is below the 2.00% this sale already earns — an override may only raise the advertised rate.",
      "meta": { "advertised_cashback_rate_percent": "2.00" }
    }
  }
  ```

- **The platform must be able to price the fee for it.** A rate above the
  ceiling of the active fee schedule is `422 rate_not_priced`, with the
  ceiling in `error.meta.ceiling_percent` (sandbox: `"10.00"`). Nothing you
  can fix at the till — the merchant's plan has to be widened by Manfaa
  first.

Both refusals are terminal for that request: fix the value (or drop the
field) and resend with a **fresh** `Idempotency-Key`. Nothing was recorded.

They apply only to a sale that actually earns cashback. On a sale that
earns none either way — a **suspended** merchant (`200
recorded_ineligible`) or one **below the minimum** (`200 below_minimum`) —
the field is ignored rather than refused, and the sale is recorded exactly
as it would be without it, frozen at the store's standing terms with
`cashback_laari: 0`. Ingestion never stops for a rate that could not have
been applied: the till still gets its `200` and the cashier still sees the
truth.

**With `lines`:** the override becomes the rate of every line that would
otherwise price at the standing rate (the `category: null` bucket). Category
overrides and exclusions are untouched — an override never pays an excluded
category, and never overwrites the merchant's rate card — and a live
promotion still lifts any line it would pay more on. No line ever earns less
than it would have without the override.

**A merchant account that is not trading answers `403 forbidden_ability`** in
the error envelope, with the message *"This merchant account is not active on
the platform."* — every status except `active` and `suspended`: a closed
account, and a self-signed-up store that has not completed onboarding (still
in setup, awaiting review, or sent back for changes). Suspended is not one of
them: a suspended merchant's sales are still accepted, as
`200 recorded_ineligible` above. Stop sending and contact us — retrying
cannot succeed until the account is reinstated.

#### Online stores

Web checkouts use the **same endpoint, same contract** — nothing above
changes. Two ways to key the customer:

- **Customer code at checkout** — ask for the 6-digit code in your checkout
  flow and send it as `customer_ref`, exactly like a till.
- **Phone-keyed posting** — send the customer's Maldivian mobile as
  `customer_ref` instead: full `+960XXXXXXX`, or the 7-digit local form
  (mobiles start `7` or `9`; we normalise to `+960` E.164). Phone-keyed
  transactions record `origin: "api_phone"`; the reward still lands at
  Pending and confirms on settlement like any other sale. An unknown phone
  answers `422 customer_not_found`, exactly like an unknown code.

Mind the mistyped-phone risk: a one-digit slip is far more likely to be a
*real, currently-assigned* number than a mistyped code is to be a real code,
and it silently credits a stranger. Prefer a verified phone from your own
account records over free-typed entry, or confirm the masked name via
`GET /v1/customers/lookup` (§4.4) before posting. A later release will let
customers reject transactions they don't recognise — until then, send a
reversal if a customer reports a credit that isn't theirs.

#### Line-item pricing — optional `lines` for stores with category rates

Some stores price cashback **per product category**: certain categories are
excluded (earn nothing), others carry their own rate, and everything else
earns the standing rate. If the merchant you integrate uses categories,
split the eligible total into `lines`; if not — or if you simply don't send
`lines` — the whole `eligible_amount` earns the standing rate exactly as
before. **Sending `lines` is never required.**

Each line is `{category, amount_laari}`. `category` is one of the
merchant's active slugs from `GET /v1/merchants/me/product-categories`
(§4.5); `category: null` is the default "everything else" bucket. Rules:

- amounts are laari integers ≥ 1 and must sum to **exactly**
  `eligible_amount` → otherwise `422 lines_sum_mismatch`;
- each category (and the null default) at most once →
  `422 duplicate_category_line`;
- unknown slug → `422 unknown_category`; deactivated →
  `422 inactive_category`.

**Worked example.** Merchant standing rate `"5.00"`, category `veggies`
overridden to `"2.00"`, category `fruits` excluded. A MVR 1,000.00 basket
split 300.00 fruits + 250.00 veggies + 450.00 other:

```sh
curl -s -X POST $MANFAA_API/v1/transactions \
  -H "Authorization: Bearer $MANFAA_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_no": "INV-2107",
    "customer_ref": "111111",
    "eligible_amount": 100000,
    "occurred_at": "2026-08-15T10:30:00+05:00",
    "lines": [
      { "category": "fruits",  "amount_laari": 30000 },
      { "category": "veggies", "amount_laari": 25000 },
      { "category": null,      "amount_laari": 45000 }
    ]
  }'
```

Every line rounds **up** independently (§4 ceiling), then the transaction
totals are the *sums of the line integers* — never recomputed on the whole
amount:

| Line | Amount | Rate | Cashback | Fee tier | Fee |
|---|---:|---|---:|---|---:|
| fruits (excluded) | 30000 | `"0.00"` | 0 | `"0.00"` | 0 |
| veggies | 25000 | `"2.00"` | ceil(25000×200/10000) = **500** | `"0.75"` | ceil(25000×75/10000) = **188** |
| default | 45000 | `"5.00"` | ceil(45000×500/10000) = **2250** | `"1.00"` | ceil(45000×100/10000) = **450** |
| **Totals** | 100000 | | **2750** | | **638** |

(The arithmetic is shown in basis points because that is exactly how the
platform computes it: `"2.00"` is 200 bp, and `ceil(amount × bp / 10000)` is
the §4 rule. On the wire you only ever see the percent string.)

```json
{
  "status": "created",
  "reason": null,
  "transaction": {
    "id": 84525,
    "state": "awaiting_validation",
    "eligible_laari": 100000,
    "cashback_rate_percent": "5.00",
    "platform_fee_percent": "1.00",
    "cashback_laari": 2750,
    "cashback_mvr": "27.50",
    "fee_laari": 638,
    "fee_mvr": "6.38",
    "lines": [
      { "category": "fruits",  "category_name_en": "Fruits",  "amount_laari": 30000, "cashback_rate_percent": "0.00", "platform_fee_percent": "0.00", "cashback_laari": 0,    "fee_laari": 0,   "priced_by": "excluded", "sort": 0 },
      { "category": "veggies", "category_name_en": "Veggies", "amount_laari": 25000, "cashback_rate_percent": "2.00", "platform_fee_percent": "0.75", "cashback_laari": 500,  "fee_laari": 188, "priced_by": "category", "sort": 1 },
      { "category": null,      "category_name_en": null,      "amount_laari": 45000, "cashback_rate_percent": "5.00", "platform_fee_percent": "1.00", "cashback_laari": 2250, "fee_laari": 450, "priced_by": "standing", "sort": 2 }
    ],
    "...": "other transaction fields as above"
  }
}
```

Notes:

- On a lined transaction, `cashback_rate_percent`/`platform_fee_percent` at
  the transaction level are the **base-rate snapshot** (the standing rate,
  or your per-sale override when you sent one); per-line truth is in `lines`
  — each line carries the rate it actually earned, and `priced_by` says why.
- During a live promotion, every **non-excluded** line earns
  max(promotion rate, its own rate) — `priced_by: "promotion"` on the
  lifted lines. Excluded categories stay excluded even during promotions.
  A promotion's minimum purchase is judged against the whole
  `eligible_amount`, not per line. Per-customer promotion caps clip only
  the promotion-priced lines, in submitted order.
- Reversals work unchanged — reverse the transaction by id; the stored
  totals reverse as one.

### 4.2 Reverse a sale — `POST /v1/transactions/{id}/reverse`

Ability: `transactions:reverse`.

> **Contractual obligation, not best-effort.** Under the vendor integration
> agreement you MUST send a reversal for every refunded, voided, or
> duplicated sale you previously POSTed. Without it, a refunded sale keeps
> its cashback, the merchant is billed for it, and every till error runs in
> the merchant's favour. Wire this into your refund/void flow, with the same
> persist-then-send queue you use for sales.

```sh
curl -s -X POST $MANFAA_API/v1/transactions/84521/reverse \
  -H "Authorization: Bearer $MANFAA_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason": "customer_refund", "occurred_at": "2026-08-15T09:30:00+05:00"}'
```

`reason` is one of `customer_refund`, `till_void`, `duplicate`, `other`.
`occurred_at` follows the same rule as on creation — **optional** (omit it
for now), ISO 8601 with an offset, or a plain wall clock read as Maldives
time.
Reversal always reverses the **stored** integers — never recomputed, even if
the rate has since changed.

The `200` body's `outcome` tells you what actually happened:

**`outcome: "reversed"`** — the transaction was still pending; it moved to
`state: "reversed"` and the accrual was undone in full:

```json
{
  "outcome": "reversed",
  "cause": null,
  "adjustment": null,
  "transaction": { "id": 84521, "state": "reversed", "reason_code": "customer_refund", "...": "..." }
}
```

**`outcome: "adjustment_created"`** — the transaction could no longer be
reversed in place: its line is locked in a settlement batch that has left
draft (`cause: "locked_in_settlement"`), or the reward is already confirmed
or paid (`cause: "already_confirmed"`). The transaction is untouched; a
**credit adjustment** was created and will be netted against the merchant's
next settlement batch:

```json
{
  "outcome": "adjustment_created",
  "cause": "locked_in_settlement",
  "adjustment": {
    "id": 311,
    "transaction_id": 84521,
    "amount_laari": -3245,
    "amount_mvr": "-32.45",
    "currency": "MVR",
    "reason_code": "customer_refund",
    "created_at": "2026-08-15T04:30:00+00:00"
  },
  "transaction": { "id": 84521, "state": "payable_unfunded", "...": "..." }
}
```

**This is a success, not a failure** — record `adjustment.id` and move on.
The distinct `cause` codes exist precisely so your till can tell "reversed"
from "credited on the next bill" without guessing. Reversing the same
transaction again returns the **same** adjustment, never a second one.

Failure cases: a terminal transaction (`reversed`, `written_off`) answers
`409 invalid_state` with the current state in `error.meta.state` (note: a
retry with the *same* `Idempotency-Key` replays the original `200` instead —
`invalid_state` only fires on a new key); an unknown or cross-merchant id
answers `404 transaction_not_found`.

**Backdated sales cannot be reversed at all.** A transaction created outside
the merchant's validation window (`"backdated": true`, `reason_code:
"backdated_final"` — see §4.1) answers:

```json
{
  "error": {
    "code": "backdated_irreversible",
    "message": "Transaction 84522 was credited as a backdated sale and cannot be reversed by the merchant — an admin adjustment is required.",
    "meta": { "state": "payable_unfunded" }
  }
}
```

This fires in **every** state — pending, confirmed or paid — and creates no
credit adjustment. It is deliberately a different code from `invalid_state`:
`invalid_state` describes where the transaction is right now, while
`backdated_irreversible` will never succeed on any retry with any key. Do not
queue it; surface it to a human, who arranges an adjustment with Manfaa.

### 4.3 Rate for the till display — `GET /v1/merchants/me/rate`

Ability: `rates:read`.

```sh
curl -s $MANFAA_API/v1/merchants/me/rate \
  -H "Authorization: Bearer $MANFAA_TOKEN"
```

Sandbox answer (the scheduled decrease is part of the fixtures):

```json
{
  "cashback_rate_percent": "2.00",
  "platform_fee_percent": "0.75",
  "currency": "MVR",
  "min_eligible_laari": 5000,
  "pending_decrease": {
    "cashback_rate_percent": "1.50",
    "platform_fee_percent": "0.50",
    "effective_at": "2026-08-15T00:00:00+05:00"
  }
}
```

While a published promotion is live and beating the standing rate, an
`active_promotion` block appears alongside — display that as the offer:

```json
{
  "cashback_rate_percent": "2.00",
  "platform_fee_percent": "0.75",
  "currency": "MVR",
  "min_eligible_laari": 5000,
  "pending_decrease": null,
  "active_promotion": {
    "cashback_rate_percent": "5.00",
    "platform_fee_percent": "1.00",
    "branch_id": null,
    "min_purchase_laari": 10000,
    "ends_at": "2026-08-20T00:00:00+05:00"
  }
}
```

What you display is **advisory** — the server always recomputes at
`occurred_at`. Rate *decreases* take effect only at 00:00 UTC+5 the next day
(that is what `pending_decrease` announces); increases apply immediately. A
stale cache can therefore only **under**-promise, never over-promise. Refresh
on the `merchant.rate_changed` webhook rather than polling. A merchant with
no configured rate answers `422 no_effective_rate`.

### 4.4 Confirm a customer — `GET /v1/customers/lookup`

Ability: `customers:lookup`. The customer shows a 6-digit code (digits or
QR); confirm the masked name **with the person at the till** before crediting
— phone numbers get recycled in the Maldives, and a typo credits a stranger.

```sh
curl -s "$MANFAA_API/v1/customers/lookup?ref=111111" \
  -H "Authorization: Bearer $MANFAA_TOKEN"
```

```json
{ "ref": "111111", "valid": true, "name": "Aisha Mohamed", "masked_name": "Ais*** Moh***" }
```

The mask keeps the first three characters of each name part — enough to
confirm, nothing more. No balance, phone number, or other customer data ever
crosses this API.

A known code that cannot currently earn (sandbox: `333333`) still answers
`200`, with `valid: false` — show the cashier "code exists but is blocked".
An unknown code answers `404 customer_not_found`; re-read it with the
customer (this is almost always a mistyped digit).

### 4.5 Product categories — `GET /v1/merchants/me/product-categories`

Ability: `rates:read`. The merchant's **active** product categories — the
allowed `lines[].category` values for line-item pricing (§4.1):

```sh
curl -s $MANFAA_API/v1/merchants/me/product-categories \
  -H "Authorization: Bearer $MANFAA_TOKEN"
```

```json
{
  "data": [
    { "category": "fruits",  "name_en": "Fruits",  "name_dv": "މޭވާ",     "mode": "excluded", "cashback_rate_percent": null },
    { "category": "veggies", "name_en": "Veggies", "name_dv": "ތަރުކާރީ", "mode": "rate",     "cashback_rate_percent": "2.00" }
  ]
}
```

`category` is the exact string to submit. `mode: "excluded"` lines earn
nothing; `mode: "rate"` lines earn their `cashback_rate_percent` instead of
the standing rate (`null` exactly when the category is excluded);
anything else goes on the `category: null` default line. The merchant can
change this list at any time — refresh it when a POST answers
`unknown_category` or `inactive_category` rather than caching forever. A
merchant with no categories returns an empty `data` and you simply never
send `lines`.

---

## 5. Errors

Apart from the auth-layer `401`/`403` and throttling `429` (§2), every error
is the envelope:

```json
{ "error": { "code": "duplicate_invoice", "message": "…", "meta": { "transaction_id": 84521 } } }
```

Match on `error.code` — messages are human-readable and may change. `errors`
(per-field messages) appears only with `validation_failed`; `meta` carries
machine-readable context where noted.

The complete code registry (also in `openapi.yaml` → `MachineCode`):

| Code | Where | HTTP | Retry? |
|---|---|---|---|
| `unauthorized` | error envelope (auth edge cases; the usual 401 body is `{"message": "Unauthenticated."}`) | 401 | After fixing credentials |
| `forbidden_ability` | error envelope (merchant not active — closed, or never approved; the usual missing-ability 403 body is `{"message": "Invalid ability provided."}`) | 403 | After fixing token abilities; never while the merchant is not active |
| `validation_failed` | error envelope, with per-field `errors` | 422 | Fix request; resend with a **fresh** key |
| `idempotency_key_required` | error envelope — write sent without the header | 422 | Add the header and resend |
| `idempotency_key_reuse_mismatch` | error envelope — same key, different body | 422 | Never — fix key generation |
| `future_dated` | error envelope — `occurred_at` beyond 5-minute skew | 422 | Fix the till clock; resend with a fresh key |
| `customer_not_found` | error envelope | 422 (create) / 404 (lookup) | Re-confirm the code with the customer |
| `no_effective_rate` | error envelope | 422 | No — contact Manfaa |
| `rate_below_advertised` | error envelope — the `cashback_rate_percent` override is below the rate the sale already earns; advertised rate in `meta.advertised_cashback_rate_percent` | 422 | Raise the value or drop the field; fresh key |
| `rate_not_priced` | error envelope — the override is above the ceiling the platform prices; ceiling in `meta.ceiling_percent` | 422 | No — contact Manfaa |
| `unknown_category` | error envelope — `lines[].category` is not one of this merchant's slugs | 422 | Refresh §4.5, fix the slug; fresh key |
| `inactive_category` | error envelope — the category was deactivated | 422 | Refresh §4.5, resubmit; fresh key |
| `duplicate_category_line` | error envelope — a category (or the null default) appears twice | 422 | Merge the lines; fresh key |
| `lines_sum_mismatch` | error envelope — line amounts ≠ `eligible_amount` | 422 | Fix the split; fresh key |
| `duplicate_invoice` | error envelope; existing id in `meta.transaction_id` | 409 | Never — sale already recorded |
| `invalid_state` | error envelope; current state in `meta.state` | 409 | Never — terminal state |
| `backdated_irreversible` | error envelope; current state in `meta.state` | 409 | Never — admin adjustment only |
| `transaction_not_found` | error envelope | 404 | Never |
| `merchant_suspended` | `reason` in a `200 recorded_ineligible` body | — | n/a — recorded, no cashback |
| `below_minimum` | `reason` in a `200 below_minimum` body | — | n/a — recorded, zero cashback |
| `backdated_final` | `reason` in a `201` body, `state: payable_unfunded` | — | n/a — accepted, payable now, irreversible |
| `locked_in_settlement` | `cause` in a `200 adjustment_created` body | — | n/a — adjustment created |
| `already_confirmed` | `cause` in a `200 adjustment_created` body | — | n/a — adjustment created |

Transient coordination codes (e.g. `idempotency_key_in_flight`, `409`) are
not part of the registry: **anything not listed above — and any `5xx` — is
retryable with the same `Idempotency-Key`.**

---

## 6. Webhooks

We POST signed events to the HTTPS endpoint you register with us (one
endpoint per POS vendor, subscribed to the events you choose). At
registration you receive a signing secret (`whsec_…`) exactly once — store it
like a password.

| Event | When | Do |
|---|---|---|
| `merchant.rate_changed` | Rate changed (decreases announce the next-midnight boundary) | Refresh the till's cached rate |
| `merchant.suspended` | Automatic day-16 suspension | Stop advertising cashback; keep POSTing sales |
| `merchant.reinstated` | Merchant settled and was reinstated | Resume advertising |
| `transaction.reversed` | Any transaction reversed — including by Manfaa admins | Sync your local record; dedupe by `transaction_id` (your own reversals echo here too) |

Every delivery carries:

| Header | Meaning |
|---|---|
| `X-Manfaa-Signature` | Lowercase hex `HMAC-SHA256(secret, raw_body)` |
| `X-Manfaa-Timestamp` | Unix seconds of this delivery **attempt** (fresh per retry, not signed) |
| `X-Manfaa-Event` | The event name, for routing before you parse |

### Verifying the signature

Compute the HMAC over the **raw request body bytes exactly as received** —
before parsing, never over a re-serialisation (your JSON library will not
reproduce our key order) — and compare in constant time.

**Worked example.** Endpoint secret:

```
whsec_sandboxSECRETsandboxSECRETsandboxSECRET000000000
```

Raw body (one line, 250 bytes, no trailing newline):

```
{"id":"evt_01J5A8Z0T2N9GQK4WMB3XVRD6H","data":{"merchant_id":12,"effective_at":"2026-08-16T00:00:00+05:00","platform_fee_percent":"0.50","cashback_rate_percent":"1.50","previous_platform_fee_percent":"0.75","previous_cashback_rate_percent":"2.00"},"type":"merchant.rate_changed","created_at":"2026-08-15T16:05:11+05:00"}
```

Expected `X-Manfaa-Signature`:

```
be5a5fe344884c02a4e0cc21fe169052d0305dac2fdaf95c25dd397c2d7f6636
```

Reproduce it on your machine:

```sh
printf '%s' '{"id":"evt_01J5A8Z0T2N9GQK4WMB3XVRD6H","data":{"merchant_id":12,"effective_at":"2026-08-16T00:00:00+05:00","platform_fee_percent":"0.50","cashback_rate_percent":"1.50","previous_platform_fee_percent":"0.75","previous_cashback_rate_percent":"2.00"},"type":"merchant.rate_changed","created_at":"2026-08-15T16:05:11+05:00"}' \
  | openssl dgst -sha256 -hmac 'whsec_sandboxSECRETsandboxSECRETsandboxSECRET000000000'
```

PHP:

```php
$computed = hash_hmac('sha256', $rawBody, $secret);          // $rawBody = file_get_contents('php://input')
$valid    = hash_equals($computed, $signatureHeader);
```

Node.js:

```js
const crypto = require('crypto');
const computed = crypto.createHmac('sha256', secret).update(rawBody).digest('hex'); // rawBody: Buffer, pre-parse
const valid = crypto.timingSafeEqual(Buffer.from(computed), Buffer.from(signatureHeader));
```

If verification passes, parse the JSON and **deduplicate by the event `id`**
(`evt_` + ULID) — delivery is at-least-once and may arrive out of order.
`created_at` inside the signed body is the event's authoritative time; the
timestamp header is delivery telemetry only.

Acknowledge with any `2xx` within **10 seconds**. Do the work after
acknowledging, not before — a slow handler looks like a failure and triggers
a retry.

---

## 7. Retry expectations

**Your retries to us (writes):**

1. Persist the sale + its `Idempotency-Key` locally *before* the first send.
2. On network failure / timeout / any `5xx` / `429` / `idempotency_key_in_flight`:
   retry with the **same key**, backing off (e.g. 1 s, 5 s, 30 s, then every
   few minutes until connectivity returns). The replay mechanics make this
   safe indefinitely.
3. On any documented `4xx`: stop. It is terminal — surface it, fix it.
4. Sales sent late still work (the rate is resolved at `occurred_at`, not at
   receipt), but anything older than the validation window + 3 days lands in
   manual review — drain your offline queue promptly.

**Our retries to you (webhooks):** a delivery that does not get a `2xx`
within 10 seconds is retried with backoff at ~1 min, 5 min, 30 min, 2 h,
8 h and 24 h after the first attempt (6 retries over ~35 hours). Retried
deliveries carry the identical body and signature with a fresh
`X-Manfaa-Timestamp`. After the final failure the event is parked and
surfaced to Manfaa operations — never silently dropped — but do not rely on
that: monitor your endpoint's availability.

---

## 8. Go-live checklist

- [ ] Production token held per merchant (from us, or from the merchant's own Settings › API access), stored server-side, never in a client bundle
- [ ] Fresh UUID per sale, persisted before send; same key reused on retry
- [ ] `occurred_at` is omitted (meaning now), carries an explicit offset, or is a plain Maldives wall clock — and the till clock is NTP-synced
- [ ] `transaction.id` stored against the invoice for reversals
- [ ] **Reversal sent for every refund/void/duplicate** (contractual — §4.2)
- [ ] `below_minimum` / `recorded_ineligible` handled as recorded-no-cashback, not errors
- [ ] `adjustment_created` handled as success, adjustment id recorded
- [ ] Customer name confirmed at the till before crediting
- [ ] Webhook signature verified over raw bytes, events deduped by `id`
- [ ] Rate cache refreshed on `merchant.rate_changed`, advertising stopped on `merchant.suspended`

Questions: **integrations@manfaa.app**.
