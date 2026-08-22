import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../../app/providers.dart';

/// Marketplace state (PLAN-marketplace.md §3).
///
/// The cart lives here and nowhere else: every mutation answers with the
/// WHOLE priced cart, so the floating bar, the store page's Add buttons and
/// the cart screen all read one object and cannot disagree after a tap.

/// Whether the platform has a marketplace at all.
///
/// Server-owned (§10). With it off there is no Market tab, no floating cart
/// and no order tracking — and the routes refuse independently, so this only
/// decides what is DRAWN.
final marketplaceEnabledProvider = Provider<bool>((ref) {
  final config = ref.watch(configProvider).valueOrNull;

  return config?.features['marketplace'] == true;
});

/// The address the shopper is buying for. Delivery terms are a property of
/// *branch → this address*, so it is an input to almost everything here.
final marketAddressProvider = StateProvider<int?>((_) => null);

final marketBranchesProvider = FutureProvider.autoDispose<List<MarketBranch>>((
  ref,
) {
  return ref
      .watch(apiProvider)
      .marketBranches(addressId: ref.watch(marketAddressProvider));
});

/// One shop's shelves, narrowed to an aisle.
final marketStoreProvider = FutureProvider.autoDispose
    .family<MarketStore, ({int branchId, String? category})>((ref, args) {
      return ref
          .watch(apiProvider)
          .marketStore(
            args.branchId,
            category: args.category,
            addressId: ref.watch(marketAddressProvider),
          );
    });

/// The priced cart.
///
/// Not autoDispose: the floating bar reads it on every Market surface, and
/// re-fetching the basket each time a screen is pushed would flicker the one
/// number a shopper is watching.
final cartProvider = AsyncNotifierProvider<CartNotifier, Cart>(
  CartNotifier.new,
);

class CartNotifier extends AsyncNotifier<Cart> {
  @override
  Future<Cart> build() async {
    final addressId = ref.watch(marketAddressProvider);

    return ref.read(apiProvider).cart(addressId: addressId);
  }

  /// Every mutation replaces the whole cart with the server's answer rather
  /// than patching a local copy — the server prices it, and a client that
  /// guessed would be a second opinion waiting to disagree with checkout.
  Future<void> add(int branchProductId, {int qty = 1}) =>
      _apply(() => ref.read(apiProvider).addToCart(branchProductId, qty: qty));

  Future<void> setQty(int cartItemId, int qty) =>
      _apply(() => ref.read(apiProvider).setCartQty(cartItemId, qty));

  Future<void> remove(int cartItemId) =>
      _apply(() => ref.read(apiProvider).removeFromCart(cartItemId));

  Future<void> clear() => _apply(() => ref.read(apiProvider).clearCart());

  Future<void> _apply(Future<Cart> Function() call) async {
    // Deliberately NOT setting a loading state: the bar and the steppers
    // stay on screen showing the last known truth while the call is in
    // flight. A basket that blanks on every tap is a basket nobody trusts.
    try {
      state = AsyncData(await call());
    } catch (error, stack) {
      state = AsyncError(error, stack);
      // Put the last good cart back so the screen is usable again.
      ref.invalidateSelf();
    }
  }

  /// How many of one listing are already in the basket — what the store
  /// page's stepper shows instead of an Add button.
  int qtyOf(int branchProductId) {
    final cart = state.valueOrNull;

    if (cart == null) return 0;

    for (final subcart in cart.subcarts) {
      for (final line in subcart.items) {
        if (line.branchProductId == branchProductId) return line.qty;
      }
    }

    return 0;
  }

  int? cartItemIdOf(int branchProductId) {
    final cart = state.valueOrNull;

    if (cart == null) return null;

    for (final subcart in cart.subcarts) {
      for (final line in subcart.items) {
        if (line.branchProductId == branchProductId) return line.cartItemId;
      }
    }

    return null;
  }
}
