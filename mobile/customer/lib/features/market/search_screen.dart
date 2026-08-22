import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'market_providers.dart';

final searchTextProvider = StateProvider<String>((_) => '');
final searchSortProvider = StateProvider<String>((_) => 'relevance');

final searchResultsProvider = FutureProvider.autoDispose<SearchResults>((ref) {
  return ref
      .watch(apiProvider)
      .searchProducts(
        query: ref.watch(searchTextProvider),
        sort: ref.watch(searchSortProvider),
        addressId: ref.watch(marketAddressProvider),
      );
});

/// Product search across every shop (`AI Product Search.png`).
///
/// This is the marketplace's front door, replacing a directory of stores.
/// A shopper wants rice; which shop stocks it is our problem, not theirs —
/// so results are PRODUCTS, each carrying its shop, its price, what it
/// earns, and how fast it can arrive. Visiting a store is still one tap,
/// from the shop line on any result.
class MarketSearchView extends ConsumerStatefulWidget {
  const MarketSearchView({super.key});

  @override
  ConsumerState<MarketSearchView> createState() => _MarketSearchViewState();
}

class _MarketSearchViewState extends ConsumerState<MarketSearchView> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final results = ref.watch(searchResultsProvider);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.lg, Gap.sm),
          child: TextField(
            controller: _controller,
            textInputAction: TextInputAction.search,
            onSubmitted: (value) =>
                ref.read(searchTextProvider.notifier).state = value,
            decoration: InputDecoration(
              prefixIcon: const Icon(
                Icons.auto_awesome_rounded,
                color: ManfaaColors.green,
              ),
              hintText: 'Search every shop — try "rice under MVR 100"',
              suffixIcon: _controller.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.close_rounded),
                      onPressed: () {
                        _controller.clear();
                        ref.read(searchTextProvider.notifier).state = '';
                      },
                    ),
            ),
          ),
        ),

        Expanded(
          child: results.when(
            loading: () => const _Skeleton(),
            error: (error, _) => _Message(
              title: 'Search is not available',
              body: error is MobileApiException && error.message.isNotEmpty
                  ? error.message
                  : 'Try again in a moment.',
            ),
            data: (data) => ListView(
              padding: const EdgeInsets.fromLTRB(
                Gap.lg,
                0,
                Gap.lg,
                Gap.navClearance,
              ),
              children: [
                if (data.facets.isNotEmpty) _Understood(results: data),
                if (ref.watch(searchTextProvider).isEmpty) ...[
                  const SizedBox(height: Gap.sm),
                  _Suggestions(
                    onPick: (text) {
                      _controller.text = text;
                      ref.read(searchTextProvider.notifier).state = text;
                    },
                  ),
                ],
                const SizedBox(height: Gap.md),
                _SortRow(
                  value: ref.watch(searchSortProvider),
                  onChanged: (next) =>
                      ref.read(searchSortProvider.notifier).state = next,
                ),
                const SizedBox(height: Gap.md),
                if (data.hits.isEmpty)
                  _Message(
                    title: ref.watch(searchTextProvider).isEmpty
                        ? 'Search the whole marketplace'
                        : 'Nothing found',
                    body: ref.watch(searchTextProvider).isEmpty
                        ? 'Type a product, a brand or a size. Every open shop '
                              'is searched at once.'
                        : 'Try fewer words, or a different brand.',
                  )
                else
                  for (var i = 0; i < data.hits.length; i++) ...[
                    _Hit(hit: data.hits[i], rank: i + 1),
                    const SizedBox(height: Gap.md),
                  ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// What the search understood, read back. The chips are the parse, not
/// decoration — "Under MVR 100" appears because a ceiling was actually
/// applied.
class _Understood extends StatelessWidget {
  const _Understood({required this.results});

  final SearchResults results;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: ManfaaColors.greenSoft,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(
                Icons.auto_awesome_rounded,
                size: 18,
                color: ManfaaColors.green,
              ),
              const SizedBox(width: Gap.sm),
              Text('Smart search', style: theme.textTheme.titleSmall),
            ],
          ),
          if (results.summary.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(results.summary, style: theme.textTheme.bodySmall),
          ],
          const SizedBox(height: Gap.sm),
          Wrap(
            spacing: Gap.sm,
            runSpacing: 4,
            children: [
              for (final facet in results.facets)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: Gap.sm,
                    vertical: 3,
                  ),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    facet.label,
                    style: const TextStyle(fontSize: 12),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Suggestions extends StatelessWidget {
  const _Suggestions({required this.onPick});

  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: Gap.sm,
      children: [
        for (final text in const [
          'rice',
          'cooking oil under MVR 50',
          'eggs',
          'milk',
          'tea',
        ])
          ActionChip(label: Text(text), onPressed: () => onPick(text)),
      ],
    );
  }
}

class _SortRow extends StatelessWidget {
  const _SortRow({required this.value, required this.onChanged});

  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 38,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          for (final (key, label) in const [
            ('relevance', 'Best match'),
            ('price_asc', 'Price: low to high'),
            ('price_desc', 'Price: high to low'),
            ('rating', 'Top rated'),
            ('fastest', 'Fastest delivery'),
          ])
            Padding(
              padding: const EdgeInsets.only(right: Gap.sm),
              child: ChoiceChip(
                label: Text(label),
                selected: key == value,
                onSelected: (_) => onChanged(key),
              ),
            ),
        ],
      ),
    );
  }
}

/// One product, from whichever shop sells it.
class _Hit extends ConsumerWidget {
  const _Hit({required this.hit, required this.rank});

  final SearchHit hit;
  final int rank;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final cart = ref.watch(cartProvider).valueOrNull;

    final line = cart?.subcarts
        .expand((row) => row.items)
        .where((item) => item.branchProductId == hit.branchProductId)
        .firstOrNull;

    return ManfaaCard(
      // Compact: a search result is a row to scan, not a poster. Tapping it
      // opens the product, where the store block and Visit store live.
      padding: const EdgeInsets.all(Gap.sm),
      onTap: () => context.push('/market/product/${hit.branchProductId}'),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(Corner.tile),
            child: SizedBox(
              width: 56,
              height: 56,
              child: hit.imageUrl == null
                  ? const ColoredBox(
                      color: ManfaaColors.stone100,
                      child: Icon(
                        Icons.inventory_2_outlined,
                        color: ManfaaColors.textFaint,
                      ),
                    )
                  : Image.network(
                      hit.imageUrl!,
                      fit: BoxFit.cover,
                      errorBuilder: (_, _, _) => const ColoredBox(
                        color: ManfaaColors.stone100,
                        child: Icon(
                          Icons.inventory_2_outlined,
                          color: ManfaaColors.textFaint,
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
                  hit.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 2),

                // The shop, and the way into it — one tap, from here.
                InkWell(
                  onTap: () => context.push('/market/${hit.store.branchId}'),
                  child: Row(
                    children: [
                      ClipOval(
                        child: SizedBox(
                          width: 16,
                          height: 16,
                          child: hit.store.logoUrl == null
                              ? const ColoredBox(color: ManfaaColors.greenSoft)
                              : Image.network(
                                  hit.store.logoUrl!,
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, _, _) => const ColoredBox(
                                    color: ManfaaColors.greenSoft,
                                  ),
                                ),
                        ),
                      ),
                      const SizedBox(width: 4),
                      Flexible(
                        child: Text(
                          hit.store.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.primary,
                          ),
                        ),
                      ),
                      if (hit.store.rating != null) ...[
                        const SizedBox(width: 4),
                        const Icon(
                          Icons.star_rounded,
                          size: 12,
                          color: ManfaaColors.amber,
                        ),
                        Text(
                          hit.store.rating!.toStringAsFixed(1),
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                      const Icon(
                        Icons.chevron_right_rounded,
                        size: 14,
                        color: ManfaaColors.textFaint,
                      ),
                    ],
                  ),
                ),

                if (hit.delivery.etaMin != null && hit.delivery.etaMax != null)
                  Text(
                    '${hit.delivery.etaMin}–${hit.delivery.etaMax} min',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: ManfaaColors.textMuted,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: Gap.sm),
          SizedBox(
            width: 92,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (hit.cashbackRatePercent != null)
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 6,
                      vertical: 2,
                    ),
                    decoration: BoxDecoration(
                      color: ManfaaColors.violetSoft,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      '${hit.cashbackRatePercent}% cashback',
                      style: const TextStyle(
                        fontSize: 10,
                        color: ManfaaColors.violetDeep,
                      ),
                    ),
                  ),
                const SizedBox(height: 4),
                Text(
                  formatRufiyaa(hit.priceLaari),
                  maxLines: 1,
                  style: theme.textTheme.titleSmall,
                ),
                if (hit.discounted)
                  Text(
                    formatRufiyaa(hit.compareAtLaari!),
                    maxLines: 1,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: ManfaaColors.textFaint,
                      decoration: TextDecoration.lineThrough,
                    ),
                  ),
                const SizedBox(height: 6),
                SizedBox(
                  width: double.infinity,
                  height: 32,
                  child: line == null
                      ? FilledButton(
                          style: FilledButton.styleFrom(
                            padding: EdgeInsets.zero,
                            visualDensity: VisualDensity.compact,
                          ),
                          onPressed: hit.inStock
                              ? () => ref
                                    .read(cartProvider.notifier)
                                    .add(hit.branchProductId)
                              : null,
                          child: Text(hit.inStock ? 'Add' : 'Out of stock'),
                        )
                      : _Qty(line: line),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Qty extends ConsumerWidget {
  const _Qty({required this.line});

  final CartLine line;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(cartProvider.notifier);

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: ManfaaColors.line),
        borderRadius: BorderRadius.circular(Corner.control),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          IconButton(
            visualDensity: VisualDensity.compact,
            onPressed: () => controller.setQty(line.cartItemId, line.qty - 1),
            icon: const Icon(Icons.remove, size: 16),
          ),
          Text('${line.qty}'),
          IconButton(
            visualDensity: VisualDensity.compact,
            onPressed: () => controller.setQty(line.cartItemId, line.qty + 1),
            icon: const Icon(Icons.add, size: 16),
          ),
        ],
      ),
    );
  }
}

class _Skeleton extends StatelessWidget {
  const _Skeleton();

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(horizontal: Gap.lg),
      child: Column(
        children: [
          SkeletonBox(height: 96),
          SizedBox(height: Gap.md),
          SkeletonBox(height: 96),
          SizedBox(height: Gap.md),
          SkeletonBox(height: 96),
        ],
      ),
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.title, required this.body});

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.huge),
      child: Column(
        children: [
          const Icon(
            Icons.search_rounded,
            size: 40,
            color: ManfaaColors.textFaint,
          ),
          const SizedBox(height: Gap.md),
          Text(title, style: theme.textTheme.titleMedium),
          const SizedBox(height: 2),
          Text(
            body,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(
              color: ManfaaColors.textMuted,
            ),
          ),
        ],
      ),
    );
  }
}
