import { z } from 'zod';
import { apiFetch, apiFetchPublic } from './client';
import {
  dataWrapped,
  MAX_CASHBACK_BP,
  PercentSchema,
  percentInput,
} from './resources';

/**
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25) — "I intend to use this feature
 * during initial merchant acquisition."
 *
 * The platform fee MANFAA charges a merchant, temporarily reduced (or waived)
 * two ways. Not to be confused with `PromotionSchema` in ./resources, which is
 * a CASHBACK promotion — a merchant paying their customers more. One lifts the
 * reward, the other lowers our cut, and they never meet.
 *
 *   INTRODUCTORY (`introductory`)   every merchant pays the promotional fee
 *                                   for their first X days on the platform,
 *                                   counted from the day their store was
 *                                   approved. A store approved before the
 *                                   promotion was switched on is NOT
 *                                   back-enrolled: their first X days are
 *                                   their first X days, and if those have
 *                                   passed they get nothing.
 *   PLATFORM-WIDE (`platform_wide`) a dated window in which EVERY merchant
 *                                   pays the promotional fee, whatever their
 *                                   age.
 *
 * WHEN BOTH APPLY, THE MERCHANT WINS: the sale is priced at the lower of the
 * two fees (and never above the merchant's own §4 tier fee, so a promotion can
 * only ever make a sale cheaper). The API decides this — a client never picks
 * between two offers; the merchant endpoint below answers with the ONE offer
 * that is actually pricing this store's sales.
 *
 * THREE DOORS, THREE AUDIENCES, and the difference between the last two is a
 * privacy rule rather than a formatting one:
 *
 *   getAdminFeePromotions / updateAdminFeePromotions
 *       the settings row. Read by any admin, WRITTEN by a superadmin (403).
 *   getMerchantFeePromotion
 *       what THIS store is being charged and when THEIR window closes —
 *       `ends_at` / `days_remaining` are personal to the authenticated
 *       merchant, computed from their own approval date.
 *   getPublicFeePromotion
 *       what is on offer to whoever signs up next. The OFFER and nothing
 *       else: an introductory offer is published as "X days at Y%", never as
 *       a date, because a visitor has no approval stamp. Unauthenticated, and
 *       fetched WITHOUT the session (apiFetchPublic).
 *
 * WIRE GRAMMAR (PLAN §1): every fee is a 2-decimal percent STRING —
 * `platform_fee_percent: "0.00"`. Basis points appear in no request and no
 * response; use `percentToBp` from ./percent only to compare or to drive a
 * slider.
 *
 * WHAT A PROMOTION DOES NOT COVER: marketplace order fees, which are a
 * separate price list on a different scale. The settings payload says so
 * itself in `applies_to` / `excludes` so an admin screen can print it.
 *
 * NOTHING HERE RE-PRICES A SALE THAT ALREADY HAPPENED. Every transaction
 * carries the fee it was rung up under, and every report, settlement and
 * journal reads that stamp — switching a promotion on, off, or to another
 * window prices NEW sales only.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

// ---------------------------------------------------------------------------
// Shared vocabulary
// ---------------------------------------------------------------------------

/** The two ways the platform fee can be promotional. */
export const FeePromotionKindSchema = z.enum([
  'introductory',
  'platform_wide',
]);
export type FeePromotionKind = z.infer<typeof FeePromotionKindSchema>;

/** Both kinds, in settings-screen order. */
export const FEE_PROMOTION_KINDS = FeePromotionKindSchema.options;

/**
 * A promotional fee's own bounds, in basis points — the mirror of the
 * server's `PercentRate::between(0, Percent::MAX_BP)`.
 *
 * ZERO IS LEGAL AND DELIBERATE: it is the "free for the first X days" case,
 * which is the whole feature. It is NOT the same as unset — a fee that has
 * never been set reads `null`, and the API refuses to switch a promotion on
 * over it rather than letting "not set" quietly become "free".
 *
 * The ceiling here is the structural 20.00% §4 puts on every rate; the bound
 * a form should actually print is `max_promotional_fee_percent` on the
 * settings payload, which is the cheapest fee on the ACTIVE tier schedule.
 */
export const FEE_PROMOTION_MIN_FEE_BP = 0;
export const FEE_PROMOTION_MAX_FEE_BP = MAX_CASHBACK_BP;

/** The longest introductory window the API accepts (10 years). */
export const FEE_PROMOTION_MAX_INTRO_DAYS = 3650;

/** Banner copy ceiling, per language, as the API validates it. */
export const FEE_PROMOTION_BANNER_MAX_CHARS = 240;

/** A promotional fee as a REQUEST may send it: "0", "0.25", "0.00" or 0.25. */
export const FeePromotionFeePercentInputSchema = percentInput(
  FEE_PROMOTION_MIN_FEE_BP,
  FEE_PROMOTION_MAX_FEE_BP,
);
export type FeePromotionFeePercentInput = z.infer<
  typeof FeePromotionFeePercentInputSchema
>;

/**
 * What still stands between a switch and being turned on, as the API's own
 * machine slugs — so the settings form can say it beside the offending input
 * BEFORE the 422 says it in a sentence:
 *
 *   fee_not_set             no promotional fee at all (not the same as 0.00)
 *   fee_above_cheapest_tier the fee is HIGHER than the cheapest fee on the
 *                           active tier schedule, so a merchant on that tier
 *                           would pay MORE — a mistake, not a promotion
 *   days                    introductory window of zero days: nobody is ever
 *                           inside it
 *   window                  platform-wide offer missing a start or an end
 *   window_order            the end falls on or before the start
 *   banner_en_missing       no English banner wording
 *   banner_dv_missing       no Dhivehi banner wording
 *
 * The last two are not a formality: the banner is how a merchant learns they
 * are being charged less, and the API refuses to enable a promotion nobody
 * can be told about, in either language.
 */
export const FeePromotionBlockerSchema = z.enum([
  'fee_not_set',
  'fee_above_cheapest_tier',
  'days',
  'window',
  'window_order',
  'banner_en_missing',
  'banner_dv_missing',
]);
export type FeePromotionBlocker = z.infer<typeof FeePromotionBlockerSchema>;

/** Every blocker this client knows how to explain. */
export const FEE_PROMOTION_BLOCKERS = FeePromotionBlockerSchema.options;

/**
 * Is this slug one this build knows? The payload's `blockers` are parsed as
 * plain strings ON PURPOSE: a slug this client has never heard of must still
 * count as "not ready to enable", and a strict enum would instead fail the
 * whole settings parse — or, with a `.catch`, silently drop the list and tell
 * the panel a broken promotion is good to go. Map the known ones, and treat
 * an unknown one as a blocker with no local wording.
 */
export function isFeePromotionBlocker(
  value: string,
): value is FeePromotionBlocker {
  return (FEE_PROMOTION_BLOCKERS as readonly string[]).includes(value);
}

/**
 * The banner sentence in the reader's own language, falling back to the other
 * one rather than to a blank: a merchant being charged less should never see
 * an empty space where the reason was. Null only when NEITHER exists — which,
 * for an active promotion, the API does not allow, since it refuses to enable
 * one without wording in both languages.
 */
export function feePromotionBanner(
  banner: { banner_en: string | null; banner_dv: string | null },
  locale: 'en' | 'dv',
): string | null {
  return locale === 'dv'
    ? (banner.banner_dv ?? banner.banner_en)
    : (banner.banner_en ?? banner.banner_dv);
}

// ---------------------------------------------------------------------------
// GET/PATCH /api/admin/platform/fee-promotions
// ---------------------------------------------------------------------------

/**
 * The introductory offer as stored. `platform_fee_percent` is null when no
 * fee has ever been set — render that as "not set", never as 0.00%.
 * `days` may be 0 on a row nobody has configured yet; enabling over it is
 * refused (`days` blocker).
 */
export const IntroductoryFeePromotionSettingsSchema = z.object({
  kind: z.literal('introductory'),
  enabled: z.boolean(),
  /** Days from the store's approval, inclusive of the approval day itself. */
  days: z.number().int(),
  platform_fee_percent: PercentSchema.nullable(),
  banner_en: z.string().nullable(),
  banner_dv: z.string().nullable(),
  blockers: z.array(z.string()),
});
export type IntroductoryFeePromotionSettings = z.infer<
  typeof IntroductoryFeePromotionSettingsSchema
>;

/**
 * The platform-wide offer as stored. `from` / `to` are ISO-8601 UTC
 * instants (null until set); `to` is EXCLUSIVE — the first instant the offer
 * no longer prices a sale.
 */
export const PlatformWideFeePromotionSettingsSchema = z.object({
  kind: z.literal('platform_wide'),
  enabled: z.boolean(),
  from: z.string().nullable(),
  to: z.string().nullable(),
  platform_fee_percent: PercentSchema.nullable(),
  banner_en: z.string().nullable(),
  banner_dv: z.string().nullable(),
  blockers: z.array(z.string()),
});
export type PlatformWideFeePromotionSettings = z.infer<
  typeof PlatformWideFeePromotionSettingsSchema
>;

/**
 * The whole settings row — one row, both kinds, both switches independent.
 *
 * `max_promotional_fee_percent` is the bound BOTH fees are judged against:
 * the cheapest platform fee on the active tier schedule. Print it above the
 * fee inputs ("must be 0.25% or less") rather than waiting for the 422.
 *
 * `applies_to` / `excludes` are the API saying out loud what a promotion
 * covers — the cashback platform fee, and NOT marketplace order fees. Open
 * string lists so the server can name something new without a client change.
 */
export const FeePromotionSettingsSchema = z.object({
  intro: IntroductoryFeePromotionSettingsSchema,
  platform_wide: PlatformWideFeePromotionSettingsSchema,
  max_promotional_fee_percent: PercentSchema,
  applies_to: z.array(z.string()),
  excludes: z.array(z.string()),
  /** When a superadmin last saved the row; null on the seeded, untouched one. */
  updated_at: z.string().nullable(),
});
export type FeePromotionSettings = z.infer<typeof FeePromotionSettingsSchema>;

export const FeePromotionSettingsResponseSchema = dataWrapped(
  FeePromotionSettingsSchema,
);
export type FeePromotionSettingsResponse = z.infer<
  typeof FeePromotionSettingsResponseSchema
>;

/**
 * GET /api/admin/platform/fee-promotions — readable by ANY admin (401
 * without an admin session). Answers the single settings row, seeded with
 * both switches off.
 */
export function getAdminFeePromotions(
  options: RequestOptions = {},
): Promise<FeePromotionSettingsResponse> {
  return apiFetch(
    '/api/admin/platform/fee-promotions',
    FeePromotionSettingsResponseSchema,
    { signal: options.signal },
  );
}

/**
 * The PATCH body. Every field is optional and only what is SENT is written,
 * and the refusals are judged against the row AS IT WOULD BE SAVED — which
 * is what makes "set the fee, write the copy and switch it on" one legal
 * request, and what makes flipping the switch alone on an empty row a 422.
 */
export const UpdateFeePromotionSettingsRequestSchema = z.object({
  intro_enabled: z.boolean().optional(),
  /** 0 is storable but not enable-able; at least 1 to switch the offer on. */
  intro_days: z
    .number()
    .int()
    .min(0)
    .max(FEE_PROMOTION_MAX_INTRO_DAYS)
    .optional(),
  /**
   * "0", "0.25", "0.00" or the number 0.25 — never basis points. Explicit
   * `null` CLEARS the fee back to "not set"; 0 means free, and the two are
   * different answers.
   */
  intro_fee_percent: FeePromotionFeePercentInputSchema.nullable().optional(),
  intro_banner_en: z
    .string()
    .max(FEE_PROMOTION_BANNER_MAX_CHARS)
    .nullable()
    .optional(),
  intro_banner_dv: z
    .string()
    .max(FEE_PROMOTION_BANNER_MAX_CHARS)
    .nullable()
    .optional(),

  wide_enabled: z.boolean().optional(),
  /**
   * ISO-8601 instants (`new Date(...).toISOString()`). The end is EXCLUSIVE
   * and must fall strictly after the start; a platform-wide offer needs both
   * edges before it can be switched on, because merchants have to be told
   * when it stops.
   */
  wide_from: z.string().nullable().optional(),
  wide_to: z.string().nullable().optional(),
  wide_fee_percent: FeePromotionFeePercentInputSchema.nullable().optional(),
  wide_banner_en: z
    .string()
    .max(FEE_PROMOTION_BANNER_MAX_CHARS)
    .nullable()
    .optional(),
  wide_banner_dv: z
    .string()
    .max(FEE_PROMOTION_BANNER_MAX_CHARS)
    .nullable()
    .optional(),
});
export type UpdateFeePromotionSettingsRequest = z.infer<
  typeof UpdateFeePromotionSettingsRequestSchema
>;

/**
 * PATCH /api/admin/platform/fee-promotions — SUPERADMIN only (403
 * otherwise). Answers the refreshed row.
 *
 * The 422s are written for a person and the panel should render the message
 * verbatim; every one of them is already visible as a slug in the read's
 * `blockers`, so a form built on that should rarely provoke one:
 * no fee set, a zero-day introductory window, a platform-wide offer missing
 * an edge or ending before it starts, a fee above the cheapest tier fee, and
 * banner wording missing in English or Dhivehi.
 *
 * A save takes effect on the NEXT sale — including a save that ENDS a
 * promotion, which is the case that matters.
 */
export function updateAdminFeePromotions(
  body: UpdateFeePromotionSettingsRequest,
  options: RequestOptions = {},
): Promise<FeePromotionSettingsResponse> {
  return apiFetch(
    '/api/admin/platform/fee-promotions',
    FeePromotionSettingsResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// GET /api/merchant/fee-promotion  (and /api/mobile/v1/merchant/fee-promotion)
// ---------------------------------------------------------------------------

/**
 * The banner THIS store should be shown — the one offer actually pricing
 * their sales right now, already resolved by the API (lower fee wins).
 *
 * THE SHAPE IS STABLE: `active: false` still carries every key, as null, so
 * a client never has to guess which fields it may expect. Narrow with
 * `isActiveFeePromotion` and the nulls go away.
 *
 * `ends_at` and `days_remaining` are PERSONAL to this merchant — for an
 * introductory offer they come from the store's own approval date. Both are
 * null for an offer with no end. `days_remaining` counts whole
 * business-timezone days and reads 1, not 0, on the last day: there is still
 * a day of it to use.
 *
 * No permission gate: every account that may log in to a store may be told
 * what that store is being charged.
 */
export const MerchantFeePromotionSchema = z.object({
  active: z.boolean(),
  kind: FeePromotionKindSchema.nullable(),
  /** English prose from the API; localise off `kind`, not off this. */
  kind_label: z.string().nullable(),
  /** What this store is ACTUALLY charged while the promotion runs. */
  platform_fee_percent: PercentSchema.nullable(),
  ends_at: z.string().nullable(),
  days_remaining: z.number().int().nullable(),
  banner_en: z.string().nullable(),
  banner_dv: z.string().nullable(),
});
export type MerchantFeePromotion = z.infer<typeof MerchantFeePromotionSchema>;

/** A merchant promotion that is actually running: the fields are all there. */
export interface ActiveMerchantFeePromotion extends MerchantFeePromotion {
  active: true;
  kind: FeePromotionKind;
  kind_label: string;
  platform_fee_percent: string;
}

/**
 * The narrowing a banner component wants: true only when a promotion is
 * pricing this store's sales, and with it the kind, the label and the fee
 * stop being nullable.
 */
export function isActiveFeePromotion(
  promotion: MerchantFeePromotion,
): promotion is ActiveMerchantFeePromotion {
  return (
    promotion.active &&
    promotion.kind !== null &&
    promotion.kind_label !== null &&
    promotion.platform_fee_percent !== null
  );
}

export const MerchantFeePromotionResponseSchema = dataWrapped(
  MerchantFeePromotionSchema,
);
export type MerchantFeePromotionResponse = z.infer<
  typeof MerchantFeePromotionResponseSchema
>;

/**
 * GET /api/merchant/fee-promotion — the authenticated store's own banner.
 * The till app reads the SAME controller at
 * /api/mobile/v1/merchant/fee-promotion; one sentence, two doors.
 *
 * NOTE for the panel: the rate/fee display surfaces (e.g. the merchant rate
 * page's `platform_fee_percent`) still quote the store's §4 TIER fee — the
 * standing price list. This banner is the mechanism that says what they are
 * actually being charged today, so render it BESIDE that figure rather than
 * expecting the other endpoint to know about promotions.
 */
export function getMerchantFeePromotion(
  options: RequestOptions = {},
): Promise<MerchantFeePromotionResponse> {
  return apiFetch(
    '/api/merchant/fee-promotion',
    MerchantFeePromotionResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// GET /api/public/fee-promotion  (merchant landing page — no auth)
// ---------------------------------------------------------------------------

/**
 * One offer as a STRANGER may be told about it: the terms, and nothing that
 * belongs to a merchant.
 *
 * `intro_days` is the length of the introductory offer ("your first 90 days")
 * and is non-null ONLY for the introductory kind — the public shape never
 * carries a date for it, because a visitor has no approval stamp and any date
 * printed would be a promise about a merchant they are not yet.
 *
 * `ends_at` is non-null only for the platform-wide kind: that IS the
 * platform's own campaign deadline, and it is meant to be on the poster.
 */
export const PublicFeePromotionOfferSchema = z.object({
  kind: FeePromotionKindSchema,
  /** English prose from the API; localise off `kind`, not off this. */
  kind_label: z.string(),
  platform_fee_percent: PercentSchema,
  /** Introductory only; null on a platform-wide offer. */
  intro_days: z.number().int().nullable(),
  /** Platform-wide only; null on an introductory offer. */
  ends_at: z.string().nullable(),
  banner_en: z.string().nullable(),
  banner_dv: z.string().nullable(),
});
export type PublicFeePromotionOffer = z.infer<
  typeof PublicFeePromotionOfferSchema
>;

/**
 * What is on offer to whoever signs up next. BOTH kinds can be live at once,
 * and here — unlike the merchant endpoint — neither wins: the landing page is
 * advertising, so it shows every running offer as its own banner. Empty
 * `offers` with `active: false` is the ordinary quiet state; render nothing.
 */
export const PublicFeePromotionsSchema = z.object({
  active: z.boolean(),
  offers: z.array(PublicFeePromotionOfferSchema),
});
export type PublicFeePromotions = z.infer<typeof PublicFeePromotionsSchema>;

export const PublicFeePromotionResponseSchema = dataWrapped(
  PublicFeePromotionsSchema,
);
export type PublicFeePromotionResponse = z.infer<
  typeof PublicFeePromotionResponseSchema
>;

/**
 * GET /api/public/fee-promotion — UNAUTHENTICATED, throttled 120/min per IP,
 * answered from a 60-second server cache.
 *
 * Fetched through `apiFetchPublic`, NOT the ordinary client: no cookie and no
 * CSRF token leave the browser for it. The landing page is served from the
 * same origin as the logged-in merchant panel, so the credentialed path would
 * hand this endpoint a merchant's session on every visit — for an answer that
 * is identical for every visitor and must stay that way.
 */
export function getPublicFeePromotion(
  options: RequestOptions = {},
): Promise<PublicFeePromotionResponse> {
  return apiFetchPublic(
    '/api/public/fee-promotion',
    PublicFeePromotionResponseSchema,
    { signal: options.signal },
  );
}
