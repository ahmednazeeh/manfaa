import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'floating_cart.dart';
import 'market_providers.dart';
import 'search_screen.dart';

final marketQueryProvider = StateProvider<String>((_) => '');

/// Which slice of the market is showing: everything, or only shops that
/// deliver to the chosen address, or only the ones this shopper hearted.
final marketFilterProvider = StateProvider<String>((_) => 'all');

/// Which half of the Market is showing.
final marketTabProvider = StateProvider<int>((_) => 0);

/// The Market.
///
/// SEARCH FIRST. It was a directory of shops, which made the customer do the
/// work of knowing who sells what — a shopper wants rice, and which shop
/// stocks it is our problem, not theirs. Products across every store are the
/// front door now; the shop list is still here, one tab over, for somebody
/// who wants to browse a particular store.
class MarketScreen extends ConsumerWidget {
  const MarketScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tab = ref.watch(marketTabProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, 0),
              child: SegmentedButton<int>(
                segments: const [
                  ButtonSegment(
                    value: 0,
                    label: Text('Search products'),
                    icon: Icon(Icons.search_rounded, size: 18),
                  ),
                  ButtonSegment(
                    value: 1,
                    label: Text('Shops'),
                    icon: Icon(Icons.storefront_outlined, size: 18),
                  ),
                ],
                selected: {tab},
                onSelectionChanged: (next) =>
                    ref.read(marketTabProvider.notifier).state = next.first,
              ),
            ),
            Expanded(
              child: tab == 0
                  ? const MarketSearchView()
                  : const _ShopDirectory(),
            ),
          ],
        ),
      ),
      bottomNavigationBar: const FloatingCart(),
    );
  }
}

/// The shop list, for browsing a particular store rather than hunting a
/// product.
class _ShopDirectory extends ConsumerWidget {
  const _ShopDirectory();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final branches = ref.watch(marketBranchesProvider);
    final query = ref.watch(marketQueryProvider).trim().toLowerCase();
    final filter = ref.watch(marketFilterProvider);

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(marketBranchesProvider),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(
          Gap.lg,
          Gap.md,
          Gap.lg,
          Gap.navClearance,
        ),
        children: [
          TextField(
            onChanged: (value) =>
                ref.read(marketQueryProvider.notifier).state = value,
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search_rounded),
              hintText: 'Search shops',
            ),
          ),
          const SizedBox(height: Gap.md),

          _Filters(
            value: filter,
            onChanged: (next) =>
                ref.read(marketFilterProvider.notifier).state = next,
          ),
          const SizedBox(height: Gap.md),

          branches.when(
            loading: () => const Column(
              children: [
                SkeletonBox(height: 132),
                SizedBox(height: Gap.md),
                SkeletonBox(height: 132),
              ],
            ),
            error: (error, _) => _Empty(
              icon: Icons.storefront_outlined,
              title: 'The market is not open',
              body: error is MobileApiException && error.message.isNotEmpty
                  ? error.message
                  : 'Try again in a moment.',
            ),
            data: (rows) =>
                _Shops(branches: rows, query: query, filter: filter),
          ),
        ],
      ),
    );
  }
}

class _Shops extends StatelessWidget {
  const _Shops({
    required this.branches,
    required this.query,
    required this.filter,
  });

  final List<MarketBranch> branches;
  final String query;
  final String filter;

  @override
  Widget build(BuildContext context) {
    final shown = branches
        .where((branch) {
          final matches =
              query.isEmpty ||
              branch.storeName.toLowerCase().contains(query) ||
              branch.branchName.toLowerCase().contains(query);

          return matches &&
              switch (filter) {
                'delivers' => branch.delivery.delivers,
                'favourites' => branch.favourite,
                _ => true,
              };
        })
        .toList(growable: false);

    if (shown.isEmpty) {
      return _Empty(
        icon: Icons.storefront_outlined,
        title: query.isEmpty ? 'No shops yet' : 'Nothing matches "$query"',
        body: query.isEmpty
            ? 'Stores are being added. Check back soon.'
            : 'Try a different name.',
      );
    }

    return Column(
      children: [
        for (final branch in shown) ...[
          _ShopCard(branch: branch),
          const SizedBox(height: Gap.md),
        ],
      ],
    );
  }
}

class _Filters extends StatelessWidget {
  const _Filters({required this.value, required this.onChanged});

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
            ('all', 'All shops'),
            ('delivers', 'Delivers to me'),
            ('favourites', 'Favourites'),
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

/// One shop, with the four things a shopper actually weighs.
class _ShopCard extends ConsumerWidget {
  const _ShopCard({required this.branch});

  final MarketBranch branch;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final terms = branch.delivery;

    return ManfaaCard(
      onTap: () => context.push('/market/${branch.branchId}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(Corner.tile),
                child: SizedBox(
                  width: 52,
                  height: 52,
                  child: branch.logoUrl == null
                      ? _Mark(name: branch.storeName)
                      : Image.network(
                          branch.logoUrl!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) =>
                              _Mark(name: branch.storeName),
                        ),
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      branch.storeName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium,
                    ),
                    Text(
                      branch.branchName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: ManfaaColors.textMuted,
                      ),
                    ),
                    if (branch.rating != null)
                      Row(
                        children: [
                          const Icon(
                            Icons.star_rounded,
                            size: 14,
                            color: ManfaaColors.amber,
                          ),
                          const SizedBox(width: 2),
                          Text(
                            '${branch.rating!.toStringAsFixed(1)}'
                            '  (${branch.ratingCount})',
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                  ],
                ),
              ),
              const SizedBox(width: Gap.sm),
              _Heart(branch: branch),
            ],
          ),

          if (branch.cashbackRatePercent != null) ...[
            const SizedBox(height: Gap.sm),
            Container(
              padding: const EdgeInsets.symmetric(
                horizontal: Gap.sm,
                vertical: 3,
              ),
              decoration: BoxDecoration(
                color: ManfaaColors.greenSoft,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                '${branch.cashbackRatePercent}% cashback',
                style: const TextStyle(fontSize: 12, color: ManfaaColors.green),
              ),
            ),
          ],

          const SizedBox(height: Gap.sm),
          Wrap(
            spacing: Gap.md,
            runSpacing: 4,
            children: [
              if (!terms.delivers)
                const _Term(
                  icon: Icons.storefront_outlined,
                  text: 'Pickup only',
                )
              else ...[
                _Term(
                  icon: Icons.delivery_dining_rounded,
                  text: terms.feeLaari == 0
                      ? 'Free delivery'
                      : formatRufiyaa(terms.feeLaari),
                ),
                if (terms.etaMin != null && terms.etaMax != null)
                  _Term(
                    icon: Icons.schedule_rounded,
                    text: '${terms.etaMin}–${terms.etaMax} min',
                  ),
                if (terms.orderMinimumLaari != null)
                  _Term(
                    icon: Icons.shopping_basket_outlined,
                    text: 'Min ${formatRufiyaa(terms.orderMinimumLaari!)}',
                  ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

/// The heart, which now does something.
class _Heart extends ConsumerStatefulWidget {
  const _Heart({required this.branch});

  final MarketBranch branch;

  @override
  ConsumerState<_Heart> createState() => _HeartState();
}

class _HeartState extends ConsumerState<_Heart> {
  bool? _local;
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final on = _local ?? widget.branch.favourite;

    return IconButton(
      visualDensity: VisualDensity.compact,
      onPressed: _busy ? null : _toggle,
      icon: Icon(
        on ? Icons.favorite_rounded : Icons.favorite_border_rounded,
        color: on ? ManfaaColors.coral : ManfaaColors.textFaint,
      ),
    );
  }

  Future<void> _toggle() async {
    // Flipped locally first: a heart that waits on a round trip feels
    // broken, and the worst case is one card out of step until the next
    // refresh.
    final next = !(_local ?? widget.branch.favourite);
    setState(() {
      _local = next;
      _busy = true;
    });

    try {
      await ref.read(apiProvider).toggleFavourite(widget.branch.branchId);
    } catch (_) {
      if (mounted) setState(() => _local = !next);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }
}

class _Term extends StatelessWidget {
  const _Term({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: ManfaaColors.textMuted),
        const SizedBox(width: 4),
        Text(text, style: Theme.of(context).textTheme.bodySmall),
      ],
    );
  }
}

class _Mark extends StatelessWidget {
  const _Mark({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: ManfaaColors.greenSoft,
      child: Center(
        child: Text(
          name.isEmpty ? '?' : name.characters.first.toUpperCase(),
          style: Theme.of(context).textTheme.titleLarge,
        ),
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty({required this.icon, required this.title, required this.body});

  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.huge),
      child: Column(
        children: [
          Icon(icon, size: 40, color: ManfaaColors.textFaint),
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
