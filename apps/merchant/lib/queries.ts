'use client';

import {
  ApiError,
  cancelMerchantPromotion,
  createMerchantBranch,
  createMerchantCredit,
  createMerchantPromotion,
  createMerchantSettlement,
  createMerchantStaff,
  deleteMerchantBranch,
  getMerchantOutstanding,
  getMerchantProfile,
  getMerchantSettlement,
  getMerchantWallet,
  listMerchantBranches,
  listMerchantPromotions,
  listMerchantSettlements,
  listMerchantStaff,
  lookupMerchantCustomer,
  publishMerchantPromotion,
  submitMerchantSettlement,
  updateMerchantBankAccount,
  updateMerchantBranch,
  updateMerchantPreferences,
  updateMerchantProfile,
  updateMerchantStaff,
  walletSettleMerchantSettlement,
  type CreateCreditRequest,
  type CreatePromotionRequest,
  type CreateMerchantBranchRequest,
  type CreateMerchantStaffRequest,
  type CreateSettlementRequest,
  type TransactionState,
  type UpdateMerchantBankAccountRequest,
  type UpdateMerchantBranchRequest,
  type UpdateMerchantPreferencesRequest,
  type UpdateMerchantProfileRequest,
  type UpdateMerchantStaffRequest,
} from '@manfaa/api-client';
import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import {
  changeRate,
  fetchMe,
  fetchRate,
  listTransactions,
  login,
  logout,
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
  transactions: (state: TransactionState | 'all', page: number) =>
    ['merchant', 'transactions', state, page] as const,
  profile: ['merchant', 'profile'] as const,
  branches: ['merchant', 'branches'] as const,
  staff: ['merchant', 'staff'] as const,
  preferences: ['merchant', 'preferences'] as const,
  promotions: ['merchant', 'promotions'] as const,
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
    retry: false,
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
    queryFn: ({ signal }) => listMerchantSettlements({ page }, { signal }),
  });
}

export function useSettlement(id: number) {
  return useQuery({
    queryKey: queryKeys.settlement(id),
    queryFn: ({ signal }) => getMerchantSettlement(id, { signal }),
    select: (response) => response.data,
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
    void queryClient.invalidateQueries({ queryKey: ['merchant', 'settlements'] });
    void queryClient.invalidateQueries({ queryKey: ['merchant', 'settlement'] });
    void queryClient.invalidateQueries({ queryKey: queryKeys.outstanding });
    void queryClient.invalidateQueries({ queryKey: ['merchant', 'transactions'] });
    void queryClient.invalidateQueries({ queryKey: queryKeys.wallet });
  };
}

export function useCreateSettlement() {
  const invalidate = useInvalidateSettlementData();
  return useMutation({
    mutationFn: (body: CreateSettlementRequest) =>
      createMerchantSettlement(body),
    onSuccess: invalidate,
  });
}

export function useSubmitSettlement(id: number) {
  const invalidate = useInvalidateSettlementData();
  return useMutation({
    mutationFn: () => submitMerchantSettlement(id),
    onSuccess: invalidate,
  });
}

export function useWalletSettle(id: number) {
  const invalidate = useInvalidateSettlementData();
  return useMutation({
    mutationFn: () => walletSettleMerchantSettlement(id),
    onSuccess: invalidate,
  });
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
      | { message?: unknown; errors?: Record<string, unknown> }
      | undefined;
    if (body && typeof body.message === 'string' && body.message.length > 0) {
      return body.message;
    }
  }
  return fallback;
}
