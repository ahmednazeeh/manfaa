import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../home/home_screen.dart' show initialsFor;
import 'floating_cart.dart';
import 'market_providers.dart';

/// The Market tab — the storefronts a shopper can buy from today
/// (`Market View.png`).
///
/// Lists BRANCHES, not merchants (§2.3): the branch is the shop, because
/// stock and fulfilment are physical and a chain's two shops genuinely
/// differ. A card reads brand and island.
class MarketScreen extends ConsumerWidget {
  const MarketScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final session = ref.watch(sessionProvider);
    final branches = ref.watch(marketBranchesProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Stack(
          children: [
            RefreshIndicator(
              onRefresh: () => ref.refresh(marketBranchesProvider.future),
              child: branches.when(
                loading: () => ListView(
                  padding: _padding(context),
                  children: const [
                    SkeletonBox(height: 40, width: 160),
                    SizedBox(height: Gap.lg),
                    SkeletonBox(height: 150, radius: Corner.card),
                    SizedBox(height: Gap.md),
                    SkeletonBox(height: 150, radius: Corner.card),
                  ],
                ),
                error: (error, _) => ListView(
                  padding: _padding(context),
                  children: [
                    const SizedBox(height: Gap.huge),
                    Text(
                      error is MobileApiException
                          ? error.message
                          : l10n.errorGeneric,
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: Gap.lg),
                    Center(
                      child: OutlinedButton(
                        onPressed: () => ref.invalidate(marketBranchesProvider),
                        child: Text(l10n.retry),
                      ),
                    ),
                  ],
                ),
                data: (rows) => ListView(
                  padding: _padding(context),
                  children: [
                    ManfaaTopBar(
                      initials: initialsFor(session.customerName ?? ''),
                      avatarUrl: ref.watch(avatarUrlProvider),
                      onAvatarTap: () => context.go('/profile'),
                    ),
                    const SizedBox(height: Gap.md),
                    Text(l10n.tabMarket,
                        style: theme.textTheme.headlineSmall),
                    const SizedBox(height: Gap.xs),
                    Text(
                      l10n.marketSubtitle,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: Gap.lg),
                    if (rows.isEmpty)
                      _EmptyMarket()
                    else
                      for (final branch in rows) ...[
                        _StoreCard(branch: branch),
                        const SizedBox(height: Gap.md),
                      ],
                  ],
                ),
              ),
            ),
            // Above the nav bar, on every Market surface.
            const Positioned(
              left: Gap.lg,
              right: Gap.lg,
              bottom: Gap.navClearance - 24,
              child: FloatingCartBar(),
            ),
          ],
        ),
      ),
    );
  }

  // Room for the nav bar AND the floating cart that sits above it.
  EdgeInsets _padding(BuildContext context) => const EdgeInsets.fromLTRB(
        Gap.lg,
        Gap.md,
        Gap.lg,
        Gap.navClearance + 76,
      );
}

/// One shop, with its terms for THIS shopper's address.
class _StoreCard extends StatelessWidget {
  const _StoreCard({required this.branch});

  final MarketBranch branch;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ManfaaCard(
      padding: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.card),
        onTap: () => context.push('/market/${branch.branchId}'),
        child: Padding(
          padding: const EdgeInsets.all(Gap.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  IconTile(Icons.storefront_rounded,
                      tint: ManfaaTint.violet, size: 44, iconSize: 22),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          branch.displayName(dhivehi),
                          style: theme.textTheme.titleMedium,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        // Brand AND island — never a bare branch id.
                        Text(
                          branch.branchName,
                          style: theme.textTheme.bodySmall?.copyWith(color: muted),
                        ),
                      ],
                    ),
                  ),
                  if (branch.cashbackRatePercent != null)
                    StatusChip(
                      label: l10n.cashbackPercent(branch.cashbackRatePercent!),
                      tone: StatusTone.confirmed,
                    ),
                ],
              ),
              const SizedBox(height: Gap.md),
              Wrap(
                spacing: Gap.md,
                runSpacing: Gap.xs,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  // A shop nobody has rated shows NOTHING here — zero stars
                  // would libel it on its first day.
                  if (branch.rating != null)
                    _Fact(
                      icon: Icons.star_rounded,
                      label: '${branch.rating} (${branch.ratingCount})',
                    ),
                  if (branch.delivery.etaLabel != null)
                    _Fact(
                      icon: Icons.schedule_rounded,
                      label: branch.delivery.etaLabel!,
                    ),
                  if (branch.pickupOnly)
                    // Never hidden for not delivering here: the customer may
                    // still collect.
                    _Fact(
                      icon: Icons.storefront_outlined,
                      label: l10n.pickupOnly,
                    )
                  else ...[
                    _Fact(
                      icon: Icons.delivery_dining_rounded,
                      label: branch.delivery.feeLaari == 0
                          ? l10n.freeDelivery
                          : formatMoney(branch.delivery.feeLaari, dhivehi: dhivehi),
                    ),
                    if (branch.delivery.orderMinimumLaari != null)
                      _Fact(
                        icon: Icons.shopping_basket_outlined,
                        label: l10n.minOrder(formatMoney(
                          branch.delivery.orderMinimumLaari!,
                          dhivehi: dhivehi,
                        )),
                      ),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: muted),
        const SizedBox(width: 4),
        Text(label, style: theme.textTheme.bodySmall?.copyWith(color: muted)),
      ],
    );
  }
}

class _EmptyMarket extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.huge),
      child: Column(
        children: [
          IconTile(Icons.storefront_outlined,
              tint: ManfaaTint.violet, size: 56, iconSize: 28),
          const SizedBox(height: Gap.lg),
          Text(l10n.marketEmpty, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.xs),
          Text(
            l10n.marketEmptyHint,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}
