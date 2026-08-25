'use client';

import {
  ApiError,
  approveConnect,
  archiveMarketplaceProduct,
  bootstrapCsrf,
  bpToPercentString,
  cancelMerchantPromotion,
  createMarketplaceProduct,
  createMerchantBranch,
  createMerchantCredential,
  createMerchantCredit,
  createMerchantProductCategory,
  createMerchantPromotion,
  createMerchantRole,
  createMerchantStaff,
  createMerchantWalletTopUp,
  deleteMerchantBranch,
  deleteMerchantRole,
  denyConnect,
  enrolInMarketplace,
  getBranchDelivery,
  getMarketplaceEnrolment,
  getMerchantOutstanding,
  getPosWaiver,
  getMerchantProfile,
  getMerchantSetup,
  getMerchantWallet,
  getSettlementPaymentProgress,
  getWalletTopUpProgress,
  isTransferWatched,
  transferWatchSecondsLeft,
  listMarketplaceCategories,
  listMarketplaceProducts,
  listMerchantBranches,
  listMerchantCredentials,
  listMerchantWebhookEndpoints,
  createMerchantWebhookEndpoint,
  deleteMerchantWebhookEndpoint,
  testMerchantWebhookEndpoint,
  type CreateMerchantWebhookEndpointRequest,
  listMerchantOrders,
  listMerchantPermissions,
  listMerchantProductCategories,
  listMerchantPromotions,
  listMerchantRoles,
  listMerchantStaff,
  lookupMerchantCustomer,
  publishMerchantPromotion,
  readConnectConsent,
  registerMerchantSignup,
  removeBranchDelivery,
  requestMerchantSignupOtp,
  reverseGeocodeBranchPin,
  revokeMerchantCredential,
  setBranchDelivery,
  setMerchantPublication,
  setProductListing,
  submitMarketplaceApplication,
  submitMerchantSetup,
  updateMarketplaceProduct,
  updateMerchantBankAccount,
  updateMerchantBranch,
  updateMerchantPreferences,
  updateMerchantProductCategory,
  updateMerchantProfile,
  updateMerchantRole,
  updateMerchantSetupLocation,
  updateMerchantSetupProfile,
  updateMerchantSetupRate,
  uploadMerchantSettingsLogo,
  uploadMerchantSetupLogo,
  verifyMerchantSignupOtp,
  type ApproveConnectRequest,
  type ConnectConsentQuery,
  type CreateCreditRequest,
  type CreateMerchantBranchRequest,
  type CreateMerchantCredentialRequest,
  type CreateMerchantRoleRequest,
  type CreateMerchantStaffRequest,
  type CreateProductCategoryRequest,
  type CreatePromotionRequest,
  type MerchantSetupStateResponse,
  type MerchantSignupRegisterRequest,
  type MerchantWalletResponse,
  type SettlementDestination,
  type TransferProgress,
  type WalletTopUpInput,
  type MerchantSignupVerifyOtpRequest,
  type TransactionState,
  type UpdateMerchantBankAccountRequest,
  type UpdateMerchantBranchRequest,
  type UpdateMerchantPreferencesRequest,
  type UpdateMerchantProfileRequest,
  type UpdateMerchantRoleRequest,
  type UpdateMerchantSetupLocationRequest,
  type UpdateMerchantSetupProfileRequest,
  type UpdateProductCategoryRequest,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import type { AmendTransactionBody, CancelReason } from '@/lib/api';
import {
  addSettlementReceipt,
  amendTransaction,
  cancelTransaction,
  changeRate,
  fetchMe,
  fetchRate,
  getSettlement,
  listSettlements,
  listTransactions,
  login,
  logout,
  previewSettlement,
  resetStaffPassword,
  submitSettlementWithReceipt,
  updateStaff,
  walletSettleSelection,
  type ReceiptSubmission,
  type SettlementSelection,
  type UpdateStaffBody,
} from '@/lib/api';

/**
 * react-query bindings for the merchant panel. Every response is zod-parsed
 * by the api-client before it reaches a component.
 */

export const queryKeys = {
  me: ['merchant', 'me'] as const,
  // Marketplace (PLAN-marketplace.md §4).
  marketplaceEnrolment: ['merchant', 'marketplace', 'enrolment'] as const,
  marketplaceProducts: ['merchant', 'marketplace', 'products'] as const,
  marketplaceOrders: ['merchant', 'marketplace', 'orders'] as const,
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
  roles: ['merchant', 'roles'] as const,
  permissionCatalogue: ['merchant', 'permissions'] as const,
  credentials: ['merchant', 'credentials'] as const,
  webhookEndpoints: ['merchant', 'webhook-endpoints'] as const,
  preferences: ['merchant', 'preferences'] as const,
  promotions: ['merchant', 'promotions'] as const,
  productCategories: ['merchant', 'product-categories'] as const,
  setup: ['merchant', 'setup'] as const,
};

export function isUnauthorized(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
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

export function usePosWaiver(enabled = true) {
  return useQuery({
    queryKey: ['merchant', 'pos-waiver'] as const,
    queryFn: ({ signal }) => getPosWaiver({ signal }),
    select: (response) => response.data,
    enabled,
    staleTime: 5 * 60 * 1000,
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

/**
 * Correcting a sale changes what is owed, what a settlement would cover and
 * the dashboard's totals, so every one of those reads is dropped rather
 * than only the list the row came from.
 */
function useInvalidateAfterCorrection() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({
      queryKey: ['merchant', 'transactions'],
    });
    void queryClient.invalidateQueries({ queryKey: queryKeys.outstanding });
    void queryClient.invalidateQueries({ queryKey: queryKeys.wallet });
  };
}

export function useAmendTransaction() {
  const invalidate = useInvalidateAfterCorrection();
  return useMutation({
    mutationFn: ({ id, ...body }: { id: number } & AmendTransactionBody) =>
      amendTransaction(id, body),
    onSuccess: invalidate,
  });
}

export function useCancelTransaction() {
  const invalidate = useInvalidateAfterCorrection();
  return useMutation({
    mutationFn: ({
      id,
      ...body
    }: {
      id: number;
      reason: CancelReason;
      note?: string | null;
    }) => cancelTransaction(id, body),
    onSuccess: invalidate,
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
  /**
   * `quote` (the default) never serves a stale answer — it is what the
   * wizard's step 2 shows the merchant they are about to PAY. `display`
   * is for ambient surfaces (the dashboard's save-banner) where a
   * 30-second-old figure is fine and refetching on every mount made the
   * dashboard feel slow (owner report 2026-08-23).
   */
  freshness: 'quote' | 'display' = 'quote',
) {
  // Never null inside the query function: the empty stand-in only exists so
  // the key is stable while the query is disabled.
  const active: SettlementSelection = selection ?? { transactionIds: [] };
  return useQuery({
    queryKey: queryKeys.settlementPreview(active),
    queryFn: ({ signal }) => previewSettlement(active, signal),
    enabled: enabled && selection !== null,
    retry: false,
    // A quote on live rows is never served stale; a display figure may be
    // up to 30s old and is refreshed by the money-event invalidations.
    staleTime: freshness === 'quote' ? 0 : 30_000,
    gcTime: freshness === 'quote' ? 0 : 5 * 60_000,
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
 * A wallet top-up CLAIM (owner, 2026-08-24): the merchant has transferred to
 * a platform account and uploads the slip; the balance moves only once the
 * bank's own history confirms it. Until then the claim sits in the wallet
 * payload's `pending_top_ups`, which is why the wallet read is dropped on
 * success — the list should show the new claim at once.
 */
export function useCreateWalletTopUp() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: WalletTopUpInput) => createMerchantWalletTopUp(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.wallet });
    },
  });
}

/**
 * Which transfer to watch: a settlement (whose NEWEST bank payment the route
 * reports) or a wallet top-up claim.
 */
export type TransferProgressTarget =
  | { kind: 'settlement'; settlementId: number }
  | { kind: 'top_up'; topUpId: number };

/**
 * 5s — the cadence the routes are sized for (they allow 120/min, ten times
 * this) and about as often as a human wants a screen to change under them.
 */
const TRANSFER_POLL_MS = 5_000;

/**
 * Consecutive failed ATTEMPTS after which the panel stops asking.
 *
 * The app's rule is four failed reads in a row
 * (transfer_progress_view.dart `_giveUpAfter`), and this is the same
 * patience counted in the unit TanStack actually exposes: `fetchFailureCount`
 * counts attempts, and QueryProvider retries a non-4xx twice, so one failed
 * poll round burns up to three of them. Twelve ≈ the app's four rounds — a
 * connection has to be properly gone, not merely blink, before a live watch
 * is given up on.
 */
const TRANSFER_GIVE_UP_AFTER = 12;

/**
 * A refusal that answers the question rather than failing to: the row is not
 * ours (401/403) or there is no transfer on it (404). Asking again cannot
 * change any of those, so the poll ends there. Everything else — 429, 5xx, a
 * dropped connection — is a blip, and a blip must not end a live watch.
 */
function isFinalTransferError(error: unknown): boolean {
  return (
    error instanceof ApiError &&
    (error.status === 401 || error.status === 403 || error.status === 404)
  );
}

/**
 * Has the poll given up? The card asks, because a screen that has stopped
 * asking must stop drawing the live bar over its last payload — the bar is a
 * claim that somebody is looking RIGHT NOW, and once the reads have stopped
 * that claim is this client's own invention.
 */
export function transferPollStopped(
  status: string,
  error: unknown,
  failureCount: number,
): boolean {
  if (status !== 'error') return false;
  return isFinalTransferError(error) || failureCount >= TRANSFER_GIVE_UP_AFTER;
}

function transferProgressKey(target: TransferProgressTarget) {
  return target.kind === 'settlement'
    ? (['merchant', 'settlement-payment-progress', target.settlementId] as const)
    : (['merchant', 'wallet-top-up-progress', target.topUpId] as const);
}

/**
 * Watching one bank transfer land (owner, 2026-08-25) — a settlement receipt
 * or a wallet top-up slip, through the one endpoint pair that answers both in
 * the same shape.
 *
 * READ-ONLY, AND NOT A TRIGGER. The server polls the bank on its own
 * schedule; this only looks. Closing the screen stops nothing and loses
 * nothing — the push and SMS on a match fire either way.
 *
 * IT NEVER POLLS FOREVER. Three separate stops, any one of which ends it:
 *
 *  - the row is DECIDED, or was never being watched — `isTransferWatched`
 *    covers both, and it is the SERVER's answer, never an inference here;
 *  - the window has passed. The deadline is the server's own
 *    `watch_until − checked_at` pinned to the local instant the response
 *    landed, so it is a fixed local moment that each further read reproduces
 *    identically — the poll cannot walk it forward by asking again;
 *  - the read failed FINALLY. A 401/403 from a missing `settlements.view` /
 *    `wallet.view` and a 404 for a batch with no bank payment are answers,
 *    and asking again cannot change them. Anything else — a 429, a 5xx, a
 *    tunnel dropping for three seconds — is NOT an answer: the poll keeps
 *    its cadence over the last good payload until
 *    {@link TRANSFER_GIVE_UP_AFTER} attempts in a row have failed, the same
 *    patience the app's own loop has. Treating one blip as terminal used to end the
 *    watch for the life of the mount, and the merchant then never saw the
 *    outcome on the screen they were staring at.
 *
 * A HIDDEN TAB COSTS NOTHING. `refetchIntervalInBackground` stays false, so
 * the timer fires only while the tab is actually being looked at; focus
 * brings it straight back, and `staleTime: 0` means that first look is fresh.
 *
 * ON A DECISION IT DROPS THE SCREENS BEHIND IT. A matched settlement changes
 * what is outstanding, what the batch shows and the wallet; a credited
 * top-up moves the balance. Both are invalidated once — so whatever the
 * merchant returns to is right — and never the progress read itself, whose
 * key deliberately shares no prefix with either.
 */
export function useTransferProgress(target: TransferProgressTarget) {
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: transferProgressKey(target),
    // Annotated rather than inferred: the two routes answer with different
    // outcome shapes, and TS would otherwise pin the query to whichever
    // branch it read first. Both are `{ data: … }` over the same union.
    queryFn: ({ signal }): Promise<{ data: TransferProgress }> =>
      target.kind === 'settlement'
        ? getSettlementPaymentProgress(target.settlementId, { signal })
        : getWalletTopUpProgress(target.topUpId, { signal }),
    select: (response) => response.data,
    staleTime: 0,
    refetchIntervalInBackground: false,
    // The same predicate the interval stops on. `outcome == null` alone kept
    // firing a read on every tab focus, forever, for a row nobody is
    // watching — window_expired, auto_verify_off, never_watched — whose
    // answer cannot change what the card says.
    refetchOnWindowFocus: (query) => {
      const progress = query.state.data?.data;
      return progress === undefined || isTransferWatched(progress);
    },
    refetchInterval: (query) => {
      if (
        isFinalTransferError(query.state.error) ||
        query.state.fetchFailureCount >= TRANSFER_GIVE_UP_AFTER
      ) {
        return false;
      }
      const progress = query.state.data?.data;
      // No payload yet — including a first read that failed with something
      // retryable. Keep asking; the failure count above bounds it.
      if (progress === undefined) return TRANSFER_POLL_MS;
      if (!isTransferWatched(progress)) return false;
      const deadline =
        query.state.dataUpdatedAt + transferWatchSecondsLeft(progress) * 1000;
      return Date.now() >= deadline ? false : TRANSFER_POLL_MS;
    },
  });

  const decided = query.data?.outcome != null;
  const rowId = query.data?.id ?? null;
  const kind = target.kind;
  const droppedFor = useRef<string | null>(null);

  useEffect(() => {
    if (!decided || rowId === null) return;
    const stamp = `${kind}:${rowId}`;
    if (droppedFor.current === stamp) return;
    droppedFor.current = stamp;

    void queryClient.invalidateQueries({ queryKey: queryKeys.wallet });
    if (kind === 'settlement') {
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
    }
  }, [decided, rowId, kind, queryClient]);

  return query;
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

/**
 * The slugs a `permission_not_held` refusal names (D5). Plain strings on
 * purpose: the checkbox they belong to came from the SERVED catalogue, so a
 * server ahead of this build can legitimately name one it has never heard
 * of, and the screen still has to point somewhere.
 */
export function roleErrorPermissions(error: unknown): string[] {
  if (!(error instanceof ApiError)) return [];
  const body = error.body as { permissions?: unknown } | undefined;
  return Array.isArray(body?.permissions)
    ? body.permissions.filter(
        (slug): slug is string => typeof slug === 'string',
      )
    : [];
}

/** How many accounts stand on a role a `role_in_use` refusal protected. */
export function roleErrorStaffCount(error: unknown): number | null {
  if (!(error instanceof ApiError)) return null;
  const body = error.body as { staff_count?: unknown } | undefined;
  return typeof body?.staff_count === 'number' ? body.staff_count : null;
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
      /**
       * Everything else that is a STATEMENT ABOUT THE OUTSTANDING BALANCE,
       * because a new sale has just changed it and the two must not be seen
       * side by side disagreeing.
       *
       * This used to be carried by a page navigation: the /credit route was
       * a different screen, so coming back to the dashboard remounted these
       * queries and they refetched past their staleTime. A dialog does not
       * navigate — the dashboard stays mounted underneath, and React Query
       * does not refetch on staleness alone — so a credit keyed from the
       * modal would leave the prompt-payment countdown quoting a discount
       * from before the sale, next to a Total payable that had just moved.
       *
       * Prefix keys: every settlement preview (`['merchant',
       * 'settlement-preview', …selection]`) is a quote against the same
       * balance, whichever selection it was quoted for.
       */
      void queryClient.invalidateQueries({
        queryKey: ['merchant', 'settlement-preview'],
      });
      void queryClient.invalidateQueries({
        queryKey: ['merchant', 'pos-waiver'],
      });
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
    { advertised_cashback_rate_percent?: unknown } | undefined;
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

/**
 * A profile save answers with the LIVE profile plus, when a public claim
 * moved, the change request now waiting on an admin (MR9). The live profile
 * is cached either way — it is what a shopper reads and therefore what the
 * form shows — and it already carries `pending_change`, so the pending-review
 * banner appears from this one response without a refetch.
 */
export function useUpdateProfile() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateMerchantProfileRequest) =>
      updateMerchantProfile(body),
    onSuccess: (result) => {
      queryClient.setQueryData(queryKeys.profile, { data: result.profile });
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

/**
 * The same GET read for its OTHER half: the branch changes waiting on a
 * reviewer (MR9). A second observer on the one query key rather than a
 * second request — react-query dedupes them — which keeps `useBranches`
 * returning the estate as it stands to the three screens that only want a
 * list of branches.
 */
export function usePendingBranchChanges(enabled = true) {
  return useQuery({
    queryKey: queryKeys.branches,
    queryFn: ({ signal }) => listMerchantBranches({ signal }),
    select: (response) => response.meta.pending_changes,
    retry: false,
    enabled,
  });
}

/**
 * Every branch write invalidates the list — and that is load-bearing for a
 * QUEUED write too, where the estate has not moved an inch: `meta` is what
 * changed, and it is what the pending-review banner renders from.
 */
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

/**
 * The store's own on/off switch. Invalidates the profile, because
 * `published` lives on it and the header badge reads from there.
 */
// ------------------------------------------------------- marketplace (§4)

export function useMarketplaceEnrolment() {
  return useQuery({
    queryKey: queryKeys.marketplaceEnrolment,
    queryFn: ({ signal }) => getMarketplaceEnrolment({ signal }),
    // A store that never opted in answers `not_enrolled` rather than 404, so
    // a failure here means the marketplace is OFF platform-wide — the sidebar
    // simply shows nothing, which is the intended behaviour.
    retry: false,
  });
}

export function useEnrolInMarketplace() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: enrolInMarketplace,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.marketplaceEnrolment,
      });
    },
  });
}

export function useSubmitMarketplaceApplication() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: submitMarketplaceApplication,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.marketplaceEnrolment,
      });
    },
  });
}

export function useMarketplaceProducts() {
  return useQuery({
    queryKey: queryKeys.marketplaceProducts,
    queryFn: ({ signal }) => listMarketplaceProducts({ signal }),
  });
}

export function useMarketplaceCategories() {
  return useQuery({
    queryKey: ['merchant', 'marketplace', 'categories'],
    queryFn: ({ signal }) => listMarketplaceCategories({ signal }),
    staleTime: 60 * 60 * 1000,
  });
}

export function useSaveProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      body,
    }: {
      id?: number;
      body: Record<string, unknown>;
    }) =>
      id === undefined
        ? createMarketplaceProduct(body)
        : updateMarketplaceProduct(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.marketplaceProducts,
      });
    },
  });
}

export function useArchiveProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: archiveMarketplaceProduct,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.marketplaceProducts,
      });
    },
  });
}

export function useSetListing() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      productId,
      body,
    }: {
      productId: number;
      body: Parameters<typeof setProductListing>[1];
    }) => setProductListing(productId, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.marketplaceProducts,
      });
    },
  });
}

export function useMerchantOrders(tab: string) {
  return useQuery({
    queryKey: [...queryKeys.marketplaceOrders, tab],
    queryFn: ({ signal }) => listMerchantOrders(tab, { signal }),
    // A shop works this screen while customers are ordering into it.
    refetchInterval: 30_000,
  });
}

export function useOrderAction() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (action: () => Promise<unknown>) => action(),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.marketplaceOrders,
      });
    },
  });
}

export function useBranchDelivery(branchId: number | null) {
  return useQuery({
    queryKey: ['merchant', 'marketplace', 'delivery', branchId],
    queryFn: ({ signal }) => getBranchDelivery(branchId!, { signal }),
    enabled: branchId !== null,
  });
}

export function useSaveBranchDelivery() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      branchId,
      body,
    }: {
      branchId: number;
      body: Parameters<typeof setBranchDelivery>[1];
    }) => setBranchDelivery(branchId, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['merchant', 'marketplace', 'delivery'],
      });
    },
  });
}

export function useRemoveBranchDelivery() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ branchId, zoneId }: { branchId: number; zoneId: number }) =>
      removeBranchDelivery(branchId, zoneId),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['merchant', 'marketplace', 'delivery'],
      });
    },
  });
}

export function useSetPublication() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (published: boolean) => setMerchantPublication(published),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.profile });
      void queryClient.invalidateQueries({ queryKey: queryKeys.me });
    },
  });
}

/**
 * Pin → address. Not a query: it runs when the merchant asks for it, and its
 * answer lands in a field they can overwrite.
 */
export function useReverseGeocode() {
  return useMutation({
    mutationFn: ({ lat, lng }: { lat: number; lng: number }) =>
      reverseGeocodeBranchPin(lat, lng),
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

/**
 * Inviting or reassigning an account moves a role's `staff_count` as well as
 * the staff list, and that count is what the roles screen refuses a delete
 * on — so the roles list goes stale with it. `me` joins the set with MR8's
 * name/email edits: the one row a staff PATCH may now touch that the header
 * renders from cache is the editor's own.
 */
function useInvalidateAfterStaffChange() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: queryKeys.staff });
    void queryClient.invalidateQueries({ queryKey: queryKeys.roles });
    void queryClient.invalidateQueries({ queryKey: queryKeys.me });
  };
}

export function useCreateStaff() {
  const invalidate = useInvalidateAfterStaffChange();
  return useMutation({
    mutationFn: (body: CreateMerchantStaffRequest) => createMerchantStaff(body),
    onSuccess: invalidate,
  });
}

export function useUpdateStaff() {
  const invalidate = useInvalidateAfterStaffChange();
  return useMutation({
    // The panel's own widened body (MR8: + name/email), not the shared
    // client's role/activation pair — see lib/api.ts.
    mutationFn: ({ id, body }: { id: number; body: UpdateStaffBody }) =>
      updateStaff(id, body),
    onSuccess: invalidate,
  });
}

/**
 * MR8 staff password reset. The response carries the one-time temp password
 * exactly as the invite does — handed to the caller for the reveal dialog
 * and deliberately never written into the query cache, so no refetch or
 * devtools panel can resurrect it. Nothing the staff table renders changes,
 * so there is nothing to invalidate.
 */
export function useResetStaffPassword() {
  return useMutation({
    mutationFn: (id: number) => resetStaffPassword(id),
  });
}

// ---------------------------------------------------------------------------
// Settings — roles (`roles.view` reads, `roles.manage` writes)
// ---------------------------------------------------------------------------

/**
 * The permission catalogue behind the roles screen's checkboxes (D8). It is
 * DEPLOY-scoped data — the catalogue is a PHP enum, so it changes only when
 * the API ships — which is why it is cached for the hour rather than
 * refetched every time a dialog opens.
 */
export function usePermissionCatalogue(enabled = true) {
  return useQuery({
    queryKey: queryKeys.permissionCatalogue,
    queryFn: ({ signal }) => listMerchantPermissions({ signal }),
    select: (response) => response.data.groups,
    retry: false,
    staleTime: 60 * 60 * 1000,
    enabled,
  });
}

export function useRoles(enabled = true) {
  return useQuery({
    queryKey: queryKeys.roles,
    queryFn: ({ signal }) => listMerchantRoles({ signal }),
    select: (response) => response.data,
    retry: false,
    enabled,
  });
}

/**
 * A role write moves three cached things at once. The roles list and the
 * staff rows are the obvious two — staff rows print the role's name, so a
 * rename leaves them stale.
 *
 * The third is the one that is easy to miss: `me` carries the signed-in
 * account's RESOLVED permission set (D3), and editing a role the reader
 * stands under changes it. The server resolves permissions per request and
 * is authoritative from the next call regardless — what the panel would
 * otherwise keep is a cached copy still hiding a control the reader has
 * just been given, or still offering one they have just lost.
 */
function useInvalidateAfterRoleChange() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: queryKeys.roles });
    void queryClient.invalidateQueries({ queryKey: queryKeys.staff });
    void queryClient.invalidateQueries({ queryKey: queryKeys.me });
  };
}

export function useCreateRole() {
  const invalidate = useInvalidateAfterRoleChange();
  return useMutation({
    mutationFn: (body: CreateMerchantRoleRequest) => createMerchantRole(body),
    onSuccess: invalidate,
  });
}

export function useUpdateRole() {
  const invalidate = useInvalidateAfterRoleChange();
  return useMutation({
    mutationFn: ({
      id,
      body,
    }: {
      id: number;
      body: UpdateMerchantRoleRequest;
    }) => updateMerchantRole(id, body),
    onSuccess: invalidate,
  });
}

export function useDeleteRole() {
  const invalidate = useInvalidateAfterRoleChange();
  return useMutation({
    mutationFn: (id: number) => deleteMerchantRole(id),
    onSuccess: invalidate,
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

/**
 * The connect consent question, read from the query string a platform put
 * in the URL. Reading writes nothing server-side: a shopkeeper who opens
 * the screen and closes it again has granted nothing.
 */
export function useConnectConsent(query: ConnectConsentQuery | null) {
  return useQuery({
    queryKey: ['connect-consent', query],
    queryFn: ({ signal }) => readConnectConsent(query!, { signal }),
    select: (response) => response.data,
    enabled: query !== null,
    retry: false,
    // A one-time question, answered once — never served from a stale cache.
    staleTime: 0,
    gcTime: 0,
  });
}

/**
 * Authorise. The reply is a url to send the browser to; the code in it is
 * good for sixty seconds and once only.
 */
export function useApproveConnect() {
  return useMutation({
    mutationFn: (body: ApproveConnectRequest) => approveConnect(body),
  });
}

export function useDenyConnect() {
  return useMutation({
    mutationFn: (body: {
      client_id: string;
      redirect_uri: string;
      state?: string | null;
    }) => denyConnect(body),
  });
}

// Merchant-owned webhook endpoints (owner, 2026-08-22), beside the
// credentials they share permissions with.

export function useWebhookEndpoints() {
  return useQuery({
    queryKey: queryKeys.webhookEndpoints,
    queryFn: ({ signal }) => listMerchantWebhookEndpoints({ signal }),
    select: (response) => response.data,
    retry: false,
  });
}

/** The signing secret is in the response once and never cached — as for tokens. */
export function useCreateWebhookEndpoint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateMerchantWebhookEndpointRequest) =>
      createMerchantWebhookEndpoint(body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhookEndpoints });
    },
  });
}

export function useDeleteWebhookEndpoint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteMerchantWebhookEndpoint(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.webhookEndpoints });
    },
  });
}

export function useTestWebhookEndpoint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => testMerchantWebhookEndpoint(id),
    onSuccess: () => {
      // The delivery lands a moment later; refetch so "last heard from"
      // catches it on the next render.
      setTimeout(() => {
        void queryClient.invalidateQueries({ queryKey: queryKeys.webhookEndpoints });
      }, 2500);
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

/**
 * The wallet screen's auto-settle toggle (owner, 2026-08-24). READ off the
 * wallet payload, WRITTEN through the one preferences route — there is no
 * wallet-scoped write. Optimistic: the switch moves at once and the wallet
 * read is patched to match; a refusal puts it back, and the wallet is
 * refetched either way so the screen ends on the server's answer.
 */
export function useSetAutoSettleFromWallet() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (enabled: boolean) =>
      updateMerchantPreferences({ auto_settle_from_wallet: enabled }),
    onMutate: async (enabled) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.wallet });
      const previous = queryClient.getQueryData<MerchantWalletResponse>(
        queryKeys.wallet,
      );
      if (previous) {
        queryClient.setQueryData<MerchantWalletResponse>(queryKeys.wallet, {
          ...previous,
          data: { ...previous.data, auto_settle_from_wallet: enabled },
        });
      }
      return { previous };
    },
    onError: (_error, _enabled, context) => {
      if (context?.previous) {
        queryClient.setQueryData(queryKeys.wallet, context.previous);
      }
    },
    onSuccess: (response) => {
      queryClient.setQueryData(queryKeys.preferences, response);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.wallet });
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
 * Whether a 422 carries a validation error on the named FIELD — the shape a
 * request rule answers with (`errors.amount`), as opposed to a domain
 * refusal with a `code`.
 */
export function hasFieldError(error: unknown, field: string): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) {
    return false;
  }
  const body = error.body as { errors?: Record<string, unknown> } | undefined;
  return body?.errors !== undefined && field in body.errors;
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

/**
 * The wizard's location step writes the store's PRIMARY branch, so the
 * branch list settings renders is stale the moment this succeeds — inactive
 * queries are only marked, never refetched, so saying so costs nothing.
 */
export function useUpdateSetupLocation() {
  const queryClient = useQueryClient();
  const cache = useCacheSetupState();
  return useMutation({
    mutationFn: (body: UpdateMerchantSetupLocationRequest) =>
      updateMerchantSetupLocation(body),
    onSuccess: (response) => {
      cache(response);
      void queryClient.invalidateQueries({ queryKey: queryKeys.branches });
    },
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

/**
 * The identical logo action mounted under Settings for ACTIVE merchants —
 * except that for a live store the upload does not become the logo (MR9):
 * the file is staged and a profile change request queues, so the PROFILE is
 * invalidated too. Its `pending_change` is where the proposed logo lives,
 * and it is the one read that knows whether an earlier pending rename rode
 * along with it (the server carries unmentioned keys forward).
 */
export function useUploadSettingsLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => uploadMerchantSettingsLogo(file),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.setup });
      void queryClient.invalidateQueries({ queryKey: queryKeys.profile });
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
