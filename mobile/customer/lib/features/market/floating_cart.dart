import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'market_providers.dart';

/// The floating basket bar (`Market View.png`).
///
/// A dark slab above the nav bar answering three questions without a tap:
/// what is in the basket, what it earns, and how far off a shop's minimum
/// it is.
///
/// LAYOUT NOTE, learned the hard way. This was one row — badge, text,
/// progress track, button — and on a narrow phone the fixed-width children
/// took everything, leaving the text an [Expanded] a few pixels wide that
/// wrapped ONE LETTER PER LINE. The progress track now lives on its own
/// line beneath, so the text column always has the row to itself minus a
/// badge and a button. [FloatingCartBar] is split out from the provider
/// wiring precisely so this can be measured in a test rather than eyeballed.
class FloatingCart extends ConsumerWidget {
  const FloatingCart({super.key, this.branchId});

  /// When set, the bar speaks for ONE shop's subcart. On the market list it
  /// speaks for the whole basket.
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

    return FloatingCartBar(
      count: count,
      totalLaari: subcart?.itemsLaari ?? cart.itemsLaari,
      earnLaari: subcart?.cashbackLaari ?? cart.cashbackLaari,
      terms: subcart?.delivery,
      onTap: () => context.push('/market/cart'),
    );
  }
}

/// The bar itself, free of providers so its layout can be tested directly.
class FloatingCartBar extends StatelessWidget {
  const FloatingCartBar({
    super.key,
    required this.count,
    required this.totalLaari,
    required this.earnLaari,
    required this.onTap,
    this.terms,
  });

  final int count;
  final int totalLaari;
  final int earnLaari;
  final DeliveryTerms? terms;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final short = terms != null &&
        !terms!.minimumMet &&
        terms!.shortfallLaari > 0 &&
        (terms!.orderMinimumLaari ?? 0) > 0;

    return Padding(
      padding: const EdgeInsets.fromLTRB(Gap.md, 0, Gap.md, Gap.md),
      child: Material(
        color: ManfaaColors.ink,
        borderRadius: BorderRadius.circular(Corner.card),
        child: InkWell(
          borderRadius: BorderRadius.circular(Corner.card),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(Gap.md),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    _Badge(count: count),
                    const SizedBox(width: Gap.md),
                    // The ONLY flexible child on this row, so it can never
                    // be squeezed by a sibling that sizes itself.
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            '$count ${count == 1 ? 'item' : 'items'} · '
                            '${formatRufiyaa(totalLaari)}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            'Earn ${formatRufiyaa(earnLaari)}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: ManfaaColors.green,
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: Gap.sm),
                    // FIXED width, for two reasons. A Row measures its
                    // non-flexible children with an UNBOUNDED main axis, and
                    // a button whose maximumSize is infinite passes that
                    // straight down to its Material, which asserts. And a
                    // fixed button makes the text column's share of the row
                    // deterministic instead of whatever is left over.
                    SizedBox(
                      width: 104,
                      child: FilledButton(
                        style: FilledButton.styleFrom(
                          backgroundColor: ManfaaColors.coral,
                          foregroundColor: Colors.white,
                          padding: EdgeInsets.zero,
                          visualDensity: VisualDensity.compact,
                        ),
                        onPressed: onTap,
                        child: const Text(
                          'View Cart',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ),
                  ],
                ),

                // Its own line, full width. As a sibling on the row above it
                // was what starved the text.
                if (short) ...[
                  const SizedBox(height: Gap.sm),
                  _Shortfall(terms: terms!, total: totalLaari),
                ],
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
        const Icon(Icons.shopping_cart_outlined, color: Colors.white, size: 26),
        Positioned(
          right: -4,
          top: -4,
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

/// How far from this shop's minimum, with a track that fills.
class _Shortfall extends StatelessWidget {
  const _Shortfall({required this.terms, required this.total});

  final DeliveryTerms terms;
  final int total;

  @override
  Widget build(BuildContext context) {
    final minimum = terms.orderMinimumLaari ?? 0;
    final progress = minimum == 0 ? 1.0 : (total / minimum).clamp(0.0, 1.0);

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '${formatRufiyaa(terms.shortfallLaari)} to minimum',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
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
    );
  }
}
