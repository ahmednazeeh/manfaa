'use client';

import {
  ApiError,
  bootstrapCsrf,
  bpToPercentString,
  cancelMerchantPromotion,
  createMerchantBranch,
  createMerchantCredential,
  createMerchantCredit,
  createMerchantProductCategory,
  createMerchantPromotion,
  createMerchantStaff,
  deleteMerchantBranch,
  getMerchantOutstanding,
  getMerchantProfile,
  getMerchantSetup,
  getMerchantWallet,
  listMerchantBranches,
  listMerchantCredentials,
  listMerchantProductCategories,
  listMerchantPromotions,
  listMerchantStaff,
  lookupMerchantCustomer,
  publishMerchantPromotion,
  registerMerchantSignup,
  requestMerchantSignupOtp,
  revokeMerchantCredential,
  submitMerchantSetup,
  updateMerchantBankAccount,
  updateMerchantBranch,
  updateMerchantPreferences,
  updateMerchantProductCategory,
  updateMerchantProfile,
  updateMerchantSetupProfile,
  updateMerchantSetupRate,
  updateMerchantStaff,
  uploadMerchantSettingsLogo,
  uploadMerchantSetupLogo,
  verifyMerchantSignupOtp,
  type CreateCreditRequest,
  type CreateMerchantBranchRequest,
  type CreateMerchantCredentialRequest,
  type CreateMerchantStaffRequest,
  type CreateProductCategoryRequest,
  type CreatePromotionRequest,
  type MerchantSetupStateResponse,
  type MerchantSignupRegisterRequest,
  type MerchantSignupVerifyOtpRequest,
  type TransactionState,
  type UpdateMerchantBankAccountRequest,
  type UpdateMerchantBranchRequest,
  type UpdateMerchantPreferencesRequest,
  type UpdateMerchantProfileRequest,
  type UpdateMerchantSetupProfileRequest,
  type UpdateMerchantStaffRequest,
  type UpdateProductCategoryRequest,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  addSettlementReceipt,
  changeRate,
  fetchMe,
  fetchRate,
  getSettlement,
  listSettlements,
  listTransactions,
  login,
  logout,
  previewSettlement,
  submitSettlementWithReceipt,
  walletSettleSelection,
  type ReceiptSubmission,
  type SettlementSelection,
} from '@/lib/api';

/**
 * react-query bindings for the merchant panel. Every response is zod-parsed
 * by the api-client before it reaches a component.
 */

export const queryKeys = {
  me: ['merchant', 'me'] as const,
  outstanding: ['merchant', 'outstanding'] as const,
  wallet: ['merchant', 'wallet'] as const,
  rate: ['merchant', 'rate'] as const,
  customerLookup: (code: string) =>
    ['merchant', 'customer-lookup', code] as const,
  settlements: (page: number) => ['merchant', 'settlements', page] as const,
  settlement: (id: number) => ['merchant', 'settlement', id] as const,
  /**
   * Keyed by the SELECTION, so switching between "settle all" and a picked
   * set re-previews instead of showing the previous batch's amount.
   */
  settlementPreview: (selection: SettlementSelection) =>
    [
      'merchant',
      'settlement-preview',
      'settleAll' in selection
        ? 'all'
        : selection.transactionIds
            .slice()
            .sort((a, b) => a - b)
            .join(','),
    ] as const,
  transactions: (state: TransactionState | 'all', page: number) =>
    ['merchant', 'transactions', state, page] as const,
  profile: ['merchant', 'profile'] as const,
  branches: ['merchant', 'branches'] as const,
  staff: ['merchant', 'staff'] as const,
  credentials: ['merchant', 'credentials'] as const,
  preferences: ['merchant', 'preferences'] as const,
  promotions: ['merchant', 'promotions'] as const,
  productCategories: ['merchant', 'product-categories'] as const,
  setup: ['merchant', 'setup'] as const,
};

export function isUnauthorized(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

/** 403 from an owner-gated settings route (code `owner_required`). */
export function isOwnerRequired(error: unknown): boolean {
  return error instanceof ApiError && error.status === 403;
}

export function useMe() {
  return useQuery({
    queryKey: queryKeys.me,
    queryFn: ({ signal }) => fetchMe(signal),
    // A rebuild or a restart makes this request fail for a second or two.
    // Only a real 401 means the session is gone; anything else is the
    // server being briefly unreachable, so retry rather than tell the
    // user they are signed out.
    retry: (failureCount, error) => !isUnauthorized(error) && failureCount < 3,
    retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 4000),
    staleTime: 5 * 60 * 1000,
  });
}

export function useLogin() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: login,
    onSuccess: (me) => {
      queryClient.setQueryData(queryKeys.me, me);
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: logout,
    onSettled: () => {
      queryClient.clear();
    },
  });
}

export function useOutstanding() {
  return useQuery({
    queryKey: queryKeys.outstanding,
    queryFn: ({ signal }) => getMerchantOutstanding({ signal }),
    select: (response) => response.data,
  });
}

export function useWallet() {
  return useQuery({
    queryKey: queryKeys.wallet,
    queryFn: ({ signal }) => getMerchantWallet({ signal }),
    select: (response) => response.data,
  });
}

export function useSettlements(page: number) {
  return useQuery({
    queryKey: queryKeys.settlements(page),
    queryFn: ({ signal }) => listSettlements(page, signal),
  });
}

export function useSettlement(id: number) {
  return useQuery({
    queryKey: queryKeys.settlement(id),
    queryFn: ({ signal }) => getSettlement(id, signal),
  });
}

export function useTransactions(state: TransactionState | 'all', page: number) {
  return useQuery({
    queryKey: queryKeys.transactions(state, page),
    queryFn: ({ signal }) =>
      listTransactions(
        { state: state === 'all' ? undefined : state, page },
        signal,
      ),
  });
}

function useInvalidateSettlementData() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({
      queryKey: ['merchant', 'settlements'],
    });
    void queryClient.invalidateQueries({
      queryKey: ['merchant', 'settlement'],
    });
    void queryClient.invalidateQueries({ queryKey: queryKeys.outstanding });
    void queryClient.invalidateQueries({
      queryKey: ['merchant', 'transactions'],
    });
    void queryClient.invalidateQueries({ queryKey: queryKeys.wallet });
  };
}

/**
 * The receipt-first wizard's step 2 (PLAN §1): what the selection costs and
 * where to send it. Reservation-free — nothing is claimed by asking, so it
 * refetches freely; `enabled` keeps it off until a selection exists.
 *
 * Never retried: the two failure modes are 422s (a transaction stopped being
 * eligible, or there is nothing to settle), and retrying re-renders the same
 * refusal while the merchant waits.
 */
export function useSettlementPreview(
  selection: SettlementSelection | null,
  enabled = true,
) {
  // Never null inside the query function: the empty stand-in only exists so
  // the key is stable while the query is disabled.
  const active: SettlementSelection = selection ?? { transactionIds: [] };
  return useQuery({
    queryKey: queryKeys.settlementPreview(active),
    queryFn: ({ signal }) => previewSettlement(active, signal),
    enabled: enabled && selection !== null,
    retry: false,
    // A preview is a quote on live rows: never serve a stale one.
    staleTime: 0,
    gcTime: 0,
  });
}

/**
 * The single act that creates a settlement (PLAN §1): selection + amount +
 * bank reference + slip, multipart, landing directly in payment_review.
 */
export function useSubmitSettlementReceipt() {
  const invalidate = useInvalidateSettlementData();
  return useMutation({
    mutationFn: ({
      selection,
      receipt,
    }: {
      selection: SettlementSelection;
      receipt: ReceiptSubmission;
    }) => submitSettlementWithReceipt(selection, receipt),
    onSuccess: invalidate,
  });
}

/** A further transfer against a batch that is still owed money (§7). */
export function useAddSettlementReceipt(id: number) {
  const invalidate = useInvalidateSettlementData();
  return useMutation({
    mutationFn: (receipt: ReceiptSubmission) =>
      addSettlementReceipt(id, receipt),
    onSuccess: invalidate,
  });
}

/**
 * §7's wallet funding: build and settle from the balance in one call. Also
 * the route for a batch fully netted by credit adjustments — it settles
 * without drawing any balance at all.
 */
export function useWalletSettleSelection() {
  const invalidate = useInvalidateSettlementData();
  return useMutation({
    mutationFn: (selection: SettlementSelection) =>
      walletSettleSelection(selection),
    onSuccess: invalidate,
  });
}

/**
 * A settlement 422 that carries neither a machine code nor field errors:
 * the domain's "these transactions are not eligible" / "nothing is due"
 * refusals, whose server prose names internal states (payable_unfunded,
 * non-cancelled settlement). The panel answers in the merchant's own terms
 * instead of forwarding that sentence.
 */
export function isSelectionRefusal(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false;
  const body = error.body as { code?: unknown; errors?: unknown } | undefined;
  return body?.code === undefined && body?.errors === undefined;
}

/** The `code` on an ApiError body, if any (slip_too_large, …). */
export function apiErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const body = error.body as { code?: unknown } | undefined;
  return typeof body?.code === 'string' ? body.code : null;
}

// ---------------------------------------------------------------------------
// Credit screen: standing rate, customer lookup, manual credit
// ---------------------------------------------------------------------------

/** The merchant's standing rate — feeds the credit screen's live estimate. */
export function useRate() {
  return useQuery({
    queryKey: queryKeys.rate,
    queryFn: ({ signal }) => fetchRate(signal),
    staleTime: 60 * 1000,
  });
}

/**
 * Masked-name confirmation for a complete 6-digit code (§11 phone-recycling
 * control). Never retried — the endpoint shares the manual-credit throttle
 * (30/min per user) and a cashier retyping the code re-queries anyway.
 */
export function useCustomerLookup(code: string) {
  return useQuery({
    queryKey: queryKeys.customerLookup(code),
    queryFn: ({ signal }) => lookupMerchantCustomer(code, { signal }),
    enabled: /^\d{6}$/.test(code),
    retry: false,
    staleTime: 60 * 1000,
  });
}

export function useCreateCredit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateCreditRequest) => createMerchantCredit(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['merchant', 'transactions'],
      });
      void queryClient.invalidateQueries({ queryKey: queryKeys.outstanding });
    },
  });
}

// ---------------------------------------------------------------------------
// Rate change + promotions (owner-only mutations; staff may read)
// ---------------------------------------------------------------------------

/**
 * POST /api/merchant/rate — §7: increases apply immediately, decreases at
 * the next business midnight, and a new change replaces any pending one.
 * The response's `change` block carries the authoritative tier-cliff data.
 */
export function useChangeRate() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (rateBp: number) => changeRate(rateBp),
    onSuccess: (response) => {
      queryClient.setQueryData(queryKeys.rate, response.data);
    },
  });
}

export function usePromotions() {
  return useQuery({
    queryKey: queryKeys.promotions,
    queryFn: ({ signal }) => listMerchantPromotions({}, { signal }),
    select: (response) => response.data,
  });
}

export function useCreatePromotion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreatePromotionRequest) => createMerchantPromotion(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.promotions });
    },
  });
}

export function usePublishPromotion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => publishMerchantPromotion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.promotions });
    },
  });
}

export function useCancelPromotion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => cancelMerchantPromotion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.promotions });
    },
  });
}

// ---------------------------------------------------------------------------
// Product categories (Task #25) — owner mutates, staff may read
// ---------------------------------------------------------------------------

/**
 * GET is STAFF-readable — it feeds the credit screen's split-by-category
 * editor. Inactive rows are included (the settings screen manages them);
 * the credit form filters on `active`.
 */
export function useProductCategories() {
  return useQuery({
    queryKey: queryKeys.productCategories,
    queryFn: ({ signal }) => listMerchantProductCategories({ signal }),
    select: (response) => response.data,
  });
}

export function useCreateProductCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateProductCategoryRequest) =>
      createMerchantProductCategory(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.productCategories,
      });
    },
  });
}

/**
 * PATCH — owner only. Deactivation (`active: false`) is the only removal;
 * historical transaction lines snapshot the category, so there is no DELETE.
 */
export function useUpdateProductCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      body,
    }: {
      id: number;
      body: UpdateProductCategoryRequest;
    }) => updateMerchantProductCategory(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.productCategories,
      });
    },
  });
}

/**
 * 422 `code: rate_not_priced`: the rate is structurally legal (up to 20%)
 * but the ACTIVE fee tier schedule does not price a platform fee for it, so
 * it cannot be sold yet. Intended behaviour, not a fault — it becomes
 * available once the platform publishes a wider fee schedule.
 */
export function isRateNotPriced(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false;
  const body = error.body as { code?: unknown } | undefined;
  return body?.code === 'rate_not_priced';
}

/**
 * Honest copy for a rate_not_priced refusal. The ceiling percent is lifted
 * from the server message ("The current fee schedule prices rates up to
 * 10.00%."); if the wording ever changes, the server message is shown as-is.
 */
export function rateNotPricedMessage(error: unknown): string {
  const serverMessage = apiErrorMessage(
    error,
    'This rate is not available yet.',
  );
  const match = /up to (\d+(?:\.\d{1,2})?)%/.exec(serverMessage);
  if (match === null) return serverMessage;
  return `Rates above ${match[1]}% are not available yet — the platform has not priced its fee for them. They become available once a wider fee schedule is published.`;
}

/**
 * 422 `code: rate_below_advertised` (PLAN §1 "Per-sale rate override"): a
 * per-sale `cashback_rate_percent` was BELOW the rate the sale already
 * earns — the standing rate, or a live promotion covering it. The
 * advertised rate is a public promise, so a one-off override may only raise
 * it. The body carries the rate it was measured against.
 */
export function isRateBelowAdvertised(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false;
  const body = error.body as { code?: unknown } | undefined;
  return body?.code === 'rate_below_advertised';
}

/**
 * The rate a refused override was measured against ("2.00"), or null when
 * the server did not name one — the caller then falls back to its own copy
 * rather than inventing a figure.
 */
export function advertisedRatePercent(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const body = error.body as
    | { advertised_cashback_rate_percent?: unknown }
    | undefined;
  const advertised = body?.advertised_cashback_rate_percent;
  return typeof advertised === 'string' ? advertised : null;
}

// ---------------------------------------------------------------------------
// Settings (owner only — the API answers 403 owner_required for staff)
// ---------------------------------------------------------------------------

export function useProfile() {
  return useQuery({
    queryKey: queryKeys.profile,
    queryFn: ({ signal }) => getMerchantProfile({ signal }),
    select: (response) => response.data,
    retry: false,
  });
}

export function useUpdateProfile() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateMerchantProfileRequest) =>
      updateMerchantProfile(body),
    onSuccess: (response) => {
      queryClient.setQueryData(queryKeys.profile, response);
    },
  });
}

/**
 * There is no GET for the bank identity — it is write-only from the panel
 * (deliberately: nothing to shoulder-surf). The PATCH response carries the
 * saved triple for the confirmation card.
 */
export function useUpdateBankAccount() {
  return useMutation({
    mutationFn: (body: UpdateMerchantBankAccountRequest) =>
      updateMerchantBankAccount(body),
  });
}

/** Owner-only route — pass enabled: false for staff viewers. */
export function useBranches(enabled = true) {
  return useQuery({
    queryKey: queryKeys.branches,
    queryFn: ({ signal }) => listMerchantBranches({ signal }),
    select: (response) => response.data,
    retry: false,
    enabled,
  });
}

export function useCreateBranch() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateMerchantBranchRequest) =>
      createMerchantBranch(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.branches });
    },
  });
}

export function useUpdateBranch() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      body,
    }: {
      id: number;
      body: UpdateMerchantBranchRequest;
    }) => updateMerchantBranch(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.branches });
    },
  });
}

export function useDeleteBranch() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteMerchantBranch(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.branches });
    },
  });
}

export function useStaff() {
  return useQuery({
    queryKey: queryKeys.staff,
    queryFn: ({ signal }) => listMerchantStaff({ signal }),
    select: (response) => response.data,
    retry: false,
  });
}

export function useCreateStaff() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateMerchantStaffRequest) => createMerchantStaff(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.staff });
    },
  });
}

export function useUpdateStaff() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      body,
    }: {
      id: number;
      body: UpdateMerchantStaffRequest;
    }) => updateMerchantStaff(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.staff });
    },
  });
}

// ---------------------------------------------------------------------------
// Settings — API access (owner only)
// ---------------------------------------------------------------------------

export function useCredentials() {
  return useQuery({
    queryKey: queryKeys.credentials,
    queryFn: ({ signal }) => listMerchantCredentials({ signal }),
    select: (response) => response.data,
    retry: false,
  });
}

/**
 * The response carries the plaintext token EXACTLY once — it is handed to
 * the caller and deliberately never written into the query cache, so no
 * later render, refetch or devtools panel can resurrect it.
 */
export function useCreateCredential() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateMerchantCredentialRequest) =>
      createMerchantCredential(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.credentials });
    },
  });
}

export function useRevokeCredential() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => revokeMerchantCredential(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.credentials });
    },
  });
}

/**
 * The API exposes no GET for preferences; an empty PATCH validates cleanly
 * ('sometimes' rules), changes nothing (`fill({})` leaves the model clean,
 * so save() issues no UPDATE) and returns the current values — used here as
 * the read model. Owner-only, like every preferences write.
 */
export function usePreferences() {
  return useQuery({
    queryKey: queryKeys.preferences,
    queryFn: () => updateMerchantPreferences({}),
    select: (response) => response.data,
    retry: false,
    staleTime: 60 * 1000,
  });
}

export function useUpdatePreferences() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateMerchantPreferencesRequest) =>
      updateMerchantPreferences(body),
    onSuccess: (response) => {
      queryClient.setQueryData(queryKeys.preferences, response);
    },
  });
}

/** Human-readable message out of an ApiError's JSON body. */
export function apiErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const body = error.body as
      { message?: unknown; errors?: Record<string, unknown> } | undefined;
    if (body && typeof body.message === 'string' && body.message.length > 0) {
      return body.message;
    }
  }
  return fallback;
}

/**
 * The API's 422 validation messages are stable error KEYS (e.g.
 * `otp_invalid`, `email_already_registered`) — collect them so the UI can
 * translate rather than echo raw identifiers.
 */
export function validationErrorKeys(error: unknown): string[] {
  if (!(error instanceof ApiError) || error.status !== 422) {
    return [];
  }
  const body = error.body as { errors?: Record<string, unknown> } | undefined;
  if (!body?.errors) {
    return [];
  }
  const keys: string[] = [];
  for (const messages of Object.values(body.errors)) {
    if (Array.isArray(messages)) {
      for (const message of messages) {
        if (typeof message === 'string') {
          keys.push(message);
        }
      }
    } else if (typeof messages === 'string') {
      keys.push(messages);
    }
  }
  return keys;
}

// ---------------------------------------------------------------------------
// Store self-signup (§1 decision 2026-08-15) — public OTP flow
// ---------------------------------------------------------------------------

export function useSignupRequestOtp() {
  return useMutation({
    mutationFn: async (phone: string) => {
      // First call of the session — make sure the CSRF cookie exists.
      await bootstrapCsrf();
      return requestMerchantSignupOtp({ phone });
    },
  });
}

export function useSignupVerifyOtp() {
  return useMutation({
    mutationFn: (body: MerchantSignupVerifyOtpRequest) =>
      verifyMerchantSignupOtp(body),
  });
}

/**
 * Register creates the DRAFT merchant + owner and logs the session in, so
 * success primes the me cache and the router can land on /setup directly.
 */
export function useSignupRegister() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: MerchantSignupRegisterRequest) =>
      registerMerchantSignup(body),
    onSuccess: (response) => {
      queryClient.setQueryData(queryKeys.me, response.data);
    },
  });
}

// ---------------------------------------------------------------------------
// Setup wizard (owner only, resumable) + post-approval logo
// ---------------------------------------------------------------------------

/**
 * GET /api/merchant/setup — readable in EVERY lifecycle state; the /setup
 * screen renders the wizard, the waiting screen and the rejection banner
 * from it, and Settings reuses it for the curated category list + logo URL.
 */
export function useSetupState(enabled = true) {
  return useQuery({
    queryKey: queryKeys.setup,
    queryFn: ({ signal }) => getMerchantSetup({ signal }),
    select: (response) => response.data,
    retry: false,
    enabled,
  });
}

/** Every wizard write returns the full refreshed setup state — cache it. */
function useCacheSetupState() {
  const queryClient = useQueryClient();
  return (response: MerchantSetupStateResponse) => {
    queryClient.setQueryData(queryKeys.setup, response);
  };
}

export function useUpdateSetupProfile() {
  const cache = useCacheSetupState();
  return useMutation({
    mutationFn: (body: UpdateMerchantSetupProfileRequest) =>
      updateMerchantSetupProfile(body),
    onSuccess: cache,
  });
}

export function useUpdateSetupRate() {
  const cache = useCacheSetupState();
  return useMutation({
    mutationFn: (rateBp: number) =>
      // PLAN §1 wire format: the wizard's percent input is parsed to bp for
      // the bounds check, then written back out as the wire's 2-decimal
      // percent string.
      updateMerchantSetupRate({
        cashback_rate_percent: bpToPercentString(rateBp),
      }),
    onSuccess: cache,
  });
}

/**
 * Logo upload during the wizard (draft/rejected). The response carries only
 * the new URL, so the setup state is invalidated for the fresh values.
 */
export function useUploadSetupLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => uploadMerchantSetupLogo(file),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.setup });
    },
  });
}

/** The identical logo action mounted under Settings for ACTIVE merchants. */
export function useUploadSettingsLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => uploadMerchantSettingsLogo(file),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.setup });
    },
  });
}

/**
 * Submit → pending_review. The me cache's merchant.status is refreshed too,
 * so the (app) layout's onboarding gate and /setup agree immediately.
 */
export function useSubmitSetup() {
  const queryClient = useQueryClient();
  const cache = useCacheSetupState();
  return useMutation({
    mutationFn: () => submitMerchantSetup(),
    onSuccess: (response) => {
      cache(response);
      void queryClient.invalidateQueries({ queryKey: queryKeys.me });
    },
  });
}

/** The wizard-refusal `code` on an ApiError body, if any. */
export function onboardingErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const body = error.body as { code?: unknown } | undefined;
  return typeof body?.code === 'string' ? body.code : null;
}

/** 422 `setup_incomplete` — the missing requirement keys, [] otherwise. */
export function setupMissingKeys(error: unknown): string[] {
  if (onboardingErrorCode(error) !== 'setup_incomplete') return [];
  const body = (error as ApiError).body as { missing?: unknown } | undefined;
  return Array.isArray(body?.missing)
    ? body.missing.filter((key): key is string => typeof key === 'string')
    : [];
}
