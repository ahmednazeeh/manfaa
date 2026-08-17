# Round plan — the Manfaa Customer App (Flutter, Android + iOS)

Started **2026-08-17**. PLAN.md §13b is the long-lived record; this file is the
scratch that survives a context loss. Fold it in and delete it when the app
ships.

Owner instruction, verbatim:

> time to start developing android/ios flutter Customer App.. setup project,
> service account keys, project credentials I gave for android and ios and
> service account credentials. Native modern UI for Customer App. Note that
> provided project manfaa-6e1b4-firebase-adminsdk-fbsvc-3760e225bc file and
> the plist is for customer app. merchant app ones will be created once ready
> for creating merchant app. Customer app must have all features web does.
> Especially the Dashboard. Must be colour modern native Mobile UI. Not a
> direct port of web UI. Ask questions, create a plan file

Questions were asked and answered 2026-08-17:

| Decision | Answer |
|---|---|
| Code home | **In the rewards monorepo** — `mobile/` beside `api/` and `apps/` |
| Sign-in | **Phone + OTP, passwordless** (needs one new API round — see R1) |
| Visual direction | **Rose-led Manfaa brand**, light-first, dark tokens from day one |
| iOS builds | **Codemagic CI** (it will build Android too — no local Android SDK needed) |

---

## 1. What exists, and what this app builds on

**The API is finished and adversarially reviewed.** PLAN-mobile-api.md: five
phases (tokens, transport contract, speed, push, write reliability) plus two
review passes, 31 defects found and fixed, suite 1166 green, committed on
branch `mobile-api`. The client contract is `docs/mobile-api-guide.md` — it is
the law for this app: the error envelope, 401-means-wipe, ETag/304, retry
rules, the version gate, device management, push registration.

**Credentials, verified 2026-08-17** (all `manfaa-6e1b4`):

| File | Identity | Goes |
|---|---|---|
| `google-services.json` | Android app `mv.manfaa.app` | `mobile/customer/android/app/` — ships in the APK, not secret |
| `GoogleService-Info.plist` | iOS app `mv.manfaa.app` | `mobile/customer/ios/Runner/` — ships in the IPA, not secret |
| `…-firebase-adminsdk-….json` | **Service account — SERVER ONLY** | already live in `api/.env`. **Never enters the app**: it can push to every device in the project, and an APK is unzippable. |

**Correction recorded:** service accounts are per-PROJECT, not per-app. The
merchant app registers `mv.manfaa.merchant` in this same Firebase project when
its time comes and reuses the same server key — no new service account.

**Mockups reviewed 2026-08-16** (`/home/ubuntu/Customer App Idea Manfaa/`
PNGs): competent but a web port. The critique is accepted design input and
§4 below bakes it in — code-first Home, empty states that teach, pending with
a clock, no 3D-stock illustration clash, Thaana proven not promised.

**QR spec:** the web encodes the bare 6-digit `customer_code`, byte mode
(apps/web/lib/qr.ts). The app renders exactly that payload with `qr_flutter`;
the merchant scanner already reads it.

---

## 2. Identity and layout

- **applicationId / bundle id: `mv.manfaa.app`** on both platforms — matches
  the Firebase registrations; permanent once published, chosen deliberately
  (`mv.` = the TLD Manfaa operates under; `mv.manfaa.merchant` reserved).
- Display name: **Manfaa** (dv: މަންފާ).
- Monorepo layout:

```
mobile/
  customer/                  # the app (mv.manfaa.app)
  packages/
    manfaa_core/             # SHARED with the future merchant app:
                             #   api client (envelope, bearer, ETag, retry),
                             #   money (laari→text, en/dv), session store,
                             #   config gate, models
    manfaa_ui/               # design tokens, theme, Thaana type scale,
                             #   shared widgets (money text, status chips)
```

The merchant app later lands as `mobile/merchant` importing both packages —
that is the whole reason they exist now.

## 3. Architecture decisions

- **Flutter stable** (installed at `/opt/flutter`), Material 3.
- **Riverpod** for state, **go_router** for navigation, **dio** for HTTP.
- **API base** `https://manfaa.app/api/mobile/v1`, overridable per build with
  `--dart-define=API_BASE_URL=`. No staging exists; dev runs against live with
  test accounts, which is how the panels are developed too.
- **The client obeys the guide**, mechanically, in `manfaa_core`:
  - every error is `{error:{code,message,meta}}` → typed exception; unknown
    `code` renders `message`; **never a raw snake_case string on screen**;
  - **401 = wipe token + local state, return to sign-in** — one place, the
    auth interceptor;
  - ETag cache sends `If-None-Match`, serves the cached body on 304
    (`/config`, `/me`, `/home` — the endpoints built for it);
  - retry: network + 5xx with backoff+jitter; 429 honours `Retry-After`;
    documented 4xx terminal;
  - boot calls `/config` first: `minimum_build` below ours → hard update
    screen (the emergency lever the API grew for exactly this);
  - token in **flutter_secure_storage** (Keychain/Keystore), never prefs.
- **Firebase via hand-written `DefaultFirebaseOptions`** (values from the two
  config files) — programmatic init, no flutterfire CLI login needed on this
  server; the json/plist still sit in the native projects for the standard
  tooling. `firebase_messaging` only; analytics stays off.
- **i18n: en + dv from the first screen.** Flutter's own material
  localizations do NOT include Divehi, so `dv` needs fallback delegates
  (our strings translate; framework strings fall back to English) — a known
  Flutter gap, handled once in `manfaa_ui`. Thaana fonts bundled from
  `/home/ubuntu`: **Faruma** (body), **MV Waheed** (display); RTL mirroring
  throughout. Every user-facing string lands in ARB with a Thaana twin —
  machine-drafted, flagged for the same native review the templates await.
- **Design tokens, rose-led, light-first + dark:** primary rose (from the
  logo — the mark finally carried through the UI), warm stone neutrals,
  emerald = confirmed money, amber = pending/conditional money, sky = info.
  Category hues for Discover. All as tokens in `manfaa_ui` so dark mode is a
  palette, not a rewrite, and the merchant app inherits the system.

## 4. The screens — a rethink, not a port

Four tabs, not the web's five: **Home · Discover · Activity · Profile**
(transactions + payouts merge into Activity — both are money history).

**Home — "especially the Dashboard".** Ordered by till-queue reality, not by
the web's card order:
1. **The code is the hero.** Large tappable code card → **fullscreen QR**:
   brightness forced up, works offline (code cached), one tap from anywhere.
   This is the app's whole job at a till and the reason it beats the website.
2. Balance: **Confirmed is the headline** (§10, non-negotiable), pending a
   separate conditional line — with *"Store A confirms within N days"* read
   from history, the clock the mockups lacked.
3. **Day-one empty state teaches**: "Show your code at any Manfaa store to
   start earning" + the nearest stores — never a giant MVR 0.00.
4. Payout-account nag when missing (nobody gets paid without one), next
   window + minimum; paid-this-month demoted to a footnote.
   One request: `GET /customer/home`, conditional.

**Discover.** Native shelves, not the web grid: featured offer banners
(image-only or server-composed text — the §13b two-kinds rule), boosted rates
as "8% — usually 5%", curated categories, search, Near you (list first; map
later round), store page with rate, **eligibility terms** (the §11
over-promise guard), channel, branches.

**Activity.** Segmented Earned | Paid out. Cursor infinite scroll (the API's
paging exists because this list grows at the top), per-item customer-facing
status with localized reason keys, payout detail showing what each transfer
covered.

**Profile.** Payout account (view/update bank details), **Devices** (the
lost-phone screen: list, revoke one, sign out everywhere), language en/dv,
notifications on/off (push-token register/delete), about + build + licences,
sign out.

**Onboarding.** Phone → OTP → new customers add name → in. One flow for
sign-in AND signup (the OTP proves possession either way — R1 API). Push
permission asked *after* first sign-in, in context, never on first launch.

**Push.** Deep-links: payout-paid → Activity/Paid, cashback-earned → Activity.
Registration bound to the auth token per the API design; delete on sign-out is
server-side automatic (FK cascade).

## 5. Rounds

Feature rounds run per house convention: workflow fan-out, full suite,
**adversarial review, verifier-fixer**. `flutter analyze` + `flutter test`
green at every round end.

- **R0 — Foundation — DONE, 2026-08-17.** Flutter stable at `/opt/flutter`
  (this server analyses and tests; Codemagic builds). `mobile/customer` +
  `manfaa_core` + `manfaa_ui` scaffolded; identity `mv.manfaa.app` on both
  platforms; json/plist placed and set to commit (client config, not
  secrets); hand-written `DefaultFirebaseOptions`; rose tokens + light/dark
  themes; en+dv ARBs with the dv framework-fallback delegates (the widgets
  one forces RTL — Flutter ships no dv, and falling back to English must not
  fall back to LTR; pinned by test) and Faruma/MV Waheed bundled; core api
  client implementing the guide (envelope→typed exception, bearer + the
  401-wipe rule, ETag/304 memory cache, GET-only retry with Retry-After);
  version gate obeyed at boot BEFORE sign-in (pinned by test); 4-tab shell;
  Home already real against `/customer/home` — code-first hero card,
  fullscreen offline-capable QR, Confirmed-headline balance with pending as
  conditional amber, teach-don't-zero empty state, payout nag/window;
  Profile with working language switch + sign-out; debug-only password
  sign-in exercises the live session stack until R1's OTP lands.
  **analyze clean ×3; tests 17/17** (core 12, ui 2, app 3).
  `codemagic.yaml` at repo root.
- **R1 — OTP auth, end to end — BUILT 2026-08-17; adversarial review IN
  FLIGHT and gates the round.** Backend: `OtpService::verifyForAccess` (the
  same locked single-use redemption, a second outcome — existing account →
  bearer token, no signup token minted; the Customer read happens INSIDE the
  locked transaction), `OtpRequestLimiter` extracted so web+mobile share ONE
  SMS budget (identical keys; alternating surfaces cannot double it),
  `Mobile\CustomerOtpController` request/verify/register, passwordless
  accounts via `Str::password(40)` — RECORDED CONSEQUENCE: they cannot use
  the web password login until a web OTP flow ships. Suspended accounts:
  403 `account_unavailable` AFTER possession is proven (register()'s own
  enumeration stance) and the code is consumed. 12 API tests; suite 1182.
  App: full phone→code→name flow, resend cooldown, error-code→localized
  mapping (unknown code → server prose, pinned by test), debug password path
  REMOVED. Guide §2/§4 rewritten. SIM-swap mitigation (fresh OTP before
  payout-account changes) lands with R5's payout-account screen.
- **R2 — Home + fullscreen QR — DONE 2026-08-17.** Fullscreen QR now forces
  brightness up and restores on exit (guarded; no channel in tests). Empty
  state gained its call to action (Browse stores → Discover — the
  nearest-stores teaser follows R4's models); pending block → Activity;
  payout nag → Profile; skeleton loading (the layout's pulsing shape,
  nothing spins). The per-store pending clock moved to R3's reason lines,
  where the transaction data honestly lives.
- **R3 — Activity — DONE 2026-08-17.** Backend: `/customer/payouts` +
  `/payouts/{id}` on the mobile tree, cursor-paged, web rules verbatim
  (pending hidden — unpromised money; failed shown; own rows only; 4 API
  tests, suite 1182). App: segmented Earned|Paid out, cursor infinite
  scroll with footer loader, status chips on the money-semantic palette,
  reason KEYS rendered as localized en+dv sentences with unknown keys
  falling back generically (never snake_case — pinned by test), payout
  detail with covered purchases and masked account. App tests 8/8.
- **R4 — Discover + store page — DONE 2026-08-17 (map deferred on the key
  blocker, as specified).** Public discovery models in manfaa_core (the rate
  stays a 2-decimal percent STRING — display text, never a double); the
  public endpoints live one level above /mobile/v1 (ApiEnv.publicBaseUrl).
  Discover: offer carousel obeying the §13b two-kinds rule — an image banner
  IS the artwork, a text banner is COMPOSED with the live rate so a stale
  percentage can never be baked into a picture; category rail with stable
  per-slug hues; shelves in the plan's order, Boosted first with
  "8% — usually 5%"; debounced directory search; store page with the §11
  eligibility card, per-category rates, branches, contact. Logos render in a
  fixed circle with a monogram fallback (real Maldivian logos are
  inconsistent; the container keeps rows tidy). Widget tests pin the boosted
  comparison, the live-rate text banner and the eligibility card — and
  caught a real 6px overflow on boosted cards before any device did.
  Map view still gated on: restrict the shared Google Maps key, mint
  per-platform mobile keys.
- **R5 — Profile.** Payout account (+ fresh-OTP gate), devices, language
  switch, push registration + notification routing + permission flow.
- **R6 — Polish — MOSTLY DONE 2026-08-17.** DONE: dark-mode pass (fixed a
  real bug — the money-semantic soft cards kept their pale light wash on a
  dark surface; a brightness-aware `toneSurface()` resolver now darkens them
  and brightens their text, with StatusChip and every standalone soft card
  routed through it); the notifications on/off toggle (the deferred R5 item —
  a persisted `pushEnabled` preference the PushRegistrar honours, deleting/
  re-registering the FCM token on flip); accessibility (Semantics on the QR
  hero, a search tooltip, a 1.6× text-scale overflow smoke test); a
  locale × theme test MATRIX (en/dv × light/dark, dv confirmed RTL). App
  tests 22/22. **STILL OWED, and I cannot do it:** (1) the NATIVE THAANA
  REVIEW of the app ARBs + the four server notification templates + the push
  titles, together — everything Dhivehi is machine-drafted; (2) PIXEL golden
  tests, deferred to the macOS build box (font hinting differs by platform,
  so Linux goldens would fail there — generate with --update-goldens on CI).
- **R7 — Release.** Icons/splash from the brand mark, store listings (en+dv),
  Codemagic signing for both stores, **APNs .p8 upload** (still outstanding —
  iOS push silently dead until then), version-gate values set, privacy
  policy URL + Play data-safety forms.

## 6. Testing

- Unit: money formatting (laari ints in → exact strings out, en and dv),
  envelope mapping, ETag cache, retry policy, config gate comparison.
- Widget: Home states (empty / earning / pending), sign-in flow, 401 wipe.
- Golden: key screens in en-LTR and dv-RTL, light and dark.
- Contract fixtures recorded from the real API (sandbox seeder data), so app
  tests never depend on the live service.

## 7. Risks and operational to-dos

- **Rotate the Firebase service-account key** — it passed through a chat
  transcript (flagged 2026-08-17; plumbing is proven, swap is one .env line
  + queue restart).
- **APNs .p8 not uploaded** — iOS devices will register and receive nothing,
  with no error surfaced anywhere. Gate for R7, needs the Apple Developer
  account.
- Apple Developer + Play Console accounts needed before R7; store review
  lead times apply.
- Google Maps: restrict the existing key, mint per-platform mobile keys
  before R4's map view.
- Thaana in the app is machine-drafted until the R6 native review.
- Play data safety + privacy policy URL are store-blocking, not optional.
- This server builds neither store artifact: **Codemagic builds both**.
  Local work is `flutter analyze`/`flutter test` (no Android SDK installed —
  deliberate; CI owns builds).
