import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../home/home_screen.dart' show initialsFor;
import 'store_widgets.dart';

/// Discover (R4 + zoning round): native shelves, not the web grid.
///
/// The header is COMPACT by demand: one row holds the title, the location
/// pill and a search icon that morphs (Hero) into the search screen's own
/// field. The location pill opens the area picker — Near me (GPS) or an
/// admin-drawn island zone — and every shelf narrows to the chosen area.
///
/// Offers obey the §13b two-kinds rule — an image banner IS the artwork, a
/// text banner is composed here from the words plus the LIVE rate.
sealed class DiscoverArea {
  const DiscoverArea();
}

class AreaEverywhere extends DiscoverArea {
  const AreaEverywhere();
}

class AreaNearMe extends DiscoverArea {
  const AreaNearMe(this.lat, this.lng);

  final double lat;
  final double lng;
}

class AreaZone extends DiscoverArea {
  const AreaZone(this.zone);

  final ZoneEntry zone;
}

final discoverAreaProvider = StateProvider<DiscoverArea>(
  (_) => const AreaEverywhere(),
);

final zonesProvider = FutureProvider.autoDispose<List<ZoneEntry>>(
  (ref) => ref.watch(apiProvider).zones(),
);

/// The device position, asked for when the Discover tab first opens (owner
/// rule 2026-08-17): the permission prompt fires here if it never has, and a
/// refusal is answered with silence — the feed renders regardless, just
/// without a Near me section. Not autoDispose: one fix (and at most one
/// prompt) per app session.
final deviceLocationProvider = FutureProvider<({double lat, double lng})?>((
  ref,
) async {
  try {
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission != LocationPermission.always &&
        permission != LocationPermission.whileInUse) {
      return null;
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.medium,
        timeLimit: Duration(seconds: 10),
      ),
    );

    return (lat: position.latitude, lng: position.longitude);
  } catch (_) {
    return null; // No location ≠ no Discover.
  }
});

final discoverProvider = FutureProvider.autoDispose<DiscoverFeed>((ref) {
  final api = ref.watch(apiProvider);

  // Enrichment, never a gate: while the fix (or the permission dialog) is
  // pending the feed loads without coordinates, and re-fetches with them
  // the moment they resolve — Near me appears on the second paint. The
  // server only ADDS distances and the nearby shelf for coordinates; no
  // other shelf changes.
  final here = ref.watch(deviceLocationProvider).valueOrNull;

  return switch (ref.watch(discoverAreaProvider)) {
    AreaNearMe(:final lat, :final lng) => api.discover(lat: lat, lng: lng),
    AreaZone(:final zone) => api.discover(
      zone: zone.id,
      lat: here?.lat,
      lng: here?.lng,
    ),
    AreaEverywhere() => api.discover(lat: here?.lat, lng: here?.lng),
  };
});

class DiscoverScreen extends ConsumerWidget {
  const DiscoverScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final feed = ref.watch(discoverProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: feed.when(
          loading: () => ListView(
            padding: const EdgeInsets.fromLTRB(
              Gap.lg,
              Gap.md,
              Gap.lg,
              Gap.navClearance,
            ),
            children: const [
              SkeletonBox(height: 40, width: 160),
              SizedBox(height: Gap.lg),
              SkeletonBox(height: 36, radius: 999),
              SizedBox(height: Gap.lg),
              SkeletonBox(height: 40, radius: 999),
              SizedBox(height: Gap.lg),
              SkeletonBox(height: 170, radius: Corner.card),
              SizedBox(height: Gap.lg),
              SkeletonBox(height: 160, radius: Corner.card),
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
      ),
    );
  }
}

class _Feed extends ConsumerStatefulWidget {
  const _Feed({required this.feed});

  final DiscoverFeed feed;

  @override
  ConsumerState<_Feed> createState() => _FeedState();
}

class _FeedState extends ConsumerState<_Feed> {
  /// The chosen category slug, or null for "All".
  String? _category;

  @override
  Widget build(BuildContext context) {
    final feed = widget.feed;
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final session = ref.watch(sessionProvider);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    // Slug → localised display name, resolved once from the feed's own
    // categories so store tag chips never show a raw slug when the server
    // has given us words for it.
    final categoryNames = <String, String>{
      for (final c in feed.categories)
        c.slug: dhivehi && (c.nameDv?.isNotEmpty ?? false)
            ? c.nameDv!
            : c.nameEn,
    };

    // Near me (owner rule 2026-08-17): stores within a kilometre; when none
    // is that close, widen once to two kilometres and no further — on an
    // atoll, "near" ends at the reef. The server's nearby shelf is 10 km
    // sorted by distance; this trims it to what "near me" actually means.
    final nearby = feed.shelves['nearby'] ?? const <StoreEntry>[];
    final withinKm = nearby
        .where((s) => (s.distanceM ?? double.maxFinite) <= 1000)
        .toList();
    final nearMe = withinKm.isNotEmpty
        ? withinKm
        : nearby
              .where((s) => (s.distanceM ?? double.maxFinite) <= 2000)
              .toList();

    final shelves = {...feed.shelves, 'nearby': nearMe};

    // The featured hero and the curated banners come first; every store
    // they show is CLAIMED so it cannot also fill a shelf below — the fix
    // for "Featured + New + In store + Online" making two merchants look
    // like a directory (owner feedback, 2026-08-23).
    final featured = [
      for (final s in shelves['featured'] ?? const <StoreEntry>[])
        if (_category == null || s.category == _category) s,
    ];
    final filtered = <String, List<StoreEntry>>{};
    final claimed = <String>{
      ...featured.map((s) => s.slug),
      ...feed.offers.map((o) => o.merchant.slug),
    };
    for (final key in const [
      'increased',
      'recently_added',
      'nearby',
      'in_store',
      'online',
    ]) {
      final list = <StoreEntry>[];
      for (final store in shelves[key] ?? const <StoreEntry>[]) {
        if (_category != null && store.category != _category) continue;
        if (claimed.contains(store.slug)) continue;
        claimed.add(store.slug);
        list.add(store);
      }
      filtered[key] = list;
    }

    // The curated banner hero stays "Featured"; a specific category filter
    // hides it (the banners are cross-store, not per-category).
    final showOffers =
        _category == null && featured.isEmpty && feed.offers.isNotEmpty;

    return RefreshIndicator(
      onRefresh: () => ref.refresh(discoverProvider.future),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(
          Gap.lg,
          Gap.md,
          Gap.lg,
          Gap.navClearance,
        ),
        children: [
          ManfaaTopBar(
            initials: initialsFor(session.customerName ?? ''),
            avatarUrl: ref.watch(avatarUrlProvider),
            onAvatarTap: () => context.go('/profile'),
          ),
          const SizedBox(height: Gap.md),
          // The title dominates; the area pill sits quietly beside it.
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Expanded(
                child: Text(
                  l10n.tabDiscover,
                  style: theme.textTheme.headlineMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const _AreaPill(),
              const SizedBox(width: Gap.xs),
              const _SearchButton(),
            ],
          ),
          if (feed.categories.isNotEmpty) ...[
            const SizedBox(height: Gap.sm),
            CategoryRail(
              categories: feed.categories,
              selected: _category,
              onSelect: (slug) => setState(() => _category = slug),
            ),
          ],
          if (featured.isNotEmpty) ...[
            const SizedBox(height: Gap.md),
            SectionHeader(l10n.shelfFeatured),
            const SizedBox(height: Gap.sm),
            FeaturedStoreCarousel(
              stores: featured,
              categoryNames: categoryNames,
            ),
          ] else if (showOffers) ...[
            const SizedBox(height: Gap.md),
            SectionHeader(l10n.featuredOffers),
            const SizedBox(height: Gap.sm),
            OfferCarousel(offers: feed.offers, categoryNames: categoryNames),
          ],
          const SizedBox(height: Gap.md),

          // Boosted — a rate lifted above the store's usual, given its own
          // coral voice so it reads as a different thing from Featured.
          if (filtered['increased']!.isNotEmpty)
            StoreShelf(
              title: l10n.shelfBoosted,
              stores: filtered['increased']!,
              categoryNames: categoryNames,
            ),
          // New on Manfaa — horizontal, each card flagged New.
          if (filtered['recently_added']!.isNotEmpty)
            StoreShelf(
              title: l10n.shelfNew,
              stores: filtered['recently_added']!,
              categoryNames: categoryNames,
              isNew: true,
            ),
          // In store near you — a two-up grid; the cards carry the distance.
          if (filtered['nearby']!.isNotEmpty)
            StoreGrid(
              title: l10n.shelfNearby,
              stores: filtered['nearby']!,
              categoryNames: categoryNames,
            ),
          if (filtered['in_store']!.isNotEmpty)
            StoreGrid(
              title: l10n.shelfInStore,
              stores: filtered['in_store']!,
              categoryNames: categoryNames,
            ),
          // Shop online — the cards carry the "Islandwide" footer.
          if (filtered['online']!.isNotEmpty)
            StoreGrid(
              title: l10n.shelfOnline,
              stores: filtered['online']!,
              categoryNames: categoryNames,
            ),

          if (_category != null && filtered.values.every((l) => l.isEmpty))
            Padding(
              padding: const EdgeInsets.only(top: Gap.huge),
              child: Center(
                child: Text(
                  l10n.discoverNoneInCategory,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _AreaPill extends ConsumerWidget {
  const _AreaPill();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final area = ref.watch(discoverAreaProvider);

    final (icon, label) = switch (area) {
      AreaNearMe() => (Icons.my_location_rounded, l10n.nearMe),
      AreaZone(:final zone) => (Icons.place_rounded, zone.displayName(dhivehi)),
      AreaEverywhere() => (Icons.place_outlined, l10n.areaEverywhere),
    };

    // Quiet by design: a muted, hairline pill that sits UNDER the big
    // Discover title rather than competing with it (owner feedback,
    // 2026-08-23).
    final muted = theme.colorScheme.onSurfaceVariant;
    return Material(
      color: theme.colorScheme.surfaceContainerLowest,
      shape: StadiumBorder(
        side: BorderSide(color: theme.colorScheme.outlineVariant),
      ),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: () => _AreaSheet.show(context),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: Gap.sm,
            vertical: 6,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 14, color: muted),
              const SizedBox(width: Gap.xs),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 92),
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: muted,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              Icon(Icons.expand_more_rounded, size: 14, color: muted),
            ],
          ),
        ),
      ),
    );
  }
}

/// The picker: Near me (GPS, permission asked HERE — at the moment of use),
/// the island zones by name, and the whole-country reset.
class _AreaSheet extends ConsumerStatefulWidget {
  const _AreaSheet();

  static Future<void> show(BuildContext context) => showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    // The island search field needs the sheet to rise above the
    // keyboard instead of being covered by it.
    isScrollControlled: true,
    useSafeArea: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(Corner.sheet)),
    ),
    builder: (_) => const _AreaSheet(),
  );

  @override
  ConsumerState<_AreaSheet> createState() => _AreaSheetState();
}

class _AreaSheetState extends ConsumerState<_AreaSheet> {
  var _locating = false;
  var _query = '';

  Future<void> _useNearMe() async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _locating = true);

    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        messenger.showSnackBar(SnackBar(content: Text(l10n.locationDenied)));
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 10),
        ),
      );

      ref.read(discoverAreaProvider.notifier).state = AreaNearMe(
        position.latitude,
        position.longitude,
      );
      if (mounted) Navigator.pop(context);
    } catch (_) {
      messenger.showSnackBar(SnackBar(content: Text(l10n.locationFailed)));
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final area = ref.watch(discoverAreaProvider);
    final zones = ref.watch(zonesProvider);

    // Islands only: Near me and Everywhere stay put while typing — the
    // search narrows the island list, it never hides the two anchors.
    final query = _query.trim().toLowerCase();
    bool matches(ZoneEntry zone) =>
        query.isEmpty ||
        zone.name.toLowerCase().contains(query) ||
        (zone.nameDv ?? '').contains(_query.trim());

    return SafeArea(
      child: Padding(
        // Rise above the keyboard while the search field is focused.
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.xl),
          children: [
            Text(l10n.locationPickerTitle, style: theme.textTheme.titleLarge),
            const SizedBox(height: Gap.md),
            TextField(
              onChanged: (value) => setState(() => _query = value),
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: l10n.searchIsland,
                prefixIcon: const Icon(Icons.search_rounded, size: 20),
                isDense: true,
              ),
            ),
            const SizedBox(height: Gap.sm),
            _AreaRow(
              tile: _locating
                  ? const SizedBox(
                      width: 40,
                      height: 40,
                      child: Center(
                        child: SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    )
                  : const IconTile(
                      Icons.my_location_rounded,
                      tint: ManfaaTint.violet,
                    ),
              label: l10n.nearMe,
              selected: area is AreaNearMe,
              onTap: _locating ? null : _useNearMe,
            ),
            _AreaRow(
              tile: const IconTile(Icons.public_rounded, tint: ManfaaTint.blue),
              label: l10n.areaEverywhere,
              selected: area is AreaEverywhere,
              onTap: () {
                ref.read(discoverAreaProvider.notifier).state =
                    const AreaEverywhere();
                Navigator.pop(context);
              },
            ),
            ...switch (zones) {
              // Server order IS the display order: the admin arranges the
              // zones list and this picker mirrors it verbatim.
              AsyncData(:final value) => [
                for (final zone in value.where(matches))
                  _AreaRow(
                    tile: const IconTile(
                      Icons.place_rounded,
                      tint: ManfaaTint.green,
                    ),
                    label: zone.displayName(dhivehi),
                    caption: l10n.zoneStoreCount(zone.storeCount),
                    selected: area is AreaZone && area.zone.id == zone.id,
                    onTap: () {
                      ref.read(discoverAreaProvider.notifier).state = AreaZone(
                        zone,
                      );
                      Navigator.pop(context);
                    },
                  ),
              ],
              AsyncError() => const [],
              _ => const [
                Padding(
                  padding: EdgeInsets.symmetric(vertical: Gap.lg),
                  child: Center(
                    child: SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
                ),
              ],
            },
          ],
        ),
      ),
    );
  }
}

class _AreaRow extends StatelessWidget {
  const _AreaRow({
    required this.tile,
    required this.label,
    required this.selected,
    required this.onTap,
    this.caption,
  });

  final Widget tile;
  final String label;
  final String? caption;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      borderRadius: BorderRadius.circular(Corner.control),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.sm),
        child: Row(
          children: [
            tile,
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: theme.textTheme.titleMedium),
                  if (caption != null)
                    Text(
                      caption!,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
            if (selected)
              const Icon(
                Icons.check_circle_rounded,
                color: ManfaaColors.violet,
              ),
          ],
        ),
      ),
    );
  }
}

/// The search icon that expands: a Hero shared with the search screen's
/// field, so the tap morphs the circle into the full search bar.
class _SearchButton extends StatelessWidget {
  const _SearchButton();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Hero(
      tag: 'discover-search',
      child: Material(
        color: theme.colorScheme.surfaceContainerLowest,
        shape: StadiumBorder(
          side: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: () => context.push('/discover/search'),
          child: SizedBox(
            width: 40,
            height: 36,
            child: Icon(
              Icons.search_rounded,
              size: 20,
              color: theme.colorScheme.onSurface,
            ),
          ),
        ),
      ),
    );
  }
}
