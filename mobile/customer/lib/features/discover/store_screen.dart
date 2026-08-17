import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'store_widgets.dart';

/// One store's public page: the rate, the terms, the places.
///
/// `eligibility_basis` gets a card of its own — the §11 over-promise guard.
/// A merchant defines their own eligible amount, so "10%" without "on what"
/// is exactly how a customer earns MVR 12 on a MVR 1,250 bill and OUR brand
/// carries the disappointment.
final storeProvider = FutureProvider.autoDispose.family<StorePage, String>(
  (ref, slug) => ref.watch(apiProvider).store(slug),
);

class StoreScreen extends ConsumerWidget {
  const StoreScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final store = ref.watch(storeProvider(slug));

    return Scaffold(
      appBar: AppBar(),
      body: store.when(
        loading: () => ListView(
          padding: const EdgeInsets.all(Gap.lg),
          children: const [
            SkeletonBox(height: 140, radius: Corner.card),
            SizedBox(height: Gap.md),
            SkeletonBox(height: 100, radius: Corner.card),
            SizedBox(height: Gap.md),
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
                  onPressed: () => ref.invalidate(storeProvider(slug)),
                  child: Text(l10n.retry),
                ),
              ],
            ),
          ),
        ),
        data: (page) => _StoreBody(page: page),
      ),
    );
  }
}

class _StoreBody extends StatelessWidget {
  const _StoreBody({required this.page});

  final StorePage page;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final store = page.entry;

    final channelLabel = switch (store.channel) {
      'online' => l10n.channelOnline,
      'both' => l10n.channelBoth,
      _ => l10n.channelInStore,
    };

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(Gap.xl),
            child: Row(
              children: [
                StoreLogo(store: store, size: 64),
                const SizedBox(width: Gap.lg),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        store.displayName(dhivehi),
                        style: theme.textTheme.titleLarge,
                      ),
                      const SizedBox(height: Gap.xs),
                      Text(
                        channelLabel,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
                RateBadge(store: store),
              ],
            ),
          ),
        ),
        if (store.boosted && store.promoEndsAt != null) ...[
          const SizedBox(height: Gap.md),
          Builder(builder: (context) {
            final tone =
                toneSurface(ToneSurface.attention, theme.brightness);
            return Card(
              color: tone.background,
              child: Padding(
                padding: const EdgeInsets.all(Gap.lg),
                child: Text(
                  l10n.promoUntil(store.promoEndsAt!.substring(0, 10)),
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: tone.foreground),
                ),
              ),
            );
          }),
        ],
        if (page.eligibilityBasis?.isNotEmpty ?? false) ...[
          const SizedBox(height: Gap.md),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l10n.storeEligibility, style: theme.textTheme.labelLarge),
                  const SizedBox(height: Gap.sm),
                  Text(page.eligibilityBasis!,
                      style: theme.textTheme.bodyMedium),
                ],
              ),
            ),
          ),
        ],
        if (page.productCategories.isNotEmpty) ...[
          const SizedBox(height: Gap.md),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l10n.storeRates, style: theme.textTheme.labelLarge),
                  const SizedBox(height: Gap.sm),
                  for (final category in page.productCategories)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              dhivehi &&
                                      ((category['name_dv'] as String?)
                                              ?.isNotEmpty ??
                                          false)
                                  ? category['name_dv'] as String
                                  : (category['name_en'] as String? ?? ''),
                            ),
                          ),
                          Text(
                            category['mode'] == 'excluded'
                                ? l10n.storeNoCashback
                                : l10n.ratePercent(
                                    category['cashback_rate_percent']
                                            as String? ??
                                        store.ratePercent,
                                  ),
                            textDirection: TextDirection.ltr,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                              color: category['mode'] == 'excluded'
                                  ? theme.colorScheme.onSurfaceVariant
                                  : ManfaaColors.confirmedGreen,
                            ),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
        if (page.branches.isNotEmpty) ...[
          const SizedBox(height: Gap.md),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l10n.storeBranches, style: theme.textTheme.labelLarge),
                  const SizedBox(height: Gap.sm),
                  for (final branch in page.branches)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: const Icon(Icons.place_outlined,
                          color: ManfaaColors.infoSky),
                      title: Text(branch.name),
                      subtitle: branch.address == null
                          ? null
                          : Text(branch.address!),
                    ),
                ],
              ),
            ),
          ),
        ],
        if (page.contactPhone?.isNotEmpty ?? false) ...[
          const SizedBox(height: Gap.md),
          Card(
            child: ListTile(
              leading: const Icon(Icons.phone_rounded,
                  color: ManfaaColors.confirmedGreen),
              title: Text(l10n.storeContact),
              subtitle: Text(
                page.contactPhone!,
                textDirection: TextDirection.ltr,
              ),
            ),
          ),
        ],
      ],
    );
  }
}
