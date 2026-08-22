import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'market_providers.dart';

final productProvider = FutureProvider.autoDispose.family<ProductDetail, int>((
  ref,
  id,
) {
  return ref.watch(apiProvider).product(id);
});

/// One product, opened on its own.
///
/// The store block is as much the point as the goods: deciding to buy is
/// also deciding who to buy from, so the shop's name, rating, delivery terms
/// and a **Visit store** button live here rather than being a separate hunt.
class ProductScreen extends ConsumerWidget {
  const ProductScreen({super.key, required this.branchProductId});

  final int branchProductId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(productProvider(branchProductId));

    return Scaffold(
      appBar: AppBar(title: const Text('Product')),
      body: detail.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(Gap.lg),
            child: Text(
              error is MobileApiException && error.message.isNotEmpty
                  ? error.message
                  : 'This product could not be opened.',
              textAlign: TextAlign.center,
            ),
          ),
        ),
        data: (data) => _Body(detail: data),
      ),
      bottomNavigationBar: detail.maybeWhen(
        data: (data) => _AddBar(detail: data),
        orElse: () => null,
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.detail});

  final ProductDetail detail;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hit = detail.hit;

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
      children: [
        AspectRatio(
          aspectRatio: 1.4,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(Corner.card),
            child: detail.images.isEmpty
                ? const ColoredBox(
                    color: ManfaaColors.stone100,
                    child: Icon(
                      Icons.inventory_2_outlined,
                      size: 56,
                      color: ManfaaColors.textFaint,
                    ),
                  )
                : PageView(
                    children: [
                      for (final url in detail.images)
                        Image.network(
                          url,
                          fit: BoxFit.contain,
                          errorBuilder: (_, _, _) => const ColoredBox(
                            color: ManfaaColors.stone100,
                            child: Icon(
                              Icons.inventory_2_outlined,
                              size: 56,
                              color: ManfaaColors.textFaint,
                            ),
                          ),
                        ),
                    ],
                  ),
          ),
        ),
        const SizedBox(height: Gap.lg),

        Text(hit.name, style: theme.textTheme.headlineSmall),
        if (hit.nameDv != null && hit.nameDv!.isNotEmpty)
          Text(
            hit.nameDv!,
            textDirection: TextDirection.rtl,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: ManfaaColors.textMuted,
            ),
          ),
        const SizedBox(height: Gap.sm),

        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              formatRufiyaa(hit.priceLaari),
              style: theme.textTheme.headlineSmall,
            ),
            if (hit.discounted) ...[
              const SizedBox(width: Gap.sm),
              Text(
                formatRufiyaa(hit.compareAtLaari!),
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: ManfaaColors.textFaint,
                  decoration: TextDecoration.lineThrough,
                ),
              ),
            ],
            const Spacer(),
            if (hit.cashbackRatePercent != null)
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: Gap.sm,
                  vertical: 3,
                ),
                decoration: BoxDecoration(
                  color: ManfaaColors.violetSoft,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  '${hit.cashbackRatePercent}% cashback',
                  style: const TextStyle(
                    fontSize: 12,
                    color: ManfaaColors.violetDeep,
                  ),
                ),
              ),
          ],
        ),

        if (!hit.inStock)
          Padding(
            padding: const EdgeInsets.only(top: Gap.sm),
            child: Text(
              'Out of stock at this shop',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: ManfaaColors.coralDeep,
              ),
            ),
          )
        else if (detail.lowStock)
          Padding(
            padding: const EdgeInsets.only(top: Gap.sm),
            child: Text(
              'Only ${detail.stockQty} left',
              style: theme.textTheme.bodySmall?.copyWith(
                color: ManfaaColors.amber,
              ),
            ),
          ),

        if (detail.description != null && detail.description!.isNotEmpty) ...[
          const SizedBox(height: Gap.lg),
          Text(detail.description!, style: theme.textTheme.bodyMedium),
        ],

        const SizedBox(height: Gap.lg),
        _StoreBlock(hit: hit),

        const SizedBox(height: Gap.md),
        Text(
          detail.allowSubstitutions
              ? 'If this is off the shelf the shop may substitute something '
                    'similar, and you are told before it ships.'
              : 'No substitutions — if it is off the shelf the shop removes '
                    'it and refunds you the difference.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: ManfaaColors.textMuted,
          ),
        ),
      ],
    );
  }
}

/// Who you are buying from, and the way into their shop.
class _StoreBlock extends StatelessWidget {
  const _StoreBlock({required this.hit});

  final SearchHit hit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final store = hit.store;
    final terms = hit.delivery;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(Corner.tile),
                child: SizedBox(
                  width: 44,
                  height: 44,
                  child: store.logoUrl == null
                      ? const ColoredBox(
                          color: ManfaaColors.greenSoft,
                          child: Icon(
                            Icons.storefront_rounded,
                            color: ManfaaColors.green,
                          ),
                        )
                      : Image.network(
                          store.logoUrl!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) => const ColoredBox(
                            color: ManfaaColors.greenSoft,
                            child: Icon(
                              Icons.storefront_rounded,
                              color: ManfaaColors.green,
                            ),
                          ),
                        ),
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      store.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                    Row(
                      children: [
                        if (store.rating != null) ...[
                          const Icon(
                            Icons.star_rounded,
                            size: 13,
                            color: ManfaaColors.amber,
                          ),
                          Text(
                            '${store.rating!.toStringAsFixed(1)}'
                            '  (${store.ratingCount})   ',
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                        if (terms.etaMin != null && terms.etaMax != null)
                          Text(
                            '${terms.etaMin}–${terms.etaMax} min',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: ManfaaColors.textMuted,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: () => context.push('/market/${store.branchId}'),
              icon: const Icon(Icons.storefront_outlined, size: 18),
              label: const Text('Visit store'),
            ),
          ),
        ],
      ),
    );
  }
}

class _AddBar extends ConsumerWidget {
  const _AddBar({required this.detail});

  final ProductDetail detail;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final hit = detail.hit;
    final cart = ref.watch(cartProvider).valueOrNull;

    final line = cart?.subcarts
        .expand((row) => row.items)
        .where((item) => item.branchProductId == hit.branchProductId)
        .firstOrNull;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(Gap.lg),
        child: SizedBox(
          width: double.infinity,
          child: line == null
              ? FilledButton.icon(
                  onPressed: hit.inStock
                      ? () => ref
                            .read(cartProvider.notifier)
                            .add(hit.branchProductId)
                      : null,
                  icon: const Icon(Icons.add_shopping_cart_rounded, size: 18),
                  label: Text(
                    hit.inStock
                        ? 'Add · ${formatRufiyaa(hit.priceLaari)}'
                        : 'Out of stock',
                  ),
                )
              : FilledButton.icon(
                  onPressed: () => context.push('/market/cart'),
                  icon: const Icon(Icons.shopping_cart_rounded, size: 18),
                  label: Text('${line.qty} in basket · View cart'),
                ),
        ),
      ),
    );
  }
}
