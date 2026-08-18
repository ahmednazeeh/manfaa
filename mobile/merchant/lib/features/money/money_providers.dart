import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../../app/providers.dart';

/// The MR3 money providers — one place, because the Dashboard and the
/// Settlements tab read the SAME server answers and must never disagree.

/// The full /merchant/home answer: today's tally always; outstanding + the
/// open batch only for accounts holding `settlements.view` (null otherwise —
/// the server withholds, the UI simply has nothing to draw).
final homeProvider = FutureProvider.autoDispose<MerchantHome>(
  (ref) => ref.watch(apiProvider).home(),
);

/// Balance + movements. Only watched behind `wallet.view` — the card is
/// hidden rather than left to fail, exactly as the web panel decides it.
final walletProvider = FutureProvider.autoDispose<MerchantWalletState>(
  (ref) => ref.watch(apiProvider).wallet(),
);

/// The settle-all preview: the whole board priced, the preset buckets, and
/// the PLAN §1 discount verdict. Reservation-free, so it is safe to hold
/// open. Screens gate the watch on `settlements.preview` + a non-empty
/// board; an empty board answers 422 (nothing to settle), which the
/// Settlements tab renders as the empty state, not an error.
final settleAllPreviewProvider =
    FutureProvider.autoDispose<SettlementPreviewData>(
  (ref) => ref.watch(apiProvider).settlementPreview(settleAll: true),
);

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
final settlementsPageProvider = FutureProvider.autoDispose<SettlementPage>(
  (ref) => ref.watch(apiProvider).settlements(),
);

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
}
