import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import 'floating_cart.dart';
import 'market_providers.dart';

/// One shop's shelves (`Market View.png`, `Market View Tablet.png`).
///
/// The header's chips — rating, ETA, delivery fee, minimum — are properties
/// of *this shop → your address*, not of the shop alone, which is why they
/// move when the address does.
class MarketStoreScreen extends ConsumerStatefulWidget {
  const MarketStoreScreen({super.key, required this.branchId});

  final int branchId;

  @override
  ConsumerState<MarketStoreScreen> createState() => _MarketStoreScreenState();
}

class _MarketStoreScreenState extends ConsumerState<MarketStoreScreen> {
  String? _category;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final store = ref.watch(
      marketStoreProvider((branchId: widget.branchId, category: _category)),
    );

    return Scaffold(
      appBar: AppBar(
        title: Text(store.valueOrNull?.storeName ?? l10n.tabMarket),
      ),
      body: SafeArea(
        top: false,
        child: Stack(
          children: [
            store.when(
              loading: () => ListView(
                padding: const EdgeInsets.all(Gap.lg),
                children: const [
                  SkeletonBox(height: 120, radius: Corner.card),
                  SizedBox(height: Gap.lg),
                  SkeletonBox(height: 200, radius: Corner.card),
                ],
              ),
              error: (error, _) => Center(
                child: Padding(
                  padding: const EdgeInsets.all(Gap.huge),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        error is MobileApiException
                            ? error.message
                            : l10n.errorGeneric,
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: Gap.lg),
                      OutlinedButton(
                        onPressed: () => ref.invalidate(marketStoreProvider),
                        child: Text(l10n.retry),
                      ),
                    ],
                  ),
                ),
              ),
              data: (data) => _Shelves(
                store: data,
                category: _category,
                onCategory: (slug) => setState(() => _category = slug),
              ),
            ),
            Positioned(
              left: Gap.lg,
              right: Gap.lg,
              bottom: Gap.md,
              // Scoped to THIS shop: the minimum a shopper standing in this
              // store can actually do something about.
              child: FloatingCartBar(branchId: widget.branchId),
            ),
          ],
        ),
      ),
    );
  }
}

class _Shelves extends StatelessWidget {
  const _Shelves({
    required this.store,
    required this.category,
    required this.onCategory,
  });

  final MarketStore store;
  final String? category;
  final void Function(String?) onCategory;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, 96),
      children: [
        _StoreHeader(store: store),
        if (store.categories.isNotEmpty) ...[
          const SizedBox(height: Gap.lg),
          SizedBox(
            height: 38,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                _CategoryChip(
                  label: l10n.allProducts,
                  selected: category == null,
                  onTap: () => onCategory(null),
                ),
                // Only the aisles this shop stocks — an empty chip is a
                // promise the shelf cannot keep.
                for (final aisle in store.categories)
                  _CategoryChip(
                    label: aisle.label(dhivehi),
                    selected: category == aisle.slug,
                    onTap: () => onCategory(aisle.slug),
                  ),
              ],
            ),
          ),
        ],
        const SizedBox(height: Gap.lg),
        if (store.products.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: Gap.huge),
            child: Center(
              child: Text(
                l10n.marketEmpty,
                style: theme.textTheme.titleMedium,
              ),
            ),
          )
        else
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
              // Two up on a phone, four on a tablet — the mockup's own
              // arithmetic, expressed as a maximum rather than a count.
              maxCrossAxisExtent: 210,
              mainAxisExtent: 236,
              crossAxisSpacing: Gap.md,
              mainAxisSpacing: Gap.md,
            ),
            itemCount: store.products.length,
            itemBuilder: (_, index) =>
                ProductCard(product: store.products[index]),
          ),
      ],
    );
  }
}

/// The store header: who they are, and what they will do for you.
class _StoreHeader extends StatelessWidget {
  const _StoreHeader({required this.store});

  final MarketStore store;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final delivery = store.delivery;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              IconTile(Icons.storefront_rounded,
                  tint: ManfaaTint.violet, size: 48, iconSize: 24),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(store.storeName, style: theme.textTheme.titleLarge),
                    Text(
                      store.branchName,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (store.address != null) ...[
            const SizedBox(height: Gap.sm),
            Text(store.address!,
                style: theme.textTheme.bodySmall?.copyWith(color: muted)),
          ],
          const SizedBox(height: Gap.md),
          Wrap(
            spacing: Gap.lg,
            runSpacing: Gap.xs,
            children: [
              if (store.rating != null)
                _HeaderFact(Icons.star_rounded, '${store.rating}'),
              if (delivery.etaLabel != null)
                _HeaderFact(Icons.schedule_rounded, delivery.etaLabel!),
              if (delivery.delivers)
                _HeaderFact(
                  Icons.delivery_dining_rounded,
                  delivery.feeLaari == 0
                      ? l10n.freeDelivery
                      : formatMoney(delivery.feeLaari, dhivehi: dhivehi),
                ),
              if (delivery.orderMinimumLaari != null)
                _HeaderFact(
                  Icons.shopping_basket_outlined,
                  l10n.minOrder(
                    formatMoney(delivery.orderMinimumLaari!, dhivehi: dhivehi),
                  ),
                ),
            ],
          ),
          // Said plainly rather than by omission: a shop that cannot reach
          // you is still a shop you can collect from.
          if (!delivery.delivers) ...[
            const SizedBox(height: Gap.md),
            Text(
              l10n.storeClosedForDelivery,
              style: theme.textTheme.bodySmall?.copyWith(color: ManfaaColors.amber),
            ),
          ],
          if (store.cashbackRatePercent != null) ...[
            const SizedBox(height: Gap.md),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(
                  horizontal: Gap.md, vertical: Gap.sm),
              decoration: BoxDecoration(
                color: ManfaaColors.greenSoft,
                borderRadius: BorderRadius.circular(Corner.tile),
              ),
              child: Text(
                l10n.cashbackPercent(store.cashbackRatePercent!),
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: ManfaaColors.green,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _HeaderFact extends StatelessWidget {
  const _HeaderFact(this.icon, this.label);

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16, color: muted),
        const SizedBox(width: 4),
        Text(label, style: theme.textTheme.bodySmall?.copyWith(color: muted)),
      ],
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsetsDirectional.only(end: Gap.sm),
      child: Material(
        color: selected ? ManfaaColors.violet : theme.colorScheme.surface,
        shape: StadiumBorder(
          side: BorderSide(
            color: selected
                ? ManfaaColors.violet
                : theme.colorScheme.outlineVariant,
          ),
        ),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
            child: Center(
              child: Text(
                label,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: selected ? Colors.white : theme.colorScheme.onSurface,
                  fontWeight: selected ? FontWeight.w600 : null,
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One product tile: picture, name, price, and the Add that becomes a
/// stepper once it is in the basket.
class ProductCard extends ConsumerWidget {
  const ProductCard({super.key, required this.product});

  final MarketProduct product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final qty = ref.watch(cartProvider.select(
      (_) => ref.read(cartProvider.notifier).qtyOf(product.branchProductId),
    ));

    return ManfaaCard(
      padding: const EdgeInsets.all(Gap.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Center(
              child: product.imageUrl == null
                  ? Icon(Icons.inventory_2_outlined,
                      size: 44, color: theme.colorScheme.outlineVariant)
                  : Image.network(
                      product.imageUrl!,
                      fit: BoxFit.contain,
                      errorBuilder: (_, _, _) => Icon(
                        Icons.inventory_2_outlined,
                        size: 44,
                        color: theme.colorScheme.outlineVariant,
                      ),
                    ),
            ),
          ),
          const SizedBox(height: Gap.sm),
          Text(
            product.displayName(dhivehi),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: Gap.xs),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              MoneyText(
                product.priceLaari,
                style: theme.textTheme.titleSmall
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              if (product.compareAtLaari != null) ...[
                const SizedBox(width: Gap.xs),
                Text(
                  formatMoney(product.compareAtLaari!, dhivehi: dhivehi),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                    decoration: TextDecoration.lineThrough,
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: Gap.sm),
          SizedBox(
            width: double.infinity,
            height: 34,
            child: !product.inStock
                ? OutlinedButton(
                    onPressed: null,
                    child: Text(l10n.outOfStock),
                  )
                : qty == 0
                    ? FilledButton(
                        onPressed: () => ref
                            .read(cartProvider.notifier)
                            .add(product.branchProductId),
                        style: FilledButton.styleFrom(
                          padding: EdgeInsets.zero,
                          backgroundColor: ManfaaColors.violet,
                        ),
                        child: Text(l10n.addToCart),
                      )
                    : _Stepper(product: product, qty: qty),
          ),
        ],
      ),
    );
  }
}

class _Stepper extends ConsumerWidget {
  const _Stepper({required this.product, required this.qty});

  final MarketProduct product;
  final int qty;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final cart = ref.read(cartProvider.notifier);
    final itemId = cart.cartItemIdOf(product.branchProductId);

    return Container(
      decoration: BoxDecoration(
        color: ManfaaColors.violetSoft,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          IconButton(
            visualDensity: VisualDensity.compact,
            onPressed: itemId == null
                ? null
                : () => cart.setQty(itemId, qty - 1),
            icon: const Icon(Icons.remove_rounded, size: 18),
            color: ManfaaColors.violet,
          ),
          Text(
            '$qty',
            style: theme.textTheme.titleSmall?.copyWith(
              color: ManfaaColors.violet,
              fontWeight: FontWeight.w700,
            ),
          ),
          IconButton(
            visualDensity: VisualDensity.compact,
            onPressed: itemId == null
                ? null
                : () => cart.setQty(itemId, qty + 1),
            icon: const Icon(Icons.add_rounded, size: 18),
            color: ManfaaColors.violet,
          ),
        ],
      ),
    );
  }
}
