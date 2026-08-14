# Manfaa Vendor Integration Guide

For POS developers integrating a till or web shop in the Maldives with the
Manfaa cashback platform. This guide walks the whole integration against the
**sandbox**; the OpenAPI contract in [`openapi.yaml`](openapi.yaml) is the
authoritative reference for every field and code.

| Environment | Base URL |
|---|---|
| Sandbox | `https://sandbox.api.manfaa.mv/api` |
| Production | `https://api.manfaa.mv/api` |

Everything below uses the sandbox. Nothing you do there mints real cashback.

**The model in one paragraph:** your till **POSTs each eligible sale** to us
with the customer's 6-digit code; we compute cashback at the merchant's rate,
run the settlement clock, and pay the customer. There is no polling — tills
sit behind NAT, so all traffic is inbound to us, plus signed webhooks from us
to you so your cached rate is never stale.

**Money:** every amount is an **integer count of laari** (1/100 rufiyaa).
MVR 1,180.00 is `118000`. Never send a float — a fractional JSON number is
rejected. Rates are integer basis points: 2% is `200`. Responses carry both
the integer (`cashback_laari: 2360`) and a display string
(`cashback_mvr: "23.60"`) for the receipt printer.

---

## 1. Sandbox fixtures

Run `php artisan manfaa:sandbox` on the sandbox host (our team runs it for
you at onboarding — the output below is what you receive). It is safe to
re-run and prints the same token every time.

| Fixture | Value |
|---|---|
| Merchant | **Sandbox Store** (`sandbox-store`) — minimum eligible sale `5000` laari (MVR 50), validation window 3 days |
| Rate | `200` bp (2.00%) now, with a **scheduled decrease** to `150` bp at the next 00:00 UTC+5 — so `GET /v1/merchants/me/rate` always shows a `pending_decrease` |
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
export MANFAA_API="https://sandbox.api.manfaa.mv/api"
```

---

## 2. Authentication

Every request carries a bearer token:

```
Authorization: Bearer <token>
```

Tokens are issued by Manfaa at onboarding, **one per merchant per POS
vendor**, and are independently revocable — a merchant switching vendors
never invalidates your tokens for other merchants. Store the token
server-side or in the till's secure storage; it is shown to you exactly once
(sandbox excepted).

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
- `occurred_at` must carry an **explicit UTC offset** (`+05:00`, or `Z`).
  Malé local time without an offset is rejected `validation_failed`. The
  cashback rate applied is the merchant's rate effective *at this instant*.
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
    "currency": "MVR",
    "eligible_laari": 118000,
    "sale_laari": 125000,
    "rate_bp": 200,
    "fee_bp": 75,
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
    "rate_bp": 200,
    "fee_bp": 75,
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
    "rate_bp": 200,
    "fee_bp": 75,
    "...": "other transaction fields as above"
  }
}
```

Keep POSTing while suspended — sales are still recorded — but stop
advertising cashback until the `merchant.reinstated` webhook. Accrual resumes
automatically; already-recorded ineligible sales are **not** retro-credited.

**`201` with `reason: "stale_timestamp"`** — a sale older than the merchant's
validation window + 3 days is accepted but arrives in `state: "on_hold"`,
routed to manual review instead of a live settlement batch. Flush your
offline queue promptly.

A closed (not merely suspended) merchant account answers
`403 forbidden_ability` in the error envelope — stop sending and contact us.

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

### 4.3 Rate for the till display — `GET /v1/merchants/me/rate`

Ability: `rates:read`.

```sh
curl -s $MANFAA_API/v1/merchants/me/rate \
  -H "Authorization: Bearer $MANFAA_TOKEN"
```

Sandbox answer (the scheduled decrease is part of the fixtures):

```json
{
  "rate_bp": 200,
  "fee_bp": 75,
  "currency": "MVR",
  "min_eligible_laari": 5000,
  "pending_decrease": {
    "rate_bp": 150,
    "fee_bp": 50,
    "effective_at": "2026-08-15T00:00:00+05:00"
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
{ "ref": "111111", "valid": true, "masked_name": "Ais*** Moh***" }
```

The mask keeps the first three characters of each name part — enough to
confirm, nothing more. No balance, phone number, or other customer data ever
crosses this API.

A known code that cannot currently earn (sandbox: `333333`) still answers
`200`, with `valid: false` — show the cashier "code exists but is blocked".
An unknown code answers `404 customer_not_found`; re-read it with the
customer (this is almost always a mistyped digit).

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
| `forbidden_ability` | error envelope (closed merchant; the usual missing-ability 403 body is `{"message": "Invalid ability provided."}`) | 403 | After fixing token abilities |
| `validation_failed` | error envelope, with per-field `errors` | 422 | Fix request; resend with a **fresh** key |
| `idempotency_key_required` | error envelope — write sent without the header | 422 | Add the header and resend |
| `idempotency_key_reuse_mismatch` | error envelope — same key, different body | 422 | Never — fix key generation |
| `future_dated` | error envelope — `occurred_at` beyond 5-minute skew | 422 | Fix the till clock; resend with a fresh key |
| `customer_not_found` | error envelope | 422 (create) / 404 (lookup) | Re-confirm the code with the customer |
| `no_effective_rate` | error envelope | 422 | No — contact Manfaa |
| `duplicate_invoice` | error envelope; existing id in `meta.transaction_id` | 409 | Never — sale already recorded |
| `invalid_state` | error envelope; current state in `meta.state` | 409 | Never — terminal state |
| `transaction_not_found` | error envelope | 404 | Never |
| `merchant_suspended` | `reason` in a `200 recorded_ineligible` body | — | n/a — recorded, no cashback |
| `below_minimum` | `reason` in a `200 below_minimum` body | — | n/a — recorded, zero cashback |
| `stale_timestamp` | `reason` in a `201` body, `state: on_hold` | — | n/a — accepted, routed to review |
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
{"id":"evt_01J5A8Z0T2N9GQK4WMB3XVRD6H","data":{"fee_bp":50,"rate_bp":150,"merchant_id":12,"effective_at":"2026-08-16T00:00:00+05:00","previous_fee_bp":75,"previous_rate_bp":200},"type":"merchant.rate_changed","created_at":"2026-08-15T16:05:11+05:00"}
```

Expected `X-Manfaa-Signature`:

```
be5a5fe344884c02a4e0cc21fe169052d0305dac2fdaf95c25dd397c2d7f6636
```

Reproduce it on your machine:

```sh
printf '%s' '{"id":"evt_01J5A8Z0T2N9GQK4WMB3XVRD6H","data":{"fee_bp":50,"rate_bp":150,"merchant_id":12,"effective_at":"2026-08-16T00:00:00+05:00","previous_fee_bp":75,"previous_rate_bp":200},"type":"merchant.rate_changed","created_at":"2026-08-15T16:05:11+05:00"}' \
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

- [ ] Fresh UUID per sale, persisted before send; same key reused on retry
- [ ] `occurred_at` always carries the UTC offset; till clock NTP-synced
- [ ] `transaction.id` stored against the invoice for reversals
- [ ] **Reversal sent for every refund/void/duplicate** (contractual — §4.2)
- [ ] `below_minimum` / `recorded_ineligible` handled as recorded-no-cashback, not errors
- [ ] `adjustment_created` handled as success, adjustment id recorded
- [ ] Customer masked name confirmed at the till before crediting
- [ ] Webhook signature verified over raw bytes, events deduped by `id`
- [ ] Rate cache refreshed on `merchant.rate_changed`, advertising stopped on `merchant.suspended`

Questions: **integrations@manfaa.mv**.
