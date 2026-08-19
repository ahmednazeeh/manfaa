import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'market_providers.dart';

/// The floating basket bar (`Market View.png`).
///
/// A dark slab that rides above the nav bar and answers three questions
/// without a tap: what is in the basket, what it earns, and — when a shop
/// sets a minimum — how far off it is. That last part is the whole reason
/// the bar carries a progress track: "MVR 66 to minimum" is actionable in a
/// way a bare total is not.
///
/// Hidden entirely when the basket is empty. A control that does nothing is
/// worse than no control.
class FloatingCart extends ConsumerWidget {
  const FloatingCart({super.key, this.branchId});

  /// When set, the bar speaks for ONE shop's subcart — its items, its
  /// earnings, its minimum. On the market list it speaks for the whole
  /// basket instead.
  final int? branchId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider).valueOrNull;

    if (cart == null || cart.subcarts.isEmpty) return const SizedBox.shrink();

    final subcart = branchId == null
        ? null
        : cart.subcarts.where((row) => row.branchId == branchId).firstOrNull;

    // On a store page with nothing from THIS store, the bar has nothing to
    // say about it.
    if (branchId != null && subcart == null) return const SizedBox.shrink();

    final count = subcart == null
        ? cart.subcarts.fold<int>(
            0,
            (sum, row) => sum + row.items.fold<int>(0, (n, i) => n + i.qty),
          )
        : subcart.items.fold<int>(0, (n, i) => n + i.qty);

    final total = subcart?.itemsLaari ?? cart.itemsLaari;
    final earn = subcart?.cashbackLaari ?? cart.cashbackLaari;
    final terms = subcart?.delivery;

    return Padding(
      padding: const EdgeInsets.fromLTRB(Gap.md, 0, Gap.md, Gap.md),
      child: Material(
        color: ManfaaColors.ink,
        borderRadius: BorderRadius.circular(Corner.card),
        child: InkWell(
          borderRadius: BorderRadius.circular(Corner.card),
          onTap: () => context.push('/market/cart'),
          child: Padding(
            padding: const EdgeInsets.all(Gap.md),
            child: Row(
              children: [
                _Badge(count: count),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        '$count ${count == 1 ? 'item' : 'items'} · '
                        '${formatRufiyaa(total)}',
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      Text(
                        'Earn ${formatRufiyaa(earn)}',
                        style: const TextStyle(
                          color: ManfaaColors.green,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
                // Dropped on a narrow screen rather than overflowing: the
                // basket total and the way to the cart matter more than the
                // progress track, and a RenderFlex overflow helps nobody.
                if (terms != null &&
                    !terms.minimumMet &&
                    terms.shortfallLaari > 0 &&
                    MediaQuery.sizeOf(context).width >= 380)
                  _Shortfall(terms: terms, total: total),
                const SizedBox(width: Gap.sm),
                FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor: ManfaaColors.coral,
                    padding: const EdgeInsets.symmetric(
                      horizontal: Gap.md,
                      vertical: Gap.md,
                    ),
                  ),
                  onPressed: () => context.push('/market/cart'),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('View Cart'),
                      SizedBox(width: 4),
                      Icon(Icons.chevron_right_rounded, size: 18),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        const Icon(Icons.shopping_cart_outlined, color: Colors.white, size: 28),
        Positioned(
          right: -6,
          top: -6,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: const BoxDecoration(
              color: ManfaaColors.coral,
              shape: BoxShape.circle,
            ),
            child: Text(
              '$count',
              style: const TextStyle(color: Colors.white, fontSize: 11),
            ),
          ),
        ),
      ],
    );
  }
}

/// How far from this shop's free-delivery minimum, with a track that fills.
class _Shortfall extends StatelessWidget {
  const _Shortfall({required this.terms, required this.total});

  final DeliveryTerms terms;
  final int total;

  @override
  Widget build(BuildContext context) {
    final minimum = terms.orderMinimumLaari ?? 0;
    final progress = minimum == 0 ? 1.0 : (total / minimum).clamp(0.0, 1.0);

    return SizedBox(
      width: 120,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${formatRufiyaa(terms.shortfallLaari)} to minimum',
            style: const TextStyle(color: Colors.white70, fontSize: 12),
          ),
          const SizedBox(height: 4),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 5,
              backgroundColor: Colors.white24,
              valueColor: const AlwaysStoppedAnimation(ManfaaColors.green),
            ),
          ),
        ],
      ),
    );
  }
}
