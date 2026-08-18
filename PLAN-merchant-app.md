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
- [x] **Settlements suite**: outstanding, wallet, settlements list/{id},
      preview (`settle_all` | ids), POST settlements (multipart receipt),
      POST settlements/wallet, POST settlements/{id}/receipts.
      (Mounted 2026-08-17 with MR3 — web SettlementController reused
      unchanged, permission-first then default EnsureMerchantApproved on
      the writes; 12 feature tests in MobileMerchantSettlementsTest.)
- [x] **Customer lookup** (`/merchant/customers/lookup`) — the credit
      screen's name-confirmation; notably absent from mobile today.
      (Mounted 2026-08-17 with MR2 step 1, plus rate + product-categories
      reads for the till's preview and split editor.)
- [x] **Transaction amend/cancel** (the till void). (Mounted 2026-08-17.)
- [ ] **Settings estate**: profile GET/PATCH + logo upload, branches CRUD,
      staff list/invite/edit, roles CRUD + the served permission catalogue,
      bank account, preferences, product categories, promotions, rate
      GET/POST.
- [x] **Signup + setup on mobile**: request-otp / verify-otp / register
      variants that mint a merchant token at register (web register only
      logs in a session), plus the setup wizard endpoints (`GET setup`,
      PATCH profile/location/rate, POST logo, POST submit) on token auth.
      (Mounted 2026-08-17 alongside MR1 step 2.)
- [ ] Feature tests per mounted group (authz, permission gating, envelope).

## Notification work (server + app — an owner requirement)

Exists today: `settlement_due` (batch created), `settlement_accepted`,
`settlement_rejected` — push-only to staff holding `settlements.view`,
via the polymorphic device_tokens path the customer app already exercises
(same FCM service account; zero server-side Firebase work).

- [x] **New deadline reminders** (the owner's "1 day left" case): a daily
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
      (Shipped 2026-08-17: `manfaa:remind-settlements`, 09:00 business tz
      beside `manfaa:escalate`; thresholds computed from live
      PlatformConfig, saving = settle-all preview's own `discount_laari`.)
- [x] **Wire the §7 ladder to push**: day-10/13/15 notices currently write
      a merchant_notices row + log line only — have EscalationLadder also
      call NotificationService so the existing ladder reaches phones.
      (Shipped 2026-08-17, behind the ladder's own alreadySent dedupe.)
- [x] **App side**: PushRegistrar clone (merchant push-token routes,
      permission asked after sign-in from Dashboard), POST_NOTIFICATIONS,
      foreground banner (SnackBar + View action), tap-routing by template
      key (settlement_accepted/rejected → detail when a `settlement_id`
      data key ships, list until then; every deadline/ladder key →
      Settlements; unknown → nowhere). Coded to completion behind
      PLACEHOLDER Firebase config — builds and runs today, push lights up
      when the real config lands, zero code changes. (2026-08-17)
- [x] Dashboard mirrors the reminder: the **prompt-discount deadline
      banner** (from `settlements/preview?settle_all=1`) exactly as
      Dashboard.png draws it ("Your oldest sale stops earning the 5%
      prompt-payment discount on DATE — settle before then and save
      MVR X"). (Shipped with MR3's Dashboard.)

## Task list — status
- MR0 Foundation — DONE 2026-08-17
- MR1 Signup + setup wizard — DONE 2026-08-17 (API mounted + app flow;
  all three Flutter suites green, goldens EYE-reviewed)
- MR2 Till round — DONE 2026-08-17 (till APIs mounted + 16 feature tests;
  Credit screen with QR + offline idempotent queue; real Transactions tab;
  Dashboard Today strip; goldens EYE-reviewed vs both Credit refs; all
  suites green: api 1292, merchant 43, core 54, customer 35. Bonus: fixed
  MobileError 429→500 header-cast production bug. The `contact`
  missing-requirement key is wired app-side too — server refuses submit
  for a phoneless store, support_phone always materialised from contact.)
- MR3 Money — DONE 2026-08-17 (settlements suite mounted + 12 feature
  tests; manfaa_core settlement models/API with 18 wire-shape tests;
  Dashboard money build-out, Settlements tab with bank + wallet pay flows,
  settlement detail, Wallet screen; en+dv strings aligned with the web
  panel's dv.json; 6 new goldens EYE-checked vs both refs; all suites
  green: merchant 56, core 72, ui 3, customer 35.)
- MR4 Notifications — DONE 2026-08-17 code-complete (server: 5 template
  keys, `manfaa:remind-settlements` daily 09:00 business tz, ladder rungs
  now push, 11 reminder tests; app: push registrar clone, tap routing,
  foreground banner, debug-APK build proven against the placeholder
  Firebase config; suites green: api 1315, merchant 62, core 72,
  customer 35. LIVE-push remains dark until the console registration of
  `mv.manfaa.merchant` — a config-file swap, zero code changes.)
- **MR5 More estate — SHIPPED 2026-08-17** (A-half: More shell + Profile
  + Cashback Settings; B-half same day: Employees/Roles/Branches/
  Promotions replaced the coming-soon routes — per-round notes below)
- MR6 Release — DONE 2026-08-17 (Android; iOS waits on the APNs/.p8 +
  Apple developer account track)
- MR7 Tablet optimisation — DONE 2026-08-17 (adaptive shell: 640dp rail
  on medium, house-styled NavigationRail ≥840dp with identical kTabs
  gating; two-column till; 4-across dashboard; two-pane transactions /
  settlements / employees / roles / branches / promotions; scanner-gun
  Enter path tested; ZERO phone goldens changed — shipped v1.0.0 pixels
  untouched; 9 tablet goldens eye-checked; suites green: merchant 101,
  core 102, ui 3, customer 35)
- MR8 Owner feedback round — DONE 2026-08-17 (all ten reported items
  fixed across server, app and web; suites green: api 1349, merchant 101,
  core 111, ui 3, customer 35)
- **MR9 Admin approval for store edits + new branches — IN FLIGHT**
  (design decided 2026-08-18, §MR9: claims queue, operations stay
  instant)

- WL merchant.manfaa.app landing — SHIPPED 2026-08-17 (landing + split
  auth panels + real-Dashboard mockup; also retired the METRONIC template
  logo the auth cards had been shipping). Only the APK card remains,
  after MR6
- MR7 Tablet optimisation (merchant only) — queued after MR6 (owner-agreed
  2026-08-17; phone-first until then)

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
- [x] API: mobile signup/setup mounting (gap list above).
- [x] Phone → OTP → details (name, optional Thaana name, email, password).
      Register mints the 90-day token and lands signed-in as draft; the
      login gained the mock's "Register your store" footer link.
- [x] Wizard, resumable, server-persisted per step: 1 profile (curated
      category chips, channel, contacts, website) → 2 location (map pin,
      skippable only for online-only; pin drag + my-location per the map
      note below — no search needed) → 3 logo (optional, raster ≤2MB,
      client-downscaled) → 4 rate (percent with bounds + all-in preview
      from the §4 static bands) → 5 terms (eligibility text) → 6 review &
      submit with fix-this jump-backs on `setup_incomplete.missing[]`.
- [x] Status screens: pending_review (hourglass + submitted date),
      rejected (admin reason blockquote + Edit-and-resubmit), owner-only
      notice for staff without `setup.view`.
- Map: flutter_map + OSM pin picker (consistent with the customer app's
  no-key decision); Places-style search via the zones/Nominatim approach
  only if needed — pin drag + my-location covers the wizard.

### MR2 — The till (Credit) + Transactions — DONE 2026-08-17
- [x] Credit screen per the two Credit refs: Enter code (6-digit OTP-style)
      / **Scan QR** (mobile_scanner) / Recent (local history); live lookup
      chip with full name + Verified; invoice no, sale date-time (default
      now, +05:00 offset), eligible amount, optional full amount;
      **custom-rate toggle** (only with `credits.custom_rate`; raise-only,
      inline 422 rendering); **split-by-category** editor (chips + amounts
      + Everything-else bucket, client sum check, server refusal mapping);
      live cost preview (cashback %, fee %, you-pay %) marked estimate;
      **backdated confirm** flow; submit with Idempotency-Key; result card.
- [x] Offline queue: credits composed offline queue locally and drain
      with their original idempotency keys; visible pending-sync state.
      (manfaa_core `CreditQueue`: FIFO drain on foreground/connectivity,
      2xx clears, permanent refusals park as needs-attention — never
      silently dropped.)
- [x] Transactions: cursor list, state filter, amend/cancel actions only
      while `awaiting_validation` && !backdated, with the web's reason set.
- [x] Dashboard today-tally strip (credit_count / eligible / cashback).

### MR3 — Money (Dashboard + Settlements + Wallet) — DONE 2026-08-17
- [x] Dashboard per Dashboard.png: Outstanding hero + Settle now,
      discount-deadline banner, aging buckets (0–5/6–10/11–15/Overdue),
      payable breakdown (cashback/fee/GST + tx count + pending
      adjustments), wallet card, credit CTA.
- [x] Settlements per Settlements.png: amount-due hero + Pay now, discount
      banner with oldest due date, **payment method** (wallet with
      insufficient state vs bank transfer), breakdown, included
      transactions, recent settlements; wizard = select (buckets presets,
      settle-all nudge) → preview (discount verdict, struck-through fee,
      credit applied, platform bank instructions with copy buttons) →
      receipt camera/file upload (≤5MB, pre-flight) or wallet settle;
      detail screen with status story, lines, payments, add-receipt.
      (Settle-all is the default MODE and travels as `settle_all`; a preset
      narrows to the SERVER's bucket ids and POSTs exactly those.)
- [x] Wallet: balance + movements.

### MR4 — Notifications (the owner requirement)
- [x] Server reminder work (section above) + tests incl. the day-9 fire,
      dedupe, and ladder-to-push. (Shipped 2026-08-17: five template keys
      seeded active English-only; SettlementReminderTest covers the day-9
      fire, silence when no discount is earnable, same-day dedupe,
      config-derived thresholds, ladder-to-push double-run, permission
      filtering, and template attribute caching.)
- [x] App push wiring + tap routing + foreground banner. Shipped behind
      well-formed PLACEHOLDER config (`android/app/google-services.json` +
      `lib/firebase_options.dart`, both loudly marked): the build passes
      the google-services plugin today; `Firebase.initializeApp` succeeds;
      `getToken()` fails and is swallowed by the registrar's guards.
      (2026-08-17; debug APK build verified against the placeholder.)
- [ ] **The ONLY remaining step** (owner action, Firebase console):
      register the Android app **`mv.manfaa.merchant`** in project
      `manfaa-6e1b4` (server FCM service account already covers it), then:
      1. download its real `google-services.json` over
         `mobile/merchant/android/app/google-services.json`;
      2. copy `api_key.current_key` → `apiKey` and `mobilesdk_app_id` →
         `appId` in `mobile/merchant/lib/firebase_options.dart` (android
         block; the project values already match) — or run
         `flutterfire configure`;
      3. rebuild. ZERO code changes — push lights up. iOS later (APNs .p8
         still missing, inherited blocker).

### MR5 — More (management estate)
- [x] More screen per Merchant More.png (identity card + menu + logout).
      (2026-08-17, engineer A: six tinted rows each behind its own read
      permission — absent slug, absent row; Cashback row any-of its three
      section slugs like the web menu; Verified chip only for
      status=active; log-out confirm dialog; agent-B rows route to real
      paths behind the coming-soon pattern. Router grew kMoreGuards
      permission redirects for the /more/* estate.)
- [x] Profile per Profile.png (logo, names en+dv, category, channel,
      contacts, website, what-earns-cashback chips; edit via PATCH).
      (2026-08-17, engineer A: view + edit; the ref's chips render as the
      ONE eligibility_basis string per reconciliation; support-phone tick
      BY COMPARISON sending the contact value; category travels only when
      changed — retired slug stays pickable; logo replace through the MR1
      setup endpoint with the 2MB/type pre-flight. manfaa_core grew
      MerchantProfile + profile GET/PATCH with wire tests.)
- [x] Manage Employees per Manage Employees.png (stat tiles, search, rows
      with role + status chips incl. Suspended; invite → one-time temp
      password reveal; role reassign + active toggle; last-owner guard).
      (2026-08-17, engineer B: reconciliation applied — stat tiles are
      REAL counts (total + active; no invite state server-side, so no
      "Pending invites"), row subtitle is the EMAIL. Invite sheet's role
      picker enforces D5 client-side (mayAssignRole; actor ownership read
      off the staff list by email — mobile /me carries no role); the
      one-time temp password dialog is locked until acknowledged, with
      copy. Edit sheet mirrors the guards as sentences (last active
      owner, self-demote/deactivate) AND renders the server's refusal
      sentence. Permissions-overview footer from real per-role counts.)
- [x] Roles per Roles.png layout (role cards with counts; editor =
      checkbox grid from the SERVED catalogue, delegation rules: only
      what you hold, owner role frozen, in-use delete refusal).
      (2026-08-17, engineer B: green ref restyled violet; owner card gets
      the Full Access treatment, frozen — no edit/delete. Editor grid is
      grouped from GET /merchant/permissions (D8 — server wording,
      verbatim); un-held slugs draw disabled+locked with the hint, stored
      slugs stay tickable (only ADDITIONS are a grant); name_dv is
      tri-state on the wire (nameDvChanged). role_in_use / cap /
      permission_not_held refusals render as the server's sentences.)
- [x] Manage Branches per Branches.png layout (stat tiles, search, cards
      with name/address/pin/zone + active state; add/edit dialog with
      flutter_map pin picker; delete → 409 branch_referenced handling).
      (2026-08-17, engineer B: reconciliation applied — no photos, hours,
      phone or active flag (branches carry none); the stat strip counts
      what is real: total / pinned / no pin. Numbered tinted circles,
      kebab edit/delete per slug. Sheet reuses the setup step's
      drag-under-a-fixed-pin picker; the pin is a PAIR — set or cleared
      whole. Delete confirm, then 409 branch_referenced as the localized
      sentence.)
- [x] Cashback Settings per Cashback Settings.png (general rate with §7
      timing copy, per-category rules incl. excluded mode, min eligible
      sale, validation window; matches the web's merged screen).
      (2026-08-17, engineer A: three sections each on its own permission;
      rate edit sheet posts the EXACT percent string and renders the
      SERVER's change.applies/effective_at answer — timing never derived
      client-side; ref's category toggle omitted (no backing data),
      "Customer visibility: Pending" rendered as static info; the ref's
      violet Save CTA saves the two preference knobs (laari integers, max
      window from the server). manfaa_core: changeRate/RateChangeSummary,
      preferences read-by-empty-PATCH, product-category writes — 14 wire
      tests. Sheets open on the ROOT navigator (the floating nav bar
      otherwise overlays their CTA). Goldens more_{light,dark},
      profile_light, cashback_settings{,_earning}_light EYE-checked vs the
      refs; suites green: merchant 75, core 86, ui 3, customer 35.)
- [x] Promotions (list, create draft with cost preview + tier-cliff
      warning, publish confirm — immutable once live, cancel draft only).
      (2026-08-17, engineer B: no ref — house card idiom + the web page's
      content. Draft/Live/Published/Ended/Cancelled chips off status +
      is_live (the SERVER's answer, never re-derived); stale-draft null
      fees render a dash line, publish left to the server's refusal.
      Builder validates as integer bp (exact-string wire), picks the
      window in Maldives wall time sent with explicit +05:00, laari
      integers, branch scope behind branches.view. cost_preview arrives
      at the ROOT beside data on create/publish and renders verbatim —
      delta and tier-cliff warning included. Publish sits behind the
      immutable-once-live confirm; cancel is draft-only. manfaa_core grew
      staff/roles/catalogue/branches/promotions clients — 16 wire tests
      (core 102). Merchant suite 86: 6 estate contract tests (temp-pw
      reveal locked till acknowledged, delegation-disabled checkboxes,
      last-owner lock + server-sentence render, branch 409 sentence,
      publish confirm) + goldens employees_{light,dark}, roles_light,
      branches_light, promotions_light EYE-checked vs the refs.)

### MR6 — Release
- [x] Branding from Merchant App Logo.png: launcher icons + splash
      (flutter_launcher_icons / flutter_native_splash), app label
      "Manfaa Merchant". (2026-08-17: the illustrative storefront mark
      extracted from the owner's logo file → branding/icon-1024.png +
      adaptive-foreground; splash on the app canvas #F6F6F9.)
- [x] NEW keystore `manfaa-merchant-release.jks` + key.properties
      (separate key from customer — deliberate). (2026-08-17: jks +
      key.properties copies live beside the customer's in /home/ubuntu,
      chmod 600; OWNER: take an OFF-SERVER backup — losing this key
      orphans every install.)
- [x] Download slot: `manfaa-merchant.apk` on the /app page (second card),
      Cloudflare purged; MOBILE_MERCHANT_ANDROID_* gates set in api/.env
      and served by /api/mobile/v1/config. (2026-08-17.)
- [x] v1.0.0+1 release APK built, signed with the merchant key
      (verified: CN=Manfaa Merchant), live at
      https://manfaa.app/app/manfaa-merchant.apk (78 MB, edge-fresh).
      On-device pass = the owner's install. (2026-08-17.)

### MR7 — Tablet optimisation (merchant app only; owner-agreed 2026-08-17)

Deferred by design until MR0–MR6 land. Phone-first per the refs; the
column-of-cards layouts carry no fixed-width assumptions, so this round
is additive. Customer app stays phone-only (owner call).

- [x] Width-adaptive shell: content rail capped ~640dp on phones-in-
      landscape/small tablets; ≥840dp gets a NavigationRail (left) instead
      of the bottom bar — same 5 destinations, same permission gating.
      (2026-08-17: lib/widgets/adaptive.dart — kMediumMinWidth 600 /
      kExpandedMinWidth 840, ContentRail width-cap wrapper +
      bottomClearanceOf() so navClearance never leaves dead space under
      the rail shell; house-styled _NavRail in shell.dart, same kTabs
      items list. Adopted by Dashboard/Credit/More; estates screens adopt
      the same wrapper in their half.)
- [x] Two-pane estates where a list drives a detail: Transactions
      (list | amend/detail), Settlements (list | detail/receipts),
      More → Employees/Roles/Branches (list | editor). (2026-08-17:
      ≥840dp CONTENT width only; phones byte-identical. Transactions:
      selectable tiles | detail pane with the same-window amend form
      inline + the cancel dialog; Settlements: overview | the detail
      body shared with the /settlements/:id route (receipt flow
      included; the PAY flow stays a pushed route — a half pane would
      cramp it); Employees/Roles/Branches AND Promotions: the phone's
      bottom-sheet editors seated in the right pane via callbacks
      instead of route pops, same fields/guards/delegation locks;
      empty selection renders a quiet PaneHint. Selection survives
      rail navigation (session StateProviders / branch-kept state).
      Remaining single-column screens (wallet, profile, profile edit,
      cashback) adopted ContentRail + bottomClearanceOf. Goldens:
      tablet_{transactions,settlements,employees,roles,branches,
      promotions}_light, eye-checked; contract tests in
      tablet_test.dart; every pre-existing phone golden verified
      byte-identical.)
- [x] Till on tablet: Credit screen as two columns ≥840dp — identify +
      amounts left, split editor + cost preview + CTA right (the counter
      use-case this round exists for). (2026-08-17. Phone column
      untouched; result card keeps the 640 rail on the wide canvas.)
- [x] Dashboard: aging buckets 2×2 → 4-across; cards flow into two
      columns. (2026-08-17: hero + deadline banner full-width above;
      left = payable breakdown + credit CTA, right = wallet + Today.)
- [x] Unlock landscape on tablets only (keep phones portrait if that is
      what the manifest does today — check, don't assume). (2026-08-17:
      CHECKED — nothing is locked anywhere: no setPreferredOrientations,
      no android:screenOrientation, iOS plist allows portrait+landscape
      on both idioms. Landscape already works on every device, so per the
      round's own rule nothing was changed.)
- [x] Golden harness gains a tablet surface (1280×800 or similar) for the
      key screens; eye-check vs taste, no refs exist for tablet.
      (2026-08-17: tabletShot() 1280×800 @2x → tablet_dashboard_light /
      tablet_credit_light / tablet_more_light, eye-checked; estates
      screens add their own tablet shots in their half. Every
      pre-existing phone golden verified byte-identical.)
- [x] Text-scale + keyboard/mouse sanity pass (tab focus on the code
      boxes, scanner-gun Enter submits the code field). (2026-08-17:
      CodeBoxes' real TextField takes hardware digits + Enter — Enter on
      a verified code walks focus to the invoice field, retries a failed
      lookup, stays quiet under six digits; Enter on invoice/amount
      fields submits only when the form is complete (same _submittable
      gate as the CTA). Widget-tested in credit_test.dart, plus a 1.3
      text-scale no-overflow pass on the till.)

### WL — merchant.manfaa.app landing (web side-quest; owner-approved 2026-08-17)

A lean front door, not a marketing site. Today `/` redirects signed-out
visitors to a bare login wall — yet the subdomain is the address that
gets said aloud and printed. The main site keeps the SEO-weighted pitch
(manfaa.app's business section already CTAs to /signup); this page just
converts whoever arrives directly. Can run beside any MR round — it
touches only apps/merchant.

- [x] Root `/`: signed-in → dashboard exactly as today (session-cookie
      presence check, real auth stays with the app layout); signed-out →
      landing instead of the login bounce. (Shipped 2026-08-17.)
- [x] Landing content (one page, violet system, en+dv): concise pitch —
      customers discover you on the app and map; you set your own
      cashback rate; simple settlement with the 5% prompt-payment
      discount — prominent **Register your store** → existing /signup
      wizard, **Log in** beside it.
- [x] Do NOT duplicate manfaa.app's marketing content; keep it a lean
      converter.
- [x] Nice-to-have: render the merchant app's real Dashboard via the
      screenshot-harness pattern (marketing fixture) as the landing's
      phone mockup, like the manfaa.app hero. (marketing_dashboard shots
      in the merchant screenshot harness → app-dashboard-{light,dark}.png.)
- [x] Split-panel auth pages (same round, owner yes 2026-08-17): /login
      and /signup entry step get a split layout — form on one side, the
      landing's OWN pitch panel (same component: three bullets + mockup)
      on the other — so a bookmarked login still tells the brand story.
      Entry step only: inside the signup wizard the panel disappears and
      the form stays clean. Today's login is a bare centered card on
      grey; that look retires.
- [x] After MR6 ships the APK: the landing links the download
      (2026-08-17).

## MR8 — Owner on-device feedback round (reported 2026-08-17, after v1.0.0)

The owner's first real pass over the shipped APK + web panel. Runs after
MR7 lands. Items keep the owner's intent verbatim; each names where the
fix lives.

### Bugs

- [x] **Promotion publish timezone bug** (server + both clients): owner
      picked the 18th, refusal says the 17th is in the past — the window
      validation compares in the wrong timezone. Fix: evaluate
      starts_at/ends_at in the business timezone (Indian/Maldives);
      ALLOW a start up to 24 hours in the past (owner decision) so a
      same-day promo publishes; and format the date in refusal messages
      as a human local date, never a raw shifted timestamp. Audit what
      the web panel sends (naive vs +05:00) — the app already sends
      explicit +05:00.
- [x] **Branch map grey screen** (app branches add/edit + check web):
      the pin-picker map fails to load tiles when creating a branch.
      App uses flutter_map/OSM — check tile requests on-device (OSM
      blocks default user agents: set userAgentPackageName; also check
      release-APK cleartext/network config). Web setup map is Google
      (NEXT_PUBLIC_GOOGLE_MAPS_API_KEY) — verify key referrer
      restrictions cover merchant.manfaa.app.
- [x] **Dashboard staleness after crediting** (app): after a credit —
      especially a backdated one that is immediately payable — returning
      Home still shows the old outstanding/due figures. Invalidate the
      dashboard providers (outstanding, settle-all preview, home) on
      every successful credit submit, and refresh on tab re-entry.
- [x] **Pay-now shows a stale amount** (app settlements): the
      "Transfer exactly this amount" step keeps the preview it was
      opened with — after the balance changes (a fresh credit, another
      settlement) the amount-to-transfer is wrong until the owner backs
      out, refreshes and re-enters. The pay screen must re-fetch its
      settlement preview every time it opens (and before submit, since
      the server prices the slip amount server-side anyway — surface a
      changed amount rather than letting a mismatched transfer happen).
- [x] **Split-by-category: eligible amount must not contradict lines**
      (app, web parity): when the split is ON, hide the eligible-amount
      field and derive it from the lines sum in the background — the
      owner hit "doesn't add up" from the visible field drifting.

### UX

- [x] **Split editor add-category rework** (app credit screen): the
      add-category popup is not friendly. Replace with inline add-row:
      each row = searchable category dropdown + amount field; "Add row"
      appends; delete per row. Keep the sum check + Everything-else
      bucket semantics.
- [x] **Tab switch resets the left tab** (app): navigating to another
      tab resets the tab being left to its root/fresh state, so
      returning never lands mid-flow.

### Features

- [x] **Employee management completeness** (server + web + app): add
      staff PASSWORD RESET (owner/manager-triggered, one-time temp
      password reveal like invite) and EDIT of staff details (name; and
      email if server allows) — currently only role + active toggle
      exist everywhere.
- [x] **Merchant app legal + closure estate** (store-readiness): More
      gains Privacy Policy and Terms of Service entries (the
      merchant.manfaa.app/{privacy,terms} documents) and a
      close-store/delete-account entry riding the shipped
      /api/merchant/account-closure flow (in-app, phone+OTP, settle-first
      gate — same rules as the web page).
- [ ] **Admin approval flow for store edits + new branches** (owner
      decision — needs a design pass first): public-facing store changes
      (profile edits, new branches) should not go live silently; they
      queue for admin approval like initial onboarding. Design the
      pending-changes model (what is held: public fields + branches;
      what stays instant: operational settings), the admin review queue
      UI, and the merchant-side "pending approval" states across web and
      app. Server + admin panel + both merchant surfaces.

## MR9 — Admin approval for store edits + new branches

The owner's ask: public-facing store changes should not go live silently.
Design decided 2026-08-18 — the line is **claims vs operations**.

**QUEUES for admin review** (what a shopper reads and trusts):
- Store name, Dhivehi name, category, channel, logo, website
- "What earns cashback" (eligibility_basis) — the promise to shoppers
- NEW branches, and edits to a branch's name/address/pin; branch delete

**STAYS INSTANT** (the merchant's own business — never blocked):
- Cashback rate (§7 timing + pricing guards already govern it, and "you
  set the rate" is the platform's pitch), product-category rules, minimum
  eligible sale, validation window
- Staff, roles, bank account, preferences, promotions (own lifecycle)
- Contact email/phone + support phone: CORRECTIVE, not a claim — a wrong
  number means customers cannot reach the store, so review would only
  prolong the harm

**Rules:**
- Gating applies only to LIVE stores (active/suspended). Draft /
  pending_review / rejected keep writing directly or onboarding deadlocks.
- Admin-panel edits apply directly — admins are the approvers.
- One pending request per merchant per type; re-submitting SUPERSEDES so a
  merchant is never stuck behind their own earlier request.
- Approve applies atomically, busts the discovery cache, notifies the
  merchant; reject requires a reason and notifies.
- Merchant surfaces show a "pending review" state carrying the proposed
  values, so the owner sees exactly what is waiting.

- [ ] Server: `merchant_change_requests` + domain service, gating in the
      merchant Profile/Branches controllers (web AND mobile mounts share
      the controllers), admin review endpoints mirroring store-reviews
      (list for admins, approve/reject for superadmin), two notification
      template keys, tests incl. the onboarding-not-gated case.
- [ ] Admin panel: review queue + before/after diff, approve / reject.
- [ ] Merchant web panel: pending-review states on profile + branches.
- [ ] Merchant app: same pending states; MR7's panes included.

## Settlement reference — findings from the owner's 2026-08-18 question

"If multiple merchants submit receipts at the same time, won't they all get
ST-2026-00003?" Investigated and PROVEN with 8 concurrent processes against
the test database: **no**. `SettlementBuilder::nextReference()` takes a
Postgres transaction-scoped advisory lock (keyed per business-tz year)
inside the `createDraft` transaction, so mints serialise — 8 racing
processes produced 8 distinct sequential references. Counterfactual with
the lock removed: all 8 computed 00001, one won and 7 died on the
`settlements_reference_unique` index. Both layers work, and the unique
index is a real backstop rather than decoration.

Two genuine issues the investigation surfaced:

- [ ] **The quoted reference can drift** (product, not correctness): the
      pay screen shows `peekReference()` — deliberately lock-free — and
      the merchant copies it into their BANK transfer before submitting
      the slip. If another store submits first, the settlement lands with
      the next number, so the purpose line at the bank no longer matches
      the settlement. The UI already says the final one is official, and
      admin reconciliation matches on merchant + amount + slip + the
      merchant-entered `bank_ref`, so nothing breaks — but the quoted
      string is a small lie. Options for the owner: (a) leave it (cheapest,
      documented), (b) quote a STABLE merchant-scoped purpose instead
      (e.g. `MANFAA <slug>` or the merchant code) which can never drift,
      (c) reserve the number by creating the draft when the pay screen
      opens (costs abandoned drafts + sequence gaps). RECOMMEND (b).
- [ ] **String-max ceiling at 99,999 per year**: `peekReference()` picks
      the next number via `max('reference')` on a 5-padded string. At
      100,000 the padding breaks ordering ("…-99999" sorts above
      "…-100000"), so the sequence would restart and collide against the
      unique index. Far away at today's volume (2 settlements to date),
      but it is a hard wall rather than a soft one — fix by ordering on a
      derived integer, or widen the padding, before that year arrives.

## Store readiness (App Store / Play — from the 2026-08-17 approval review)

The model itself is store-safe (cashback on physical retail = Rakuten's
category; customer app ships as Shopping, merchant app as Business). The
approval risk is compliance details:

- [x] Privacy policy, Terms, and account-deletion pages — customer set on
      manfaa.app/{privacy,terms,account-deletion}, merchant set on
      merchant.manfaa.app/{privacy,terms,account-deletion}; English-only
      LTR legal documents, linked from both footers. (2026-08-17. Owner
      to-dos flagged: confirm the legal entity name used ("Manfaa Pvt
      Ltd") and stand up the support@manfaa.app mailbox; lawyer review
      recommended before store submission.)
- [ ] IN-APP account deletion (customer app; Apple 5.1.1(v) + Play
      require it) — SERVER + WEB SHIPPED 2026-08-17: phone+OTP
      self-service flows live on both /account-deletion pages
      (customer: anonymise-not-delete, balance warning, tombstoned
      phone frees the number; merchant: settle-first gate, staff doors
      shut). Remaining: the one-tap flow in the customer app's Profile
      riding on POST /api/customer/account-deletion/*, and the signup
      screens (web done, apps pending) using verify-otp's new
      `already_registered` flag to stop existing numbers at the code
      step.
- [ ] OTP review bypass: a designated test number whose SMS code is
      static server-side (reviewers cannot receive Maldivian SMS) — both
      apps, gated so it never works for real numbers.
- [ ] Organization developer accounts in the company name (Apple needs
      D-U-N-S; start early, takes weeks).
- [ ] App Privacy (Apple) / Data Safety (Play) questionnaires + reviewer
      notes ("merchant-funded cashback, no deposits, no investment").

## Standing blockers / owner asks
- Firebase console: register `mv.manfaa.merchant` (Android; iOS later).
  App side is fully coded behind placeholders — the swap procedure is in
  the MR4 round notes above and in `lib/firebase_options.dart`'s header.
- APNs .p8 still missing (iOS push silent until then) — inherited blocker.
- FCM service-account key rotation — inherited blocker.
- Forgot-password for merchant users: no backend; decide want/skip.
- Login field says "Email or phone" in the mock; merchant accounts are
  email-only today — shipping as Email unless the owner wants phone
  identities added server-side.
