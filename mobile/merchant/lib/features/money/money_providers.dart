import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../../app/providers.dart';
import '../onboarding/onboarding_providers.dart';

/// How long a cached money answer may serve before a screen entry quietly
/// refreshes it in the background (owner report 2026-08-23: the Dashboard
/// refetched on EVERY visit, because autoDispose threw the cache away the
/// moment the screen was left).
const moneyStaleAfter = Duration(seconds: 45);

/// Wall-clock of each provider's last successful fetch, for the staleness
/// check. Plain module state on purpose: it must survive the providers'
/// own dispose/rebuild cycle, and it carries no money — only timestamps.
final _moneyFetchedAt = <String, DateTime>{};

extension _MoneyCache on Ref {
  /// Keep this (autoDispose) provider's answer alive after its listeners go
  /// away, and stamp the fetch time. Called only on SUCCESS — a thrown
  /// fetch keeps plain autoDispose semantics, so errors are never cached.
  ///
  /// No expiry timer, deliberately: a pending Timer trips flutter_test's
  /// teardown assertion, and freshness is governed by [refreshStaleMoney]
  /// anyway — every money-screen entry and app resume refetches anything
  /// older than [moneyStaleAfter], while Riverpod keeps the previous value
  /// on screen during the refetch.
  void cacheMoney(String key) {
    _moneyFetchedAt[key] = DateTime.now();
    keepAlive();
    onDispose(() => _moneyFetchedAt.remove(key));
  }
}

bool _stale(String key) {
  final at = _moneyFetchedAt[key];
  return at == null || DateTime.now().difference(at) > moneyStaleAfter;
}

/// Refresh whichever money answers have gone stale — called on entering a
/// money screen and on app resume. Riverpod keeps the previous value while
/// the refetch runs, so the screen shows the cached board instantly and the
/// numbers update in place; nothing flashes back to a skeleton.
void refreshStaleMoney(WidgetRef ref) {
  if (_stale('home')) ref.invalidate(homeProvider);
  if (_stale('wallet')) ref.invalidate(walletProvider);
  if (_stale('preview')) ref.invalidate(settleAllPreviewProvider);
  if (_stale('settlements')) ref.invalidate(settlementsPageProvider);
  refreshStaleFeePromotion(ref);
}

/// Refresh the fee promotion ALONE if it has gone stale — for a screen that
/// quotes a fee without being a money screen. The till is the one that
/// matters: it prices its cost preview at the promotional rate, so it has
/// to notice a campaign ending, and it must not drag the whole board's
/// worth of refetches along on every visit to the Credit tab.
///
/// The banner also expires itself locally off `ends_at`, so this is the
/// second line of defence, not the only one.
void refreshStaleFeePromotion(WidgetRef ref) {
  if (_stale('fee_promotion')) ref.invalidate(feePromotionProvider);
}

/// The MR3 money providers — one place, because the Dashboard and the
/// Settlements tab read the SAME server answers and must never disagree.

/// The full /merchant/home answer: today's tally always; outstanding + the
/// open batch only for accounts holding `settlements.view` (null otherwise —
/// the server withholds, the UI simply has nothing to draw).
final homeProvider = FutureProvider.autoDispose<MerchantHome>((ref) async {
  final home = await ref.watch(apiProvider).home();
  ref.cacheMoney('home');
  return home;
});

/// GET /merchant/fee-promotion — the platform fee promotion this store is
/// trading under (owner, 2026-08-25). Lives beside the other money reads
/// because three screens ask for it (Dashboard, Credit, Settlements) and
/// they must never disagree about what the store is being charged.
///
/// UNGATED, like the endpoint: every account that may log in to a store may
/// be told what that store is being charged.
final feePromotionProvider =
    FutureProvider.autoDispose<MerchantFeePromotion>((ref) async {
  final promotion = await ref.watch(apiProvider).feePromotion();
  ref.cacheMoney('fee_promotion');
  return promotion;
});

/// The promotion AS A SCREEN SHOULD TREAT IT: the served answer, or
/// "nothing running" while the read is in flight, has failed, or came back
/// describing something this build does not understand.
///
/// Every surface reads THIS, never the AsyncValue — which is what makes
/// "nothing when no promo is active" and "nothing when the server got ahead
/// of us" the same code path, and why a failed read quietly prices at the
/// tier fee (the figure the server would have charged anyway) instead of
/// putting an error on a till screen about a discount.
final activeFeePromotionProvider = Provider.autoDispose<MerchantFeePromotion>(
  (ref) =>
      ref.watch(feePromotionProvider).valueOrNull ?? MerchantFeePromotion.none,
);

/// Balance + movements. Only watched behind `wallet.view` — the card is
/// hidden rather than left to fail, exactly as the web panel decides it.
final walletProvider =
    FutureProvider.autoDispose<MerchantWalletState>((ref) async {
  final wallet = await ref.watch(apiProvider).wallet();
  ref.cacheMoney('wallet');
  return wallet;
});

/// The settle-all preview: the whole board priced, the preset buckets, and
/// the PLAN §1 discount verdict. Reservation-free, so it is safe to hold
/// open. Screens gate the watch on `settlements.preview` + a non-empty
/// board; an empty board answers 422 (nothing to settle), which the
/// Settlements tab renders as the empty state, not an error.
final settleAllPreviewProvider =
    FutureProvider.autoDispose<SettlementPreviewData>((ref) async {
  final preview =
      await ref.watch(apiProvider).settlementPreview(settleAll: true);
  ref.cacheMoney('preview');
  return preview;
});

/// Which preset the Settlements tab has narrowed to: 'all' (the default,
/// which submits as the race-proof settle_all MODE) or a server preset key
/// whose ids come straight off the catalogue's buckets.
final settlementsPresetProvider =
    StateProvider.autoDispose<String>((_) => 'all');

/// The merchant's OWN pick from the transaction picker (MR11), or null
/// while a preset rules the board. Never derived: these are ids the
/// catalogue's rows carried, handed back untouched for the server to
/// re-price. An empty list is not a selection — the picker refuses to
/// apply one — so null and empty both mean "the preset decides".
final settlementCustomSelectionProvider =
    StateProvider.autoDispose<List<int>?>((_) => null);

/// The PRICED preview for the current selection. A hand-picked set re-prices
/// exactly those ids; 'all' is the catalogue itself; a preset re-prices the
/// server's own membership ids — the app never works out which rows a preset
/// contains, and never prices anything itself.
final pricedPreviewProvider =
    FutureProvider.autoDispose<SettlementPreviewData>((ref) async {
  final custom = ref.watch(settlementCustomSelectionProvider);
  if (custom != null && custom.isNotEmpty) {
    return ref.watch(apiProvider).settlementPreview(transactionIds: custom);
  }

  final preset = ref.watch(settlementsPresetProvider);
  if (preset == 'all') return ref.watch(settleAllPreviewProvider.future);

  final catalogue = await ref.watch(settleAllPreviewProvider.future);
  final ids = catalogue.buckets[preset]?.transactionIds ?? const <int>[];
  if (ids.isEmpty) return catalogue;

  return ref.watch(apiProvider).settlementPreview(transactionIds: ids);
});

/// Settlement history, first page (paginate(25) server-side).
final settlementsPageProvider =
    FutureProvider.autoDispose<SettlementPage>((ref) async {
  final page = await ref.watch(apiProvider).settlements();
  ref.cacheMoney('settlements');
  return page;
});

/// One batch with lines + payments.
final settlementDetailProvider = FutureProvider.autoDispose
    .family<MerchantSettlement, int>(
  (ref, id) => ref.watch(apiProvider).settlement(id),
);

/// Refresh everything a landed settlement changes: the board, the batch
/// history, the wallet balance and the dashboard blocks.
void invalidateMoney(WidgetRef ref) {
  // A landed settlement moves the board: a hand-picked set of ids from
  // before it can contain rows nobody may settle twice, so the pick is
  // dropped and the board reopens on everything outstanding.
  ref.invalidate(settlementCustomSelectionProvider);
  ref.invalidate(homeProvider);
  ref.invalidate(walletProvider);
  ref.invalidate(settleAllPreviewProvider);
  ref.invalidate(settlementsPageProvider);
  // A landed settlement is also the guided setup's "settle your first bill"
  // becoming true. Same rule as the credit path: the tasklist reads real
  // state, so the read is repeated when the state moves.
  refreshOnboardingGuideFrom(ref);
}
