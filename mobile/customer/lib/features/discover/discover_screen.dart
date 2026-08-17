import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'store_widgets.dart';

/// Discover (R4): native shelves, not the web grid.
///
/// Order is the plan's: Boosted first (the promotional rate is the strongest
/// merchandising hook), then Featured, then the rest. Offers obey the §13b
/// two-kinds rule — an image banner IS the artwork, a text banner is
/// composed here from the words plus the LIVE rate, so a stale percentage
/// can never be burned into a picture we then contradict.
///
/// Near you appears only when the feed carries it (location permission and
/// the map arrive after the Maps key is restricted — recorded blocker).
final discoverProvider = FutureProvider.autoDispose<DiscoverFeed>(
  (ref) => ref.watch(apiProvider).discover(),
);

class DiscoverScreen extends ConsumerWidget {
  const DiscoverScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final feed = ref.watch(discoverProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.tabDiscover),
        actions: [
          IconButton(
            icon: const Icon(Icons.search_rounded),
            tooltip: l10n.searchTitle,
            onPressed: () => context.push('/discover/search'),
          ),
        ],
      ),
      body: feed.when(
        loading: () => ListView(
          padding: const EdgeInsets.all(Gap.lg),
          children: const [
            SkeletonBox(height: 168, radius: Corner.card),
            SizedBox(height: Gap.lg),
            SkeletonBox(height: 40, radius: 999),
            SizedBox(height: Gap.lg),
            SkeletonBox(height: 140, radius: Corner.card),
            SizedBox(height: Gap.md),
            SkeletonBox(height: 140, radius: Corner.card),
          ],
        ),
        error: (e, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(Gap.huge),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  e is MobileApiException ? e.message : l10n.errorGeneric,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: Gap.lg),
                OutlinedButton(
                  onPressed: () => ref.invalidate(discoverProvider),
                  child: Text(l10n.retry),
                ),
              ],
            ),
          ),
        ),
        data: (feed) => _Feed(feed: feed),
      ),
    );
  }
}

class _Feed extends ConsumerWidget {
  const _Feed({required this.feed});

  final DiscoverFeed feed;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;

    final sections = <({String key, String title})>[
      (key: 'increased', title: l10n.shelfBoosted),
      (key: 'featured', title: l10n.shelfFeatured),
      (key: 'nearby', title: l10n.shelfNearby),
      (key: 'recently_added', title: l10n.shelfNew),
      (key: 'in_store', title: l10n.shelfInStore),
      (key: 'online', title: l10n.shelfOnline),
    ];

    return RefreshIndicator(
      onRefresh: () => ref.refresh(discoverProvider.future),
      child: ListView(
        padding: const EdgeInsets.symmetric(vertical: Gap.lg),
        children: [
          if (feed.offers.isNotEmpty) ...[
            OfferCarousel(offers: feed.offers),
            const SizedBox(height: Gap.xl),
          ],
          if (feed.categories.isNotEmpty) ...[
            CategoryRail(categories: feed.categories),
            const SizedBox(height: Gap.lg),
          ],
          for (final section in sections)
            if ((feed.shelves[section.key] ?? const []).isNotEmpty)
              StoreShelf(
                title: section.title,
                stores: feed.shelves[section.key]!,
              ),
        ],
      ),
    );
  }
}
