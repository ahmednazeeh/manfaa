import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../../app/providers.dart';

/// The shop's marketplace state — `active` is the only value that means it
/// sells online (PLAN-marketplace.md §4.1).
///
/// Read once and cached: the Orders tab and the Products entry both hang off
/// it, and a store's enrolment does not change while somebody is looking at
/// a screen.
final shopEnrolmentProvider = FutureProvider<String>((ref) {
  return ref.watch(apiProvider).marketplaceState();
});

/// Whether this store is an approved vendor.
///
/// Reads the VALUE only. "We could not ask" is not "you are not enrolled",
/// and conflating them told an approved vendor its store does not sell —
/// which is what happened when the permission behind this call was granted
/// after the app had already asked once and cached the refusal.
/// [shopEnrolmentUnknown] carries that third state.
final sellsOnMarketplaceProvider = Provider<bool>((ref) {
  return ref.watch(shopEnrolmentProvider).valueOrNull == 'active';
});

/// True when the question has not been answered — still loading, or the
/// answer failed. Screens must say "could not check" here, never "not
/// enrolled".
final shopEnrolmentUnknownProvider = Provider<bool>((ref) {
  final state = ref.watch(shopEnrolmentProvider);

  return state.isLoading || state.hasError;
});

/// Which tab of the queue is showing: new / preparing / ready / completed.
final shopOrderTabProvider = StateProvider<String>((_) => 'new');

/// Free-text filter over the visible queue — "#, customer, or store" as the
/// ref's search field promises. Applied CLIENT-side over the tab already
/// fetched: the queue is a working set of a few dozen, and a round trip per
/// keystroke would be slower than the list is long.
final shopOrderQueryProvider = StateProvider<String>((_) => '');

final shopOrdersProvider = FutureProvider.autoDispose<ShopOrderPage>((ref) {
  return ref
      .watch(apiProvider)
      .shopOrders(tab: ref.watch(shopOrderTabProvider));
});

final shopOrderProvider =
    FutureProvider.autoDispose.family<ShopOrder, int>((ref, id) {
  return ref.watch(apiProvider).shopOrder(id);
});

final shopProductsProvider =
    FutureProvider.autoDispose<List<ShopProduct>>((ref) {
  return ref.watch(apiProvider).shopProducts();
});

/// Which product filter the shelf is showing: all / active / draft / out.
final shopProductTabProvider = StateProvider<String>((_) => 'all');

final shopProductQueryProvider = StateProvider<String>((_) => '');
