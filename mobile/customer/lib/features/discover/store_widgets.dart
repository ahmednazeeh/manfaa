import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';

bool _dhivehi(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'dv';

/// A category keeps its hue between sessions — same formula everywhere.
Color categoryHue(String slug) => ManfaaColors
    .categoryHues[slug.hashCode.abs() % ManfaaColors.categoryHues.length];

/// A line icon per curated category. The server only ships a slug, so this
/// maps by keyword and falls back to a storefront — never a blank.
IconData categoryIcon(String slug) {
  final s = slug.toLowerCase();
  if (s.contains('grocer') || s.contains('mart') || s.contains('super')) {
    return Icons.shopping_cart_outlined;
  }
  if (s.contains('din') ||
      s.contains('food') ||
      s.contains('cafe') ||
      s.contains('coffee') ||
      s.contains('restaurant')) {
    return Icons.restaurant_rounded;
  }
  if (s.contains('fashion') || s.contains('cloth') || s.contains('apparel')) {
    return Icons.checkroom_rounded;
  }
  if (s.contains('electronic') || s.contains('tech') || s.contains('gadget')) {
    return Icons.desktop_windows_outlined;
  }
  if (s.contains('health') || s.contains('pharma') || s.contains('care')) {
    return Icons.favorite_border_rounded;
  }
  if (s.contains('beauty') || s.contains('salon') || s.contains('spa')) {
    return Icons.spa_outlined;
  }
  if (s.contains('travel') || s.contains('hotel') || s.contains('guest')) {
    return Icons.flight_takeoff_rounded;
  }
  return Icons.storefront_outlined;
}

/// "fresh-produce" → "Fresh Produce" — the fallback label when the feed's
/// categories list doesn't carry a display name for this slug.
String humanizeCategory(String slug) => slug
    .split(RegExp(r'[-_\s]+'))
    .where((w) => w.isNotEmpty)
    .map((w) => w[0].toUpperCase() + w.substring(1))
    .join(' ');

/// The small category tag chip on store cards and rows — StatusChip's shape,
/// tinted with the category's stable hue.
class CategoryTag extends StatelessWidget {
  const CategoryTag({super.key, required this.slug, this.label});

  final String slug;

  /// A resolved display name (localised, from the feed's categories).
  /// Falls back to a humanised slug.
  final String? label;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final hue = categoryHue(slug);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: hue.withValues(alpha: dark ? 0.22 : 0.13),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(categoryIcon(slug), size: 12, color: hue),
          const SizedBox(width: Gap.xs),
          Flexible(
            child: Text(
              label ?? humanizeCategory(slug),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context)
                  .textTheme
                  .labelSmall
                  ?.copyWith(color: hue, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

/// A store's logo, or its monogram when there is none / it fails to load.
/// Real Maldivian merchant logos are full-colour and inconsistent; the
/// fixed circle with an inset is what keeps rows tidy regardless.
class StoreLogo extends StatelessWidget {
  const StoreLogo({super.key, required this.store, this.size = 48});

  final StoreEntry store;
  final double size;

  @override
  Widget build(BuildContext context) {
    final tint = tintColors(ManfaaTint.violet, Theme.of(context).brightness);
    final monogram = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: tint.bg,
        shape: BoxShape.circle,
      ),
      alignment: Alignment.center,
      child: Text(
        store.name.isEmpty ? '•' : store.name[0].toUpperCase(),
        style: TextStyle(
          color: tint.fg,
          fontWeight: FontWeight.w800,
          fontSize: size * 0.4,
        ),
      ),
    );

    final url = store.logoUrl;
    if (url == null || url.isEmpty) return monogram;

    return ClipOval(
      child: Image.network(
        url,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => monogram,
      ),
    );
  }
}

/// "8% Cashback" — and when boosted, "usually 5%" underneath. The
/// promotional rate is the strongest hook Discover has, and the comparison
/// is what makes it land. Cashback % is the VIOLET accent; a boosted rate
/// goes coral (the promotional brand voice).
class RateBadge extends StatelessWidget {
  const RateBadge({super.key, required this.store, this.compact = false});

  final StoreEntry store;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final accent = store.boosted
        ? (dark ? ManfaaColors.coral : ManfaaColors.coralDeep)
        : theme.colorScheme.secondary;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // scaleDown: the rate is money and must never truncate; on a card
        // too narrow for it the whole line shrinks a few percent instead of
        // overflowing.
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                l10n.ratePercent(store.ratePercent),
                textDirection: TextDirection.ltr,
                style: (compact
                        ? theme.textTheme.titleMedium
                        : theme.textTheme.titleLarge)
                    ?.copyWith(color: accent, fontWeight: FontWeight.w800),
              ),
              const SizedBox(width: Gap.xs),
              Text(
                l10n.cashbackWord,
                style: theme.textTheme.labelSmall
                    ?.copyWith(color: accent, fontWeight: FontWeight.w700),
              ),
            ],
          ),
        ),
        if (store.boosted)
          Text(
            l10n.usuallyRate(store.standingRatePercent),
            textDirection: TextDirection.ltr,
            style: theme.textTheme.labelSmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
      ],
    );
  }
}

/// The §13b two-kinds offer banner: image = the artwork edge to edge with
/// NOTHING printed over it; text = composed here with the store's live rate,
/// so this kind can never quote a rate the shop has moved off. Text banners
/// now wear the brand's lavender featured card; dots track the page.
class OfferCarousel extends StatefulWidget {
  const OfferCarousel({super.key, required this.offers, this.categoryNames});

  final List<OfferEntry> offers;
  final Map<String, String>? categoryNames;

  @override
  State<OfferCarousel> createState() => _OfferCarouselState();
}

class _OfferCarouselState extends State<OfferCarousel> {
  final _controller = PageController(viewportFraction: 0.94);
  int _page = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      children: [
        SizedBox(
          height: 208,
          child: PageView.builder(
            controller: _controller,
            onPageChanged: (i) => setState(() => _page = i),
            itemCount: widget.offers.length,
            itemBuilder: (context, index) {
              final offer = widget.offers[index];

              return Padding(
                padding: const EdgeInsets.symmetric(horizontal: Gap.xs),
                child: offer.kind == 'image'
                    ? _ImageOffer(offer: offer)
                    : _TextOffer(
                        offer: offer,
                        categoryNames: widget.categoryNames,
                      ),
              );
            },
          ),
        ),
        if (widget.offers.length > 1)
          Padding(
            padding: const EdgeInsets.only(top: Gap.md),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                for (var i = 0; i < widget.offers.length; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    margin: const EdgeInsets.symmetric(horizontal: 3),
                    width: i == _page ? 18 : 6,
                    height: 6,
                    decoration: BoxDecoration(
                      color: i == _page
                          ? scheme.secondary
                          : scheme.onSurfaceVariant.withValues(alpha: 0.3),
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

class _ImageOffer extends StatelessWidget {
  const _ImageOffer({required this.offer});

  final OfferEntry offer;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(Corner.card),
      onTap: () => context.push('/discover/store/${offer.merchant.slug}'),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(Corner.card),
        child: offer.imageUrl == null
            ? const SizedBox.shrink()
            : Image.network(
                offer.imageUrl!,
                fit: BoxFit.cover,
                width: double.infinity,
                errorBuilder: (_, _, _) => Container(
                  color: ManfaaColors.violetSoft,
                ),
              ),
      ),
    );
  }
}

/// The mockup's featured card: soft lavender wash, a violet "limited time"
/// pill, the store's circular logo, and the LIVE violet rate.
class _TextOffer extends StatelessWidget {
  const _TextOffer({required this.offer, this.categoryNames});

  final OfferEntry offer;
  final Map<String, String>? categoryNames;

  @override
  Widget build(BuildContext context) {
    final dhivehi = _dhivehi(context);
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final l10n = context.l10n;

    final title = dhivehi && (offer.titleDv?.isNotEmpty ?? false)
        ? offer.titleDv!
        : offer.title;
    final blurb = dhivehi && (offer.blurbDv?.isNotEmpty ?? false)
        ? offer.blurbDv
        : offer.blurb;
    final badge = dhivehi && (offer.badgeDv?.isNotEmpty ?? false)
        ? offer.badgeDv
        : offer.badge;
    final category = offer.merchant.category;

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.card),
      onTap: () => context.push('/discover/store/${offer.merchant.slug}'),
      child: Container(
        padding: const EdgeInsets.all(Gap.xl),
        decoration: BoxDecoration(
          color: scheme.secondaryContainer,
          borderRadius: BorderRadius.circular(Corner.card),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (badge != null)
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: ManfaaColors.violet,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.bolt_rounded,
                        size: 13, color: Colors.white),
                    const SizedBox(width: Gap.xs),
                    Text(
                      badge,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
            const Spacer(),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(3),
                  decoration: const BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                  ),
                  child: StoreLogo(store: offer.merchant, size: 50),
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleLarge
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                      if (blurb != null) ...[
                        const SizedBox(height: 2),
                        Text(
                          blurb,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
            const Spacer(),
            Row(
              children: [
                Expanded(
                  child: Text(
                    // LIVE rate, read at render — the whole point of a text
                    // banner (and the exact string §13b promises).
                    l10n.ratePercentCashback(offer.merchant.ratePercent),
                    textDirection: TextDirection.ltr,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleLarge?.copyWith(
                      color: scheme.secondary,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                if (category != null) ...[
                  const SizedBox(width: Gap.sm),
                  CategoryTag(
                    slug: category,
                    label: categoryNames?[category],
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// The pill category chips under the search field — a white pill with the
/// category's line icon in its hue, as in the mockup.
class CategoryRail extends StatelessWidget {
  const CategoryRail({super.key, required this.categories});

  final List<CategoryEntry> categories;

  @override
  Widget build(BuildContext context) {
    final dhivehi = _dhivehi(context);
    final theme = Theme.of(context);

    return SizedBox(
      height: 44,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.zero,
        itemCount: categories.length,
        separatorBuilder: (_, _) => const SizedBox(width: Gap.sm),
        itemBuilder: (context, index) {
          final category = categories[index];
          final hue = categoryHue(category.slug);
          final label = dhivehi && (category.nameDv?.isNotEmpty ?? false)
              ? category.nameDv!
              : category.nameEn;

          return Material(
            color: theme.colorScheme.surfaceContainerLowest,
            shape: StadiumBorder(
              side: BorderSide(color: theme.colorScheme.outlineVariant),
            ),
            child: InkWell(
              customBorder: const StadiumBorder(),
              onTap: () => context
                  .push('/discover/search?category=${category.slug}'),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                    horizontal: Gap.lg, vertical: Gap.sm),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(categoryIcon(category.slug), size: 18, color: hue),
                    const SizedBox(width: Gap.sm),
                    Text(label, style: theme.textTheme.labelLarge),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class StoreShelf extends StatelessWidget {
  const StoreShelf({
    super.key,
    required this.title,
    required this.stores,
    this.categoryNames,
  });

  final String title;
  final List<StoreEntry> stores;
  final Map<String, String>? categoryNames;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: Gap.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionHeader(title),
          const SizedBox(height: Gap.md),
          SizedBox(
            // An upper BOUND for the tallest card (boosted rate + km
            // footer), not a size cards stretch to: each card top-aligns at
            // its own content height, so nothing pads out with white.
            height: 148,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: EdgeInsets.zero,
              itemCount: stores.length,
              separatorBuilder: (_, _) => const SizedBox(width: Gap.md),
              itemBuilder: (context, index) => SizedBox(
                width: 200,
                child: Align(
                  alignment: Alignment.topCenter,
                  child: StoreCard(
                    store: stores[index],
                    categoryNames: categoryNames,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The mockup's merchant card, exactly: the round logo on the LEFT, and to
/// its right a single stack — bold name, the violet rate, then the category
/// chip — with a hairline divider and the distance footer underneath (only
/// when the feed carries a distance — data honesty).
class StoreCard extends StatelessWidget {
  const StoreCard({super.key, required this.store, this.categoryNames});

  final StoreEntry store;
  final Map<String, String>? categoryNames;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final category = store.category;
    final distanceM = store.distanceM;

    return ManfaaCard(
      padding: EdgeInsets.zero,
      onTap: () => context.push('/discover/store/${store.slug}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        // min: the card is exactly as tall as its content, in the rail and
        // the grid alike — never a band of dead white below the category.
        mainAxisSize: MainAxisSize.min,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(Gap.md, Gap.md, Gap.md, Gap.sm),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                StoreLogo(store: store, size: 44),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        store.displayName(_dhivehi(context)),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleSmall,
                      ),
                      const SizedBox(height: Gap.xs),
                      RateBadge(store: store, compact: true),
                      if (category != null) ...[
                        const SizedBox(height: Gap.xs),
                        CategoryTag(
                            slug: category, label: categoryNames?[category]),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
          if (distanceM != null)
            Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Divider(height: 1, color: theme.colorScheme.outlineVariant),
                Padding(
                  padding:
                      const EdgeInsets.fromLTRB(Gap.md, Gap.sm, Gap.md, Gap.sm),
                  child: Row(
                    children: [
                      Icon(Icons.place_outlined, size: 13, color: muted),
                      const SizedBox(width: 3),
                      Expanded(
                        child: Text(
                          context.l10n
                              .kmAway((distanceM / 1000).toStringAsFixed(1)),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall
                              ?.copyWith(color: muted),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

/// The mockup's merchant grid — the same card, laid as tiles: two per row
/// on bigger phones, one per row on smaller ones, where two tiles would
/// squeeze the logo-left card until the name has no room.
class StoreGrid extends StatelessWidget {
  const StoreGrid({
    super.key,
    required this.title,
    required this.stores,
    this.categoryNames,
  });

  final String title;
  final List<StoreEntry> stores;
  final Map<String, String>? categoryNames;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: Gap.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionHeader(title),
          const SizedBox(height: Gap.sm),
          LayoutBuilder(builder: (context, constraints) {
            const spacing = Gap.md;
            // 330 content-width is the seam: a 375dp phone (343 after page
            // padding) carries two tiles of ~165, enough for logo + name +
            // rate; a 360dp-or-narrower phone drops to one card per row so
            // the tiles never squeeze the name out.
            final twoUp = constraints.maxWidth >= 330;
            final width = twoUp
                ? (constraints.maxWidth - spacing) / 2
                : constraints.maxWidth;
            return Wrap(
              spacing: spacing,
              runSpacing: spacing,
              children: [
                for (final store in stores)
                  SizedBox(
                    width: width,
                    child: StoreCard(store: store, categoryNames: categoryNames),
                  ),
              ],
            );
          }),
        ],
      ),
    );
  }
}

/// A directory/search result row — and the mockup's "Nearby stores" row:
/// logo, name + category tag, right-aligned rate (+ distance when the feed
/// carries one; omitted when it doesn't).
class StoreRow extends StatelessWidget {
  const StoreRow({super.key, required this.store, this.categoryNames});

  final StoreEntry store;
  final Map<String, String>? categoryNames;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final category = store.category;
    final distanceM = store.distanceM;

    return ManfaaCard(
      padding: const EdgeInsets.all(Gap.lg),
      onTap: () => context.push('/discover/store/${store.slug}'),
      child: Row(
        children: [
          StoreLogo(store: store, size: 48),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  store.displayName(_dhivehi(context)),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleMedium,
                ),
                if (category != null) ...[
                  const SizedBox(height: 6),
                  CategoryTag(
                      slug: category, label: categoryNames?[category]),
                ],
              ],
            ),
          ),
          const SizedBox(width: Gap.md),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              RateBadge(store: store, compact: true),
              if (distanceM != null) ...[
                const SizedBox(height: Gap.xs),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.place_outlined, size: 13, color: muted),
                    const SizedBox(width: 3),
                    Text(
                      context.l10n
                          .kmAway((distanceM / 1000).toStringAsFixed(1)),
                      style:
                          theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ],
            ],
          ),
          const SizedBox(width: Gap.xs),
          Icon(Icons.chevron_right_rounded, color: muted),
        ],
      ),
    );
  }
}
