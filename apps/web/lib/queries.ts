'use client';

import {
  ApiError,
  bootstrapCsrf,
  createCustomerClaim,
  customerLogin,
  customerLogout,
  getCustomerBalance,
  getCustomerMe,
  getCustomerPayoutAccount,
  getDirectory,
  getDiscovery,
  getStore,
  listCustomerClaims,
  getCustomerPayout,
  listCustomerPayouts,
  listCustomerTransactions,
  registerCustomer,
  requestCustomerOtp,
  saveCustomerPayoutAccount,
  verifyCustomerOtp,
  type CreateClaimRequest,
  type Customer,
  type CustomerLoginRequest,
  type DirectoryParams,
  type RegisterCustomerRequest,
  type SavePayoutAccountRequest,
  type StoreDetail,
  type VerifyOtpRequest,
} from '@manfaa/api-client';
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';

/**
 * react-query bindings for the customer app. Every response is zod-parsed by
 * the api-client before it reaches a component; money stays integer laari
 * end to end.
 */

export const queryKeys = {
  me: ['customer', 'me'] as const,
  balance: ['customer', 'balance'] as const,
  transactions: (page: number) => ['customer', 'transactions', page] as const,
  payouts: (page: number) => ['customer', 'payouts', page] as const,
  payout: (id: number) => ['customer', 'payout', id] as const,
  payoutAccount: ['customer', 'payout-account'] as const,
  claims: (page: number) => ['customer', 'claims', page] as const,
  discovery: (coords: { lat: number; lng: number } | null) =>
    ['discover', coords?.lat ?? null, coords?.lng ?? null] as const,
  directory: (params: DirectoryParams) =>
    [
      'directory',
      params.q ?? null,
      params.category ?? null,
      params.page ?? 1,
    ] as const,
  store: (slug: string) => ['store', slug] as const,
};

export function isUnauthorized(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

export function isNotFound(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404;
}

export function isUnprocessable(error: unknown): boolean {
  return error instanceof ApiError && error.status === 422;
}

/**
 * Collects the field-error KEYS out of a Laravel 422 body (e.g. `otp_invalid`,
 * `phone_already_registered`). The API sends translation keys, not prose —
 * the caller maps them through t().
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
// Session
// ---------------------------------------------------------------------------

export function useMe() {
  return useQuery({
    queryKey: queryKeys.me,
    queryFn: async ({ signal }): Promise<Customer> =>
      (await getCustomerMe({ signal })).data,
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
    mutationFn: async (body: CustomerLoginRequest): Promise<Customer> => {
      await bootstrapCsrf();
      return (await customerLogin(body)).data;
    },
    onSuccess: (me) => {
      queryClient.setQueryData(queryKeys.me, me);
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => customerLogout(),
    onSettled: () => {
      queryClient.clear();
    },
  });
}

// ---------------------------------------------------------------------------
// Signup: request-otp -> verify-otp -> register
// ---------------------------------------------------------------------------

export function useRequestOtp() {
  return useMutation({
    mutationFn: async (phone: string) => {
      await bootstrapCsrf();
      return requestCustomerOtp({ phone });
    },
  });
}

export function useVerifyOtp() {
  return useMutation({
    mutationFn: (body: VerifyOtpRequest) => verifyCustomerOtp(body),
  });
}

export function useRegister() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: RegisterCustomerRequest): Promise<Customer> =>
      (await registerCustomer(body)).data,
    onSuccess: (me) => {
      queryClient.setQueryData(queryKeys.me, me);
    },
  });
}

// ---------------------------------------------------------------------------
// Balance, history
// ---------------------------------------------------------------------------

export function useBalance() {
  return useQuery({
    queryKey: queryKeys.balance,
    queryFn: ({ signal }) => getCustomerBalance({ signal }),
    select: (response) => response.data,
  });
}

export function useTransactions(page: number) {
  return useQuery({
    queryKey: queryKeys.transactions(page),
    queryFn: ({ signal }) => listCustomerTransactions({ page }, { signal }),
  });
}

// ---------------------------------------------------------------------------
// Payouts
// ---------------------------------------------------------------------------

export function usePayouts(page: number) {
  return useQuery({
    queryKey: queryKeys.payouts(page),
    queryFn: ({ signal }) => listCustomerPayouts({ page }, { signal }),
  });
}

export function usePayout(id: number) {
  return useQuery({
    queryKey: queryKeys.payout(id),
    queryFn: ({ signal }) => getCustomerPayout(id, { signal }),
    select: (response) => response.data,
  });
}

// ---------------------------------------------------------------------------
// Payout account
// ---------------------------------------------------------------------------

export function usePayoutAccount() {
  return useQuery({
    queryKey: queryKeys.payoutAccount,
    queryFn: ({ signal }) => getCustomerPayoutAccount({ signal }),
    select: (response) => response.data,
  });
}

export function useSavePayoutAccount() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: SavePayoutAccountRequest) =>
      saveCustomerPayoutAccount(body),
    onSuccess: (response) => {
      queryClient.setQueryData(queryKeys.payoutAccount, response);
      // has_payout_account on the balance card may have flipped.
      void queryClient.invalidateQueries({ queryKey: queryKeys.balance });
    },
  });
}

// ---------------------------------------------------------------------------
// Claims
// ---------------------------------------------------------------------------

export function useClaims(page: number) {
  return useQuery({
    queryKey: queryKeys.claims(page),
    queryFn: ({ signal }) => listCustomerClaims({ page }, { signal }),
  });
}

export function useCreateClaim() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateClaimRequest) => createCustomerClaim(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['customer', 'claims'] });
    },
  });
}

// ---------------------------------------------------------------------------
// Discovery (public)
// ---------------------------------------------------------------------------

export function useDiscovery(coords: { lat: number; lng: number } | null) {
  return useQuery({
    queryKey: queryKeys.discovery(coords),
    queryFn: ({ signal }) => getDiscovery(coords ?? {}, { signal }),
    select: (response) => response.data,
    staleTime: 60_000,
    // Granting location flips the query key (coords join it), which would
    // otherwise blank `data` and unmount every already-loaded section while
    // the coord-scoped refetch is in flight. Keep the previous sections on
    // screen instead; callers can read isPlaceholderData for the interim.
    placeholderData: keepPreviousData,
  });
}

/**
 * The public store directory (the "All stores" grid on /discover). Filter or
 * page changes flip the query key; keepPreviousData holds the current grid
 * on screen while the next page loads, so the list never blanks between
 * filter keystrokes.
 */
export function useDirectory(params: DirectoryParams) {
  return useQuery({
    queryKey: queryKeys.directory(params),
    queryFn: ({ signal }) => getDirectory(params, { signal }),
    staleTime: 60_000,
    placeholderData: keepPreviousData,
  });
}

/**
 * One public store page. A 404 is a real answer (unknown or unlisted slug),
 * not a transient failure — never retried; callers branch on isNotFound.
 * `initialStore` seeds the cache with the server-rendered payload (fresh for
 * the same 60s the API caches it), so hydration paints instantly without an
 * immediate duplicate fetch.
 */
export function useStore(slug: string, initialStore?: StoreDetail) {
  return useQuery({
    queryKey: queryKeys.store(slug),
    queryFn: ({ signal }) => getStore(slug, { signal }),
    select: (response) => response.data,
    staleTime: 60_000,
    retry: (failureCount, error) => !isNotFound(error) && failureCount < 2,
    initialData:
      initialStore === undefined ? undefined : { data: initialStore },
  });
}
