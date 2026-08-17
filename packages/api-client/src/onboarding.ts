import { z } from 'zod';
import { apiFetch } from './client';
import { MerchantBranchSchema, MerchantRoleSummarySchema } from './merchant';
import {
  CashbackPercentInputSchema,
  dataWrapped,
  MerchantChannelSchema,
  MerchantStatusSchema,
  PercentSchema,
} from './resources';

/**
 * Store self-signup + onboarding (§1 decision 2026-08-15, Task #24):
 *
 *  - the PUBLIC signup flow — phone OTP → short-lived signup token →
 *    register creates a DRAFT merchant plus its owner and logs the owner in
 *    (Sanctum session; call bootstrapCsrf() before the first POST);
 *  - the resumable setup wizard, gated on `setup.view` / `.edit` /
 *    `.submit` and seeded to the Owner role alone — profile (curated category,
 *    channel, terms, contact), the primary branch's map pin, optional logo
 *    (FormData), initial cashback rate, then submit → pending_review;
 *  - the ADMIN approval queue (approve → active / reject with a reason) and
 *    CRUD over the superadmin-curated store categories.
 *
 * Every rate here travels as a 2-decimal percent string (PLAN §1 wire
 * format) — `cashback_rate_percent`, `rate_bounds.min_percent` /
 * `max_percent`. Basis points are the API's internal representation and
 * never appear in a body; convert with the shared percent helpers when you
 * need arithmetic — never floats.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

// ---------------------------------------------------------------------------
// Signup flow: request-otp → verify-otp → register (public, throttled)
// ---------------------------------------------------------------------------

/** Maldives mobile numbers: +960 then a 7-digit number starting 7 or 9. */
export const MerchantSignupPhoneSchema = z.string().regex(/^\+960[79]\d{6}$/);

export const MerchantSignupRequestOtpRequestSchema = z.object({
  phone: MerchantSignupPhoneSchema,
});
export type MerchantSignupRequestOtpRequest = z.infer<
  typeof MerchantSignupRequestOtpRequestSchema
>;

export const MerchantSignupRequestOtpResponseSchema = z.object({
  /**
   * Deliberately identical whether or not the phone already has an account —
   * enumeration-safe. Throttled 3/hour per phone + 10/hour per IP; a 429
   * arrives as an ApiError with a Retry-After header.
   */
  message: z.string(),
});
export type MerchantSignupRequestOtpResponse = z.infer<
  typeof MerchantSignupRequestOtpResponseSchema
>;

/** POST /api/merchant/signup/request-otp — sends the SMS code. */
export function requestMerchantSignupOtp(
  body: MerchantSignupRequestOtpRequest,
  options: RequestOptions = {},
): Promise<MerchantSignupRequestOtpResponse> {
  return apiFetch(
    '/api/merchant/signup/request-otp',
    MerchantSignupRequestOtpResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

export const MerchantSignupVerifyOtpRequestSchema = z.object({
  phone: MerchantSignupPhoneSchema,
  /** The 6-digit SMS code. */
  code: z.string().regex(/^\d{6}$/),
});
export type MerchantSignupVerifyOtpRequest = z.infer<
  typeof MerchantSignupVerifyOtpRequestSchema
>;

export const MerchantSignupVerifyOtpResponseSchema = dataWrapped(
  z.object({
    /** Single-use; redeem it at register within the TTL. */
    signup_token: z.string(),
    expires_in_minutes: z.number().int(),
  }),
);
export type MerchantSignupVerifyOtpResponse = z.infer<
  typeof MerchantSignupVerifyOtpResponseSchema
>;

/**
 * POST /api/merchant/signup/verify-otp — redeems the SMS code for a signup
 * token. Failures are 422 validation errors on `code`: `otp_invalid` (which
 * never says WHY — wrong, expired and unknown-phone are indistinguishable)
 * or `otp_attempts_exceeded`.
 */
export function verifyMerchantSignupOtp(
  body: MerchantSignupVerifyOtpRequest,
  options: RequestOptions = {},
): Promise<MerchantSignupVerifyOtpResponse> {
  return apiFetch(
    '/api/merchant/signup/verify-otp',
    MerchantSignupVerifyOtpResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

export const MerchantSignupRegisterRequestSchema = z.object({
  signup_token: z.string().min(1),
  business_name: z.string().min(2).max(120),
  /**
   * The store's own name in Thaana. Optional — a store that leaves it blank
   * simply shows its Latin name to Dhivehi visitors — and never used for
   * the slug, which stays ASCII off `business_name`.
   */
  business_name_dv: z.string().max(120).nullish(),
  email: z.email().max(255),
  password: z.string().min(8).max(255),
});
export type MerchantSignupRegisterRequest = z.infer<
  typeof MerchantSignupRegisterRequestSchema
>;

/**
 * The merchant panel account as /api/merchant/auth login/me and signup
 * register return it — THE one schema for that body. `merchant.status` is
 * the onboarding lifecycle: the panel routes draft/rejected owners into the
 * setup wizard and pending_review ones onto the waiting screen.
 *
 * A second copy of this shape is how the permission set goes missing. Zod
 * strips unknown keys, so a panel-local /me schema that omits `permissions`
 * hands every gate `undefined` with no type error and no failed parse, and
 * every gate then denies. There is one copy, and it lives here.
 */
export const MerchantAuthUserSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.email(),
  /**
   * The RESOLVED flat permission set, owner wildcard already expanded
   * against the catalogue server-side (D3). This is what the panel gates on
   * and the only thing it gates on: a set has no order, so there is no tier
   * left to compare, and shipping the owner's wildcard as a sentinel instead
   * would make `permissions.includes('bank_account.update')` false for the
   * one account the wildcard exists to protect.
   *
   * Plain strings, not the MerchantPermission enum: a server ahead of this
   * build sends slugs it does not know, and `includes` must keep working
   * rather than throw the whole session away at parse time.
   */
  permissions: z.array(z.string()),
  /**
   * Carried to be PRINTED — "signed in as Shift lead" — and so the roles
   * screen can tell which row is the reader's own. Never gate on it: custom
   * role names are the store's own words. Null when the account somehow
   * stands on no role, which grants nothing.
   */
  role: MerchantRoleSummarySchema.nullable(),
  merchant: z.object({
    id: z.number().int(),
    name: z.string(),
    status: MerchantStatusSchema,
  }),
});
export type MerchantAuthUser = z.infer<typeof MerchantAuthUserSchema>;

export const MerchantAuthUserResponseSchema =
  dataWrapped(MerchantAuthUserSchema);
export type MerchantAuthUserResponse = z.infer<
  typeof MerchantAuthUserResponseSchema
>;

/**
 * POST /api/merchant/signup/register — 201: creates the DRAFT merchant plus
 * its owner account and LOGS THE OWNER IN (Sanctum session). Failures are
 * 422 validation errors: `signup_token_invalid` on `signup_token` (expired
 * or already used) or `email_already_registered` on `email` — the latter is
 * only ever disclosed after OTP-proven phone possession.
 */
export function registerMerchantSignup(
  body: MerchantSignupRegisterRequest,
  options: RequestOptions = {},
): Promise<MerchantAuthUserResponse> {
  return apiFetch(
    '/api/merchant/signup/register',
    MerchantAuthUserResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Setup wizard (`setup.view` / `.edit` / `.submit`, resumable)
// ---------------------------------------------------------------------------

/**
 * One curated store category as the wizard's picker lists it. `name_dv` is
 * the Dhivehi label; fall back to `name_en` when null.
 */
export const StoreCategoryOptionSchema = z.object({
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
});
export type StoreCategoryOption = z.infer<typeof StoreCategoryOptionSchema>;

/**
 * Everything the wizard needs to resume after any interruption. `steps` is
 * UI convenience only — completeness is re-validated server-side from the
 * actual values at submit. `rejected_reason` is non-null exactly while
 * `status` is `rejected`.
 */
export const MerchantSetupStateSchema = z.object({
  status: MerchantStatusSchema,
  steps: z.object({
    profile: z.boolean(),
    location: z.boolean(),
    logo: z.boolean(),
    rate: z.boolean(),
  }),
  values: z.object({
    name: z.string(),
    slug: z.string(),
    /** Curated store-category slug; null until the profile step sets it. */
    category: z.string().nullable(),
    channel: MerchantChannelSchema,
    eligibility_basis: z.string().nullable(),
    contact_email: z.string().nullable(),
    contact_phone: z.string().nullable(),
    support_phone: z.string().nullable().catch(null),
    website_url: z.string().nullable().catch(null),
    /**
     * The store's pin — its lowest-id branch, which is the primary one.
     * Null while the store has no branch at all; a branch added from
     * settings without coordinates answers with `lat`/`lng` null, so a
     * branch on file is not by itself a pin.
     */
    primary_branch: MerchantBranchSchema.nullable(),
    logo_url: z.string().nullable(),
    /**
     * The initial standing rate as a 2-decimal percent string; null until
     * the rate step writes one.
     */
    cashback_rate_percent: PercentSchema.nullable(),
  }),
  /**
   * §4 structural minimum and the ACTIVE fee tier schedule's own ceiling,
   * both percent strings — a rate above `max_percent` answers 422
   * `rate_not_priced`.
   */
  rate_bounds: z.object({
    min_percent: PercentSchema,
    max_percent: PercentSchema,
  }),
  /** The curated list (active rows only), in admin sort order. */
  categories: z.array(StoreCategoryOptionSchema),
  submitted_at: z.string().nullable(),
  rejected_reason: z.string().nullable(),
});
export type MerchantSetupState = z.infer<typeof MerchantSetupStateSchema>;

export const MerchantSetupStateResponseSchema = dataWrapped(
  MerchantSetupStateSchema,
);
export type MerchantSetupStateResponse = z.infer<
  typeof MerchantSetupStateResponseSchema
>;

/**
 * Wizard refusal codes carried on ApiError bodies as `code`:
 *  - `setup_not_editable` (409) — writes are allowed only while the store
 *    is draft or rejected;
 *  - `setup_incomplete` (422) — submit with the missing requirement keys in
 *    `missing` (`category` | `channel` | `rate` | `terms`);
 *  - `not_pending_review` (409) — admin approve/reject on a store that is
 *    not awaiting review;
 *  - `rate_not_priced` (422) — the rate step above the active schedule
 *    ceiling.
 */
export const OnboardingErrorCodeSchema = z.enum([
  'setup_not_editable',
  'setup_incomplete',
  'not_pending_review',
  'rate_not_priced',
]);
export type OnboardingErrorCode = z.infer<typeof OnboardingErrorCodeSchema>;

/**
 * GET /api/merchant/setup — `setup.view`. Readable in EVERY lifecycle state
 * (the panel renders the pending_review waiting screen and the rejection
 * banner from it); only the writes are gated to draft/rejected.
 */
export function getMerchantSetup(
  options: RequestOptions = {},
): Promise<MerchantSetupStateResponse> {
  return apiFetch('/api/merchant/setup', MerchantSetupStateResponseSchema, {
    signal: options.signal,
  });
}

export const UpdateMerchantSetupProfileRequestSchema = z.object({
  /** An ACTIVE curated store-category slug (422 otherwise), or null. */
  category: z.string().max(80).nullable().optional(),
  channel: MerchantChannelSchema.optional(),
  /** The terms & exclusions text; required (non-empty) before submit. */
  eligibility_basis: z.string().max(2000).nullable().optional(),
  contact_email: z.email().max(255).nullable().optional(),
  contact_phone: z.string().max(32).nullable().optional(),
  /** The number shoppers ring; the panel offers "same as contact" as one tick. */
  support_phone: z.string().max(32).nullable().optional(),
  /** A bare domain is fine — the API adds https:// and validates the result. */
  website_url: z.string().max(255).nullable().optional(),
});
export type UpdateMerchantSetupProfileRequest = z.infer<
  typeof UpdateMerchantSetupProfileRequestSchema
>;

/**
 * PATCH /api/merchant/setup/profile — partial; omitted keys are untouched.
 * 409 `setup_not_editable` outside draft/rejected.
 */
export function updateMerchantSetupProfile(
  body: UpdateMerchantSetupProfileRequest,
  options: RequestOptions = {},
): Promise<MerchantSetupStateResponse> {
  return apiFetch(
    '/api/merchant/setup/profile',
    MerchantSetupStateResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

export const UpdateMerchantSetupLocationRequestSchema = z.object({
  lat: z.number().min(-90).max(90),
  lng: z.number().min(-180).max(180),
});
export type UpdateMerchantSetupLocationRequest = z.infer<
  typeof UpdateMerchantSetupLocationRequestSchema
>;

/**
 * PATCH /api/merchant/setup/location — drops the pin on the store's primary
 * branch, creating that branch under the store's own name when it has none.
 * Re-entering the step moves the existing pin instead of adding a second
 * branch, so the owner may pass through it as often as they like.
 *
 * Both coordinates are REQUIRED, unlike the nullable pair the branch
 * endpoints take: this call exists to set a pin, and an online-only store
 * that skips the step simply never makes it. 409 `setup_not_editable`
 * outside draft/rejected.
 */
export function updateMerchantSetupLocation(
  body: UpdateMerchantSetupLocationRequest,
  options: RequestOptions = {},
): Promise<MerchantSetupStateResponse> {
  return apiFetch(
    '/api/merchant/setup/location',
    MerchantSetupStateResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

export const MerchantLogoResponseSchema = dataWrapped(
  z.object({
    /** Absolute URL of the stored logo — cache-bust when re-rendering. */
    logo_url: z.string(),
  }),
);
export type MerchantLogoResponse = z.infer<typeof MerchantLogoResponseSchema>;

function uploadLogo(
  path: string,
  file: File | Blob,
  options: RequestOptions,
): Promise<MerchantLogoResponse> {
  const form = new FormData();
  form.append('logo', file);
  return apiFetch(path, MerchantLogoResponseSchema, {
    method: 'POST',
    body: form,
    signal: options.signal,
  });
}

/**
 * POST /api/merchant/setup/logo — multipart FormData, field `logo`. Raster
 * images only (jpg/png/webp — SVG is refused), max 2 MB, 64–4096 px per
 * side. Allowed while draft/rejected AND for active merchants (post-
 * approval logo changes); 409 `setup_not_editable` otherwise.
 */
export function uploadMerchantSetupLogo(
  file: File | Blob,
  options: RequestOptions = {},
): Promise<MerchantLogoResponse> {
  return uploadLogo('/api/merchant/setup/logo', file, options);
}

/**
 * POST /api/merchant/settings/logo — the identical action mounted under the
 * settings module for ACTIVE merchants; same validation and response.
 */
export function uploadMerchantSettingsLogo(
  file: File | Blob,
  options: RequestOptions = {},
): Promise<MerchantLogoResponse> {
  return uploadLogo('/api/merchant/settings/logo', file, options);
}

export const UpdateMerchantSetupRateRequestSchema = z.object({
  /**
   * A 2-decimal percent — the string "2.5" or the JSON number 2.5. §4
   * bounds 0.50%–20.00%; the live schedule ceiling is enforced server-side.
   */
  cashback_rate_percent: CashbackPercentInputSchema,
});
export type UpdateMerchantSetupRateRequest = z.infer<
  typeof UpdateMerchantSetupRateRequestSchema
>;

/**
 * PATCH /api/merchant/setup/rate — writes the store's INITIAL standing
 * rate. 422 `rate_not_priced` above the active fee tier schedule's ceiling
 * (`rate_bounds.max_percent`); 409 `setup_not_editable` outside
 * draft/rejected.
 */
export function updateMerchantSetupRate(
  body: UpdateMerchantSetupRateRequest,
  options: RequestOptions = {},
): Promise<MerchantSetupStateResponse> {
  return apiFetch('/api/merchant/setup/rate', MerchantSetupStateResponseSchema, {
    method: 'PATCH',
    body,
    signal: options.signal,
  });
}

/**
 * POST /api/merchant/setup/submit — draft/rejected → pending_review. 422
 * `setup_incomplete` lists the missing requirements in `missing`; the store
 * stays INVISIBLE publicly until the admin queue approves it.
 */
export function submitMerchantSetup(
  options: RequestOptions = {},
): Promise<MerchantSetupStateResponse> {
  return apiFetch(
    '/api/merchant/setup/submit',
    MerchantSetupStateResponseSchema,
    { method: 'POST', signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Admin — store approval queue
// ---------------------------------------------------------------------------

export const StoreReviewStateSchema = z.enum([
  'pending_review',
  'rejected',
  'draft',
]);
export type StoreReviewState = z.infer<typeof StoreReviewStateSchema>;

/**
 * One store in the review queue — everything the merchant entered in the
 * wizard. `cashback_rate_percent` is null while the rate step is unset;
 * `setup_state` is the raw completed-step map.
 */
export const StoreReviewSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  status: MerchantStatusSchema,
  category: z.string().nullable(),
  channel: MerchantChannelSchema,
  eligibility_basis: z.string().nullable(),
  contact_email: z.string().nullable(),
  contact_phone: z.string().nullable(),
  logo_url: z.string().nullable(),
  cashback_rate_percent: PercentSchema.nullable(),
  /**
   * The store's pin, so a reviewer can see whether it has one — it is
   * deliberately NOT an approval requirement (D17), which is exactly why
   * nothing else on the row would say. `.catch` because an API build that
   * predates the field must not blank the whole review queue.
   */
  primary_branch: MerchantBranchSchema.nullable().catch(null),
  setup_state: z.record(z.string(), z.boolean()),
  submitted_at: z.string().nullable(),
  rejected_at: z.string().nullable(),
  rejected_reason: z.string().nullable(),
  created_at: z.string().nullable(),
});
export type StoreReview = z.infer<typeof StoreReviewSchema>;

export const StoreReviewListResponseSchema = z.object({
  data: z.array(StoreReviewSchema),
  meta: z.object({
    /** The state the listing was filtered to (default pending_review). */
    state: StoreReviewStateSchema,
    /** Tab badges: how many stores sit in each reviewable state. */
    counts: z.object({
      pending_review: z.number().int(),
      rejected: z.number().int(),
      draft: z.number().int(),
    }),
  }),
});
export type StoreReviewListResponse = z.infer<
  typeof StoreReviewListResponseSchema
>;

/**
 * GET /api/admin/store-reviews?state= — pending_review (default, oldest
 * submission first), rejected or draft (newest change first). Capped at
 * 200 rows.
 */
export function listStoreReviews(
  params: { state?: StoreReviewState } = {},
  options: RequestOptions = {},
): Promise<StoreReviewListResponse> {
  const query =
    params.state !== undefined
      ? `?state=${encodeURIComponent(params.state)}`
      : '';
  return apiFetch(
    `/api/admin/store-reviews${query}`,
    StoreReviewListResponseSchema,
    { signal: options.signal },
  );
}

export const StoreReviewApprovalResponseSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    status: MerchantStatusSchema,
    approved_at: z.string().nullable(),
    approved_by: z.number().int().nullable(),
  }),
);
export type StoreReviewApprovalResponse = z.infer<
  typeof StoreReviewApprovalResponseSchema
>;

/**
 * POST /api/admin/store-reviews/{id}/approve — pending_review → active; the
 * store becomes publicly visible on the next discovery read. 409
 * `not_pending_review` for any other state.
 */
export function approveStoreReview(
  merchantId: number,
  options: RequestOptions = {},
): Promise<StoreReviewApprovalResponse> {
  return apiFetch(
    `/api/admin/store-reviews/${merchantId}/approve`,
    StoreReviewApprovalResponseSchema,
    { method: 'POST', signal: options.signal },
  );
}

export const StoreReviewRejectionResponseSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    status: MerchantStatusSchema,
    rejected_at: z.string().nullable(),
    rejected_reason: z.string().nullable(),
  }),
);
export type StoreReviewRejectionResponse = z.infer<
  typeof StoreReviewRejectionResponseSchema
>;

export const RejectStoreReviewRequestSchema = z.object({
  /** Shown to the merchant verbatim — write it for them. */
  reason: z.string().min(3).max(2000),
});
export type RejectStoreReviewRequest = z.infer<
  typeof RejectStoreReviewRequestSchema
>;

/**
 * POST /api/admin/store-reviews/{id}/reject — pending_review → rejected
 * with a required reason; the merchant edits the wizard and resubmits. 409
 * `not_pending_review` for any other state.
 */
export function rejectStoreReview(
  merchantId: number,
  body: RejectStoreReviewRequest,
  options: RequestOptions = {},
): Promise<StoreReviewRejectionResponse> {
  return apiFetch(
    `/api/admin/store-reviews/${merchantId}/reject`,
    StoreReviewRejectionResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Admin — curated store categories CRUD
// ---------------------------------------------------------------------------

/**
 * One curated store category as the admin CRUD sees it. There is no DELETE
 * anywhere — deactivation is the only removal, and it is refused (409
 * `category_in_use`) while any ACTIVE merchant still carries the slug. The
 * slug is immutable after creation; merchants store it by value.
 */
export const StoreCategorySchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
  /** Curated glyph name; the rail's fallback when no artwork is uploaded. */
  icon: z.string().nullable().catch(null),
  /** Absolute URL of the uploaded icon; null when none. */
  icon_url: z.string().nullable().catch(null),
  sort: z.number().int(),
  active: z.boolean(),
  /** How many ACTIVE merchants currently carry this slug. */
  active_merchant_count: z.number().int(),
});
export type StoreCategory = z.infer<typeof StoreCategorySchema>;

export const StoreCategoryListResponseSchema = z.object({
  data: z.array(StoreCategorySchema),
});
export type StoreCategoryListResponse = z.infer<
  typeof StoreCategoryListResponseSchema
>;

// ---------------------------------------------------------------------------
// Curated featured offers — the banners at the top of Discover
// ---------------------------------------------------------------------------

/** The exact artwork an image banner takes. Mirrors App\Domain\Storefront\OfferImage. */
export const OFFER_ARTWORK = {
  width: 1200,
  height: 675,
  ratio: '16:9',
  minWidth: 800,
  minHeight: 450,
  maxKb: 2048,
} as const;

/**
 * Which banner the storefront draws. An offer with artwork is the artwork,
 * edge to edge; one without is laid out from its words and the store's live
 * rate. Never a blend of the two.
 */
export const OfferKindSchema = z.enum(['text', 'image']);
export type OfferKind = z.infer<typeof OfferKindSchema>;

/**
 * Why an offer is or is not on the storefront right now. Computed by the
 * API, never stored: it answers the question an admin has when a banner
 * they just saved is nowhere to be seen.
 */
export const OfferLiveStateSchema = z.enum([
  'live',
  'inactive',
  'scheduled',
  'ended',
  'store_not_trading',
]);
export type OfferLiveState = z.infer<typeof OfferLiveStateSchema>;

export const StoreOfferSchema = z.object({
  id: z.number().int(),
  merchant_id: z.number().int(),
  merchant: z
    .object({
      name: z.string(),
      name_dv: z.string().nullable().catch(null),
      slug: z.string(),
      status: z.string(),
    })
    .nullable(),
  title: z.string(),
  title_dv: z.string().nullable(),
  blurb: z.string().nullable(),
  blurb_dv: z.string().nullable(),
  badge: z.string().nullable(),
  badge_dv: z.string().nullable(),
  /** Null on a text banner. */
  image_url: z.string().nullable(),
  kind: OfferKindSchema,
  starts_at: z.string().nullable(),
  ends_at: z.string().nullable(),
  sort: z.number().int(),
  active: z.boolean(),
  live: OfferLiveStateSchema,
});
export type StoreOffer = z.infer<typeof StoreOfferSchema>;

export const StoreOfferListResponseSchema = z.object({
  data: z.array(StoreOfferSchema),
});
export type StoreOfferListResponse = z.infer<
  typeof StoreOfferListResponseSchema
>;

export const StoreOfferResponseSchema = dataWrapped(StoreOfferSchema);
export type StoreOfferResponse = z.infer<typeof StoreOfferResponseSchema>;

/** GET /api/admin/store-offers — every offer, curated order. */
export function listStoreOffers(
  options: RequestOptions = {},
): Promise<StoreOfferListResponse> {
  return apiFetch('/api/admin/store-offers', StoreOfferListResponseSchema, {
    signal: options.signal,
  });
}

export interface StoreOfferInput {
  merchant_id?: number;
  title?: string;
  title_dv?: string | null;
  blurb?: string | null;
  blurb_dv?: string | null;
  badge?: string | null;
  badge_dv?: string | null;
  starts_at?: string | null;
  ends_at?: string | null;
  sort?: number;
  active?: boolean;
}

export function createStoreOffer(
  body: StoreOfferInput,
  options: RequestOptions = {},
): Promise<StoreOfferResponse> {
  return apiFetch('/api/admin/store-offers', StoreOfferResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

export function updateStoreOffer(
  id: number,
  body: StoreOfferInput,
  options: RequestOptions = {},
): Promise<StoreOfferResponse> {
  return apiFetch(`/api/admin/store-offers/${id}`, StoreOfferResponseSchema, {
    method: 'PATCH',
    body,
    signal: options.signal,
  });
}

/**
 * Attaches or replaces the banner artwork, which also makes the offer an
 * IMAGE banner. Raster only, and exactly 16:9 — see OFFER_ARTWORK.
 */
export function uploadStoreOfferImage(
  id: number,
  file: File,
  options: RequestOptions = {},
): Promise<StoreOfferResponse> {
  const body = new FormData();
  body.append('image', file);

  return apiFetch(
    `/api/admin/store-offers/${id}/image`,
    StoreOfferResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

/** Removes the artwork, turning an image banner back into a text one. */
export function deleteStoreOfferImage(
  id: number,
  options: RequestOptions = {},
): Promise<StoreOfferResponse> {
  return apiFetch(
    `/api/admin/store-offers/${id}/image`,
    StoreOfferResponseSchema,
    { method: 'DELETE', signal: options.signal },
  );
}

export const StoreCategoryResponseSchema = dataWrapped(StoreCategorySchema);
export type StoreCategoryResponse = z.infer<typeof StoreCategoryResponseSchema>;

/** GET /api/admin/store-categories — ALL rows (inactive included), sort order. */
export function listStoreCategories(
  options: RequestOptions = {},
): Promise<StoreCategoryListResponse> {
  return apiFetch(
    '/api/admin/store-categories',
    StoreCategoryListResponseSchema,
    { signal: options.signal },
  );
}

export const CreateStoreCategoryRequestSchema = z.object({
  /** Immutable after creation. Kebab-case, unique. */
  slug: z
    .string()
    .max(80)
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/),
  name_en: z.string().min(1).max(120),
  name_dv: z.string().max(120).nullable().optional(),
  sort: z.number().int().min(0).max(100000).optional(),
  active: z.boolean().optional(),
});
export type CreateStoreCategoryRequest = z.infer<
  typeof CreateStoreCategoryRequestSchema
>;

/** POST /api/admin/store-categories — 201. */
export function createStoreCategory(
  body: CreateStoreCategoryRequest,
  options: RequestOptions = {},
): Promise<StoreCategoryResponse> {
  return apiFetch('/api/admin/store-categories', StoreCategoryResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

export const UpdateStoreCategoryRequestSchema = z.object({
  name_en: z.string().min(1).max(120).optional(),
  name_dv: z.string().max(120).nullable().optional(),
  sort: z.number().int().min(0).max(100000).optional(),
  active: z.boolean().optional(),
});
export type UpdateStoreCategoryRequest = z.infer<
  typeof UpdateStoreCategoryRequestSchema
>;

/**
 * PATCH /api/admin/store-categories/{id} — names, sort, activation (the
 * slug is immutable). Deactivating a category still carried by active
 * merchants answers 409 `category_in_use`.
 */
export function updateStoreCategory(
  id: number,
  body: UpdateStoreCategoryRequest,
  options: RequestOptions = {},
): Promise<StoreCategoryResponse> {
  return apiFetch(
    `/api/admin/store-categories/${id}`,
    StoreCategoryResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

/**
 * Replaces the category's icon artwork. Multipart, so it is its own call
 * rather than a field on the PATCH above. Raster only (jpg/png/webp), at
 * least 64x64 — the server rejects SVG, which would be a document served
 * from our own origin.
 */
export function uploadStoreCategoryIcon(
  id: number,
  file: File,
  options: RequestOptions = {},
): Promise<StoreCategoryResponse> {
  const body = new FormData();
  body.append('icon', file);

  return apiFetch(
    `/api/admin/store-categories/${id}/icon`,
    StoreCategoryResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

/** Removes the artwork; the category falls back to its curated glyph. */
export function deleteStoreCategoryIcon(
  id: number,
  options: RequestOptions = {},
): Promise<StoreCategoryResponse> {
  return apiFetch(
    `/api/admin/store-categories/${id}/icon`,
    StoreCategoryResponseSchema,
    { method: 'DELETE', signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Customer notification templates
// ---------------------------------------------------------------------------

/**
 * One thing the platform says to a customer. The KEYS are code — a template
 * nothing fires would be words nobody reads — so there is no create and no
 * delete here, only editing the sentence and the switch.
 */
export const NotificationTemplateSchema = z.object({
  id: z.number().int(),
  key: z.string(),
  label: z.string(),
  /** When it fires, in the words of someone deciding whether to enable it. */
  description: z.string(),
  /**
   * The one and only body: every notification sends English by decision
   * (2026-08-17). Dhivehi bodies are gone from the wire entirely.
   */
  body_en: z.string(),
  active: z.boolean(),
  /** Read from the code catalogue, so it lists what is really substituted. */
  variables: z.array(z.object({ token: z.string(), description: z.string() })),
  updated_at: z.string().nullable(),
  updated_by: z.string().nullable(),
});
export type NotificationTemplate = z.infer<typeof NotificationTemplateSchema>;

export const NotificationTemplateListResponseSchema = z.object({
  data: z.array(NotificationTemplateSchema),
});
export type NotificationTemplateListResponse = z.infer<
  typeof NotificationTemplateListResponseSchema
>;

export const NotificationTemplateResponseSchema = dataWrapped(
  NotificationTemplateSchema,
);
export type NotificationTemplateResponse = z.infer<
  typeof NotificationTemplateResponseSchema
>;

/** GET /api/admin/notification-templates — every moment, in key order. */
export function listNotificationTemplates(
  options: RequestOptions = {},
): Promise<NotificationTemplateListResponse> {
  return apiFetch(
    '/api/admin/notification-templates',
    NotificationTemplateListResponseSchema,
    { signal: options.signal },
  );
}

export interface UpdateNotificationTemplateInput {
  body_en?: string;
  active?: boolean;
}

/** PATCH /api/admin/notification-templates/{id} — superadmin only. */
export function updateNotificationTemplate(
  id: number,
  body: UpdateNotificationTemplateInput,
  options: RequestOptions = {},
): Promise<NotificationTemplateResponse> {
  return apiFetch(
    `/api/admin/notification-templates/${id}`,
    NotificationTemplateResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}
