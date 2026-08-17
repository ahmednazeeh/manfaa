# Manfaa Merchant App — Flutter Build Plan

Owner-commissioned 2026-08-17. This file is the source of truth for the
merchant app build; work it round by round, checking off as rounds land.

## ⚠ RULE ZERO — the design references are the spec

**Before building or restyling ANY screen, open and study the reference
images in `/home/ubuntu/Merchant App Flutter Manfaa/`.** Twelve files:
Merchant Login, Dashboard, Credit Customer, With Category Credit Customer,
Settlements, Merchant More, Profile, Manage Employees, Roles, Branches,
Cashback Settings, Merchant App Logo. Every agent that touches UI must read
the refs for its screens itself — do not work from prose descriptions.
(The customer app taught this lesson twice: UI built without the refs was
rejected and redone.)

Reconciliation notes, decided after studying all twelve:
- The **violet-accent set** (Login, Dashboard, Credit ×2, Settlements, More,
  Profile, Employees, Cashback Settings) is canonical: same design family as
  the customer app (near-white canvas, white soft-shadow cards, ink primary
  buttons, coral M mark) with **violet as the merchant accent** and a
  "Merchant" wordmark suffix.
- **Roles.png and Branches.png are an older green-accent iteration** with a
  different bottom nav (Dashboard/Sales/Cashback/Notifications/More): take
  LAYOUT from them, restyle into the violet system.
- Canonical bottom nav: **Dashboard · Credit · Transactions · Settlements ·
  More** (More holds Profile, Manage Employees, Roles, Manage Branches,
  Cashback Settings, Promotions, Log out — per Merchant More.png).
- Mockup chrome with no backing data (branch photos and opening hours in
  Branches.png, "Continue with Google", employee email avatars) is omitted —
  the look is followed, data is never invented. Branch cards render name,
  address, pin state, zone; hours/photos become backend work only if the
  owner asks.

## What the app IS

The merchant panel in the pocket, till-first: credit a customer in seconds
at the counter, watch what you owe, settle before the prompt-payment
discount dies, and manage the store from More. Full signup + setup wizard
(all web steps) so a new shop can onboard entirely from the phone. en + dv
(RTL, Thaana) everywhere, light + dark, same §10/§11 money rules as the
whole platform: integer laari, exact percent strings, confirmed vs pending
never blended.

## Architecture (from the 2026-08-17 codebase survey)

- New app at **`mobile/merchant/`** beside `mobile/customer/`, path-depending
  on the same `mobile/packages/manfaa_core` + `manfaa_ui`. manfaa_ui needs
  **zero changes** (survey-verified: no customer coupling).
- **manfaa_core refactor (MR0)**, parameterize instead of forking:
  1. Extract transport (Dio + AuthInterceptor/EtagCache/Retry + `_run`
     envelope plumbing) into a base class; `CustomerApi` and new
     `MerchantApi` extend it.
  2. Split `SessionStore`: base (token/locale/theme/push/revision/wipe —
     all the AuthInterceptor needs) + per-app profile fields. Merchant
     session caches `user`, `merchant {id,name,slug,status}`, and the
     resolved `permissions` list; **`/merchant/me` refreshes permissions +
     status on every launch/resume** (mobile-api-guide rule — nav is built
     from it).
  3. `MobileConfig` parses gates per app (`apps.merchant` is already served;
     the Dart parser currently drops it) and `resolveGate` takes the app name.
  4. `ApiCode` gains merchant codes (`merchant_not_active`, `future_dated`,
     `no_effective_rate`, `rate_below_advertised`, `permission_required`,
     idempotency codes…).
  5. NEW in core: `Idempotency-Key` support — `POST /merchant/credits`
     REQUIRES it; the till's offline queue drains through it.
- **Auth**: email + password → `POST /mobile/v1/merchant/auth/token`
  (exists; 90-day token, 5-device cap, `mobile:merchant` ability). The
  Login mock's "email or phone" field: MerchantUsers have no phone —
  label it Email; "Continue with Google" and "Forgot password" have no
  backend and are omitted (forgot-password is a backlog ask, admin reset
  exists today).
- **Permission-driven UI everywhere**: tabs, More rows, and per-action
  buttons render from the flat `permissions` set exactly as the web does
  (`can()` = includes; server enforces regardless). Screen→permission map
  is in the survey; the essentials: Dashboard `settlements.view`, Credit
  `credits.create`, Transactions `transactions.view`, Settlements
  `settlements.view`/`create`, More rows each on their own read slug.
- **Suspension is never a locked panel**: credit refusals render the
  suspended message; everything needed to END a suspension (settling,
  receipts, reads) stays open.

## API gap work (server, alongside MR1–MR3)

The mobile surface has auth/me/home/transactions/credits/devices/push only.
Mount the rest under `mobile/v1/merchant` + `mobile.token:merchant`,
reusing the existing panel controllers unchanged (EnsureMobileToken sets
the merchant guard user, so `merchant.can:*` and EnsureMerchantApproved
just work):
- [ ] **Settlements suite**: outstanding, wallet, settlements list/{id},
      preview (`settle_all` | ids), POST settlements (multipart receipt),
      POST settlements/wallet, POST settlements/{id}/receipts.
- [ ] **Customer lookup** (`/merchant/customers/lookup`) — the credit
      screen's name-confirmation; notably absent from mobile today.
- [ ] **Transaction amend/cancel** (the till void).
- [ ] **Settings estate**: profile GET/PATCH + logo upload, branches CRUD,
      staff list/invite/edit, roles CRUD + the served permission catalogue,
      bank account, preferences, product categories, promotions, rate
      GET/POST.
- [ ] **Signup + setup on mobile**: request-otp / verify-otp / register
      variants that mint a merchant token at register (web register only
      logs in a session), plus the setup wizard endpoints (`GET setup`,
      PATCH profile/location/rate, POST logo, POST submit) on token auth.
- [ ] Feature tests per mounted group (authz, permission gating, envelope).

## Notification work (server + app — an owner requirement)

Exists today: `settlement_due` (batch created), `settlement_accepted`,
`settlement_rejected` — push-only to staff holding `settlements.view`,
via the polymorphic device_tokens path the customer app already exercises
(same FCM service account; zero server-side Firebase work).

- [ ] **New deadline reminders** (the owner's "1 day left" case): a daily
      command beside `manfaa:escalate` (09:00 business tz) computing, per
      merchant with outstanding payables, the oldest `clock_start_at` age:
      - `prompt_discount_expiring` — fires at age `max_age_days − 1`
        (day 9 by default): "Settle today and keep your 5% prompt-payment
        discount — you save MVR X." (X from settle-all preview.)
      - `settlement_due_soon` — day 13/14 (due at 15): "MVR X becomes
        overdue on DATE."
      New `NotificationTemplateKey` cases + seeded active templates
      (English-only bodies per the 2026-08-17 decision), fired through
      `sendToMerchantStaff(..., Permission::SettlementsView)`,
      EscalationLadder-style dedupe (one per merchant per cycle day).
- [ ] **Wire the §7 ladder to push**: day-10/13/15 notices currently write
      a merchant_notices row + log line only — have EscalationLadder also
      call NotificationService so the existing ladder reaches phones.
- [ ] **App side**: PushRegistrar clone (merchant push-token routes,
      permission asked after sign-in from Dashboard), POST_NOTIFICATIONS,
      foreground banner, tap-routing by template key (settlement_* →
      Settlements/detail, discount/due reminders → Settlements).
- [ ] Dashboard mirrors the reminder: the **prompt-discount deadline
      banner** (from `settlements/preview?settle_all=1`) exactly as
      Dashboard.png draws it ("Your oldest sale stops earning the 5%
      prompt-payment discount on DATE — settle before then and save
      MVR X").

## Task list — status
- **MR0 Foundation — IN PROGRESS (started 2026-08-17)**
- MR1 Signup + setup wizard — queued
- MR2 Till (Credit) + Transactions — queued
- MR3 Money (Dashboard/Settlements/Wallet) — queued
- MR4 Notifications — queued (Firebase registration blocker)
- MR5 More estate — queued
- MR6 Release — queued

## Rounds

House convention per round: read the design refs first → build → full
`flutter analyze` + tests green across app + both packages → screenshot
harness goldens reviewed by EYE (light+dark, en+dv where layout-affecting)
→ adversarial review → fix → next. API changes: pest suites green + pint +
`systemctl restart manfaa-queue.service`.

### MR0 — Foundation
- [x] manfaa_core refactor (transport base, session split, per-app gates,
      merchant ApiCodes, Idempotency-Key) — customer app suites stay green.
- [x] `mobile/merchant` scaffold: applicationId `mv.manfaa.merchant`,
      compileSdk 37, Java/Kotlin 17, minify template copied from customer;
      fonts (Manrope/Faruma/MVWaheed); l10n en+dv skeleton; theme =
      manfaa_ui with violet accent + "Merchant" wordmark suffix widget.
- [x] Boot/config gate (`apps.merchant`), router with revision-listening
      redirect + **status routing**: 401→login; merchant.status
      draft/pending_review/rejected→setup flow; active→shell.
- [x] Login per Merchant Login.png (hero panel, email+password, ink CTA,
      security note; no Google/forgot).
- [x] Shell: floating pill nav Dashboard/Credit/Transactions/Settlements/
      More, permission-aware.
- [x] Screenshot harness (`_ShotApi over MerchantApi`) from day one.

### MR1 — Signup + setup wizard (all web steps, in-app)
- [ ] API: mobile signup/setup mounting (gap list above).
- [ ] Phone → OTP → details (name, optional Thaana name, email, password).
- [ ] Wizard, resumable, server-persisted per step: 1 profile (curated
      category chips, channel, contacts, website) → 2 location (map pin,
      Maldives-bounded search, skippable only for online-only) → 3 logo
      (optional, raster ≤2MB) → 4 rate (percent with bounds + all-in
      preview) → 5 terms (eligibility text) → 6 review & submit with
      fix-this jump-backs on `setup_incomplete.missing[]`.
- [ ] Status screens: pending_review (hourglass), rejected (admin reason +
      Edit-and-resubmit), owner-only notice for staff.
- Map: flutter_map + OSM pin picker (consistent with the customer app's
  no-key decision); Places-style search via the zones/Nominatim approach
  only if needed — pin drag + my-location covers the wizard.

### MR2 — The till (Credit) + Transactions
- [ ] Credit screen per the two Credit refs: Enter code (6-digit OTP-style)
      / **Scan QR** (mobile_scanner) / Recent (local history); live lookup
      chip with full name + Verified; invoice no, sale date-time (default
      now, +05:00 offset), eligible amount, optional full amount;
      **custom-rate toggle** (only with `credits.custom_rate`; raise-only,
      inline 422 rendering); **split-by-category** editor (chips + amounts
      + Everything-else bucket, client sum check, server refusal mapping);
      live cost preview (cashback %, fee %, you-pay %) marked estimate;
      **backdated confirm** flow; submit with Idempotency-Key; result card.
- [ ] Offline queue: credits composed offline queue locally and drain
      with their original idempotency keys; visible pending-sync state.
- [ ] Transactions: cursor list, state filter, amend/cancel actions only
      while `awaiting_validation` && !backdated, with the web's reason set.
- [ ] Dashboard today-tally strip (credit_count / eligible / cashback).

### MR3 — Money (Dashboard + Settlements + Wallet)
- [ ] Dashboard per Dashboard.png: Outstanding hero + Settle now,
      discount-deadline banner, aging buckets (0–5/6–10/11–15/Overdue),
      payable breakdown (cashback/fee/GST + tx count + pending
      adjustments), wallet card, credit CTA.
- [ ] Settlements per Settlements.png: amount-due hero + Pay now, discount
      banner with oldest due date, **payment method** (wallet with
      insufficient state vs bank transfer), breakdown, included
      transactions, recent settlements; wizard = select (buckets presets,
      settle-all nudge) → preview (discount verdict, struck-through fee,
      credit applied, platform bank instructions with copy buttons) →
      receipt camera/file upload (≤5MB, pre-flight) or wallet settle;
      detail screen with status story, lines, payments, add-receipt.
- [ ] Wallet: balance + movements.

### MR4 — Notifications (the owner requirement)
- [ ] Server reminder work (section above) + tests incl. the day-9 fire,
      dedupe, and ladder-to-push.
- [ ] App push wiring + tap routing + foreground banner.
- [ ] BLOCKER to clear first: register **`mv.manfaa.merchant`** Android app
      in Firebase project `manfaa-6e1b4` → new google-services.json +
      firebase_options.dart (server FCM credentials already cover it).
      Owner action in Firebase console, or say the word and I'll walk it.

### MR5 — More (management estate)
- [ ] More screen per Merchant More.png (identity card + menu + logout).
- [ ] Profile per Profile.png (logo, names en+dv, category, channel,
      contacts, website, what-earns-cashback chips; edit via PATCH).
- [ ] Manage Employees per Manage Employees.png (stat tiles, search, rows
      with role + status chips incl. Suspended; invite → one-time temp
      password reveal; role reassign + active toggle; last-owner guard).
- [ ] Roles per Roles.png layout (role cards with counts; editor =
      checkbox grid from the SERVED catalogue, delegation rules: only
      what you hold, owner role frozen, in-use delete refusal).
- [ ] Manage Branches per Branches.png layout (stat tiles, search, cards
      with name/address/pin/zone + active state; add/edit dialog with
      flutter_map pin picker; delete → 409 branch_referenced handling).
- [ ] Cashback Settings per Cashback Settings.png (general rate with §7
      timing copy, per-category rules incl. excluded mode, min eligible
      sale, validation window; matches the web's merged screen).
- [ ] Promotions (list, create draft with cost preview + tier-cliff
      warning, publish confirm — immutable once live, cancel draft only).

### MR6 — Release
- [ ] Branding from Merchant App Logo.png: launcher icons + splash
      (flutter_launcher_icons / flutter_native_splash), app label
      "Manfaa Merchant".
- [ ] NEW keystore `manfaa-merchant-release.jks` + key.properties
      (separate key from customer — deliberate; back it up like the
      customer one).
- [ ] Download slot: `manfaa-merchant.apk` on the /app page (second card),
      Cloudflare purge rule applies; set `MOBILE_MERCHANT_ANDROID_URL` /
      admin App-releases gate values.
- [ ] v1.0.0+1 build, on-device pass, commit + push.

## Standing blockers / owner asks
- Firebase console: register `mv.manfaa.merchant` (Android; iOS later).
- APNs .p8 still missing (iOS push silent until then) — inherited blocker.
- FCM service-account key rotation — inherited blocker.
- Forgot-password for merchant users: no backend; decide want/skip.
- Login field says "Email or phone" in the mock; merchant accounts are
  email-only today — shipping as Email unless the owner wants phone
  identities added server-side.
