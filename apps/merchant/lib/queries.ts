'use client';

import {
  ApiError,
  createMerchantSettlement,
  getMerchantOutstanding,
  getMerchantSettlement,
  getMerchantWallet,
  listMerchantSettlements,
  submitMerchantSettlement,
  walletSettleMerchantSettlement,
  type CreateSettlementRequest,
  type TransactionState,
} from '@manfaa/api-client';
import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import { fetchMe, listTransactions, login, logout } from '@/lib/api';

/**
 * react-query bindings for the merchant panel. Every response is zod-parsed
 * by the api-client before it reaches a component.
 */

export const queryKeys = {
  me: ['merchant', 'me'] as const,
  outstanding: ['merchant', 'outstanding'] as const,
  wallet: ['merchant', 'wallet'] as const,
  settlements: (page: number) => ['merchant', 'settlements', page] as const,
  settlement: (id: number) => ['merchant', 'settlement', id] as const,
  transactions: (state: TransactionState | 'all', page: number) =>
    ['merchant', 'transactions', state, page] as const,
};

export function isUnauthorized(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
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
