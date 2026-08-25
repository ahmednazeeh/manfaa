import { z } from "zod";
import {
  bpToPercentString,
  isPercentDeltaString,
  isPercentInput,
  isPercentString,
} from "./percent";

/**
 * Zod schemas mirroring the Laravel JsonResources shared by the merchant and
 * admin surfaces. Money is always integer laari (`z.number().int()`); the
 * `*_mvr` companions are pre-formatted decimal strings from the API (e.g.
 * "118.25") and are display-only — never parse them back into numbers.
 *
 * RATES follow the same idiom (PLAN §1 wire format, decision 2026-08-15):
 * `rate_bp` / `fee_bp` appear in no request and no response. Every rate is a
 * 2-decimal percent STRING — `cashback_rate_percent: "2.00"`,
 * `platform_fee_percent: "0.75"`. Basis points stay the API's internal
 * representation (storage, ledger, every computation) and never reach a
 * client; convert with `percentToBp` from ./percent when you need to compare
 * rates or drive a slider.
 */

// ---------------------------------------------------------------------------
// Rates on the wire (PLAN §1 wire format)
// ---------------------------------------------------------------------------

/** §4 structural cashback bounds, in basis points — the request grammar. */
export const MIN_CASHBACK_BP = 50;
export const MAX_CASHBACK_BP = 2000;
/** A platform fee may sit below the cashback floor (the 0.25% first tier). */
export const MIN_FEE_BP = 1;

/**
 * A rate as the API EMITS it: a 2-decimal percent string. Strict on
 * purpose — a server that regressed to basis points ("200") must fail
 * loudly here rather than reach a screen as "200%".
 */
export const PercentSchema = z
  .string()
  .refine(isPercentString, 'Expected a 2-decimal percent string, e.g. "2.00".');

/**
 * A DIFFERENCE between two rates, the one rate value that may be negative
 * (`all_in_delta_percent`): "0.26", "-0.26".
 */
export const PercentDeltaSchema = z
  .string()
  .refine(
    isPercentDeltaString,
    'Expected a 2-decimal percent delta string, e.g. "-0.26".',
  );

/**
 * A rate as a REQUEST may send it: a string ("2", "2.5", "2.50") or a JSON
 * number (2, 2.5) with at most 2 decimals, bounded in basis points exactly
 * as App\Rules\PercentRate bounds it server-side. Build the string with
 * `bpToPercentString` when the form works in basis points.
 */
export function percentInput(minBp: number, maxBp: number) {
  return z
    .union([z.string(), z.number()])
    .refine(
      (value) => isPercentInput(value, minBp, maxBp),
      `Expected a percent between ${bpToPercentString(minBp)} and ${bpToPercentString(maxBp)} with at most 2 decimal places.`,
    );
}

/** A customer cashback rate on a request: §4's 0.50%–20.00%. */
export const CashbackPercentInputSchema = percentInput(
  MIN_CASHBACK_BP,
  MAX_CASHBACK_BP,
);
export type CashbackPercentInput = z.infer<typeof CashbackPercentInputSchema>;

/** A platform fee rate on a request: 0.01%–20.00%. */
export const FeePercentInputSchema = percentInput(MIN_FEE_BP, MAX_CASHBACK_BP);
export type FeePercentInput = z.infer<typeof FeePercentInputSchema>;

// ---------------------------------------------------------------------------
// Response envelopes
// ---------------------------------------------------------------------------

/** Laravel wraps a single JsonResource response as `{ data: ... }`. */
export function dataWrapped<Schema extends z.ZodType>(schema: Schema) {
  return z.object({ data: schema });
}

export const PaginationLinksSchema = z.object({
  first: z.string().nullable(),
  last: z.string().nullable(),
  prev: z.string().nullable(),
  next: z.string().nullable(),
});
export type PaginationLinks = z.infer<typeof PaginationLinksSchema>;

export const PaginationMetaSchema = z.object({
  current_page: z.number().int(),
  from: z.number().int().nullable(),
  last_page: z.number().int(),
  links: z.array(
    z.object({
      url: z.string().nullable(),
      label: z.string(),
      // Absent on the "..." separator entries.
      page: z.number().int().nullable().optional(),
      active: z.boolean(),
    }),
  ),
  path: z.string().nullable(),
  per_page: z.number().int(),
  to: z.number().int().nullable(),
  total: z.number().int(),
});
export type PaginationMeta = z.infer<typeof PaginationMetaSchema>;

/** Laravel resource-collection pagination: `{ data, links, meta }`. */
export function paginated<Schema extends z.ZodType>(schema: Schema) {
  return z.object({
    data: z.array(schema),
    links: PaginationLinksSchema,
    meta: PaginationMetaSchema,
  });
}

// ---------------------------------------------------------------------------
// Merchant lifecycle + channel (§1 decisions 2026-08-15)
// ---------------------------------------------------------------------------

/**
 * Where the store sells. Replaces the former `is_online` boolean everywhere.
 * Display copy NEVER says "both" — the UI renders it as "In Store & Online"
 * (localised).
 */
export const MerchantChannelSchema = z.enum(["in_store", "online", "both"]);
export type MerchantChannel = z.infer<typeof MerchantChannelSchema>;

// ---------------------------------------------------------------------------
// Queued store changes (MR9 — admin approval for store edits + new branches)
// ---------------------------------------------------------------------------

/**
 * What a LIVE store asked to change about the claims a shopper reads: its
 * name, Dhivehi name, category, channel, logo, website, its "what earns
 * cashback" promise, or its branch estate. One shape on every surface — the
 * admin review queue, the merchant panel's pending-review banner and the
 * merchant app's — so the diff is built once, server-side.
 *
 * PLAN-merchant-app.md §MR9 draws the line: claims queue, operations stay
 * instant (contact email/phone, support phone, the cashback rate, staff,
 * roles, bank account, preferences, promotions). Gating applies only while
 * the store is `active` or `suspended`; a store still onboarding writes
 * straight through the wizard.
 */
export const ChangeRequestKindSchema = z.enum([
  "profile",
  "branch_create",
  "branch_update",
  "branch_delete",
]);
export type ChangeRequestKind = z.infer<typeof ChangeRequestKindSchema>;

export const ChangeRequestStatusSchema = z.enum([
  "pending",
  "approved",
  "rejected",
  /** Replaced by a newer submission against the same target — never applied. */
  "superseded",
]);
export type ChangeRequestStatus = z.infer<typeof ChangeRequestStatusSchema>;

/**
 * One field's before/after. `field` is the wire's own column name (`name`,
 * `name_dv`, `category`, `channel`, `eligibility_basis`, `description`,
 * `website_url`, or `name`/`address`/`lat`/`lng` on a branch) with ONE
 * rename: a logo change arrives as `logo`, both sides being preview URLs
 * rather than paths.
 *
 * The values stay `unknown` on purpose. They are raw JSON out of the stored
 * payload/snapshot, so the same field can be a float on one side and the
 * column's decimal STRING on the other (coordinates above all) — a caller
 * formats them, never trusting a type the wire does not guarantee.
 */
export const ChangeRequestDiffSchema = z.object({
  field: z.string(),
  from: z.unknown(),
  to: z.unknown(),
});
export type ChangeRequestDiff = z.infer<typeof ChangeRequestDiffSchema>;

export const MerchantChangeRequestSchema = z.object({
  id: z.number().int(),
  merchant_id: z.number().int(),
  kind: ChangeRequestKindSchema,
  /**
   * The server's English name for the kind — what the merchant's push
   * notification called it. Localised surfaces map `kind` themselves.
   */
  kind_label: z.string(),
  status: ChangeRequestStatusSchema,
  /** Null for `profile` and `branch_create`, and nulled once a removal applies. */
  branch_id: z.number().int().nullable(),
  /**
   * The branch's name AS SNAPSHOTTED. Null on a create (there is no branch
   * yet) and also on an update that did not touch the name — the snapshot
   * holds only the fields in play — so a surface with the branch list in
   * hand should fall back to resolving `branch_id`.
   */
  branch_name: z.string().nullable(),
  changes: z.array(ChangeRequestDiffSchema),
  /** The proposed values; `logo_path` is published as `logo_url`. */
  proposed: z.record(z.string(), z.unknown()),
  /** The SUBMIT-TIME snapshot, so the diff survives later edits. */
  current: z.record(z.string(), z.unknown()),
  submitted_at: z.string().nullable(),
  /** Admin surfaces only — absent on the merchant's own reads. */
  submitted_by: z.object({ id: z.number().int(), name: z.string() }).optional(),
  reviewed_at: z.string().nullable(),
  reviewed_by: z.number().int().nullable(),
  /** The words the admin owed the merchant on a refusal. */
  rejected_reason: z.string().nullable(),
  /** Admin surfaces only — absent on the merchant's own reads. */
  merchant: z
    .object({
      id: z.number().int(),
      name: z.string(),
      slug: z.string(),
      status: z.string(),
      logo_url: z.string().nullable(),
    })
    .optional(),
});
export type MerchantChangeRequest = z.infer<typeof MerchantChangeRequestSchema>;

/**
 * The 202 body every gated write answers with. The status line alone tells a
 * client "queued" from "saved"; this is the detail behind it. Mixed writes
 * add their instant half beside it (the profile PATCH adds `profile`, the
 * logo upload adds the `logo_url` STILL being served).
 */
export const QueuedChangeSchema = z.object({
  status: z.literal("pending_review"),
  change_request: MerchantChangeRequestSchema,
});
export type QueuedChange = z.infer<typeof QueuedChangeSchema>;

// ---------------------------------------------------------------------------
// Banks
// ---------------------------------------------------------------------------

/**
 * The banks money moves through, mirroring App\Domain\Platform\Bank.
 *
 * Bank was free text on every form until this landed, which made "BML",
 * "Bank of Maldives" and a typo three banks to the database and one bank to
 * a payments clerk — survivable in a note, not in a bulk transfer file where
 * that column decides whether someone gets paid.
 *
 * NOT a closed z.enum on the read side: rows written before the enum existed
 * hold free text, and a stored value this build does not recognise must
 * render as itself rather than throw the whole payload away. Parse with
 * `bankOf()` and fall back to printing what you were given.
 */
export const BANKS = [
  {
    slug: "bml",
    label: "Bank of Maldives",
    shortLabel: "BML",
    /** Served from each app's public/banks — see the logo files there. */
    logo: "/banks/bml.svg",
  },
  {
    slug: "mib",
    label: "Maldives Islamic Bank",
    shortLabel: "MIB",
    logo: "/banks/mib.png",
  },
] as const;

export type Bank = (typeof BANKS)[number];
export type BankSlug = Bank["slug"];

/** The slug a form submits. Closed, because a WRITE may only name a real bank. */
export const BankSlugSchema = z.enum(["bml", "mib"]);

/**
 * Resolves a stored or submitted bank, tolerating the free-text era the same
 * way the PHP enum's parse() does — slug, short name or full name, any case.
 * Null for anything else, which is the caller's cue to print the raw string
 * instead of asserting a bank nobody chose.
 */
export function bankOf(value: string | null | undefined): Bank | null {
  if (value === null || value === undefined || value.trim() === "") {
    return null;
  }
  const needle = value.trim().toLowerCase();
  return (
    BANKS.find(
      (bank) =>
        needle === bank.slug ||
        needle === bank.shortLabel.toLowerCase() ||
        needle === bank.label.toLowerCase(),
    ) ?? null
  );
}

/** What to print for a bank field: the real name, or the raw value verbatim. */
export function bankLabel(value: string | null | undefined): string {
  return bankOf(value)?.label ?? value ?? "";
}

/**
 * The full merchant lifecycle. `draft` (mid-wizard), `pending_review`
 * (submitted, awaiting the superadmin queue) and `rejected` (sent back with
 * a reason) are the self-signup onboarding states — none of the three is
 * EVER visible publicly; public payloads expose ACTIVE merchants only.
 */
export const MerchantStatusSchema = z.enum([
  "draft",
  "pending_review",
  "rejected",
  "active",
  "suspended",
  "closed",
]);
export type MerchantStatus = z.infer<typeof MerchantStatusSchema>;

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------

export const TransactionStateSchema = z.enum([
  "tracked",
  "awaiting_validation",
  "payable_unfunded",
  "on_hold",
  "confirmed",
  "paid",
  "reversed",
  "written_off",
]);
export type TransactionState = z.infer<typeof TransactionStateSchema>;

/**
 * How the sale reached us. `claim` is written by ClaimApprovalService when an
 * admin approves a missing-transaction claim — it is a real value of the
 * transactions.origin CHECK constraint, so it must live here or a single
 * claim-originated row would fail the response parse and blank the screen.
 *
 * These are MACHINE keys and every surface labels them itself (each app's
 * own `lib/labels.ts`). One label is worth knowing about because the server
 * now prints it too: `manual` is the sale a merchant typed into the Manfaa
 * app rather than one a till pushed, and every surface renders it
 * "Manfaa App" — the superadmin reports
 * (App\Domain\Reports\ReportLabels::origin), the admin panel
 * (apps/admin/lib/labels.ts) and the merchant panel
 * (apps/merchant/locales/{en,dv}.json → `labels.origin.manual`), owner
 * 2026-08-24. One row, one name, on every screen an operator reads side by
 * side: a surface that renames it is naming the same origin something else
 * in the same conversation — align the label maps, never this enum.
 */
export const TransactionOriginSchema = z.enum([
  "pos",
  "manual",
  "online_link",
  "api_phone",
  "card_linked",
  "claim",
]);
export type TransactionOrigin = z.infer<typeof TransactionOriginSchema>;

/**
 * GST ON THE PLATFORM FEE (owner, 2026-08-24) — which side of Manfaa's fee
 * the tax sits on. A platform-wide policy, but STAMPED onto every
 * transaction at creation, so a later switch can never re-price a sale that
 * was already quoted: read it off the row (`Transaction.fee_treatment`),
 * never off the current tax settings, whenever you are explaining a sale.
 *
 *   on_top     the quoted fee is exclusive of tax. The merchant owes
 *              cashback + fee + GST, so the bill goes UP by the tax and
 *              Manfaa's fee income is unchanged.
 *   inclusive  the quoted fee already contained the tax. The merchant owes
 *              exactly what they always did and the GST is carved OUT of
 *              Manfaa's own revenue.
 *
 * In BOTH cases `fee_laari` is Manfaa's NET charge and `fee_gst_laari` is
 * the tax on it, and the merchant owes cashback + fee + GST. The treatment
 * says how those two numbers were arrived at — never add the GST twice.
 */
export const FeeTreatmentSchema = z.enum(["on_top", "inclusive"]);
export type FeeTreatment = z.infer<typeof FeeTreatmentSchema>;

/** The treatments in policy order, for a radio group or a route guard. */
export const FEE_TREATMENTS = FeeTreatmentSchema.options;

/**
 * Every `reason_code` the API writes onto a transaction row or one of its
 * append-only events — the state QUALIFIER that answers "why is this row
 * where it is". Codes are machine keys; no UI may render one raw (PLAN §13b
 * task #22), so each app keeps an EXHAUSTIVE label map typed against this
 * union and a new entry here fails every app's typecheck until it is
 * labelled.
 *
 * Where each one is written:
 *
 *   auto_validation_window  CreditRecorder — clean credit enters the refund
 *                           window (event only; the row's reason stays null)
 *   backdated_final         CreditRecorder — PLAN §1 backdated credit: never
 *                           sat in the refund window, payable now, merchant-
 *                           irreversible (row + event)
 *   below_minimum           CreditRecorder — under the store's minimum
 *                           eligible sale; recorded, zeroed, closed
 *   merchant_suspended      ApiCreditService — §7 suspended store; ingested
 *                           and recorded ineligible so the till sees truth
 *   merchant_unpublished    ApiCreditService — the store PAUSED ITSELF
 *                           (2026-08-18); same ingestion semantics as a
 *                           suspension, a different sentence to the reader:
 *                           nothing is wrong with the account
 *   settlement_allocated    LineAllocator — the store's payment covered this
 *                           line, so the reward confirmed
 *   payout_completed        ItemResultService — the bank confirmed the
 *                           customer transfer
 *   merchant_default_90d    WriteOffService — 90 days past due, never settled
 *   claim_approved          ClaimApprovalService — created from an approved
 *                           missing-transaction claim
 *   customer_refund         reversal reason (POS /v1 + admin adjustment)
 *   till_void               reversal reason
 *   duplicate               reversal reason
 *   other                   reversal reason
 *   admin_release           hold queue — a human cleared the review
 *   admin_reject            hold queue — a human refused the sale
 *
 * LEGACY — no code writes these any more (task #23 removed the staleness
 * hold), but production rows and event history still carry them, so the
 * labels stay:
 *
 *   stale_timestamp         the old staleness hold
 *   admin_release_stale     an admin releasing one of those holds
 */
export const TRANSACTION_REASON_CODES = [
  "auto_validation_window",
  "backdated_final",
  "below_minimum",
  "merchant_suspended",
  "merchant_unpublished",
  "settlement_allocated",
  "payout_completed",
  "merchant_default_90d",
  "claim_approved",
  "customer_refund",
  "till_void",
  "duplicate",
  "other",
  "admin_release",
  "admin_reject",
  "stale_timestamp",
  "admin_release_stale",
] as const;
export const TransactionReasonCodeSchema = z.enum(TRANSACTION_REASON_CODES);
export type TransactionReasonCode = (typeof TRANSACTION_REASON_CODES)[number];

/**
 * Narrows an arbitrary `reason_code` from the wire. The column is a plain
 * string on purpose — an older row may carry a code this build predates — so
 * label helpers test with this and fall back to prose, never to the code.
 */
export function isTransactionReasonCode(
  value: string,
): value is TransactionReasonCode {
  return (TRANSACTION_REASON_CODES as readonly string[]).includes(value);
}

// ---------------------------------------------------------------------------
// Product categories + line-item pricing (Task #25)
// ---------------------------------------------------------------------------

/**
 * What a per-store product category does to the rate: `excluded` earns
 * nothing (even during promotions), `rate` overrides the standing rate with
 * the category's own `cashback_rate_percent`.
 */
export const ProductCategoryModeSchema = z.enum(["excluded", "rate"]);
export type ProductCategoryMode = z.infer<typeof ProductCategoryModeSchema>;

/**
 * WHY a line priced the way it did: `excluded` (category excluded → zeros),
 * `category` (the category's own rate override), `standing` (no category /
 * default bucket → the sale's BASE rate, which is the per-sale
 * `cashback_rate_percent` override when one was sent, otherwise the merchant
 * standing rate), `promotion` (a live promo beat the line's own rate — only
 * these lines consume the per-customer promo cap).
 */
export const TransactionLinePricedBySchema = z.enum([
  "excluded",
  "category",
  "standing",
  "promotion",
]);
export type TransactionLinePricedBy = z.infer<
  typeof TransactionLinePricedBySchema
>;

/**
 * One priced line of a lined credit — an immutable creation-time snapshot.
 * `category` is the product-category slug (null = the default "everything
 * else" bucket, which also has a null `category_name_en`). All money is
 * integer laari, per-line §4 ceiling; the transaction totals equal the SUM
 * of these stored integers.
 *
 * `cashback_rate_percent` is the rate this LINE actually priced at ("0.00"
 * for an excluded category) and `platform_fee_percent` the fee that followed
 * it — both 2-decimal percent strings.
 */
export const TransactionLineSchema = z.object({
  category: z.string().nullable(),
  category_name_en: z.string().nullable(),
  amount_laari: z.number().int(),
  cashback_rate_percent: PercentSchema,
  platform_fee_percent: PercentSchema,
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  /**
   * The GST on THIS line's fee, and the rate it was taxed at — computed per
   * line, ceiling, so the lines always sum to the transaction's own totals
   * (a header-level computation can disagree by a laari). `fee_laari` above
   * is always NET of this. "0.00" and 0 while GST is switched off.
   *
   * There is no per-line `fee_treatment`: one transaction is priced under
   * one treatment, and a second copy on every line could only ever
   * disagree. Read `Transaction.fee_treatment`.
   */
  fee_gst_laari: z.number().int(),
  fee_gst_percent: PercentSchema,
  priced_by: TransactionLinePricedBySchema,
  sort: z.number().int(),
});
export type TransactionLine = z.infer<typeof TransactionLineSchema>;

export const TransactionSchema = z.object({
  id: z.number().int(),
  origin: TransactionOriginSchema,
  invoice_no: z.string(),
  state: TransactionStateSchema,
  reason_code: z.string().nullable(),
  currency: z.string(),
  eligible_laari: z.number().int(),
  sale_laari: z.number().int().nullable(),
  /**
   * The rate and fee FROZEN on this row at occurred_at, as 2-decimal
   * percent strings. On a lined credit they are the base-rate snapshot —
   * the per-line truth lives in `lines`.
   *
   * `platform_fee_percent` is what the sale was ACTUALLY CHARGED, so a sale
   * rung up under a platform fee promotion (see ./fee-promotions) carries
   * the promotional fee — legitimately "0.00" on a free-for-X-days sale.
   * The §4 tier fee it would otherwise have paid stays server-side; what
   * the promotion cost the platform is reported in aggregate as
   * `fee_forgone_laari`, never per row on the wire.
   */
  cashback_rate_percent: PercentSchema,
  platform_fee_percent: PercentSchema,
  /**
   * What the sale ACTUALLY returned — cashback_laari / eligible_laari — as
   * opposed to the base rate above, which it was priced AGAINST. Equal on a
   * single-rate sale; different on a lined one, where it is the only honest
   * single figure. Null only when there is nothing to divide by.
   */
  effective_cashback_rate_percent: z.string().nullable().catch(null),
  effective_platform_fee_percent: z.string().nullable().catch(null),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  /**
   * The GST terms FROZEN on this sale (owner, 2026-08-24) — the rate it was
   * actually taxed at and which side of the fee the tax sat on, never the
   * platform's current settings. `fee_laari` is Manfaa's NET charge and
   * `fee_gst_laari` the tax on it, whichever treatment applied; the store
   * owes cashback + fee + GST. "0.00" / "on_top" on every row priced while
   * GST was switched off, which is every row today.
   */
  fee_gst_percent: PercentSchema,
  fee_treatment: FeeTreatmentSchema,
  /**
   * PLAN §1 "Backdated credits": the sale was credited outside the merchant's
   * validation window, so it never sat in the refund window — it is payable
   * immediately AND permanently merchant-irreversible (an admin adjustment is
   * the only correction). Branch on this flag, never on `reason_code`, which
   * later transitions rewrite.
   */
  backdated: z.boolean(),
  occurred_at: z.string(),
  received_at: z.string(),
  /**
   * The pricing split of a lined credit, in submitted order. Present only
   * when the endpoint loads it (e.g. the credit POST response for a lined
   * credit); single-rate transactions keep the exact pre-lines shape. On a
   * lined credit the row-level percents are the base-rate snapshot — the
   * per-line truth lives here.
   */
  lines: z.array(TransactionLineSchema).optional(),
});
export type Transaction = z.infer<typeof TransactionSchema>;

// ---------------------------------------------------------------------------
// Backdated credits (PLAN §1, decision 2026-08-14 late)
// ---------------------------------------------------------------------------

/**
 * The state qualifier a backdated credit carries through its (instantaneous)
 * hop to payable_unfunded. It is an EVENT reason, not a row state: it tells
 * a history reader why the credit skipped the validation window. No admin
 * approval is involved — on_hold is now fraud/velocity only.
 */
export const BACKDATED_REASON_CODE = "backdated_final";

/**
 * The 409 `code` a reversal of a backdated credit answers with — merchant
 * and vendor alike (POST /v1/transactions/{id}/reverse). Distinct from a
 * plain failure so a POS can tell the cashier the truth: this one needs an
 * admin adjustment, retrying will never work.
 */
export const BACKDATED_IRREVERSIBLE_CODE = "backdated_irreversible";

/**
 * Days past the merchant's validation window before the API treats a credit
 * as backdated (CreditRecorder::STALE_GRACE_DAYS). Kept in step with the
 * server so the entry form's warning fires on exactly the sales the server
 * will make final.
 */
export const BACKDATED_STALE_GRACE_DAYS = 3;

/** A wall clock with no offset — the API reads one as Maldives time. */
const OFFSETLESS_INSTANT = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/;

/**
 * The instant an `occurred_at` names, in epoch milliseconds, read exactly as
 * the API reads it (App\Http\Support\OccurredAt):
 *
 *  - ISO 8601 WITH an offset ("…+05:00", "…Z", "…+0500") is taken as sent;
 *  - a plain wall clock with NO offset ("2026-08-15 13:45:00",
 *    "2026-08-15T13:45:00") is MALDIVES time — never the viewer's own
 *    timezone, which is what `Date.parse` would assume and what would make a
 *    panel abroad warn about the wrong sales;
 *  - anything unparseable is null.
 *
 * Maldives is UTC+05:00 year-round (no DST), so pinning the offset is exact.
 */
export function parseOccurredAt(occurredAt: string | number | Date): number {
  if (occurredAt instanceof Date) {
    return occurredAt.getTime();
  }
  if (typeof occurredAt === "number") {
    return occurredAt;
  }
  const trimmed = occurredAt.trim();
  return Date.parse(
    OFFSETLESS_INSTANT.test(trimmed)
      ? `${trimmed.replace(" ", "T")}+05:00`
      : trimmed,
  );
}

/**
 * Would this sale be credited as BACKDATED — payable immediately and
 * merchant-irreversible? Mirrors the server rule exactly: occurred_at
 * strictly before now minus (validation window + grace days). The entry form
 * warns on true BEFORE submit, because the decision cannot be undone
 * afterwards.
 *
 * `occurred_at` is now OPTIONAL on the credit (PLAN §1): omitting it means
 * NOW, which is never backdated — pass undefined and this answers false.
 *
 * @param validationWindowDays the merchant's own preferences value
 */
export function isBackdatedOccurrence(
  occurredAt: string | number | Date | undefined | null,
  validationWindowDays: number,
  now: Date = new Date(),
): boolean {
  if (occurredAt === undefined || occurredAt === null || occurredAt === "") {
    return false;
  }

  const occurred = parseOccurredAt(occurredAt);

  if (!Number.isFinite(occurred)) {
    return false;
  }

  const staleAfterMs =
    (validationWindowDays + BACKDATED_STALE_GRACE_DAYS) * 24 * 60 * 60 * 1000;

  return now.getTime() - occurred > staleAfterMs;
}

// ---------------------------------------------------------------------------
// Promotions (shared by the merchant builder and the admin listing)
// ---------------------------------------------------------------------------

export const PromotionStatusSchema = z.enum([
  "draft",
  "published",
  "ended",
  "cancelled",
]);
export type PromotionStatus = z.infer<typeof PromotionStatusSchema>;

/**
 * The §4 cost picture of one cashback rate, all 2-decimal percent strings:
 * the platform fee tier the rate lands on and the resulting all-in merchant
 * cost. Mind the tier cliffs — compare in basis points (`percentToBp`),
 * never as text: "4.99" → "5.00" moves the fee tier.
 */
export const RateDescriptionSchema = z.object({
  cashback_rate_percent: PercentSchema,
  platform_fee_percent: PercentSchema,
  all_in_percent: PercentSchema,
});
export type RateDescription = z.infer<typeof RateDescriptionSchema>;

/**
 * One promotion as the merchant and admin panels see it. The fee fields are
 * resolved from the promo rate's §4 tier exactly as they will be at credit
 * time. Timestamps are ISO 8601 in the business timezone (UTC+5).
 *
 * platform_fee_percent/all_in_percent are null in exactly one degenerate
 * case: a stale DRAFT whose rate the fee schedule now governing its window
 * no longer prices (drafts never block an admin schedule change; publish
 * would refuse this draft). The listing must still render so the merchant
 * can see and cancel it.
 */
export const PromotionSchema = RateDescriptionSchema.extend({
  platform_fee_percent: PercentSchema.nullable(),
  all_in_percent: PercentSchema.nullable(),
  id: z.number().int(),
  merchant_id: z.number().int(),
  branch_id: z.number().int().nullable(),
  status: PromotionStatusSchema,
  /** published AND the window covers now. */
  is_live: z.boolean(),
  starts_at: z.string(),
  ends_at: z.string(),
  min_purchase_laari: z.number().int().nullable(),
  max_cashback_per_customer_laari: z.number().int().nullable(),
  published_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
});
export type Promotion = z.infer<typeof PromotionSchema>;

// ---------------------------------------------------------------------------
// Claims (customer files them; admin resolves them)
// ---------------------------------------------------------------------------

export const ClaimStateSchema = z.enum([
  "open",
  "in_review",
  "approved",
  "rejected",
]);
export type ClaimState = z.infer<typeof ClaimStateSchema>;

// ---------------------------------------------------------------------------
// Settlements
// ---------------------------------------------------------------------------

export const SettlementStateSchema = z.enum([
  "draft",
  "awaiting_payment",
  "payment_review",
  "settled",
  "partially_settled",
  "cancelled",
]);
export type SettlementState = z.infer<typeof SettlementStateSchema>;

export const SettlementFundingMethodSchema = z.enum(["bank", "wallet"]);
export type SettlementFundingMethod = z.infer<
  typeof SettlementFundingMethodSchema
>;

export const SettlementLineSchema = z.object({
  id: z.number().int(),
  transaction_id: z.number().int(),
  currency: z.string(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  due_laari: z.number().int(),
  due_mvr: z.string(),
  allocated_at: z.string().nullable(),
  // Present only when the endpoint eager-loads the transaction.
  transaction: TransactionSchema.optional(),
});
export type SettlementLine = z.infer<typeof SettlementLineSchema>;

export const SettlementPaymentStateSchema = z.enum([
  "pending",
  "matched",
  "rejected",
]);
export type SettlementPaymentState = z.infer<
  typeof SettlementPaymentStateSchema
>;

/**
 * TWO FIGURES, ONE TRANSFER (owner, 2026-08-25). Spread into both claim
 * schemas — settlement payments and wallet top-ups — so the two review
 * screens read the same three field names and cannot drift apart.
 *
 * `amount_laari` is the merchant's CLAIM: what they typed on the upload
 * form. It is never rewritten, on either table.
 *
 * `received_laari` is the FACT: what the bank actually credited, off the
 * matched statement row (or, on a hand-matched row, what the reviewer read
 * on the statement). NULL until the row is matched — and null forever on
 * rows matched by hand before this field existed. It is what actually funded
 * the batch or the wallet, so it is the figure a screen must lead with once
 * it is known.
 *
 * `amount_differs` is the branch: TRUE only when a bank figure is known AND
 * disagrees with the claim. An unknown is not a discrepancy, so a screen can
 * never announce a mismatch it cannot name both sides of.
 *
 * A discrepancy is NOT an error. A merchant typing MVR 20.00 and sending
 * MVR 10.00 is credited the 10.00 that arrived — the money is real and the
 * claim was a typo. It is worth an auditor's eye, never an alarm.
 */
const claimAndFactShape = {
  /** THE CLAIM: what the merchant typed. Never rewritten. */
  amount_laari: z.number().int(),
  amount_mvr: z.string(),
  /** THE FACT: what the bank credited. Null until matched. */
  received_laari: z.number().int().nullable(),
  received_mvr: z.string().nullable(),
  /** True only when both figures are known and disagree. */
  amount_differs: z.boolean(),
};

export const SettlementPaymentSchema = z.object({
  id: z.number().int(),
  settlement_id: z.number().int(),
  ...claimAndFactShape,
  currency: z.string(),
  method: z.string(),
  /** What the MERCHANT typed. Often null — the slip carries the reference. */
  bank_ref: z.string().nullable(),

  /**
   * What the BANK says, once matched — kept separate from `bank_ref`
   * because "claimed" and "confirmed" are different facts.
   *
   * Unique in the database where not null, so one bank credit can never
   * settle two payments. That index is what makes dedup safe; this field is
   * how a reader sees which credit was spent.
   */
  matched_trx_id: z.string().nullable(),
  /** The payer as the BANK names them, which may not be the store. */
  matched_payer_name: z.string().nullable(),
  matched_score: z.number().int().nullable(),
  /**
   * Which evidence won, strongest first: `reference` (typed),
   * `receipt_reference` (a bank-issued reference read off the slip),
   * `receipt_name` (the payer named on the slip verbatim),
   * `receipt_name_fuzzy` (the payer named on the slip allowing for spelling —
   * BML prints "AHMD.NAZEEH" for "AHMED NAZEEH"), or `name` (the registered
   * account name, fuzzy). Only the first three score 100.
   */
  matched_by_rule: z.string().nullable(),
  /**
   * Every identifier the matched credit answered to. `matched_trx_id` is the
   * one dedup keys on (a single stable value a unique index can hold); this
   * carries the others — notably the reference BML prints on the merchant's
   * slip, which is what an operator reconciling by hand actually has.
   */
  matched_trx_refs: z.array(z.string()).default([]),
  auto_matched: z.boolean(),

  /**
   * Path on the private `slips` disk. It is NOT fetchable — the disk has no
   * URL and is not served. Branch on `has_slip`; read the bytes only through
   * the authenticated admin slip route.
   */
  slip_path: z.string().nullable(),
  has_slip: z.boolean(),
  /** Mime derived from the uploaded BYTES, never the client's Content-Type. */
  slip_mime: z.string().nullable(),
  slip_size_bytes: z.number().int().nullable(),
  uploaded_by: z.number().int().nullable(),
  state: SettlementPaymentStateSchema,
  matched_by: z.number().int().nullable(),
  matched_at: z.string().nullable(),
  rejected_by: z.number().int().nullable(),
  rejected_at: z.string().nullable(),
  /** Why an admin refused the receipt — the merchant reads this verbatim. */
  rejection_reason: z.string().nullable(),
  created_at: z.string(),
});
export type SettlementPayment = z.infer<typeof SettlementPaymentSchema>;

/**
 * The platform's active primary account, exactly as the merchant must type
 * it into their bank. Copy buttons quote these three strings verbatim.
 */
export const SettlementBankAccountSchema = z.object({
  bank_name: z.string(),
  account_no: z.string(),
  account_name: z.string(),
});
export type SettlementBankAccount = z.infer<typeof SettlementBankAccountSchema>;

/**
 * One account the merchant may transfer to — the shape above plus the id to
 * send back, so the receipt records WHICH account the money went to. At most
 * one per bank, enforced by a partial unique index.
 *
 * `is_primary` is the platform's default, not a restriction: the panel
 * preselects it and the merchant may pick the other. A store banking with
 * MIB pays no fee and waits no day sending to MIB, and a cross-bank transfer
 * they did not have to make is a cost the platform imposed for its own
 * filing convenience.
 */
export const SettlementDestinationSchema = SettlementBankAccountSchema.extend({
  id: z.number().int(),
  currency: z.string(),
  is_primary: z.boolean(),
});
export type SettlementDestination = z.infer<typeof SettlementDestinationSchema>;

/**
 * Where to actually send the transfer: the platform's active primary bank
 * account alongside the amount and the reference to quote. When no platform
 * account is configured, `bank_account` is null and `needs_configuration`
 * is true — details are never invented.
 */
export const SettlementPaymentInstructionsSchema = z.object({
  /** The settlement reference the merchant must quote on the transfer. */
  reference: z.string(),
  amount_due_laari: z.number().int(),
  amount_due_mvr: z.string(),
  bank_account: SettlementBankAccountSchema.nullable(),
  /**
   * Every account the merchant may choose. `.catch([])` so a panel newer
   * than its API degrades to the single `bank_account` above rather than
   * failing the whole settlement payload.
   */
  bank_accounts: z.array(SettlementDestinationSchema).catch([]),
  needs_configuration: z.boolean(),
});
export type SettlementPaymentInstructions = z.infer<
  typeof SettlementPaymentInstructionsSchema
>;

/**
 * The rejection that cancelled a batch, read off the payment the admin
 * refused. Present only on a cancelled batch whose payments are loaded — a
 * plain cancellation (no payment ever recorded) refuses nothing and carries
 * no rejection.
 */
export const SettlementRejectionSchema = z.object({
  reason: z.string().nullable(),
  rejected_at: z.string().nullable(),
  /** The bank reference the merchant quoted on the refused transfer. */
  bank_ref: z.string().nullable(),
  payment_id: z.number().int(),
});
export type SettlementRejection = z.infer<typeof SettlementRejectionSchema>;

/**
 * What the MERCHANT is told about their batch (PLAN §1 receipt-first). The
 * raw §6 `state` stays for machines; this is the human answer to "what is
 * happening to my transfer" — `verifying` while an admin reviews the slip,
 * `rejected` (with the reason) when the transfer could not be verified.
 */
export const SettlementMerchantStatusCodeSchema = z.enum([
  "draft",
  "awaiting_payment",
  "verifying",
  "settled",
  "partially_settled",
  "rejected",
  "cancelled",
]);
export type SettlementMerchantStatusCode = z.infer<
  typeof SettlementMerchantStatusCodeSchema
>;

export const SettlementMerchantStatusSchema = z.object({
  code: SettlementMerchantStatusCodeSchema,
  /** English prose from the API; localise off `code`, not off this. */
  message: z.string(),
  rejection: SettlementRejectionSchema.nullable(),
});
export type SettlementMerchantStatus = z.infer<
  typeof SettlementMerchantStatusSchema
>;

/**
 * Why a batch did — or did not — get the PLAN §1 prompt-payment discount.
 * Machine keys; the human labels belong in the panels' label maps.
 *
 *  - `eligible`            granted: the batch covered everything outstanding
 *                          and every line was under the age window;
 *  - `not_all_outstanding` the merchant still has payable transactions this
 *                          batch does not cover (including lines frozen on an
 *                          earlier, still-unpaid batch);
 *  - `line_too_old`        at least one included line has reached the window
 *                          (10 days by default);
 *  - `clock_not_started`   at least one included line has no settlement clock
 *                          at all (a null `clock_start_at`, §13b), so its age
 *                          cannot be proved — and nothing ages it, either;
 *  - `disabled`            the platform has the incentive switched off (0bp).
 */
export const PromptDiscountReasonSchema = z.enum([
  "eligible",
  "not_all_outstanding",
  "line_too_old",
  "clock_not_started",
  "disabled",
]);
export type PromptDiscountReason = z.infer<typeof PromptDiscountReasonSchema>;

export const SettlementSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: SettlementStateSchema,
  funding_method: SettlementFundingMethodSchema,
  currency: z.string(),
  sale_total_laari: z.number().int(),
  cashback_total_laari: z.number().int(),
  fee_total_laari: z.number().int(),
  fee_gst_total_laari: z.number().int(),
  /**
   * PLAN §1 prompt-payment discount as GRANTED at submit — 5% off the
   * PLATFORM FEE, never off the customer's cashback. Already subtracted from
   * `amount_due_laari`, so never subtract it again. `discount_rate_percent`
   * is null when nothing was granted, and `discount_reason` says why (it is
   * set on refusals too, which is what lets a panel explain the full price).
   */
  discount_laari: z.number().int(),
  discount_mvr: z.string(),
  discount_rate_percent: PercentSchema.nullable(),
  discount_reason: PromptDiscountReasonSchema.nullable(),
  amount_due_laari: z.number().int(),
  amount_received_laari: z.number().int(),
  cashback_total_mvr: z.string(),
  fee_total_mvr: z.string(),
  /**
   * The tax on the fee, as its own display string (owner, 2026-08-24) —
   * beside `fee_total_mvr`, never folded into it. Manfaa's charge and the
   * government's tax on that charge are two facts, and the batch owes
   * cashback + fee + GST. A batch legitimately spans rows priced under
   * different regimes, so no single rate is quoted here: this is the sum of
   * the stored per-line integers.
   */
  fee_gst_total_mvr: z.string(),
  amount_due_mvr: z.string(),
  amount_received_mvr: z.string(),
  due_at: z.string().nullable(),
  created_at: z.string(),
  payment_instructions: SettlementPaymentInstructionsSchema,
  /**
   * The merchant-facing reading of `state` (PLAN §1). `rejection` is filled
   * only when the endpoint loads the payments — the merchant list and detail
   * both do, so a rejected batch always explains itself.
   */
  merchant_status: SettlementMerchantStatusSchema,
  // Present only on endpoints that eager-load the relations.
  lines: z.array(SettlementLineSchema).optional(),
  payments: z.array(SettlementPaymentSchema).optional(),
});
export type Settlement = z.infer<typeof SettlementSchema>;

// ---------------------------------------------------------------------------
// Merchant wallet
// ---------------------------------------------------------------------------

/**
 * What moved the merchant wallet: `top_up` (a bank transfer in — since
 * 2026-08-24 the merchant's own matched claim as well as an admin's manual
 * credit), `settlement` (wallet balance spent on a batch, whether the
 * merchant pressed the button or the hourly auto-settle run drew it — same
 * path, same type) and `settlement_credit` (an overpayment or unallocated
 * remainder parked for the next batch) — WalletFunding and
 * SettlementAllocator write no others. Auto-settlement added NO new type:
 * tell the two apart by the settlement's `funding_method` and its line
 * events' `actor_type` (`system`), not by the movement.
 */
export const WALLET_MOVEMENT_TYPES = [
  "top_up",
  "settlement",
  "settlement_credit",
] as const;
export type WalletMovementType = (typeof WALLET_MOVEMENT_TYPES)[number];

export function isWalletMovementType(
  value: string,
): value is WalletMovementType {
  return (WALLET_MOVEMENT_TYPES as readonly string[]).includes(value);
}

export const WalletMovementSchema = z.object({
  id: z.number().int(),
  amount_laari: z.number().int(),
  amount_mvr: z.string(),
  balance_after_laari: z.number().int(),
  /** Kept a free string on the wire; narrow with isWalletMovementType. */
  type: z.string(),
  reference_type: z.string().nullable(),
  reference_id: z.number().int().nullable(),
  description: z.string().nullable(),
  created_at: z.string(),
});
export type WalletMovement = z.infer<typeof WalletMovementSchema>;

// ---------------------------------------------------------------------------
// Merchant wallet top-ups (owner, 2026-08-24 — reverses "wallet is not
// pre-funding")
// ---------------------------------------------------------------------------

/**
 * A top-up is a CLAIM, never money: `pending` until the transfer is found in
 * the bank's own history (auto) or an admin matches it by hand, at which
 * point it becomes `matched` and the wallet is credited — through the ONE
 * crediting path — with `wallet_transaction_id` as the audit link. A
 * rejected claim releases its bank reference so the merchant can claim it
 * again once the problem is sorted.
 */
export const WalletTopUpStateSchema = z.enum(["pending", "matched", "rejected"]);
export type WalletTopUpState = z.infer<typeof WalletTopUpStateSchema>;

/** The platform account a top-up names, as both surfaces embed it. */
export const WalletTopUpBankAccountSchema = z.object({
  id: z.number().int(),
  bank_name: z.string(),
  account_no: z.string(),
  account_name: z.string(),
});
export type WalletTopUpBankAccount = z.infer<typeof WalletTopUpBankAccountSchema>;

/**
 * A wallet top-up claim, as the merchant (on create) and the admin queue
 * both read it. Deliberately the same shape as SettlementPaymentSchema where
 * the columns coincide, so the two review screens can share components —
 * with two differences worth knowing: there is no `slip_path` (branch on
 * `has_slip`; admins stream the bytes through the top-up slip route), and
 * the refusal reason is `rejected_reason`, not `rejection_reason`.
 *
 * `merchant` is present only on the admin queue; `platform_bank_account` is
 * loaded on both, and null when the claim named no account.
 *
 * The money is the CLAIM/FACT pair (see `claimAndFactShape`): `amount_laari`
 * is what the merchant typed, `received_laari` what the bank actually sent,
 * and the wallet is credited the second. On the admin match there is no bank
 * row to read, so the reviewer states `received_laari` themselves — see
 * `MatchWalletTopUpRequestSchema`.
 */
export const WalletTopUpSchema = z.object({
  id: z.number().int(),
  merchant_id: z.number().int(),
  merchant: z
    .object({
      id: z.number().int(),
      name: z.string(),
      /** The store's registered payer name — what the bank may print. */
      bank_account_name: z.string().nullable(),
    })
    .optional(),
  ...claimAndFactShape,
  currency: z.string(),
  /** What the MERCHANT typed. Often null — the slip carries the reference. */
  bank_ref: z.string().nullable(),
  platform_bank_account_id: z.number().int().nullable(),
  platform_bank_account: WalletTopUpBankAccountSchema.nullable().optional(),
  state: WalletTopUpStateSchema,
  has_slip: z.boolean(),
  /** Mime derived from the uploaded BYTES, never the client's Content-Type. */
  slip_mime: z.string().nullable(),
  slip_size_bytes: z.number().int().nullable(),
  uploaded_by: z.number().int().nullable(),

  /**
   * What the BANK said, once matched — separate from `bank_ref` on purpose:
   * "the merchant claimed this" and "we found this" are different facts.
   * `matched_trx_id` is unique across all three claim tables (settlement
   * payments, orders, top-ups), so one bank credit can never fund two
   * things; `matched_by_rule` is the top of the ladder settlement payments
   * use — `reference` or `receipt_reference` ONLY. The name rungs
   * (`receipt_name`, `receipt_name_fuzzy`, `name`) never auto-credit a
   * top-up: the merchant chooses the amount and writes the slip, so a name
   * is not evidence here. Unreferenced transfers wait for the admin queue.
   */
  auto_matched: z.boolean(),
  matched_trx_id: z.string().nullable(),
  matched_trx_refs: z.array(z.string()),
  matched_payer_name: z.string().nullable(),
  matched_score: z.number().int().nullable(),
  matched_by_rule: z.string().nullable(),
  matched_by: z.number().int().nullable(),
  matched_at: z.string().nullable(),
  /** The wallet movement the match produced — the audit link from claim to money. */
  wallet_transaction_id: z.number().int().nullable(),
  /**
   * The bank watch as it stands on the ROW: the poll runs until this
   * instant (null once decided), and `poll_attempts` counts its looks.
   * 0 attempts with the window open means no job was ever dispatched —
   * auto-verify was off at claim time — so nothing is watching.
   */
  poll_until: z.string().nullable(),
  poll_attempts: z.number().int(),
  rejected_by: z.number().int().nullable(),
  rejected_at: z.string().nullable(),
  /** Why an admin refused the claim — the merchant reads this verbatim. */
  rejected_reason: z.string().nullable(),
  created_at: z.string(),
});
export type WalletTopUp = z.infer<typeof WalletTopUpSchema>;

/**
 * A claim still in flight, as the wallet payload lists it — money the
 * merchant has sent that is not yet balance, so the wallet screen can say
 * "MVR 500 to BML, waiting". Only `pending` rows appear here; the full row
 * (WalletTopUpSchema) is what the create call answers with.
 */
export const WalletPendingTopUpSchema = z.object({
  id: z.number().int(),
  /**
   * The claim, and what the bank actually credited once that is known —
   * the same three fields the full row carries. Both, because the wallet
   * screen is where a merchant notices that MVR 10.00 landed on a claim
   * they typed MVR 20.00 on.
   */
  ...claimAndFactShape,
  bank_ref: z.string().nullable(),
  bank: WalletTopUpBankAccountSchema.nullable(),
  /**
   * `pending` while the bank or an admin is still to decide; `rejected`
   * rows stay on the list for a week after the decision so the merchant
   * reads the reason instead of watching the claim vanish.
   */
  state: WalletTopUpStateSchema,
  /** Why an admin refused the claim — shown verbatim; null unless rejected. */
  rejected_reason: z.string().nullable(),
  rejected_at: z.string().nullable(),
  created_at: z.string(),
});
export type WalletPendingTopUp = z.infer<typeof WalletPendingTopUpSchema>;

export const WalletSchema = z.object({
  balance_laari: z.number().int(),
  balance_mvr: z.string(),
  currency: z.string(),
  /**
   * The smallest transfer the merchant may claim as a top-up, integer laari
   * (the platform's `wallet_top_up_min_laari`, MVR 100 by default). Read
   * live so an admin raising it moves the form at once — render it as the
   * amount field's floor and refuse below it before the server does (422
   * `top_up_below_minimum`).
   */
  top_up_min_laari: z.number().int(),
  /**
   * Whether the hourly run settles validated cashback from this balance,
   * oldest first, as much as fits (owner, 2026-08-24; default ON). READ here
   * because this is where the merchant sees the money; WRITTEN only through
   * `updateMerchantPreferences({ auto_settle_from_wallet })` — there is no
   * wallet-scoped write route.
   */
  auto_settle_from_wallet: z.boolean(),
  /**
   * Where a top-up may be sent: the platform's active accounts, default
   * first — the same list a settlement's `payment_instructions.bank_accounts`
   * carries, published here so a store with nothing payable and no
   * settlement history can still fund its wallet. Pass the chosen `id` as
   * `platform_bank_account_id`. Empty means the platform has configured no
   * account yet — say so, never invent one.
   */
  bank_accounts: z.array(SettlementDestinationSchema),
  /**
   * Present on the merchant wallet read; newest first. Pending claims plus
   * those rejected in the last 7 days (see WalletPendingTopUpSchema).
   */
  pending_top_ups: z.array(WalletPendingTopUpSchema).optional(),
  transactions: z.array(WalletMovementSchema).optional(),
});
export type Wallet = z.infer<typeof WalletSchema>;

// ---------------------------------------------------------------------------
// Payout batches
// ---------------------------------------------------------------------------

export const PayoutBatchStateSchema = z.enum([
  "draft",
  "approved",
  "processing",
  "sent",
  "completed",
  "partially_failed",
  "cancelled",
]);
export type PayoutBatchState = z.infer<typeof PayoutBatchStateSchema>;

export const PayoutItemStateSchema = z.enum([
  "pending",
  "sent",
  "paid",
  "failed",
]);
export type PayoutItemState = z.infer<typeof PayoutItemStateSchema>;

export const PayoutItemSchema = z.object({
  id: z.number().int(),
  batch_id: z.number().int(),
  customer_id: z.number().int(),
  // The key the transfer sheet is matched on, and the customer as they were
  // when the batch was built — both snapshots, so a rename after the fact
  // never rewrites an instruction already with the bank.
  idempotency_key: z.string(),
  customer_name: z.string().nullable(),
  customer_phone: z.string().nullable(),
  amount_laari: z.number().int(),
  currency: z.string(),
  bank: z.string().nullable(),
  account: z.string().nullable(),
  account_name: z.string().nullable(),
  state: PayoutItemStateSchema,
  failure_reason: z.string().nullable(),
  bank_reference: z.string().nullable(),
  // What the bank API said, when the batch went out that way.
  attempts: z.number().int(),
  error_code: z.string().nullable(),
  // An approvals-queue record id, not a transaction reference — a parked
  // transfer is alive, and must never be shown as paid.
  approval_id: z.string().nullable(),
});
export type PayoutItem = z.infer<typeof PayoutItemSchema>;

export const PayoutBatchSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: PayoutBatchStateSchema,
  period_start: z.string(),
  period_end: z.string(),
  cutoff_at: z.string(),
  total_laari: z.number().int(),
  currency: z.string(),
  customer_count: z.number().int(),
  // Money waiting on bank details: eligible customers skipped at build time.
  excluded_customer_count: z.number().int(),
  excluded_total_laari: z.number().int(),
  created_by: z.number().int().nullable(),
  approved_by: z.number().int().nullable(),
  approved_at: z.string().nullable(),
  exported_at: z.string().nullable(),
  // Set when the batch went out through the bank API rather than as a sheet.
  // Deliberately not exported_at: no file was ever made.
  api_sent_at: z.string().nullable(),
  // Present only on endpoints that eager-load the items.
  items: z.array(PayoutItemSchema).optional(),
});
export type PayoutBatch = z.infer<typeof PayoutBatchSchema>;
