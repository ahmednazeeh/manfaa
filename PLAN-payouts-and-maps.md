# Round plan — merchant/customer maps, and payouts that can run weekly

## STATUS — task list (2026-08-16)

The harness task tool disconnected mid-round, so the list lives here instead.

| # | Task | State |
|---|---|---|
| 43 | Pin the batch-window property with a regression test | **done** — the owner's own scenario is a named test; nothing was ever lost, only delayed |
| 44 | Build batches to an as-of date, not a calendar month | **done** — `PB-YYYYMMDD`, cutoff chosen, weekly runs possible |
| 45 | Collapse dual approval to a single admin | **done** — `SameApproverException` deleted, columns collapsed |
| 46 | Persist an `MNF` idempotency key on every item | **done** — Postgres sequence, NOT NULL, unique |
| 47 | Export the transfer sheet as xlsx | **done** — seven columns, numeric Amount Owed |
| 48 | Settle: upload the filled sheet, one customer, or all | **done** — one shared ledger path |
| 49 | Shared Google Maps loader | **done** — loader only in `packages/ui`, no styled markup |
| 50 | Pin merchant location at signup and in branch settings | **done** — new `setup/location` endpoint, wizard step at index 1 |
| 51 | Map view for the customer's nearby stores | **done** — entries now publish branch coordinates |
| 52 | Restore the wiped database + install backups | **done** — baseline seeded, nightly pg_dump at 03:25 |
| 53 | **Deploy the three frontends** | **BLOCKED on the owner.** See below — this is now load-bearing, not cosmetic |
| 54 | Browser-verify the two map surfaces | blocked by 53 (and the Chrome extension is currently disconnected) |
| 55 | Fold this round into PLAN.md §13b and delete this file | **done for §13b**; delete this file once 53/54 close |

### Why 53 stopped being optional

PHP deploys the instant it is committed; the Next.js apps only deploy when they
are rebuilt. So the API is already serving the new contract while the three
browser bundles are still the old ones — and for the admin payout screens that
gap is fatal rather than tolerable:

`PayoutBatchSchema` in the deployed bundle declares `approved_by_first`,
`approved_by_second`, `first_approved_at` and `second_approved_at`. Zod's
`.nullable()` permits a null VALUE, not a missing KEY, so when the API stops
sending those four the parse fails and both payout screens die at query time.
The survey called this exactly: *"the schema edit and the resource edit must
ship together; there is no tolerant window."* Committing the API half without
building the frontends opened that window.

Everything else survives the skew: zod strips keys it does not declare, so the
old storefront bundle simply ignores the new `branches` on each entry, and the
old merchant bundle ignores the new `location` step.

**So the deploy is the remedy, not the risk.** Until it runs,
admin.manfaa.app/payouts is broken.

Working plan for the round started **2026-08-16**. PLAN.md §13b stays the
long-lived record; this file is the scratch that survives a context loss.
When the round lands, fold a summary into §13b and delete this file.

Owner instruction, verbatim, so nothing is paraphrased away:

> for merchant location, intergrate google maps api. Copy google maps api key
> from avasprint project. On sign up, ask to pin location for merchant. with
> search map and select from map and locate me option. Same for customer side
> nearby stores feature. Builds a draft for the month's confirmed rewards. Two
> distinct admins must approve before export. should remove that. one admin
> should be enough. Also verify if Create a payout batch on august 27 and then
> next september would miss august 28 and after or not. if it does, will need
> to fix. The selected month, should be purely to be upto this day's confirmed
> cashback. Actually it would be better to Ask date. So that batches can be
> generated for until the poiunt and payouts can be processed even weekly.
> Make sure it can generate an excel sheet with these columns. A sequential
> Unique Key for Transfer prefixed MNF (Column Name should be Idempotency
> Key), Customer Name, Customer Phone, Customer Account Name, Customer Account
> Number, Amount Owed. Transfer Reference Number. (transfer reference number
> should be blank. So we can fill and upload. Allow uploading the file and
> marking batch as settled. or each customer settled individually in batch or
> settle all.

---

## 0. Answer to the verification question — asked and settled first

**Nothing is lost today, but everything after the 24th is delayed by a month,
and the "August 27" batch the owner describes cannot be built at all.**

`PayoutBatchBuilder::buildDraft($year, $month)` derives the cutoff as the
**24th at 23:59:59** business time (`CUTOFF_DAY = 24`), not the month end.
`EligibilityQuery::eligibleAt($cutoff)` then selects **every** confirmed
transaction with `payout_item_id IS NULL` confirmed at or before that instant
— it is not bounded below by the period start.

So for the owner's scenario:

- An August batch built on 27 Aug still carries a cutoff of **24 Aug**.
  Rewards confirmed 25–31 Aug stay unlinked.
- The September batch (cutoff 24 Sep) sweeps in everything unlinked ≤ 24 Sep,
  **including 25–31 Aug**.

So: **no money is missed** — the open-ended lower bound is what saves it — but
a reward confirmed on 25 August waits until the late-September run. That is
the real defect, and the date-based cutoff below fixes it.

Regression test to write first, so the property is pinned before the
refactor touches it: confirm a reward after batch A's cutoff, build batch B
with a later cutoff, assert the reward lands in B.

---

## 1. Scope

Five threads. 2–5 are one subsystem and share a migration series.

1. **Maps** — pin a merchant's location at signup and in branch settings;
   the same picker for the customer's nearby-stores view.
2. **Single approval** — drop the two-distinct-admins gate.
3. **Date-based batches** — build to an as-of date, not a calendar month, so
   payouts can run weekly.
4. **Excel export** — the seven-column transfer sheet, with a persisted
   `MNF…` idempotency key per item.
5. **Settlement in** — upload the filled sheet, or settle one customer, or
   settle all.

---

## 2. Ground already established (do not re-derive)

| Fact | Where |
|---|---|
| Google Maps key lives in avasprint's `storefront/.env.production` as `NEXT_PUBLIC_GOOGLE_MAPS_API_KEY` | **Already copied** into `apps/web/.env.production` and `apps/merchant/.env.production` (both gitignored) |
| A proven loader + picker to adapt | `/var/www/avasprint.com/storefront/lib/maps.ts` and `components/location-picker.tsx` — Maps JS + Places autocomplete, fixed centre pin, drag-to-adjust, "My location", `country: 'mv'` restriction, Malé default centre `4.1755, 73.5093` |
| Merchants already store coordinates | `merchant_branches.lat` / `.lng`, `decimal(10,7)`, nullable |
| Distance already works end to end | `DiscoveryService` computes `distance_m`; `/discover` has a "Near you" section behind a "Use my location" button |
| Payout state machine | `PayoutBatchState`: draft → approved → processing → sent → completed / partially_failed / cancelled; `PayoutItemState`: pending → sent → paid / failed |
| The ledger rule on payout | `ResultImporter::applyPaid` posts **one** `payoutSent` journal per item for the item's **stored** integer, and transitions each linked transaction confirmed → paid via `TransitionService` with reason `payout_completed` |
| Failure re-queues, never rewrites | `applyFailed` unlinks `payout_item_id` and leaves the transactions confirmed |
| Bank details are snapshotted onto the item at build time | `bank`, `account`, `account_name` — a later change on the customer never rewrites a built item |
| Customers without complete bank details are skipped, and the skipped money is surfaced | `excluded_customer_count`, `excluded_total_laari` |
| Per-customer minimum | `min_payout_laari` platform setting, MVR 100 default |
| Production is empty of payout data | 0 batches, 0 items — migrations may restructure freely |
| There is exactly **one** admin account | which is precisely why dual approval blocks the owner |
| No spreadsheet library is installed | `phpoffice/phpspreadsheet` to be added; `ext-zip`, `dom`, `xmlwriter`, `gd` all present |

---

## 3. Decisions taken for this round

1. **Cutoff is a date, chosen by the admin.** `POST /api/admin/payout-batches`
   takes `cutoff_date` (`YYYY-MM-DD`, business timezone, resolved to
   23:59:59.999999 of that day). `year`/`month` is removed, not deprecated —
   there is no external consumer, and two ways to say the same thing is how
   a cutoff silently drifts. Default in the UI: **today**.
2. **A future cutoff stays refused.** `CutoffInFutureException` survives: a
   batch built ahead of its cutoff would silently miss confirmations still
   to come.
3. **Reference becomes `PB-YYYYMMDD`.** Monthly `PB-YYYY-MM` cannot express
   two runs in one month. A second non-cancelled batch on the same date is
   still refused (`DuplicatePayoutBatchException`) — same intent as today.
4. **`period_start` is the previous batch's cutoff**, not the 1st of a month:
   with a rolling cutoff the honest period is "since the last one". Display
   only; eligibility remains open-ended below.
5. **Single approval.** `approved_by_first`/`second` collapse to
   `approved_by`/`approved_at`; `SameApproverException` is deleted. Approval
   stays a real state transition, in the domain, not a hidden button.
6. **The idempotency key is persisted at build time**, never derived at
   export time — a key that changes between exports is not an idempotency
   key. New column `payout_items.idempotency_key`, unique, format
   `MNF` + 6-digit zero-padded sequence. A re-queued failure gets a **new**
   key on its next item, because it is a new transfer attempt.
7. **PhpSpreadsheet, not a hand-rolled writer.** The sheet is edited in Excel
   and comes back, so the reader has to survive whatever Excel re-saves
   (sharedStrings, styles, its own cell typing). Round-tripping a
   finance-facing artifact is not the place to save a dependency.
8. **The exported sheet is the import format.** One shape in, one shape out —
   matched on Idempotency Key. A row with a Transfer Reference Number is a
   paid transfer; a blank one is untouched, so a half-filled sheet can be
   uploaded twice as the bank works through it.
9. **Marking failed stays a deliberate act in the UI**, not a spreadsheet
   column: the owner asked for a reference to fill in, and inventing a
   "Failure Reason" column would invite a typo to unlink real transactions.
10. **Exactly the seven columns asked for.** Bank name is deliberately NOT
    added even though a multi-bank upload usually wants it — flag it to the
    owner rather than quietly widening the sheet.

---

## 3b. Decisions taken after the survey (2026-08-16)

The survey surfaced twenty-odd open questions. Every one is settled here; an
implementer must not re-open them.

**Payout**

- **D1. Snapshot `customer_name` and `customer_phone` onto the item**, beside
  the bank details already snapshotted. The sheet has to say the same thing
  on a re-export as it said on the first one, and a customer who renames
  after the batch was built must not change a transfer instruction already
  with the bank. It also retires the bare `#{customer_id}` the items table
  renders today.
- **D2. `idempotency_key` is NOT NULL and unique**, minted from a dedicated
  Postgres sequence (`MNF` + 6-digit zero-padded `nextval`). Not derived from
  the row id — the id does not exist until after the insert, and a
  `max(id)+1` read is a race. Production has 0 rows, so no backfill.
- **D3. One concept, one name on the wire: `bank_reference`.** "Transfer
  Reference Number" is a column heading in a spreadsheet, nothing more.
- **D4. Settle-all takes one shared reference and requires it.** A bulk
  transfer settles as one bank transaction covering many payees, so a single
  reference is the honest record. Without this, settle-all would paint items
  paid with no reference at all, which contradicts the sheet's whole premise.
- **D5. Per-item actions only on `pending`/`sent`. `paid` and `failed` are
  terminal in the UI.** A mistyped reference on a paid item has no remedy
  this round — noted, not built.
- **D6. No eligible-total preview endpoint this round.** The build itself
  reports what it found.
- **D7. Every new payout endpoint returns `$batch->refresh()->load('items')`**
  so the items table updates in one round-trip, unlike approve/cancel today.

**Maps**

- **D8. `packages/ui` gets the LOADER ONLY** (`loadGoogleMaps(apiKey)`), never
  styled markup. Tailwind v4 here has no `@source` directive and does not
  scan the package through its pnpm symlink, so a styled shared component
  would ship with its classes silently missing. The key is passed in as an
  argument — a `NEXT_PUBLIC_*` read inside a package consumed by three
  separately-built apps is inlined per app and works in some, not others.
- **D9. Two components, one loader.** The merchant's pin-drop (write one
  coordinate, fixed centre pin, no markers) and the customer's store map
  (read many coordinates, tappable markers, fitBounds) share almost nothing
  but the script tag. Each lives in its own app and uses that app's i18n.
- **D10. Discovery entries gain `branches: [{lat, lng}]`** — bare pairs, no
  names or addresses. The coordinates already exist inside `buildEntries()`
  and are already public on the store page; `presentEntry()` simply strips
  them. Without this the map cannot place a single pin. Two tests assert the
  entry key list exactly and must be extended, not weakened.
- **D11. Pins come from the union of the coordinate-bearing shelves, deduped
  by slug. No refetch on drag.** `nearby` is empty by construction until the
  visitor grants location, so sourcing pins from it would show an empty map
  to exactly the person the map is for. And discovery is throttled 60/min
  per IP with a query key built from raw floats — an idle-driven refetch
  mints a request per micro-pan.
- **D12. The List/Map choice is component state, not a URL parameter.** A
  view preference is not a result. It must NOT be expressed as a `view`
  value: `view` already swaps the whole page into facet mode.
- **D13. Drop `leaflet`, `react-leaflet` and `@types/leaflet`** from all three
  apps. Zero imports anywhere — dead Metronic template weight, and leaving
  it means shipping two mapping stacks.
- **D14. The location step is index 1** — profile → location → logo → rate →
  terms → review. Channel is decided in profile, and location belongs with
  "who and where you are". Required to continue for `in_store`/`both`,
  skippable for `online`.
- **D15. The auto-created primary branch takes the merchant's own name.** One
  less field in the wizard; renameable in settings afterwards. Find-then-
  update on the lowest-id branch, never create — the owner re-enters this
  step on every resume, and a bare create grows a duplicate each time.
- **D16. Translate the branches page in this round.** Dropping an i18n-driven
  picker into a page whose other thirty strings are hardcoded English would
  render a half-Dhivehi dialog.
- **D17. Location does NOT join `missingRequirements()`.** The owner asked to
  *ask* for a pin at signup, not to make it an approval gate — and adding it
  would make any store already sitting in `pending_review` without a pin
  instantly un-approvable, with a refusal code the review sheet cannot even
  render. The wizard requires it to continue; submit and approve do not.
- **D18. The admin review sheet shows the pin**, since a reviewer otherwise
  has no way to see whether a store has one. Plus `location: 'Location'` in
  `STEP_LABELS`, or the raw key prints.

**Process**

- **D19. No agent runs `pnpm build` or `php artisan test` during the build
  phase.** A build trips the systemd path unit and restarts the live service;
  two concurrent `artisan test` runs share one test database. Verification is
  central, after the writing stops.

## 4. Work breakdown

### A. Maps — shared package
- `packages/ui` (or a new `packages/maps`): `loadGoogleMaps()` + `<LocationPicker>`
  adapted from avasprint — Manfaa tokens, RTL-safe, `prefers-reduced-motion`
  irrelevant here, Dhivehi strings via i18n rather than hardcoded English.
- Both apps consume it; the key is read from `NEXT_PUBLIC_GOOGLE_MAPS_API_KEY`.
- Degrade honestly when the key is missing or the script fails: the form must
  stay submittable, exactly as avasprint's does.

### B. Maps — merchant
- Setup wizard (`apps/merchant/components/setup/setup-wizard.tsx`, 1049 lines):
  a location step that pins the **primary branch**. Required for `in_store`
  and `both`; skippable for `online`.
- `apps/merchant/app/(app)/settings/branches/page.tsx`: the picker in the
  add/edit branch dialog, replacing typed coordinates.
- API: branch create/update must accept and validate `lat`/`lng`
  (`-90..90`, `-180..180`, and refuse one without the other).

### C. Maps — customer
- A map view for "Near you" on `/discover`: search, drag, "locate me", store
  pins from the discovery payload, tapping a pin opens the store card.
- Keep the existing list; the map is an alternative view, not a replacement —
  the list is what works with no key, no JS and no permission.

### D. Payout domain
- `PayoutBatchBuilder`: `buildDraft(CarbonImmutable $cutoff, AdminUser)`.
- `ApprovalService`: single approval.
- `payout_items.idempotency_key` + backfill (nothing to backfill: 0 rows).
- `TransferSheetExporter` (xlsx) replacing `GenericCsvFormatter` as the
  default `BankFileFormatter`. Keep the interface — §14's real BML format
  will be another implementation.
- `TransferSheetImporter` replacing `ResultImporter`'s parse layer, keeping
  its ledger and transition core untouched.
- New: `PayoutItemSettler` — settle one item with a reference, or every
  pending/sent item at once. Same ledger path as the importer, never a
  second one.

### E. Admin UI
- Create-batch dialog: a **date** field defaulting to today, with the
  eligible total previewed before the build if cheap to compute.
- Batch page: single Approve; Download sheet (.xlsx); Upload filled sheet;
  per-row Mark paid / Mark failed; Settle all.

### F. Docs and tests
- `docs/openapi.yaml` — the payout endpoints are admin-only and not in the
  vendor spec; confirm before editing.
- PLAN.md §13b — fold in when the round lands. §14 "Bank bulk payout file
  format" is now partly answered: this sheet is the interim format.

---

## 5. Risks

- **The Maps key is shared with avasprint.** If it is restricted by HTTP
  referrer, `manfaa.app` and `merchant.manfaa.app` must be added in the
  Google console or the map fails silently in production. **Check the
  browser console on the deployed page, not just locally.** Owner action if
  restricted.
- **Places autocomplete bills per session.** Same key, same project, so the
  spend lands on avasprint's bill.
- `setup-wizard.tsx` is 1049 lines; a location step must not disturb the
  resume-mid-wizard behaviour (`setup_state`).
- Excel writes numbers as floats. **Amount Owed must be written as a numeric
  cell from integer laari / 100**, never a pre-formatted string, or the
  finance team's own SUM will lie.
- The importer must refuse a sheet whose keys belong to a different batch —
  matched by key, but the key must also belong to *this* batch.
