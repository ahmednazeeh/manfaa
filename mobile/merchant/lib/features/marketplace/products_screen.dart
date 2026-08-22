import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'marketplace_providers.dart';
import 'marketplace_widgets.dart';

/// The shelf, drawn to `products.png` (PLAN-marketplace.md §4.2).
///
/// Search, four filters, two tiles, and a row per product with its picture,
/// shelf, price and stock. The three controls on the right are exactly the
/// three quick edits the app is for: price and stock behind the pencil,
/// visibility behind the eye.
///
/// Adding a product is deliberately NOT here. A new product needs pictures,
/// words, a shelf and a category, and every one of those is miserable on a
/// phone — so the banner sends people to the desktop portal rather than
/// offering a form that would be abandoned halfway.
class ShopProductsScreen extends ConsumerWidget {
  const ShopProductsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final products = ref.watch(shopProductsProvider);
    final tab = ref.watch(shopProductTabProvider);
    final query = ref.watch(shopProductQueryProvider).trim().toLowerCase();

    return Scaffold(
      appBar: AppBar(title: const Text('Products')),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(shopProductsProvider),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.huge),
          children: [
            Text(
              'Manage your marketplace products and availability.',
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: ManfaaColors.textMuted),
            ),
            const SizedBox(height: Gap.md),
            TextField(
              onChanged: (value) =>
                  ref.read(shopProductQueryProvider.notifier).state = value,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search_rounded),
                hintText: 'Search products by name or SKU',
              ),
            ),
            const SizedBox(height: Gap.md),
            SegmentedTabs(
              tabs: const [
                ('all', 'All'),
                ('active', 'Active'),
                ('draft', 'Draft'),
                ('out', 'Out of stock'),
              ],
              value: tab,
              onChanged: (next) =>
                  ref.read(shopProductTabProvider.notifier).state = next,
            ),
            const SizedBox(height: Gap.md),

            products.when(
              loading: () => const Column(
                children: [
                  SkeletonBox(height: 88),
                  SizedBox(height: Gap.md),
                  SkeletonBox(height: 88),
                ],
              ),
              error: (error, _) => ErrorNote(
                error: error,
                onRetry: () => ref.invalidate(shopProductsProvider),
              ),
              data: (rows) => _Shelf(rows: rows, tab: tab, query: query),
            ),

            const SizedBox(height: Gap.lg),
            const DesktopHint(
              title: 'Advanced catalogue work is best done on desktop.',
              body: 'Use mobile for quick updates to prices, stock, and '
                  'visibility.',
            ),
          ],
        ),
      ),
    );
  }
}

class _Shelf extends StatelessWidget {
  const _Shelf({required this.rows, required this.tab, required this.query});

  final List<ShopProduct> rows;
  final String tab;
  final String query;

  @override
  Widget build(BuildContext context) {
    final attention =
        rows.where((row) => row.isOutOfStock || row.isLowStock).length;

    final shown = rows.where((row) {
      if (query.isNotEmpty &&
          !row.name.toLowerCase().contains(query) &&
          !(row.sku ?? '').toLowerCase().contains(query)) {
        return false;
      }

      return switch (tab) {
        'active' => row.isActive,
        'draft' => row.isDraft,
        'out' => row.isOutOfStock || row.isLowStock,
        _ => true,
      };
    }).toList(growable: false);

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: StatTile(
                icon: Icons.inventory_2_outlined,
                label: 'Total products',
                value: '${rows.length}',
                caption: 'All time',
                tone: StatTone.calm,
              ),
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: StatTile(
                icon: Icons.error_outline_rounded,
                label: 'Low / out of stock',
                value: '$attention',
                caption: 'Needs attention',
                tone: StatTone.attention,
              ),
            ),
          ],
        ),
        const SizedBox(height: Gap.md),
        if (shown.isEmpty)
          const EmptyNote(
            icon: Icons.inventory_2_outlined,
            title: 'Nothing here',
            body: 'Products matching this filter will appear here.',
          )
        else
          for (final product in shown) ...[
            _ProductRow(product: product),
            const SizedBox(height: Gap.md),
          ],
      ],
    );
  }
}

class _ProductRow extends ConsumerWidget {
  const _ProductRow({required this.product});

  final ShopProduct product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final listing = product.primary;

    final (label, tone) = switch (product) {
      final p when p.isOutOfStock => ('Out of stock', StatusTone.closed),
      final p when p.isLowStock => ('Low stock', StatusTone.pending),
      final p when p.isDraft => ('Draft', StatusTone.pending),
      _ => ('Active', StatusTone.confirmed),
    };

    return ManfaaCard(
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(Corner.tile),
            child: SizedBox(
              width: 52,
              height: 52,
              child: product.imageUrl == null
                  ? Container(
                      color: ManfaaColors.stone100,
                      child: const Icon(Icons.inventory_2_outlined,
                          color: ManfaaColors.textFaint),
                    )
                  : Image.network(product.imageUrl!, fit: BoxFit.cover),
            ),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(product.name, style: theme.textTheme.titleSmall),
                if (product.shelf != null)
                  Text(
                    product.shelf!,
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: ManfaaColors.textMuted),
                  ),
                const SizedBox(height: 2),
                Text(
                  listing == null
                      ? 'Not on a shelf'
                      : '${formatRufiyaa(listing.priceLaari)}  ·  '
                          'Stock: ${listing.stockQty ?? '—'}',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(label: label, tone: tone),
              const SizedBox(height: Gap.xs),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconButton(
                    visualDensity: VisualDensity.compact,
                    onPressed: listing == null
                        ? null
                        : () => _edit(context, ref, product, listing),
                    icon: const Icon(Icons.edit_outlined, size: 18),
                  ),
                  IconButton(
                    visualDensity: VisualDensity.compact,
                    onPressed: listing == null
                        ? null
                        : () => _toggle(context, ref, product, listing),
                    icon: Icon(
                      listing != null && listing.buyable
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                      size: 18,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Price and stock — the two numbers that change on a shop floor.
  Future<void> _edit(
    BuildContext context,
    WidgetRef ref,
    ShopProduct product,
    ShopListing listing,
  ) async {
    final price = TextEditingController(
      text: (listing.priceLaari / 100).toStringAsFixed(2),
    );
    final stock = TextEditingController(text: '${listing.stockQty ?? ''}');

    final saved = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(product.name),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: price,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Price',
                prefixText: 'MVR ',
              ),
            ),
            const SizedBox(height: Gap.md),
            TextField(
              controller: stock,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Stock'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (saved != true || !context.mounted) return;

    // Parsed to integer laari, never carried as a double: money is exact and
    // a float is not.
    final laari = parseMvrToLaari(price.text);

    await _apply(
      context,
      ref,
      product.id,
      branchId: listing.branchId,
      priceLaari: laari,
      stockQty: int.tryParse(stock.text.trim()),
    );
  }

  /// On the shelf or off it — one tap, because that is the whole point of
  /// having this on a phone.
  Future<void> _toggle(
    BuildContext context,
    WidgetRef ref,
    ShopProduct product,
    ShopListing listing,
  ) =>
      _apply(
        context,
        ref,
        product.id,
        branchId: listing.branchId,
        state: listing.buyable ? 'hidden' : 'active',
      );

  Future<void> _apply(
    BuildContext context,
    WidgetRef ref,
    int productId, {
    required int branchId,
    int? priceLaari,
    int? stockQty,
    String? state,
  }) async {
    try {
      await ref.read(apiProvider).updateShopListing(
            productId,
            branchId: branchId,
            priceLaari: priceLaari,
            stockQty: stockQty,
            state: state,
          );
      ref.invalidate(shopProductsProvider);
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(messageFor(error))));
      }
    }
  }
}
