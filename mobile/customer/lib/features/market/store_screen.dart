import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'floating_cart.dart';
import 'market_providers.dart';

/// One shop's shelves, drawn to `Market View.png`.
///
/// The ref's order is the shopper's order: search in THIS shop, the store's
/// terms (rating, how long, what delivery costs, what it earns), the aisles
/// it stocks, then the goods themselves two to a row. The basket bar rides
/// above it all, carrying the distance to this shop's minimum.
class MarketStoreScreen extends ConsumerStatefulWidget {
  const MarketStoreScreen({super.key, required this.branchId});

  final int branchId;

  @override
  ConsumerState<MarketStoreScreen> createState() => _MarketStoreScreenState();
}

class _MarketStoreScreenState extends ConsumerState<MarketStoreScreen> {
  String? _category;
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final store = ref.watch(
      marketStoreProvider((branchId: widget.branchId, category: _category)),
    );

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            _SearchBar(
              storeName: store.valueOrNull?.storeName,
              onChanged: (value) => setState(() => _query = value),
            ),
            Expanded(
              child: store.when(
                loading: () => const Center(child: CircularProgressIndicator()),
                error: (error, _) => Center(
                  child: Padding(
                    padding: const EdgeInsets.all(Gap.lg),
                    child: Text(
                      error is MobileApiException && error.message.isNotEmpty
                          ? error.message
                          : 'This shop could not be opened.',
                      textAlign: TextAlign.center,
                    ),
                  ),
                ),
                data: (data) => _Shelves(
                  store: data,
                  category: _category,
                  query: _query,
                  onCategory: (slug) => setState(() => _category = slug),
                ),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: FloatingCart(branchId: widget.branchId),
    );
  }
}

class _SearchBar extends ConsumerWidget {
  const _SearchBar({required this.storeName, required this.onChanged});

  final String? storeName;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider).valueOrNull;
    final count = cart?.subcarts.fold<int>(
          0,
          (sum, row) => sum + row.items.fold<int>(0, (n, i) => n + i.qty),
        ) ??
        0;

    return Padding(
      padding: const EdgeInsets.all(Gap.md),
      child: Row(
        children: [
          IconButton.filledTonal(
            onPressed: () => context.pop(),
            icon: const Icon(Icons.arrow_back_rounded),
          ),
          const SizedBox(width: Gap.sm),
          Expanded(
            child: TextField(
              onChanged: onChanged,
              decoration: InputDecoration(
                prefixIcon: const Icon(Icons.search_rounded),
                hintText: storeName == null
                    ? 'Search'
                    : 'Search in $storeName',
              ),
            ),
          ),
          const SizedBox(width: Gap.sm),
          Stack(
            clipBehavior: Clip.none,
            children: [
              IconButton.filledTonal(
                onPressed: () => context.push('/market/cart'),
                icon: const Icon(Icons.shopping_cart_outlined),
              ),
              if (count > 0)
                Positioned(
                  right: 0,
                  top: 0,
                  child: Container(
                    padding: const EdgeInsets.all(5),
                    decoration: const BoxDecoration(
                      color: ManfaaColors.coral,
                      shape: BoxShape.circle,
                    ),
                    child: Text(
                      '$count',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 10,
                        height: 1,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Shelves extends StatelessWidget {
  const _Shelves({
    required this.store,
    required this.category,
    required this.query,
    required this.onCategory,
  });

  final MarketStore store;
  final String? category;
  final String query;
  final ValueChanged<String?> onCategory;

  @override
  Widget build(BuildContext context) {
    final needle = query.trim().toLowerCase();

    final products = store.products
        .where((p) => needle.isEmpty || p.name.toLowerCase().contains(needle))
        .toList(growable: false);

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.md, 0, Gap.md, Gap.lg),
      children: [
        _StoreHeader(store: store),
        const SizedBox(height: Gap.md),

        if (store.categories.isNotEmpty)
          SizedBox(
            height: 40,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                _Chip(
                  label: 'All',
                  selected: category == null,
                  onTap: () => onCategory(null),
                ),
                for (final aisle in store.categories)
                  _Chip(
                    label: aisle.nameEn,
                    selected: category == aisle.slug,
                    onTap: () => onCategory(aisle.slug),
                  ),
              ],
            ),
          ),
        const SizedBox(height: Gap.md),

        if (products.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: Gap.huge),
            child: Center(
              child: Text(
                needle.isEmpty
                    ? 'This shop has nothing on its shelves yet.'
                    : 'Nothing here matches "$query".',
                textAlign: TextAlign.center,
                style: const TextStyle(color: ManfaaColors.textMuted),
              ),
            ),
          )
        else
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 2,
              mainAxisSpacing: Gap.md,
              crossAxisSpacing: Gap.md,
              childAspectRatio: 0.62,
            ),
            itemCount: products.length,
            itemBuilder: (_, index) => ProductCard(product: products[index]),
          ),
      ],
    );
  }
}

/// The shop's terms, in the order a shopper weighs them.
class _StoreHeader extends StatelessWidget {
  const _StoreHeader({required this.store});

  final MarketStore store;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final terms = store.delivery;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: ManfaaColors.greenSoft,
                child: Text(
                  store.storeName.isEmpty
                      ? '?'
                      : store.storeName.characters.first.toUpperCase(),
                  style: theme.textTheme.titleLarge,
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(store.storeName, style: theme.textTheme.titleLarge),
                    Text(
                      store.branchName,
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: ManfaaColors.textMuted),
                    ),
                    const SizedBox(height: Gap.sm),
                    Wrap(
                      spacing: Gap.md,
                      runSpacing: 4,
                      children: [
                        // A new shop shows NO rating rather than 0.0 — an
                        // invented score is worse than an absent one.
                        if (store.rating != null)
                          _Term(
                            icon: Icons.star_rounded,
                            tint: ManfaaColors.amber,
                            text: store.rating!.toStringAsFixed(1),
                          ),
                        if (terms.etaMin != null && terms.etaMax != null)
                          _Term(
                            icon: Icons.schedule_rounded,
                            text: '${terms.etaMin}–${terms.etaMax} min',
                          ),
                        if (terms.delivers)
                          _Term(
                            icon: Icons.delivery_dining_rounded,
                            text: terms.feeLaari == 0
                                ? 'Free delivery'
                                : formatRufiyaa(terms.feeLaari),
                          ),
                      ],
                    ),
                    if (terms.orderMinimumLaari != null)
                      Text(
                        'Min ${formatRufiyaa(terms.orderMinimumLaari!)}',
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: ManfaaColors.textMuted),
                      ),
                  ],
                ),
              ),
            ],
          ),
          if (store.cashbackRatePercent != null) ...[
            const SizedBox(height: Gap.md),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(
                horizontal: Gap.md,
                vertical: Gap.sm,
              ),
              decoration: BoxDecoration(
                color: ManfaaColors.greenSoft,
                borderRadius: BorderRadius.circular(Corner.tile),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.savings_outlined,
                      size: 18, color: ManfaaColors.green),
                  const SizedBox(width: Gap.sm),
                  Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '${store.cashbackRatePercent}% cashback',
                          style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            color: ManfaaColors.green,
                          ),
                        ),
                        const TextSpan(text: ' on all items'),
                      ],
                    ),
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _Term extends StatelessWidget {
  const _Term({required this.icon, required this.text, this.tint});

  final IconData icon;
  final String text;
  final Color? tint;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16, color: tint ?? ManfaaColors.textMuted),
        const SizedBox(width: 4),
        Text(text, style: Theme.of(context).textTheme.bodySmall),
      ],
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: Gap.sm),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
      ),
    );
  }
}

/// One item on the shelf: picture, heart, name, price, what it earns, Add.
class ProductCard extends ConsumerWidget {
  const ProductCard({super.key, required this.product});

  final MarketProduct product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final cart = ref.watch(cartProvider).valueOrNull;

    final line = cart?.subcarts
        .expand((row) => row.items)
        .where((item) => item.branchProductId == product.branchProductId)
        .firstOrNull;

    return ManfaaCard(
      padding: const EdgeInsets.all(Gap.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Stack(
              children: [
                Center(
                  child: product.imageUrl == null
                      ? const Icon(Icons.inventory_2_outlined,
                          size: 40, color: ManfaaColors.textFaint)
                      : Image.network(
                          product.imageUrl!,
                          fit: BoxFit.contain,
                          errorBuilder: (_, _, _) => const Icon(
                            Icons.inventory_2_outlined,
                            size: 40,
                            color: ManfaaColors.textFaint,
                          ),
                        ),
                ),
                Positioned(
                  right: 0,
                  top: 0,
                  child: Icon(
                    Icons.favorite_border_rounded,
                    size: 20,
                    color: ManfaaColors.textFaint,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: Gap.sm),
          Text(
            product.name,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 2),
          Text(
            formatRufiyaa(product.priceLaari),
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: Gap.sm),
          Row(
            children: [
              if (product.cashbackRatePercent != null)
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: Gap.sm,
                      vertical: 3,
                    ),
                    decoration: BoxDecoration(
                      color: ManfaaColors.greenSoft,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      '${product.cashbackRatePercent}% cashback',
                      style: const TextStyle(
                        fontSize: 11,
                        color: ManfaaColors.green,
                      ),
                    ),
                  ),
                )
              else
                const Spacer(),
              const SizedBox(width: Gap.sm),
              if (line == null)
                FilledButton(
                  style: FilledButton.styleFrom(
                    padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
                    minimumSize: const Size(0, 36),
                  ),
                  onPressed: product.inStock
                      ? () => ref
                          .read(cartProvider.notifier)
                          .add(product.branchProductId)
                      : null,
                  child: Text(product.inStock ? 'Add' : 'Out'),
                )
              else
                _Stepper(line: line),
            ],
          ),
        ],
      ),
    );
  }
}

class _Stepper extends ConsumerWidget {
  const _Stepper({required this.line});

  final CartLine line;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(cartProvider.notifier);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        InkWell(
          onTap: () => controller.setQty(line.cartItemId, line.qty - 1),
          child: const Padding(
            padding: EdgeInsets.all(4),
            child: Icon(Icons.remove_circle_outline, size: 22),
          ),
        ),
        Text('${line.qty}'),
        InkWell(
          onTap: () => controller.setQty(line.cartItemId, line.qty + 1),
          child: const Padding(
            padding: EdgeInsets.all(4),
            child: Icon(Icons.add_circle, size: 22),
          ),
        ),
      ],
    );
  }
}
