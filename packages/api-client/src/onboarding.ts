import { z } from "zod";
import { apiFetch, apiFetchPublic } from "./client";
import { MerchantBranchSchema, MerchantRoleSummarySchema } from "./merchant";
import {
  CashbackPercentInputSchema,
  dataWrapped,
  MerchantChangeRequestSchema,
  MerchantChannelSchema,
  MerchantStatusSchema,
  PercentSchema,
} from "./resources";

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
 *    CRUD over the superadmin-curated store categories;
 *  - the PUBLIC signup-options read (owner, 2026-08-25), which is how the
 *    signup form learns the validation-window range it may offer instead of
 *    hard-coding one that goes stale the day an admin moves the ceiling;
 *  - the GUIDED-SETUP tasklist (owner, 2026-08-25) — the sidebar checklist
 *    and tour prompt a new merchant user sees for their first five days.
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
// Signup options (PUBLIC) — what the form must know before it is submitted
// ---------------------------------------------------------------------------

/**
 * The floor of the validation-window range, mirroring
 * `App\Rules\ValidationWindowDays::MIN_DAYS`. Zero means "validate
 * immediately" and a merchant may always tighten to it.
 *
 * THE CEILING IS DELIBERATELY NOT A CONSTANT HERE. It is admin policy
 * (the platform's `default_validation_window_days`), read at request time
 * by the one server rule that enforces it, and published as
 * `validation_window.max_days` on the signup-options endpoint below. A form
 * that hard-coded 3 would keep offering 3 on the afternoon an admin lowered
 * the platform to 1, and every merchant who took it would be refused at
 * submit by a rule they were never shown.
 */
export const VALIDATION_WINDOW_MIN_DAYS = 0;

/**
 * The validation-window field, described by the server that validates it:
 * the live range, the default to preselect, and the merchant-facing copy in
 * both languages — including the exact refusal (`invalid_en` / `invalid_dv`)
 * so a form can say the same sentence the server would, before anyone waits
 * for a round trip. Every number inside the prose is interpolated from these
 * same bounds, so an admin moving the ceiling moves the help text too.
 */
export const ValidationWindowOptionSchema = z.object({
  /** Always `VALIDATION_WINDOW_MIN_DAYS`; served so the copy can name it. */
  min_days: z.number().int(),
  /** The live admin ceiling. Render THIS as the field's bound. */
  max_days: z.number().int(),
  /** What a store that says nothing at signup is created with. */
  default_days: z.number().int(),
  label_en: z.string(),
  label_dv: z.string(),
  help_en: z.string(),
  help_dv: z.string(),
  invalid_en: z.string(),
  invalid_dv: z.string(),
});
export type ValidationWindowOption = z.infer<
  typeof ValidationWindowOptionSchema
>;

export const MerchantSignupOptionsSchema = z.object({
  validation_window: ValidationWindowOptionSchema,
});
export type MerchantSignupOptions = z.infer<typeof MerchantSignupOptionsSchema>;

export const MerchantSignupOptionsResponseSchema = dataWrapped(
  MerchantSignupOptionsSchema,
);
export type MerchantSignupOptionsResponse = z.infer<
  typeof MerchantSignupOptionsResponseSchema
>;

/**
 * GET /api/merchant/signup/options — public and unauthenticated, like the
 * signup steps themselves, and it discloses nothing about any store: only
 * what the platform currently allows.
 *
 * Fetched WITHOUT the session (`apiFetchPublic`) for the same reason as the
 * public fee-promotion banner: the answer must not depend on who is asking,
 * and the visitor filling in this form has no session yet.
 */
export function getMerchantSignupOptions(
  options: RequestOptions = {},
): Promise<MerchantSignupOptionsResponse> {
  return apiFetchPublic(
    "/api/merchant/signup/options",
    MerchantSignupOptionsResponseSchema,
    { signal: options.signal },
  );
}

/**
 * The client mirror of `App\Rules\ValidationWindowDays` — whole days inside
 * the published range — so a field can go red on exactly the text the
 * server would refuse, and only on that text.
 *
 * Accepts the numeric STRING a text input hands back ("3") exactly as the
 * server's rule does, and refuses `2.5`, `"2.5"`, `""` and `" 3"` exactly as
 * it does — the untrimmed string is the point: this answers "would the
 * server take this?", not "could this be tidied into something it would
 * take". Trim before asking if trimming is what the form intends to submit.
 */
export function isValidValidationWindowDays(
  value: unknown,
  bounds: Pick<ValidationWindowOption, "min_days" | "max_days">,
): boolean {
  const days =
    typeof value === "number" && Number.isInteger(value)
      ? value
      : typeof value === "string" && /^-?\d+$/.test(value)
        ? Number(value)
        : null;

  return days !== null && days >= bounds.min_days && days <= bounds.max_days;
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
    "/api/merchant/signup/request-otp",
    MerchantSignupRequestOtpResponseSchema,
    { method: "POST", body, signal: options.signal },
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
    "/api/merchant/signup/verify-otp",
    MerchantSignupVerifyOtpResponseSchema,
    { method: "POST", body, signal: options.signal },
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
  /**
   * How many days a sale stays open for returns before its cashback is
   * confirmed (owner, 2026-08-25). OPTIONAL: omit it — or send null — and
   * the store is created with the platform default, byte-identical to how
   * signup behaved before this field existed.
   *
   * The real bound is `validation_window.max_days` from
   * `getMerchantSignupOptions()`, read at request time from admin policy.
   * The 30 here is only the absolute platform range, the same guard the
   * preferences PATCH carries — pre-validate with
   * `isValidValidationWindowDays()` against the live bounds instead of
   * trusting this ceiling.
   *
   * A refusal is a 422 on `validation_window_days`, whose message names the
   * whole allowed range.
   */
  validation_window_days: z
    .number()
    .int()
    .min(VALIDATION_WINDOW_MIN_DAYS)
    .max(30)
    .nullish(),
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

export const MerchantAuthUserResponseSchema = dataWrapped(
  MerchantAuthUserSchema,
);
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
    "/api/merchant/signup/register",
    MerchantAuthUserResponseSchema,
    { method: "POST", body, signal: options.signal },
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
    /**
     * The store's own words about itself, shown to shoppers on its page.
     * Required (non-empty) before submit, capped at
     * STORE_DESCRIPTION_MAX_WORDS words rather than characters.
     */
    description: z.string().nullable(),
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
 *    `missing` (`category` | `channel` | `contact` | `rate` | `terms` |
 *    `description`);
 *  - `not_pending_review` (409) — admin approve/reject on a store that is
 *    not awaiting review;
 *  - `rate_not_priced` (422) — the rate step above the active schedule
 *    ceiling.
 */
export const OnboardingErrorCodeSchema = z.enum([
  "setup_not_editable",
  "setup_incomplete",
  "not_pending_review",
  "rate_not_priced",
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
  return apiFetch("/api/merchant/setup", MerchantSetupStateResponseSchema, {
    signal: options.signal,
  });
}

export const UpdateMerchantSetupProfileRequestSchema = z.object({
  /** An ACTIVE curated store-category slug (422 otherwise), or null. */
  category: z.string().max(80).nullable().optional(),
  channel: MerchantChannelSchema.optional(),
  /** The terms & exclusions text; required (non-empty) before submit. */
  eligibility_basis: z.string().max(2000).nullable().optional(),
  /**
   * The store's description; required (non-empty) before submit. No
   * character cap here on purpose — the API's ceiling is
   * STORE_DESCRIPTION_MAX_WORDS *words* (App\Rules\MaxWords), so a length in
   * characters would refuse text the server accepts.
   */
  description: z.string().nullable().optional(),
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
    "/api/merchant/setup/profile",
    MerchantSetupStateResponseSchema,
    { method: "PATCH", body, signal: options.signal },
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
    "/api/merchant/setup/location",
    MerchantSetupStateResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

/**
 * Both logo endpoints answer this. For a store still onboarding it is the
 * plain 200: the file IS the logo now. For a LIVE store (MR9) it is a 202 —
 * the file is staged, the change is queued, and `logo_url` is the logo STILL
 * being served, which is null when the store never had one. Read
 * `change_request` to tell the two apart.
 */
export const MerchantLogoResponseSchema = dataWrapped(
  z.object({
    /** Absolute URL of the logo on display — cache-bust when re-rendering. */
    logo_url: z.string().nullable(),
    status: z.literal("pending_review").optional(),
    /** Present exactly when the upload queued rather than applied. */
    change_request: MerchantChangeRequestSchema.optional(),
  }),
);
export type MerchantLogoResponse = z.infer<typeof MerchantLogoResponseSchema>;

function uploadLogo(
  path: string,
  file: File | Blob,
  options: RequestOptions,
): Promise<MerchantLogoResponse> {
  const form = new FormData();
  form.append("logo", file);
  return apiFetch(path, MerchantLogoResponseSchema, {
    method: "POST",
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
  return uploadLogo("/api/merchant/setup/logo", file, options);
}

/**
 * POST /api/merchant/settings/logo — the identical action mounted under the
 * settings module for ACTIVE merchants; same validation and response.
 */
export function uploadMerchantSettingsLogo(
  file: File | Blob,
  options: RequestOptions = {},
): Promise<MerchantLogoResponse> {
  return uploadLogo("/api/merchant/settings/logo", file, options);
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
  return apiFetch(
    "/api/merchant/setup/rate",
    MerchantSetupStateResponseSchema,
    {
      method: "PATCH",
      body,
      signal: options.signal,
    },
  );
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
    "/api/merchant/setup/submit",
    MerchantSetupStateResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Guided setup — the sidebar tasklist and the tour prompt (owner 2026-08-25)
// ---------------------------------------------------------------------------

/**
 * The tasklist a new merchant sees in the sidebar for their first five days.
 *
 * THREE RULES, ALL SERVER-SIDE, and a client that fights any of them is
 * wrong:
 *
 *  1. PER PERSON, not per store. The five days are anchored on the
 *     signed-in account's own first read of this endpoint, so a cashier
 *     added in three months gets their own five days rather than inheriting
 *     an owner's expired ones. There is no id in any of these routes: the
 *     only account any of them can reach is the authenticated one.
 *  2. FIVE DAYS IS A HARD STOP. `show` goes false five whole days after
 *     that anchor whether or not anything was completed, and skipping is
 *     permanent and immediate. Never persist a local "dismissed" flag
 *     beside this — `show` is the whole answer, and it is shared across
 *     surfaces (skip on the phone, gone on the website).
 *  3. EVERY TASK IS DERIVED FROM REAL STATE. `done` on "credit your first
 *     customer" means a transaction exists. Nothing here is tickable, and
 *     a client that offered a tick would be offering a lie.
 *
 * The GET is cheap enough to hang off every page load by design: one query
 * while the guide is live, none once it is skipped or over.
 */

/**
 * The task keys this build knows how to talk about.
 *
 * `key` is parsed as a plain string rather than an enum ON PURPOSE: the API
 * can ship a sixth task before a cached panel bundle has heard of it, and a
 * strict enum would fail the whole payload's parse and blank the sidebar
 * over one unknown row. Route on `web_path`, which the server supplies for
 * exactly this reason, and use `isKnownMerchantOnboardingTaskKey` only when
 * you want to special-case a task you recognise.
 */
export const MERCHANT_ONBOARDING_TASK_KEYS = [
  "finish_setup",
  "bank_account",
  "credit_customer",
  "settle_bill",
  "add_staff",
] as const;
export type MerchantOnboardingTaskKey =
  (typeof MERCHANT_ONBOARDING_TASK_KEYS)[number];

export function isKnownMerchantOnboardingTaskKey(
  key: string,
): key is MerchantOnboardingTaskKey {
  return (MERCHANT_ONBOARDING_TASK_KEYS as readonly string[]).includes(key);
}

/**
 * One row of the tasklist. `help_en` / `help_dv` are the instructional prose
 * — full sentences telling a merchant how to credit a customer and how to
 * settle a bill — written to be reused verbatim in a highlight bubble.
 */
export const MerchantOnboardingTaskSchema = z.object({
  /** See MERCHANT_ONBOARDING_TASK_KEYS: a string, tolerantly. */
  key: z.string(),
  label_en: z.string(),
  label_dv: z.string(),
  help_en: z.string(),
  help_dv: z.string(),
  /** Derived from real data every read; never a stored checkbox. */
  done: z.boolean(),
  /**
   * The permission slug that makes this task THIS person's to do. The API
   * publishes it rather than filtering on it, because the client already
   * holds the resolved set from `/merchant/auth/me`. A cashier must not be
   * shown "add your bank account" — filter with
   * `merchantOnboardingChecklist()` rather than rendering `tasks` raw.
   */
  permission: z.string(),
  /** The mobile app's screen hint; the panel routes on `web_path`. */
  target: z.string(),
  /** Where the panel sends someone who taps the row. */
  web_path: z.string(),
});
export type MerchantOnboardingTask = z.infer<
  typeof MerchantOnboardingTaskSchema
>;

export const MerchantOnboardingGuideSchema = z.object({
  /** The only thing a sidebar consults: false means draw nothing at all. */
  show: z.boolean(),
  skipped: z.boolean(),
  expired: z.boolean(),
  /** The walkthrough was finished. NOT the same as skipping the tasklist. */
  tour_completed: z.boolean(),
  /** This person's own anchor, stamped on their first read. */
  started_at: z.string(),
  expires_at: z.string(),
  /** Whole days left, rounded UP: 5 on arrival, 1 through the last 24h. */
  days_remaining: z.number().int(),
  /** The hard rule, served rather than assumed. 5 today. */
  window_days: z.number().int(),
  title_en: z.string(),
  title_dv: z.string(),
  /** Empty whenever `show` is false — nothing is computed then. */
  tasks: z.array(MerchantOnboardingTaskSchema),
  tasks_done: z.number().int(),
  tasks_total: z.number().int(),
  all_done: z.boolean(),
});
export type MerchantOnboardingGuide = z.infer<
  typeof MerchantOnboardingGuideSchema
>;

export const MerchantOnboardingGuideResponseSchema = dataWrapped(
  MerchantOnboardingGuideSchema,
);
export type MerchantOnboardingGuideResponse = z.infer<
  typeof MerchantOnboardingGuideResponseSchema
>;

/**
 * GET /api/merchant/onboarding — the signed-in person's own guided-setup
 * state. No permission gate: gating it would hide the tasklist from exactly
 * the staff who most need telling how the till works.
 */
export function getMerchantOnboarding(
  options: RequestOptions = {},
): Promise<MerchantOnboardingGuideResponse> {
  return apiFetch(
    "/api/merchant/onboarding",
    MerchantOnboardingGuideResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/merchant/onboarding/skip — permanent and immediate. Nothing
 * un-skips it, and a second call is the same as the first.
 *
 * Answers the FULL state, so never follow this with `getMerchantOnboarding`.
 */
export function skipMerchantOnboarding(
  options: RequestOptions = {},
): Promise<MerchantOnboardingGuideResponse> {
  return apiFetch(
    "/api/merchant/onboarding/skip",
    MerchantOnboardingGuideResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

/**
 * POST /api/merchant/onboarding/tour — the walkthrough was finished, so
 * stop offering it. Deliberately NOT a skip: the tasklist stays until it is
 * skipped or the five days run out, because watching the tour is not the
 * same as having credited anybody.
 *
 * Answers the FULL state, like skip.
 */
export function completeMerchantOnboardingTour(
  options: RequestOptions = {},
): Promise<MerchantOnboardingGuideResponse> {
  return apiFetch(
    "/api/merchant/onboarding/tour",
    MerchantOnboardingGuideResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

/** The tasklist as ONE person may actually see it. */
export interface MerchantOnboardingChecklist {
  /** `show` AND something left to draw after the permission filter. */
  show: boolean;
  tasks: MerchantOnboardingTask[];
  done: number;
  total: number;
  allDone: boolean;
}

/**
 * The tasklist narrowed to what this account may actually do, with counts
 * over THAT list.
 *
 * Two things this fixes, and both are visible to a merchant:
 *
 *  - a cashier must not be told to add the shop's bank account, so tasks
 *    whose `permission` they do not hold are dropped;
 *  - the counts must then describe the rows on screen. Rendering four rows
 *    under "2 of 5 done" is a bug a reader can see, so `done` / `total`
 *    here are recomputed over the filtered list rather than taken from the
 *    server's `tasks_done` / `tasks_total`, which describe the whole store's
 *    list. Use those two only if you want the unfiltered picture.
 *
 * `show` goes false when the filter empties the list: a person with nothing
 * to do is shown nothing, not an empty box.
 *
 * @param permissions the resolved slugs from `/api/merchant/auth/me`.
 */
export function merchantOnboardingChecklist(
  guide: MerchantOnboardingGuide,
  permissions: Iterable<string>,
): MerchantOnboardingChecklist {
  const held = new Set(permissions);
  const tasks = guide.show
    ? guide.tasks.filter((task) => held.has(task.permission))
    : [];
  const done = tasks.filter((task) => task.done).length;

  return {
    show: tasks.length > 0,
    tasks,
    done,
    total: tasks.length,
    allDone: tasks.length > 0 && done === tasks.length,
  };
}

// ---------------------------------------------------------------------------
// Admin — store approval queue
// ---------------------------------------------------------------------------

export const StoreReviewStateSchema = z.enum([
  "pending_review",
  "rejected",
  "draft",
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
  /** The store's own words — the public claim the reviewer is approving. */
  description: z.string().nullable(),
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
      : "";
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
    { method: "POST", signal: options.signal },
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
    { method: "POST", body, signal: options.signal },
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
  ratio: "16:9",
  minWidth: 800,
  minHeight: 450,
  maxKb: 2048,
} as const;

/**
 * Which banner the storefront draws. An offer with artwork is the artwork,
 * edge to edge; one without is laid out from its words and the store's live
 * rate. Never a blend of the two.
 */
export const OfferKindSchema = z.enum(["text", "image"]);
export type OfferKind = z.infer<typeof OfferKindSchema>;

/**
 * Why an offer is or is not on the storefront right now. Computed by the
 * API, never stored: it answers the question an admin has when a banner
 * they just saved is nowhere to be seen.
 */
export const OfferLiveStateSchema = z.enum([
  "live",
  "inactive",
  "scheduled",
  "ended",
  "store_not_trading",
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
  return apiFetch("/api/admin/store-offers", StoreOfferListResponseSchema, {
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
  return apiFetch("/api/admin/store-offers", StoreOfferResponseSchema, {
    method: "POST",
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
    method: "PATCH",
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
  body.append("image", file);

  return apiFetch(
    `/api/admin/store-offers/${id}/image`,
    StoreOfferResponseSchema,
    { method: "POST", body, signal: options.signal },
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
    { method: "DELETE", signal: options.signal },
  );
}

export const StoreCategoryResponseSchema = dataWrapped(StoreCategorySchema);
export type StoreCategoryResponse = z.infer<typeof StoreCategoryResponseSchema>;

/** GET /api/admin/store-categories — ALL rows (inactive included), sort order. */
export function listStoreCategories(
  options: RequestOptions = {},
): Promise<StoreCategoryListResponse> {
  return apiFetch(
    "/api/admin/store-categories",
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
  return apiFetch("/api/admin/store-categories", StoreCategoryResponseSchema, {
    method: "POST",
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
    { method: "PATCH", body, signal: options.signal },
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
  body.append("icon", file);

  return apiFetch(
    `/api/admin/store-categories/${id}/icon`,
    StoreCategoryResponseSchema,
    { method: "POST", body, signal: options.signal },
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
    { method: "DELETE", signal: options.signal },
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
   * Only reachable with the marketplace switched on — the order moments and
   * the enrolment outcomes. The panel hides these while it is off, because
   * nothing can send them and a list of dead moments misleads.
   */
  marketplace_only: z.boolean(),
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
    "/api/admin/notification-templates",
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
    { method: "PATCH", body, signal: options.signal },
  );
}
