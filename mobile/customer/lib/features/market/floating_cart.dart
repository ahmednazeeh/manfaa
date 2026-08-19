import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import 'market_providers.dart';

/// The floating cart bar (`Market View.png`).
///
/// Owner decision: a BAR, not a fifth tab. It rides above the nav on every
/// Market surface and disappears when the basket is empty — a permanent
/// empty cart is a permanent reminder of nothing.
///
/// It carries the three numbers a shopper is actually watching: what is in
/// the basket, what they will earn, and how far they are from the shop's
/// minimum.
class FloatingCartBar extends ConsumerWidget {
  const FloatingCartBar({super.key, this.branchId});

  /// When shown on ONE shop's page, the progress line tracks that shop's
  /// minimum rather than the whole basket's — which is the number the
  /// shopper standing in that shop can actually do something about.
  final int? branchId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider).valueOrNull;

    if (cart == null || cart.isEmpty) {
      return const SizedBox.shrink();
    }

    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final focus = branchId == null
        ? null
        : cart.subcarts.where((s) => s.branchId == branchId).firstOrNull;

    // On a shop's page, show that shop's basket; on the Market list, the lot.
    final itemCount = focus?.items.length ?? cart.itemCount;
    final amount = focus?.itemsLaari ?? cart.itemsLaari;
    final cashback = focus?.cashbackLaari ?? cart.cashbackLaari;

    if (itemCount == 0) {
      return const SizedBox.shrink();
    }

    return Material(
      color: theme.colorScheme.onSurface,
      borderRadius: BorderRadius.circular(Corner.card),
      elevation: 8,
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.card),
        onTap: () => context.push('/market/cart'),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: Gap.lg,
            vertical: Gap.md,
          ),
          child: Row(
            children: [
              _Basket(count: itemCount),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      '$itemCount · ${formatMoney(amount, dhivehi: dhivehi)}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: theme.colorScheme.surface,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (cashback > 0)
                      Text(
                        l10n.cartEarn(formatMoney(cashback, dhivehi: dhivehi)),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: ManfaaColors.green,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    if (focus != null) ...[
                      const SizedBox(height: Gap.xs),
                      _MinimumProgress(subcart: focus),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: Gap.md),
              FilledButton(
                onPressed: () => context.push('/market/cart'),
                style: FilledButton.styleFrom(
                  backgroundColor: ManfaaColors.coral,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(
                    horizontal: Gap.lg,
                    vertical: Gap.sm,
                  ),
                ),
                child: Text(l10n.viewCart),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The shop's minimum, as a line the shopper can close.
class _MinimumProgress extends StatelessWidget {
  const _MinimumProgress({required this.subcart});

  final Subcart subcart;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final minimum = subcart.delivery.orderMinimumLaari;

    if (minimum == null || minimum == 0) {
      return const SizedBox.shrink();
    }

    final met = subcart.delivery.minimumMet;
    final progress = (subcart.itemsLaari / minimum).clamp(0.0, 1.0);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          met
              ? l10n.minimumMet
              : l10n.toMinimum(
                  formatMoney(subcart.delivery.shortfallLaari, dhivehi: dhivehi),
                ),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodySmall?.copyWith(
            color: met ? ManfaaColors.green : ManfaaColors.amber,
          ),
        ),
        const SizedBox(height: 3),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            value: progress,
            minHeight: 4,
            backgroundColor: theme.colorScheme.surface.withValues(alpha: 0.25),
            valueColor: AlwaysStoppedAnimation(
              met ? ManfaaColors.green : ManfaaColors.amber,
            ),
          ),
        ),
      ],
    );
  }
}

class _Basket extends StatelessWidget {
  const _Basket({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Stack(
      clipBehavior: Clip.none,
      children: [
        Icon(Icons.shopping_cart_rounded,
            size: 26, color: theme.colorScheme.surface),
        Positioned(
          top: -6,
          right: -8,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: const BoxDecoration(
              color: ManfaaColors.coral,
              shape: BoxShape.circle,
            ),
            constraints: const BoxConstraints(minWidth: 17, minHeight: 17),
            child: Text(
              '$count',
              textAlign: TextAlign.center,
              style: theme.textTheme.labelSmall?.copyWith(
                color: Colors.white,
                fontWeight: FontWeight.w700,
                fontSize: 10,
              ),
            ),
          ),
        ),
      ],
    );
  }
}
