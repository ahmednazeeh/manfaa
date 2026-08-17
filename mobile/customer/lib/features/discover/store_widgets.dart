import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';

bool _dhivehi(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'dv';

/// A store's logo, or its monogram when there is none / it fails to load.
/// Real Maldivian merchant logos are full-colour and inconsistent; the
/// fixed circle with an inset is what keeps rows tidy regardless.
class StoreLogo extends StatelessWidget {
  const StoreLogo({super.key, required this.store, this.size = 48});

  final StoreEntry store;
  final double size;

  @override
  Widget build(BuildContext context) {
    final monogram = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: ManfaaColors.rose100,
        shape: BoxShape.circle,
      ),
      alignment: Alignment.center,
      child: Text(
        store.name.isEmpty ? '•' : store.name[0].toUpperCase(),
        style: TextStyle(
          color: ManfaaColors.rose700,
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

/// "8%" — and when boosted, "usually 5%" underneath. The promotional rate is
/// the strongest hook Discover has, and the comparison is what makes it land.
class RateBadge extends StatelessWidget {
  const RateBadge({super.key, required this.store, this.compact = false});

  final StoreEntry store;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          l10n.ratePercent(store.ratePercent),
          textDirection: TextDirection.ltr,
          style: (compact ? theme.textTheme.titleSmall : theme.textTheme.titleMedium)
              ?.copyWith(
            color: store.boosted
                ? ManfaaColors.rose600
                : ManfaaColors.confirmedGreen,
            fontWeight: FontWeight.w800,
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
/// so this kind can never quote a rate the shop has moved off.
class OfferCarousel extends StatelessWidget {
  const OfferCarousel({super.key, required this.offers});

  final List<OfferEntry> offers;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 180,
      child: PageView.builder(
        controller: PageController(viewportFraction: 0.92),
        itemCount: offers.length,
        itemBuilder: (context, index) {
          final offer = offers[index];

          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: Gap.xs),
            child: offer.kind == 'image'
                ? _ImageOffer(offer: offer)
                : _TextOffer(offer: offer),
          );
        },
      ),
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
                  color: ManfaaColors.rose100,
                ),
              ),
      ),
    );
  }
}

class _TextOffer extends StatelessWidget {
  const _TextOffer({required this.offer});

  final OfferEntry offer;

  @override
  Widget build(BuildContext context) {
    final dhivehi = _dhivehi(context);
    final theme = Theme.of(context);
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

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.card),
      onTap: () => context.push('/discover/store/${offer.merchant.slug}'),
      child: Container(
        padding: const EdgeInsets.all(Gap.xl),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [ManfaaColors.rose600, ManfaaColors.rose700],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(Corner.card),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (badge != null)
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  badge,
                  style: theme.textTheme.labelSmall
                      ?.copyWith(color: Colors.white),
                ),
              ),
            const SizedBox(height: Gap.sm),
            Text(
              title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleLarge?.copyWith(
                color: Colors.white,
                fontWeight: FontWeight.w800,
              ),
            ),
            if (blurb != null) ...[
              const SizedBox(height: Gap.xs),
              Text(
                blurb,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.rose100),
              ),
            ],
            const SizedBox(height: Gap.sm),
            Text(
              // LIVE rate, read at render — the whole point of a text banner.
              l10n.ratePercentCashback(offer.merchant.ratePercent),
              textDirection: TextDirection.ltr,
              style: theme.textTheme.titleMedium?.copyWith(
                color: Colors.white,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class CategoryRail extends StatelessWidget {
  const CategoryRail({super.key, required this.categories});

  final List<CategoryEntry> categories;

  @override
  Widget build(BuildContext context) {
    final dhivehi = _dhivehi(context);

    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
        itemCount: categories.length,
        separatorBuilder: (_, _) => const SizedBox(width: Gap.sm),
        itemBuilder: (context, index) {
          final category = categories[index];
          final hue = ManfaaColors
              .categoryHues[category.slug.hashCode.abs() %
                  ManfaaColors.categoryHues.length];
          final label = dhivehi && (category.nameDv?.isNotEmpty ?? false)
              ? category.nameDv!
              : category.nameEn;

          return ActionChip(
            onPressed: () => context
                .push('/discover/search?category=${category.slug}'),
            avatar: CircleAvatar(backgroundColor: hue, radius: 5),
            label: Text(label),
          );
        },
      ),
    );
  }
}

class StoreShelf extends StatelessWidget {
  const StoreShelf({super.key, required this.title, required this.stores});

  final String title;
  final List<StoreEntry> stores;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: Gap.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
            child: Text(title, style: theme.textTheme.titleLarge),
          ),
          const SizedBox(height: Gap.md),
          SizedBox(
            height: 148,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
              itemCount: stores.length,
              separatorBuilder: (_, _) => const SizedBox(width: Gap.md),
              itemBuilder: (context, index) =>
                  StoreCard(store: stores[index]),
            ),
          ),
        ],
      ),
    );
  }
}

class StoreCard extends StatelessWidget {
  const StoreCard({super.key, required this.store});

  final StoreEntry store;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SizedBox(
      width: 148,
      child: Card(
        child: InkWell(
          borderRadius: BorderRadius.circular(Corner.card),
          onTap: () => context.push('/discover/store/${store.slug}'),
          child: Padding(
            padding: const EdgeInsets.all(Gap.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                StoreLogo(store: store, size: 40),
                const Spacer(),
                Text(
                  store.displayName(_dhivehi(context)),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
                const SizedBox(height: 2),
                RateBadge(store: store, compact: true),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A directory/search result row.
class StoreRow extends StatelessWidget {
  const StoreRow({super.key, required this.store});

  final StoreEntry store;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        onTap: () => context.push('/discover/store/${store.slug}'),
        leading: StoreLogo(store: store, size: 44),
        title: Text(
          store.displayName(_dhivehi(context)),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: RateBadge(store: store, compact: true),
      ),
    );
  }
}
