# Manfaa Mobile API Guide

For the two Flutter apps — **Customer** and **Merchant**. The POS vendor API
(`/api/v1`) is a different surface with a different contract; see
`integration-guide.md` for that one and do not mix them.

Base URL: `https://manfaa.app/api/mobile/v1`

Everything here is versioned in the path. `v1` will not change shape under an
installed app; a breaking change arrives as `v2` alongside it.

---

## 1. Launch

Call `GET /config` **before** anything else, including sign-in. It needs no
credential, because a build old enough to be blocked must be told so before it
tries to authenticate.

```json
{
  "data": {
    "currency": "MVR",
    "apps": {
      "customer": {
        "ios":     { "minimum_build": 1, "latest_build": 4, "store_url": "…" },
        "android": { "minimum_build": 1, "latest_build": 4, "store_url": "…" }
      },
      "merchant": { "…": "…" }
    },
    "features": { "customer_claims": false }
  }
}
```

- **`minimum_build`** — if your build number is below this, stop and send the
  user to `store_url`. This is the only lever we have to pull a bad build out
  of service, so it must be obeyed unconditionally.
- **`latest_build`** — offer an optional update.
- **`server_time`** — sent as the **`X-Server-Time` response header**, not in
  the body (a per-second value inside the body would change the ETag on every
  read and the 304 could never fire). Compare it against the device clock on
  launch: a till tablet with a wrong clock will otherwise date a sale into or
  out of its refund window. Warn the operator if the drift is large.
- **`features`** — hide screens the API will refuse.

`/config` is cacheable — see §5.

---

## 2. Signing in

| | Customer | Merchant |
|---|---|---|
| `POST` | `/customer/auth/token` | `/merchant/auth/token` |
| Body | `phone`, `password`, `device_name` | `email`, `password`, `device_name` |
| Token life | 365 days | 90 days |

`phone` accepts either the seven local digits (`7712345`) or full E.164
(`+9607712345`); both resolve to the same account.

`device_name` is shown to the user in their device list, and it is how they
recognise which phone to cut off when one is stolen. Send something a human
would recognise — "Ahmed's iPhone", "Counter tablet" — not a UUID.

**201 response:**

```json
{
  "data": {
    "token": "17|xxxxxxxx…",
    "expires_at": "2027-08-16T09:41:00Z",
    "device_name": "Ahmed's iPhone",
    "customer": { "id": 4, "name": "…", "customer_code": "374230" }
  }
}
```

The merchant response instead carries `user`, `merchant`, and `permissions`.

Store the token in the platform keystore (iOS Keychain / Android Keystore),
never in plain preferences. It is returned exactly once and cannot be
retrieved again.

Send it on every subsequent request:

```
Authorization: Bearer 17|xxxxxxxx…
```

**A maximum of 5 devices per account.** Signing in on a sixth silently signs
out the least recently used one. A reinstall counts as a new device, because
the old token is gone from the phone and cannot be revoked from there.

---

## 3. Staying signed in

Call `GET /customer/me` or `GET /merchant/me` on launch and on resume.

For the merchant app this is not optional. `permissions` is what the till
builds its navigation from, and the array returned at sign-in would otherwise
be frozen for the token's full 90 days — a cashier whose role was narrowed
this morning would keep rendering screens they can no longer use. `me` returns
them resolved fresh, and is cheap (see §5).

`GET /merchant/me` also returns `merchant.status`. If the store is not
trading, say so on the till rather than letting a cashier discover it on a
refused credit.

---

## 4. Errors

**Every** error from this API has one shape:

```json
{
  "error": {
    "code": "rate_limited",
    "message": "Too many attempts. Please wait a moment and try again.",
    "meta": { "retry_after_seconds": 300 }
  }
}
```

- **`code`** is the contract. Switch on it, and localise it into Dhivehi and
  English in the app.
- **`message`** is a fallback sentence, always present and always prose.
  When you meet a `code` your build does not know — a server deploy can add
  one at any time — **display `message`**. Never display a raw code.
- **`meta`** is code-specific. `validation_failed` carries `meta.fields`, a
  map of field name to messages. A permission refusal carries the permission
  slug.

Field messages inside `meta.fields` are display text, not codes — some come
from the framework as English prose. Treat `error.code` as the only reliable
machine-readable value.

### Codes

| HTTP | `code` | What to do |
|---|---|---|
| 401 | `unauthenticated` | Wipe the stored token and local state, return to sign-in. |
| 403 | `forbidden` | The account may not do this. Refresh `me`; the role may have narrowed. |
| 404 | `not_found` | Terminal. |
| 405 | `method_not_allowed` | A client bug. |
| 409 | `duplicate_invoice` | Terminal — this invoice is already recorded. |
| 409 | `idempotency_key_in_flight` | **Retryable.** Another request is holding this key; wait and retry with the **same** key. |
| 409 | `conflict` | Terminal; show `message`. |
| 404 | `customer_not_found` | The code was mistyped. Show it and let the cashier re-enter. |
| 422 | `merchant_not_active` / `future_dated` / `no_effective_rate` | Terminal. Show `message`; retyping cannot fix these. |
| 422 | `backdated_confirmation_required` | See §7. Check the device clock before resending. |
| 422 | `cursor_invalid` | Discard the cursor and restart the list from the top. |
| 422 | `validation_failed` | Show `meta.fields` against the form. |
| 429 | `rate_limited` | Wait — see §6. |
| 5xx | `server_error` | Retry with backoff. |

---

## 5. Conditional requests

`GET /config`, `/customer/me`, `/merchant/me`, `/customer/home` and
`/merchant/home` return an `ETag`. Keep it, and send it back:

```
If-None-Match: W/"a1b2c3…"
```

An unchanged answer costs a ~200-byte **304** instead of the whole body. On a
Maldives mobile connection this is the single cheapest saving available, and
it makes calling `me` on every resume essentially free.

`Cache-Control` is always `private`. These answers are per-account; never put
them in a shared cache.

---

## 6. Retrying

**Retry:** network failures, timeouts, and `5xx`. Exponential backoff starting
around 1s, with jitter, and give up after a few attempts rather than hammering
a struggling origin.

**Do not retry:** any documented `4xx` **except** `idempotency_key_in_flight`
(see below). They are terminal — the same request will fail the same way. Fix
the request or show the error.

**An UNDOCUMENTED 4xx is retryable** with the same `Idempotency-Key`. Codes are
added by server deploys, and a build in the field cannot know them; treating an
unknown refusal as terminal would silently drop a sale.

**429 is a special case.** Honour `Retry-After` (a header, and also
`meta.retry_after_seconds`) and do not retry before it elapses. Sign-in is
limited **per account** as well as per address, so a retry loop on a wrong
password will lock the account out for the window rather than eventually
succeeding. Show the wait; do not spin.

### Idempotency — required on every credit

`POST /merchant/credits` **must** carry an `Idempotency-Key` header. Generate
one per sale (a UUID is fine), store it with the queued sale, and reuse the
**same** key for every retry of that sale.

- Same key, same body → the original response body, plus
  `Idempotency-Replay: true`. **The replay arrives as `200`, not the `201` the
  first attempt returned** — key off the 2xx range or the replay header, never
  off `201`, or a committed sale stays in your queue forever.
- Same key, different body → `422 idempotency_key_reuse_mismatch`. A client
  bug: you have reused a key for a different sale.
- Missing → `422 idempotency_key_required`.
- Another request still holding the key → `409 idempotency_key_in_flight`.
  **Transient.** Back off and retry with the same key; it means a concurrent
  attempt is mid-flight, not that the sale failed.

This is what lets an offline queue drain safely: a request that timed out
*after* the server committed replays its original answer instead of booking a
second sale. `(merchant_id, invoice_no)` is still the final backstop and
answers `409` — but a 409 tells you nothing about what happened the first
time, which is why the key matters.

### Clock

Send `occurred_at` only when you mean a specific time; omit it and the server
records now, which is what a till ringing up a sale means.

If the resolved time is older than the store's refund window, the sale skips
that window entirely: it can never be reversed by the store and is payable to
Manfaa immediately. The API refuses that by default with
`422 backdated_confirmation_required` and returns `meta.server_time`.

**Compare that to the device clock before retrying.** A tablet that has
drifted, or reset after a flat battery, is the usual cause — and blindly
resending with `backdated_acknowledged: true` would turn a whole shift of
queued sales into irreversible debt. Only send the flag when a human has
deliberately chosen to record an older sale.

---

## 7. Signing out and lost devices

| | |
|---|---|
| `DELETE /{audience}/auth/token` | Sign out **this** device. |
| `DELETE /{audience}/auth/tokens` | Sign out **every** device. |
| `GET /{audience}/devices` | List signed-in devices. |
| `DELETE /{audience}/devices/{id}` | Cut off one device. |
| `DELETE /{audience}/devices` | Cut off all. |

`devices` returns name, when it signed in, when it was last used, when it
expires, and `is_current_device`. It never returns the token itself.

The same list is reachable from the **website** with only a session, which is
the path that matters when the phone is gone. Point users there in your
support copy.

**Revocation takes effect on the next request** — there is no cache and no
grace window. But nothing pushes the device out, so the app must treat any
`401` as "session over": clear the stored token and local state immediately.
A screen already rendered will otherwise keep showing stale data.

Account state is re-checked on every request. A suspended customer or a
deactivated staff member starts receiving `401` immediately, and deactivating
a staff member destroys their tokens outright.
