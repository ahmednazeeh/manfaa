import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:url_launcher/url_launcher.dart';

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
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final title = store.valueOrNull?.entry.displayName(dhivehi);

    return Scaffold(
      appBar: AppBar(title: title == null ? null : Text(title)),
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
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final store = page.entry;
    final category = store.category;

    final channelLabel = switch (store.channel) {
      'online' => l10n.channelOnline,
      'both' => l10n.channelBoth,
      _ => l10n.channelInStore,
    };

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
      children: [
        // Identity: logo, name, channel — the rate as the violet headline.
        ManfaaCard(
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
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                    if (category != null) ...[
                      const SizedBox(height: Gap.sm),
                      CategoryTag(slug: category),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: Gap.md),
              RateBadge(store: store),
            ],
          ),
        ),
        if (store.boosted && store.promoEndsAt != null) ...[
          const SizedBox(height: Gap.md),
          Builder(builder: (context) {
            final tone =
                toneSurface(ToneSurface.attention, theme.brightness);
            return ManfaaCard(
              color: tone.background,
              padding: const EdgeInsets.all(Gap.lg),
              child: Row(
                children: [
                  Icon(Icons.bolt_rounded, size: 20, color: tone.foreground),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: Text(
                      l10n.promoUntil(store.promoEndsAt!.substring(0, 10)),
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: tone.foreground,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ),
            );
          }),
        ],
        if (page.eligibilityBasis?.isNotEmpty ?? false) ...[
          const SizedBox(height: Gap.md),
          ManfaaCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _CardLabel(
                  icon: Icons.verified_outlined,
                  tint: ManfaaTint.violet,
                  label: l10n.storeEligibility,
                ),
                const SizedBox(height: Gap.md),
                Text(page.eligibilityBasis!,
                    style: theme.textTheme.bodyMedium),
              ],
            ),
          ),
        ],
        if (page.productCategories.isNotEmpty) ...[
          const SizedBox(height: Gap.md),
          ManfaaCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _CardLabel(
                  icon: Icons.percent_rounded,
                  tint: ManfaaTint.green,
                  label: l10n.storeRates,
                ),
                const SizedBox(height: Gap.sm),
                for (final category in page.productCategories)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
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
                            style: theme.textTheme.bodyMedium,
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
                            fontWeight: FontWeight.w700,
                            color: category['mode'] == 'excluded'
                                ? muted
                                : theme.colorScheme.secondary,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
        if (page.branches.isNotEmpty) ...[
          const SizedBox(height: Gap.md),
          ManfaaCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _CardLabel(
                  icon: Icons.place_outlined,
                  tint: ManfaaTint.blue,
                  label: l10n.storeBranches,
                ),
                // The map appears only when at least one branch is pinned —
                // never an empty ocean tile.
                if (page.branches.any((b) => b.pinned)) ...[
                  const SizedBox(height: Gap.md),
                  _BranchMap(
                      branches:
                          page.branches.where((b) => b.pinned).toList()),
                ],
                const SizedBox(height: Gap.sm),
                for (final branch in page.branches)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: Gap.sm),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(Icons.place_outlined,
                            size: 18, color: ManfaaColors.blue),
                        const SizedBox(width: Gap.md),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(branch.name,
                                  style: theme.textTheme.bodyMedium
                                      ?.copyWith(fontWeight: FontWeight.w600)),
                              if (branch.address != null)
                                Text(
                                  branch.address!,
                                  style: theme.textTheme.bodySmall
                                      ?.copyWith(color: muted),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
        if ((page.contactPhone?.isNotEmpty ?? false) ||
            (page.websiteUrl?.isNotEmpty ?? false)) ...[
          const SizedBox(height: Gap.md),
          ManfaaCard(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.lg, Gap.lg, 0),
                  child: _CardLabel(
                    icon: Icons.support_agent_rounded,
                    tint: ManfaaTint.green,
                    label: l10n.storeContact,
                  ),
                ),
                const SizedBox(height: Gap.xs),
                if (page.contactPhone?.isNotEmpty ?? false)
                  _ContactRow(
                    icon: Icons.phone_rounded,
                    tint: ManfaaTint.green,
                    label: l10n.storeCall,
                    value: page.contactPhone!,
                    uri: Uri(scheme: 'tel', path: page.contactPhone!),
                  ),
                if (page.websiteUrl?.isNotEmpty ?? false)
                  _ContactRow(
                    icon: Icons.language_rounded,
                    tint: ManfaaTint.blue,
                    label: l10n.storeWebsite,
                    value: page.websiteUrl!,
                    uri: Uri.tryParse(page.websiteUrl!) ?? Uri(),
                  ),
                const SizedBox(height: Gap.sm),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

/// The icon-tile-led card label, as on the profile setting cards.
class _CardLabel extends StatelessWidget {
  const _CardLabel({
    required this.icon,
    required this.tint,
    required this.label,
  });

  final IconData icon;
  final ManfaaTint tint;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        IconTile(icon, tint: tint, size: 40, iconSize: 20),
        const SizedBox(width: Gap.md),
        Expanded(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
      ],
    );
  }
}

/// The branches on an OSM map — flutter_map, no API key in the APK. The
/// camera fits every pin; gestures stay OFF so the ListView scrolls past
/// without fighting the map.
class _BranchMap extends StatelessWidget {
  const _BranchMap({required this.branches});

  final List<StoreBranch> branches;

  @override
  Widget build(BuildContext context) {
    final points = [
      for (final b in branches) LatLng(b.lat!, b.lng!),
    ];

    final center = LatLng(
      points.map((p) => p.latitude).reduce((a, b) => a + b) / points.length,
      points.map((p) => p.longitude).reduce((a, b) => a + b) / points.length,
    );

    // One pin → a close street view; several → fit them all.
    final camera = points.length == 1
        ? MapOptions(
            initialCenter: center,
            initialZoom: 16,
            interactionOptions:
                const InteractionOptions(flags: InteractiveFlag.none),
          )
        : MapOptions(
            initialCameraFit: CameraFit.coordinates(
              coordinates: points,
              padding: const EdgeInsets.all(40),
              maxZoom: 16,
            ),
            interactionOptions:
                const InteractionOptions(flags: InteractiveFlag.none),
          );

    return ClipRRect(
      borderRadius: BorderRadius.circular(Corner.tile),
      child: SizedBox(
        height: 180,
        child: FlutterMap(
          options: camera,
          children: [
            TileLayer(
              // OUR origin, not OSM's — see ApiEnv.tileUrlTemplate.
              urlTemplate: ApiEnv.tileUrlTemplate,
              userAgentPackageName: 'app.manfaa.customer',
            ),
            MarkerLayer(
              markers: [
                for (final point in points)
                  Marker(
                    point: point,
                    width: 36,
                    height: 36,
                    alignment: Alignment.topCenter,
                    child: const Icon(
                      Icons.place_rounded,
                      size: 34,
                      color: ManfaaColors.coral,
                      shadows: [
                        Shadow(color: Color(0x40000000), blurRadius: 6),
                      ],
                    ),
                  ),
              ],
            ),
            // OSM's attribution requirement — small, but present.
            const Align(
              alignment: Alignment.bottomRight,
              child: Padding(
                padding: EdgeInsets.all(2),
                child: Text(
                  '© OpenStreetMap',
                  style: TextStyle(fontSize: 9, color: Color(0x99000000)),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A tappable contact row: Call / Website. The launch failure path is a
/// silent no-op — a missing dialler app is not the customer's error.
class _ContactRow extends StatelessWidget {
  const _ContactRow({
    required this.icon,
    required this.tint,
    required this.label,
    required this.value,
    required this.uri,
  });

  final IconData icon;
  final ManfaaTint tint;
  final String label;
  final String value;
  final Uri uri;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return InkWell(
      onTap: () => launchUrl(uri, mode: LaunchMode.externalApplication)
          .catchError((_) => false),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.sm),
        child: Row(
          children: [
            IconTile(icon, tint: tint, size: 36, iconSize: 18),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: theme.textTheme.titleSmall),
                  Text(
                    value,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ],
              ),
            ),
            Icon(Icons.chevron_right_rounded, color: muted),
          ],
        ),
      ),
    );
  }
}
