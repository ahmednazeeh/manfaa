# Round plan — the mobile API for the Customer and Merchant apps

Started **2026-08-16**. PLAN.md §13b is the long-lived record; this file is the
scratch that survives a context loss. Fold it in and delete it when the round
lands.

Owner instruction, verbatim:

> create a seperate plan file, create task list and start working on API. I
> want to do best API option for mobile apps for speed and reliability

Context: two Flutter apps are coming — Customer and Merchant (two binaries,
one codebase; till-first merchant scope). This round builds the API they will
be built against. It ships **before** the app design work, so the apps are
never built against auth that has to change underneath them.

---

## 1. What exists today

The business surface is close to complete. This round adds almost no domain
logic; it adds the layer that makes an HTTP API a *mobile* API.

**Already there, and reused as-is:**

- `laravel/sanctum ^4.0`, with personal access tokens already minted for POS
  vendors (`CredentialService:158`).
- `HasApiTokens` **already on all four models** — `Customer`, `MerchantUser`,
  `AdminUser`, `Merchant`. Nothing to add.
- `EnsureVendorCredential` — the token-type-assert pattern, already written
  and already documenting the exact hazard this round must not re-open.
- `IdempotencyMiddleware` — replay-safe, race-safe, merchant-scoped.
- `EnsureMerchantPermission` (`merchant.can:`) — the staff permission
  catalogue, landed.
- `NotificationService` + `NotificationTemplateKey` — the customer-facing
  moments are already enumerated (`cashback_earned`, `payout_paid`) and
  already fire afterCommit. Push is a **new channel on this**, never a
  parallel system.
- Domain services that hold all the logic: `BalanceQuery`, `PayoutWindow`,
  `EligibilityQuery`, `CreditRecorder`, `SettlementBuilder`,
  `DiscoveryService`.
- `docs/openapi.yaml` — the published contract. Extended, not replaced.

**What is missing is only this:**

1. Customer and merchant-user auth is **session-cookie only**. Every guard in
   `config/auth.php` is `'driver' => 'session'`; `bootstrap/app.php` calls
   `statefulApi()`. `CustomerAuthController::login` and
   `OtpAuthController:128` both end in `session()->regenerate()` and return a
   resource — no token. The only `createToken()` anywhere is on the
   **Merchant** model, which carries no staff identity and so can drive
   neither `merchant.can:` nor `transaction_events.actor_id`.
2. No push infrastructure of any kind.
3. No versioning on the first-party routes, and no minimum-build gate.
4. Offset pagination on customer history; no ETags anywhere; no aggregate
   endpoint, so app-open costs 4–5 sequential round trips.

---

## 2. Decisions taken

### 2.1 REST, not GraphQL

The screens are known and few, the payloads are small, and `docs/openapi.yaml`
is a published contract that POS vendors already build against. GraphQL would
add a resolver layer, forfeit the HTTP caching story (ETag/304) that is the
single biggest win available on a mobile network, and complicate the
idempotency middleware that already works. Rejected on the merits, not on
unfamiliarity.

### 2.2 Sanctum personal access tokens, not Passport or JWT

Already installed, already minting. Adding a second token system to run
alongside would be pure cost. Token mode is Laravel's documented answer for
native clients; the codebase is simply using the other half of Sanctum
(stateful cookies) for everything first-party today.

### 2.3 A dedicated `/api/mobile/v1` tree, composing the same domain services

Not a rewrite and **not duplicated business logic** — controllers here are
thin and call the same `BalanceQuery`, `CreditRecorder`, `SettlementBuilder`
as the web routes. The separate tree buys three things the shared tree cannot:

- **The web routes stay free to change.** An installed app three months old is
  still calling its paths; the panels deploy with their API and must not be
  frozen to suit that.
- **Different shapes are correct.** Mobile wants aggregates and cursors; the
  panels want per-resource reads and page numbers. Forcing one shape on both
  makes both worse.
- **Version stability is expressible.** `/api/mobile/v1` can be frozen and
  `/v2` added beside it; `/api/customer/balance` cannot.

Cost: two HTTP surfaces over one domain. Accepted, because the domain layer is
where every rule lives and it is shared completely.

### 2.4 Sanctum does NOT check token type — verified in the installed source

`vendor/laravel/sanctum/src/Guard.php:46` calls only `supportsTokens()`, which
checks whether the tokenable *uses the trait*. The `$provider` constructor
property is never consulted on the bearer path. Because `HasApiTokens` is
already on all four models, **the moment a customer or staff token is issued,
every `auth:sanctum` route accepts every token type** unless a type assert
stands in front of it.

Compounding it: `sanctum.guard` is `['admin','merchant','customer']`, checked
*before* the bearer token, and a session user carries a `TransientToken` whose
`can()` returns true for **every** ability — so `CheckAbilities` alone proves
nothing. `EnsureVendorCredential` already documents and defends both halves
for `/v1`. This round mirrors it, and no mobile route ships without it.

**This is the highest-risk item in the round.** It gets the adversarial review.

### 2.5 Tokens attach to the guard, so controllers do not change

Rather than flipping `config/auth.php` guards to `'driver' => 'sanctum'` —
which would break `Auth::guard('customer')->attempt()` and the
`session()->invalidate()` calls in the auth controllers — a middleware
validates the bearer token, asserts its type, and calls
`Auth::guard($guard)->setUser($tokenable)`. Every existing
`$request->user('customer')` and `merchantUser($request)` keeps working
untouched, and `merchant.can:` keeps receiving the real staff user.

---

## 3. Speed: where the milliseconds actually are

Measured against the live origin, not assumed.

| Finding | Status |
|---|---|
| Brotli compression | **Already handled** by Cloudflare at the edge (`content-encoding: br` confirmed on `/api/discover`). No work. |
| `gzip_types` commented out in `nginx.conf` | Origin→Cloudflare hop is uncompressed. Bandwidth only, not client latency. **Low priority.** |
| No `ETag` on any GET; `cache-control: no-cache, private` | Every discover load re-downloads in full. A 304 is ~200 bytes against a multi-KB payload. **Biggest single win.** |
| 4–5 sequential round trips on app open | On a mobile RTT this is the difference between one paint and a staircase of spinners. **Aggregate endpoint.** |
| Offset pagination on `customer/transactions` | New rows land at the top, so infinite scroll duplicates and skips. Cursor paging is both correct *and* cheaper. |

---

## 4. Task list

Phases are sequential where marked. **M1 blocks everything else** — no other
phase ships against auth that is still moving.

### M1 — Tokens (blocking) — **DONE, 2026-08-16. 18 tests; full suite 1078 green.**

- [x] `MobileAudience` — the closed set of audiences. One place answers, per
      app: which model may hold its tokens, which guard its user is set on,
      which ability its tokens carry, how long they live. Admin is absent on
      purpose.
- [x] `MobileTokenService` — the one place a mobile token is minted or
      revoked. Ability is per audience and never the `*` wildcard, which would
      make the ability half of the check vacuous.
- [x] `EnsureMobileToken` — asserts **four** things, not two: the tokenable is
      exactly this audience's model; it authenticated with a real
      `PersonalAccessToken` and not a session's `TransientToken`; the token
      carries the audience ability; and the account may still use the apps.
      Then `Auth::guard($guard)->setUser($user)` — so every existing
      `$request->user('customer')`, `merchant.can:` and controller runs
      unchanged behind it. All four failures answer one identical 401.
- [x] `MobileTokenSubject` on `Customer` and `MerchantUser`. **A token
      outlives the login that made it** — without re-asking the account's
      standing on every request, a deactivated cashier keeps crediting sales
      for months and the only remedy is hunting down tokens by hand.
- [x] `POST /api/mobile/v1/{customer,merchant}/auth/token`, throttled 5/min.
      Neither establishes a session: a bearer client has none, and creating
      one would set a cookie no app returns while widening what a stolen
      response is worth.
- [x] `DELETE .../auth/token` (this device) and `.../auth/tokens` (everywhere
      — the lost-phone remedy).
- [x] Expiry recorded: **customer 365 days, merchant 90**. A personal phone
      re-authenticating is friction on the screen that must be instant in a
      till queue; a till device is shared, left on a counter, and often not
      owned by the person holding it. Revocation is the real control; expiry
      covers the device nobody reports.
- [x] Live-token cap of 5 per user per audience, evicting least-recently-used.
      A reinstall cannot revoke its predecessor — the old plaintext is gone
      from the device — so without a cap every reinstall leaves a working key
      behind forever.
- [x] **Adversarial tests, all passing:** customer token refused on every
      merchant route AND on `/api/v1/customers/lookup`; merchant token refused
      on customer routes; POS vendor token refused on both; **browser session
      refused on the mobile tree entirely**; suspended customer and
      deactivated staff both stop working mid-token.

**Decision needed from the owner (does not block M2):** sign-in is
**phone/email + password**, mirroring the web exactly, so no new
authentication semantics entered the system with a plumbing round. Passwordless
**OTP sign-in for a new device** is the better mobile experience and is what
the app will want — but it is a real product change (SIM-swap becomes account
takeover) and wants deciding on its merits, not slipping in here.

### M1b — Adversarial review and its fixes — **DONE, 2026-08-16. Suite 1098 green.**

45 raw findings across 8 lenses, each attacked by 3 refuters; 10 survived. The
type-confusion gate itself held under attack — no wrong-audience token,
session cookie or POS vendor credential reaches a mobile route. Everything
below is what the review found around it.

- [x] **The lost-phone remedy was unreachable.** `revokeAll` was documented in
      two places as the stolen-phone remedy and sat behind
      `mobile.token:customer` — *you had to present a live token from the
      device you were trying to kill.* No second path existed: no admin
      screen, no support lever, and nothing in the codebase ever writes a
      non-`active` customer status, so even the crude workaround needed raw
      SQL. A thief held a 365-day credential over balance, history and payout
      bank details. **Fixed:** a device list — see, name, and cut off — mounted
      on BOTH the website (session) and the app (bearer), for customers and
      staff alike. One controller serves both, because EnsureMobileToken sets
      the user on the session guard. Revocation is scoped through the user's
      own relation, so a foreign token id matches nothing rather than relying
      on a check a later edit could drop.
- [x] **Deactivation left the credential alive.** `mayUseMobileApp()` made a
      deactivated staff member's token *refuse*, but the row survived — so
      reactivating them months later revived a token on a phone they no longer
      held, with nobody re-entering a password. StaffService now destroys them
      as part of the deactivation, which is the only reading under which "no
      DELETE, deactivation is the only removal" is actually true.
- [x] **`$request->ip()` was attacker-controlled, platform-wide.** nginx
      resolves the client correctly (Cloudflare ranges, `CF-Connecting-IP`);
      `trustProxies(at: '*')` then threw that away and took the leftmost,
      client-supplied `X-Forwarded-For`. Every unauthenticated rate limit on
      the platform keys on that, so rotating one header bought a fresh bucket
      per request — unlimited password guessing against every login, not only
      the new ones. **Fixed** by dropping `X-Forwarded-For` from the trusted
      header set while keeping proto/host/port, which is the only reason the
      `'*'` was there. Demonstrated: under the old config the test route
      reported the forged `203.0.113.99`; it now reports the real address, and
      scheme detection is unchanged.
- [x] **A per-ACCOUNT sign-in counter** on both endpoints — the only control
      on this path a forged header cannot rotate around, and the shape
      `OtpAuthController` already uses. 10 per 15 minutes, cleared by a
      correct password so a shop signing tills in all morning never throttles
      itself out. The lockout-as-DoS trade is stated in the trait.
- [x] **Every unnamed `throttle:` in the repo shares one counter per IP** —
      Laravel keys them `sha1(domain|ip)` with no route component, and
      `logos.php` allows 240/min, so a visitor who just painted a storefront
      arrived at a login with the bucket spent and was refused at 5. The two
      new routes now carry their own key prefixes. **The other 18 inline
      throttles still share one bucket — a separate ticket.**
- [x] **`pruneTo` evicted the wrong token, two ways.** `NULLS FIRST` made a
      never-used token the FIRST evicted (sign a till in, sign in elsewhere a
      minute later, the till is dead) — and conversely made any dormant token
      immune to the control meant to clear it. Separately, expired rows held
      cap slots and *outranked* live ones, because Sanctum refuses an expired
      token before stamping `last_used_at`, freezing it more recent than a
      live-but-idle token's. Now: reap the dead first, then rank on
      `COALESCE(last_used_at, created_at)`. Both regression tests were
      confirmed to FAIL against the old code.
- [x] **The cap was not concurrency-safe** — no lock, READ COMMITTED, so two
      simultaneous sign-ins both pruned the same victim and both inserted. Now
      takes the row lock `CredentialService` already takes for its own cap.
- [x] `sanctum:prune-expired` scheduled daily; nothing reaped tokens for a
      user who had stopped signing in.
- [x] **An undocumented ordering dependency** in `EnsureMobileToken`:
      `setUser()` fires `Authenticated`, and two live providers listen for it
      and call `logout()`. Inert only because the account-state check happens
      to run first. Now re-asks the guard afterwards, so a listener added later
      produces the promised 401 rather than a 500 on a null user.
- [x] **Two tests proved nothing.** No test asserted the merchant gate ever
      *allowed* anything — denying the whole audience would have left the suite
      green. And the vendor-token test made two authenticated requests, so the
      second never read its own header (replace the token with garbage and it
      still passed). Both fixed; the one-authenticated-request-per-test rule is
      now written in both test files.
- [x] `TouchCredentialLastUsed` returns early for non-vendor tokenables — it
      was issuing a zero-row UPDATE on every mobile request forever.

**Refuted and NOT to be reopened:** `whereJsonContains` and the raw ORDER BY
are valid on this stack (Postgres in both `.env` and `phpunit.xml`);
`sanctum.expiration` is null so the per-token 365/90-day expiry is not
silently capped; `merchant_users.email` and `customers.phone` are globally
unique so neither lookup is cross-tenant ambiguous; the merchant *status*
gate is deliberately absent from sign-in, matching the web login exactly
(EnsureMerchantApproved gates routes, not logins). `docs/openapi.yaml`
documents the `/v1` vendor contract only and no first-party route, so the
mobile tree is correctly absent from it.

**Still open, deliberately:** the merchant app's `permissions` array is
returned only at sign-in and goes stale for the life of a 90-day token — a
demoted cashier keeps rendering the settlement builder. M2 should add a
`me`/session refresh; noted so it is not rediscovered.

### M2 — The transport contract — **DONE, 2026-08-16. Suite 1112 green.**

- [x] `/api/mobile/v1` prefix, route file `routes/api/mobile.php` (landed M1).
- [x] **One error envelope** `{error:{code,message,meta}}`, via
      `MobileError`. `code` is the contract; `message` is a server-supplied
      sentence so a code newer than the installed build renders as prose
      rather than raw snake_case. `proseOrNull()` refuses to echo a bare
      identifier as the human half — the codebase deliberately throws
      messages that ARE codes (`otp_attempts_exceeded`), and echoing one
      would put snake_case on a customer's screen.
- [x] **Scoped by path** (`api/mobile/*`) and proven so by test: the panels
      keep Laravel's stock `{message,errors}` and the published `/v1` vendor
      contract keeps its own 401 body. Neither may drift because the apps
      wanted one parser.
- [x] `NormalisesMobileErrors` wraps the whole tree, because middleware
      *returns* refusals rather than throwing them —
      `EnsureMerchantPermission`'s `permission_required` is shared with the
      panels and cannot be reshaped for the apps. Without it an app would need
      two parsers and would meet the second one in production, at a till.
- [x] Sign-in throttling now raises a real `ThrottleRequestsException`, not a
      `ValidationException` wearing a 429 — so a client can tell "wait" from
      "wrong" instead of redrawing the form against a possibly-correct
      password. `Retry-After` travels as both header and meta.
- [x] `GET /api/mobile/v1/config` — per-platform `minimum_build` gate, feature
      flags, `server_time`. Unauthenticated by necessity: a build old enough
      to block must be told before it authenticates. The gate is the only
      lever that can pull a bad build out of service, and one added after
      release cannot reach the builds already out there.
- [x] `CacheableJson` — ETag + `If-None-Match` → 304, handling the header as
      the LIST it is. Always `private`: Cloudflare fronts this origin and must
      never hold one account's answer for another.
- [x] **`GET /{customer,merchant}/me`** — closes the gap flagged in M1b. The
      till's permissions were captured at sign-in and frozen for the token's
      90 days; a demoted cashier kept rendering the settlement builder, and
      the only refresh was a re-sign-in that evicts another device. Now
      resolved fresh, and conditional so calling it on every resume is nearly
      free.
- [x] Client contract written: **`docs/mobile-api-guide.md`** — retry rules
      (5xx and network retry with backoff; documented 4xx are terminal; 429
      honours `Retry-After`), the 401-means-wipe-your-session rule, the
      version gate, conditional GETs, and device management. Deliberately a
      SEPARATE document from `integration-guide.md`, which is the POS vendor
      contract and must not acquire mobile client advice.

**Corrected along the way:** `__('auth.failed')` does NOT return its key —
Laravel resolves it against the framework's own bundled lang files even with
no published `lang/` directory. So field-level messages are a mixed bag
(framework prose in some places, bare codes in others). The envelope's
`error.code` is therefore the only reliable machine-readable value, and the
guide tells the apps exactly that.

**Also found:** the M2 envelope silently made an M1 assertion vacuous — a test
comparing top-level `errors` between three refusals began comparing `null` to
`null`. Rewritten against `error.meta.fields`. Worth remembering whenever a
response shape changes under existing tests.

### M2b — Adversarial review of M2–M5, and its fixes — **DONE, 2026-08-16. Suite 1166 green.**

46 raw findings across 10 lenses, each attacked by 3 refuters; 33 survived,
merged to 21 distinct defects. **All fixed.**

**The worst one came from the completeness critic, not the ten lenses, and it
was a money bug this round introduced.** `NotificationService` ran SQL inside
the caller's settlement transaction and swallowed its errors. On PostgreSQL a
swallowed error leaves the transaction ABORTED, so the COMMIT that follows is
executed as a ROLLBACK **and reports no error** — the admin is told the payment
matched while the allocations, ledger postings, wallet debit and state change
are all discarded. Demonstrated directly against Postgres before fixing. The
code comment claiming "nothing here can fail a settlement" was exactly
backwards: swallowing is what made it dangerous. Now the ENTIRE body — template
lookup, recipient query, fan-out — runs behind `DB::afterCommit`, and the
guarantee is pinned by a test asserting zero queries execute while the caller's
transaction is open.

- [x] **A1** Mobile merchant reads honoured no permissions while the panel
      twins did — a credits-only cashier could read the full history and the
      whole ageing table. `transactions` now carries `merchant.can:transactions.view`;
      `home` stays open but withholds `outstanding` and `open_settlement`
      unless the account holds `settlements.view`.
- [x] **A2** `SettlementDue` fired on the receipt-first and wallet-funded
      paths, where the batch never rests in `awaiting_payment` — telling a
      store to transfer money for a batch it had already paid, which produces
      a duplicate transfer arriving as an unmatched deposit. Now gated on
      COMMITTED state.
- [x] **A3** The credit throttle keyed on a bare numeric id, and
      `ThrottleRequests` sorts above the audience gate — customer #42's
      rejected requests locked out merchant-user #42 at an unrelated store.
      Replaced with a named limiter keyed on class + id.
- [x] **B1** Every 304 emitted a mangled ETag: Symfony's `setEtag()`
      re-quotes a weak validator into `"W/"…""`, so it never round-tripped.
      Verified by execution. Now `headers->set()`, with a test that reads the
      tag off the 304 — the absence of which is why it shipped.
- [x] **B2/B3** `/config` and `/merchant/home` could never return a 304: both
      embedded a per-second timestamp inside the hashed body. `server_time`
      moved to an `X-Server-Time` header; `as_of` stripped from the mobile
      projection only. The two config tests no longer need frozen time — that
      they did was the tell.
- [x] **Critic #3** FCM's `400 INVALID_ARGUMENT` was treated as a dead device.
      It is a verdict on the MESSAGE, so one long rejection reason typed by an
      admin would have deleted every till in a store from push at once.
      Removed from the permanent set; `401/403` now evicts the cached
      credential, and the token TTL comes from the issuer's `expires_in`.
- [x] **Critic #2** `idempotency_keys` had no retention while M5 pointed every
      till sale at it — and an unbounded replay window turns a factory-reset
      device's restarted key counter into permanently refused sales.
      `manfaa:prune-idempotency-keys` (30 days, chunked) scheduled daily.
- [x] **B5–B11** malformed cursor 500 → 422; push-token takeover
      (delete-and-recreate, never transfer); partial re-registration no longer
      resets `locale`/`app_build`; expired-but-unswept tokens excluded from
      the fan-out; duplicate "settlement accepted" gated on the TRANSITION;
      money formatted in the template's own language (no "MVR" in a Thaana
      sentence); missing index on `device_tokens.personal_access_token_id`.
- [x] **D1–D4** the guide now documents `idempotency_key_in_flight` as the one
      RETRYABLE 409, states that an undocumented 4xx is retryable, says the
      replay arrives as 200 not 201, lists the real ETag endpoints, and names
      the new credit error codes.
- [x] **D2** Domain credit refusals kept their identity: `customer_not_found`,
      `merchant_not_active`, `future_dated`, `no_effective_rate`,
      `duplicate_invoice` — three terminal refusals had been arriving as
      `validation_failed` with no meta, telling a till to show them against a
      form field that could not fix them.
- [x] **C1–C6** the missing tests, including the 304-validator assertion and a
      `merchant/home` 304 test.

**Caught while fixing:** my first regression test for the money bug passed
against the UNFIXED code — it provoked a PHP exception, and only a failed SQL
*statement* aborts a Postgres transaction. Rewritten and verified to fail
against the old code. Every non-trivial fix in this round was checked the same
way.

### Review status

- **M1 + M1b: reviewed**, 10 findings fixed.
- **M2–M5 + M2b: reviewed**, 21 findings fixed.
- Nothing in this round is now unreviewed.

### M3 — Speed — **DONE, 2026-08-16. Suite 1126 green.**

- [x] `GET /customer/home` — code, balance (Confirmed headline, pending
      separate and never summed), payout window, minimum, account state. One
      request where opening the app used to cost four or five sequential
      round trips; on a Maldives connection latency, not bandwidth, is what a
      phone actually pays.
- [x] `GET /merchant/home` — store status, today's credits (business-day, so
      a 9pm Malé sale does not land on tomorrow's tally, and reversed /
      written-off sales are excluded or the till disagrees with the receipt
      roll), outstanding by age bucket via the panel's own
      `OutstandingSummary`, and the open settlement.
- [x] **Deliberately NOT in either aggregate: transaction history.** It
      changes on every purchase, and folding a volatile list in would churn
      the ETag on every read and destroy exactly the 304s that make calling
      home on each resume cheap.
- [x] ETag/304 on both `home` endpoints, `config`, **and the public
      `discover` feed + store directory + store page** — the largest payloads
      either app or the storefront fetches, previously re-downloaded in full
      every time. Safe by construction: a client can only send an
      `If-None-Match` it was given, which means it already holds that body.
- [x] Cursor pagination on `{customer,merchant}/transactions` in the mobile
      tree. These lists grow at the TOP, so with `?page=2` a sale credited
      between pages shifts every row down one — the client redraws a row it
      already showed and never sees the one pushed across the boundary.
      Pinned by a test that inserts a sale between pages and asserts no
      overlap. The panels keep page numbers; a numbered table wants them.
- [x] N+1 guard asserting the query count is **constant in the number of
      rows**, expressed as a relationship rather than an absolute so an
      unrelated refactor cannot make it fail spuriously. Verified to have
      teeth: dropping the eager-load takes it from 6 queries to 16.
- [x] Home aggregate held under a query ceiling.

**Two things learned, worth keeping:**

- The query-count test was initially FLAKY, not wrong. Sanctum stamps
  `last_used_at` on every authenticated request and Eloquent only writes when
  the value actually changes, so that write landed in one measurement and not
  the other depending on whether a second boundary happened to pass. Fixed by
  freezing time and warming the token before measuring. Any future
  query-count test needs the same treatment.
- Every authenticated mobile request therefore carries a single-row UPDATE to
  `personal_access_tokens`. Kept deliberately: `last_used_at` is what the
  device list shows and what `pruneTo` ranks eviction on. It self-throttles
  to at most one write per second per token.

### M4 — Push — **DONE, 2026-08-16. Suite 1139 green.**

- [x] `device_tokens` — polymorphic across `Customer` and `MerchantUser`,
      unique on the provider token, carrying platform, app build and locale.
- [x] **The binding that matters: a push registration is a FOREIGN KEY on the
      auth token, cascading on delete.** Signing out, cutting a device off
      from the website, the token cap evicting the least recently used, and
      deactivating a staff member all delete personal access tokens — and now
      every one of them stops the push too, structurally. There is no
      revocation path anyone can add later that forgets to. A sold or stolen
      phone cannot keep announcing a balance on its lock screen.
- [x] `PUT/DELETE /{audience}/push-token`. PUT because apps re-send on every
      launch and providers rotate tokens, so it must be idempotent; keyed on
      the provider token so a reinstall or a second person on a shared handset
      MOVES the row instead of leaving a twin delivering to whoever held it
      last. DELETE is "turn notifications off" — distinct from signing out.
- [x] `PushSender` with **`log` as the default driver**, so registration,
      revocation, language and the moments all ship, test and review before a
      Firebase project exists. An unconfigured platform degrades to a log
      line, never to an exception beside the money path.
- [x] `FcmPushSender` — HTTP v1, one provider covering Android and iOS. **No
      new composer dependency**: the service-account JWT is one `openssl_sign`
      and one token call, cheaper than pulling google/auth into a live
      payments app for two signatures an hour. Access token cached 55 minutes.
- [x] A rejected registration (uninstalled app, rotated token) is DELETED
      rather than retried forever — `PushDeliveryException::$permanent`
      separates that from a provider blip, which retries.
- [x] Push channel inside `NotificationService`, same afterCommit discipline,
      same never-break-the-caller rule. **SMS and push are now independent**:
      the old shape returned early when a customer had no phone number, so a
      customer with the app but no number on file could not be reached at all.
- [x] Merchant moments — `settlement_due`, `settlement_accepted`,
      `settlement_rejected` — seeded active (a push has no per-message bill,
      so the reason `cashback_earned` is off by default does not apply), and
      wired at the real transitions in `SettlementBuilder` and
      `SettlementAllocator`. Addressed by PERMISSION, not broadcast: a
      settlement message reaching a cashier who cannot open the settlement
      screen is noise they cannot act on.
      `settlement_accepted` fires only on FULL settlement — announcing it on
      a partial allocation would read as "we are done" when the store still
      owes.
- [x] Push titles live in code, not in the editable template: a title is
      structural, and an empty one renders as a heading-less notification on
      both platforms. Localised per device from the `locale` column.
- [x] The trailing "— Manfaa" is stripped for push. Both platforms already
      print the app name above the body, and a push gets about two lines.

**Migration guard retargeted.** `create_notification_templates_table`
asserted that every enum key had a seeded row — a property of the whole
migration SET, not of one file, so adding any later moment broke that
migration on a fresh database. It now checks the reverse direction (no seeded
key without a case), and the original invariant is covered continuously by
`CustomerNotificationTest`, which runs on every commit rather than only at
migrate time.

**Caught in review of my own edit:** the `settlement_rejected` call was
initially inserted at TWO sites, because `releaseLinesAndCancel()` is shared
by `reject()` and `cancel()`. The second was in the merchant's own
cancellation — no `$reason` in scope, and a "receipt refused" push would have
been wrong even if it had compiled. Four settlement tests caught it.

**Operational to-dos (not code):**
- Create the Firebase project and service account; set `PUSH_DRIVER=fcm`,
  `FCM_PROJECT_ID`, `FCM_CLIENT_EMAIL`, `FCM_PRIVATE_KEY` in `.env`. The key
  is a credential and never enters the repository.
- APNs auth key uploaded to Firebase before any iOS build ships.
- Native review of the Thaana push titles and the three merchant templates.

### M5 — Write reliability — **DONE, 2026-08-16. Suite 1152 green.**

- [x] `POST /api/mobile/v1/merchant/credits`, with **Idempotency-Key
      required**. `IdempotencyMiddleware` was merchant-token-only; it now
      resolves the merchant from either principal — a POS vendor token
      authenticates AS the merchant, a till app as one of its staff. The key
      stays scoped to the STORE, not the account: two tills retrying the same
      key are retrying the same sale and must collide rather than book it
      twice.
- [x] **The clock guard.** A sale older than the refund window skips
      `awaiting_validation` entirely — irreversible by the store and payable
      immediately. The panel has a human ticking a box for that; a till app
      has nobody in the loop, and a tablet whose clock has drifted or reset
      after a flat battery would stamp a whole shift's queued sales far
      enough back to trip the rule. The mobile endpoint therefore refuses a
      backdated credit with `backdated_confirmation_required` unless the app
      sends `backdated_acknowledged`, and returns `server_time` with the
      refusal so the operator can be told their clock is wrong rather than
      just seeing a failure.
- [x] The rule is asked of the DOMAIN — `CreditRecorder::wouldBeBackdated()`,
      made public and now used by the recorder itself as well. Two copies of
      that arithmetic is exactly how a guard comes to disagree with the
      behaviour it guards.
- [x] **`HandlesCreditRequests` extracted**, shared by the panel and the till.
      The validation rules and the domain-exception mapping were the
      drift-prone halves: a rule tightened in one place, or a new domain
      exception caught in one controller and not the other, would surface as a
      500 at a till instead of the refusal the merchant expects. The panel
      controller was refactored onto it; its 22 tests passed unchanged.
- [x] Credit throttle raised to **60/min with its own key prefix** — a
      draining offline queue is a burst by definition and a human at a till is
      not, while manual entry remains the exposed fraud surface (§11) so it
      stays bounded. The prefix keeps it off every other limiter.
- [x] `(merchant_id, invoice_no)` remains the final authority and is pinned by
      test: a duplicate invoice under a DIFFERENT key still answers 409.

**Note on the envelope:** the idempotency refusals reach the apps as
`idempotency_key_required` / `idempotency_key_reuse_mismatch` inside the M2
envelope, even though the middleware returns rather than throws — that is
`NormalisesMobileErrors` doing exactly the job it was added for, and the
permission refusal's `permission` slug survives into `error.meta` so the app
can say which permission is missing.

### Out of scope, deliberately

- Admin. It stays web, English-only, session-cookie. No app, no tokens.
- Claims. Feature-flagged off (`config/features.php`, 2026-08-14 — merchant
  mediated). The customer app needs a "contact the store" path instead, which
  is a separate, smaller decision.
- Any change to `/api/v1`. The vendor contract is published and stays put.

---

## 5. Rules for this round

- Never `config:cache` (see PLAN.md and the memory note — it bakes a stale
  `.env` and the tests then lie).
- Tests run on PostgreSQL, never sqlite. `phpunit.xml` → `manfaa_test`.
- `pint --dirty` before every commit.
- Nothing here ships without the adversarial review, and §2.4 is what the
  reviewer is pointed at first.
