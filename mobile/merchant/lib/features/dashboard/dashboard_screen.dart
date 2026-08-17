import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';
import '../money/money_providers.dart';
import '../push/push_registrar.dart';
import '../settlements/settlement_widgets.dart';

/// The Dashboard (MR3), drawn to Dashboard.png: the outstanding hero with
/// Settle now, the prompt-discount deadline banner, the 2×2 ageing buckets,
/// the payable breakdown, the wallet card and the credit CTA — plus the
/// MR2 Today strip.
///
/// Every block renders exactly what the server serves and nothing else:
/// the money cards exist only when /merchant/home carries `outstanding`
/// (the server withholds it without `settlements.view`), the wallet card
/// only behind `wallet.view`, and the deadline banner only when the
/// settle-all preview both may be read (`settlements.preview`) and has
/// something to price.
class DashboardScreen extends ConsumerStatefulWidget {
  const DashboardScreen({super.key});

  @override
  ConsumerState<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends ConsumerState<DashboardScreen> {
  @override
  void initState() {
    super.initState();
    // Push permission is asked HERE — signed in, the outstanding hero on
    // screen, the value obvious — never on first launch (PushRegistrar's
    // docblock). Same timing pattern as the customer app's Home.
    Future.microtask(
      () => ref.read(pushRegistrarProvider).ensureRegistered(),
    );
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final home = ref.watch(homeProvider);

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => invalidateMoney(ref),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              Gap.xl,
              Gap.lg,
              Gap.xl,
              Gap.navClearance,
            ),
            children: [
              MerchantTopBar(initials: initials),
              const SizedBox(height: Gap.lg),
              ...home.when(
                loading: () => const [
                  SkeletonBox(height: 108, radius: Corner.card),
                  SizedBox(height: Gap.md),
                  SkeletonBox(height: 96, radius: Corner.card),
                  SizedBox(height: Gap.md),
                  SkeletonBox(height: 200, radius: Corner.card),
                ],
                error: (error, _) => [
                  _ErrorBlock(
                    message: error is MobileApiException
                        ? error.message
                        : l10n.errorGeneric,
                    onRetry: () => ref.invalidate(homeProvider),
                  ),
                ],
                data: (home) => _blocks(context, ref, session, home),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _blocks(
    BuildContext context,
    WidgetRef ref,
    MerchantSession session,
    MerchantHome home,
  ) {
    final l10n = context.l10n;
    final outstanding = home.outstanding;

    return [
      // The money blocks exist only when the server chose to show them —
      // null means this account may not learn the store's standing.
      if (outstanding != null) ...[
        _OutstandingHero(
          outstanding: outstanding,
          canSettle: session.can('settlements.create'),
        ),
        const SizedBox(height: Gap.md),
        if (session.can('settlements.preview') && outstanding.total.count > 0)
          const _DeadlineBanner(),
        _BucketsGrid(outstanding: outstanding),
        const SizedBox(height: Gap.md),
        _PayableBreakdown(outstanding: outstanding),
        const SizedBox(height: Gap.md),
      ],
      if (session.can('wallet.view')) ...[
        const _WalletCard(),
        const SizedBox(height: Gap.md),
      ],
      TodayStrip(today: home.today),
      const SizedBox(height: Gap.md),
      if (session.can('credits.create'))
        ManfaaCard(
          onTap: () => context.go('/credit'),
          child: Row(
            children: [
              const IconTile(
                Icons.person_add_alt_rounded,
                tint: ManfaaTint.green,
                size: 48,
                iconSize: 24,
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.creditCtaTitle,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      l10n.creditCtaBody,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color:
                                Theme.of(context).colorScheme.onSurfaceVariant,
                          ),
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.chevron_right_rounded,
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ],
          ),
        ),
    ];
  }
}

/// The violet-soft hero (Dashboard.png): coin tile, "Outstanding to
/// settle", the server's payable total, and the ink Settle now button —
/// which is `settlements.create`'s to press.
class _OutstandingHero extends StatelessWidget {
  const _OutstandingHero({required this.outstanding, required this.canSettle});

  final MerchantOutstanding outstanding;
  final bool canSettle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer.withValues(alpha: 0.55),
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        children: [
          const IconTile(
            Icons.paid_outlined,
            tint: ManfaaTint.violet,
            size: 44,
            iconSize: 23,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.dashOutstandingTitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                // Scale down rather than wrap: a split money figure is
                // unreadable, and the ref draws it on one line.
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: MoneyText(
                    outstanding.total.payableLaari,
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.dashOutstandingSub,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          if (canSettle) ...[
            const SizedBox(width: Gap.sm),
            FilledButton(
              // The theme's minimumSize is full-width; this one sits in a row.
              style: FilledButton.styleFrom(
                minimumSize: const Size(0, 44),
                padding: const EdgeInsets.symmetric(horizontal: 18),
              ),
              onPressed: () => context.go('/settlements'),
              child: Text(l10n.settleNow),
            ),
          ],
        ],
      ),
    );
  }
}

/// The deadline banner, fed by the settle-all preview — the one endpoint
/// that evaluates the discount. Loading and failure draw nothing: a
/// countdown is a bonus, never an error surface.
class _DeadlineBanner extends ConsumerWidget {
  const _DeadlineBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final preview = ref.watch(settleAllPreviewProvider).valueOrNull;
    final discount = preview?.discount;
    if (preview == null || discount == null) return const SizedBox.shrink();

    return Column(
      children: [
        DiscountDeadlineBanner(discount: discount, rows: preview.transactions),
        const SizedBox(height: Gap.md),
      ],
    );
  }
}

/// The 2×2 ageing grid (0–5 / 6–10 / 11–15 / Overdue), each cell the
/// server's own bucket sum and count — never recomputed here.
class _BucketsGrid extends StatelessWidget {
  const _BucketsGrid({required this.outstanding});

  final MerchantOutstanding outstanding;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    Widget cell(String key, String label, IconData icon, ManfaaTint tint) {
      final bucket = outstanding.buckets[key];

      return Expanded(
        child: _BucketCard(
          label: label,
          icon: icon,
          tint: tint,
          payableLaari: bucket?.payableLaari ?? 0,
          count: bucket?.count ?? 0,
        ),
      );
    }

    return Column(
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            cell(
              '0_5',
              l10n.bucket05,
              Icons.trending_up_rounded,
              ManfaaTint.green,
            ),
            const SizedBox(width: Gap.md),
            cell(
              '6_10',
              l10n.bucket610,
              Icons.arrow_forward_rounded,
              ManfaaTint.blue,
            ),
          ],
        ),
        const SizedBox(height: Gap.md),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            cell(
              '11_15',
              l10n.bucket1115,
              Icons.schedule_rounded,
              ManfaaTint.amber,
            ),
            const SizedBox(width: Gap.md),
            cell(
              'overdue',
              l10n.bucketOverdue,
              Icons.error_outline_rounded,
              ManfaaTint.coral,
            ),
          ],
        ),
      ],
    );
  }
}

class _BucketCard extends StatelessWidget {
  const _BucketCard({
    required this.label,
    required this.icon,
    required this.tint,
    required this.payableLaari,
    required this.count,
  });

  final String label;
  final IconData icon;
  final ManfaaTint tint;
  final int payableLaari;
  final int count;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  label,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              IconTile(icon, tint: tint, size: 32, iconSize: 17),
            ],
          ),
          const SizedBox(height: Gap.xs),
          MoneyText(
            payableLaari,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            l10n.bucketTransactions(count),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

/// Payable breakdown (Dashboard.png): cashback / fee / GST rows, the
/// outstanding count, the §7 pending-adjustment credit when one exists,
/// and the violet MVR chip carrying the total.
class _PayableBreakdown extends StatelessWidget {
  const _PayableBreakdown({required this.outstanding});

  final MerchantOutstanding outstanding;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final total = outstanding.total;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  l10n.payableBreakdownTitle,
                  style: theme.textTheme.titleMedium,
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 5,
                ),
                decoration: BoxDecoration(
                  color: theme.colorScheme.secondaryContainer,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: MoneyText(
                  total.payableLaari,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: theme.colorScheme.onSecondaryContainer,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          MoneyRow(label: l10n.payableCashback, laari: total.cashbackLaari),
          MoneyRow(label: l10n.payableFee, laari: total.feeLaari),
          MoneyRow(label: l10n.payableGst, laari: total.feeGstLaari),
          if (outstanding.pendingAdjustmentCount > 0)
            MoneyRow(
              label: l10n.payablePendingCredit,
              child: MoneyText(
                outstanding.pendingAdjustmentCreditLaari,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: ManfaaColors.green,
                ),
              ),
            ),
          const Divider(height: Gap.xl),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    l10n.payableOutstandingCount,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                Text(
                  '${total.count}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The wallet card (Dashboard.png): balance + helper + View movements.
/// Failure renders the quiet unavailable line, never an error card — this
/// account HAS the wallet, the read just failed.
class _WalletCard extends ConsumerWidget {
  const _WalletCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final wallet = ref.watch(walletProvider);

    return ManfaaCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.walletCardTitle, style: theme.textTheme.titleMedium),
                const SizedBox(height: Gap.xs),
                wallet.when(
                  loading: () =>
                      const SkeletonBox(height: 26, width: 120, radius: 8),
                  error: (_, _) => Text(
                    l10n.walletUnavailable,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  data: (wallet) => MoneyText(
                    wallet.balanceLaari,
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                const SizedBox(height: Gap.xs),
                Text(
                  l10n.walletCardHint,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.md),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(minimumSize: const Size(0, 40)),
            onPressed: () => context.push('/wallet'),
            icon: const Icon(Icons.account_balance_wallet_outlined, size: 18),
            label: Text(l10n.walletViewMovements),
          ),
        ],
      ),
    );
  }
}

/// The Today strip from MR2 — the till worker's glance (credit count,
/// eligible, cashback) in the BUSINESS day, reversed/written-off already
/// excluded server-side.
class TodayStrip extends StatelessWidget {
  const TodayStrip({super.key, required this.today});

  final MerchantToday today;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget cell(String label, Widget value, {int flex = 3}) => Expanded(
      flex: flex,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: theme.textTheme.labelSmall?.copyWith(color: muted),
          ),
          const SizedBox(height: 2),
          value,
        ],
      ),
    );

    final valueStyle = theme.textTheme.titleSmall?.copyWith(
      fontWeight: FontWeight.w800,
    );

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const IconTile(
                Icons.today_rounded,
                tint: ManfaaTint.violet,
                size: 36,
                iconSize: 19,
              ),
              const SizedBox(width: Gap.sm),
              Text(l10n.todayTitle, style: theme.textTheme.titleMedium),
            ],
          ),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              cell(
                l10n.todayCredits,
                Text('${today.creditCount}', style: valueStyle),
                flex: 2,
              ),
              cell(
                l10n.todayEligible,
                MoneyText(today.eligibleLaari, style: valueStyle),
              ),
              cell(
                l10n.todayCashback,
                MoneyText(today.cashbackLaari, style: valueStyle),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ErrorBlock extends StatelessWidget {
  const _ErrorBlock({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return Padding(
      padding: const EdgeInsets.only(top: Gap.huge),
      child: Column(
        children: [
          const IconTile(
            Icons.wifi_off_rounded,
            tint: ManfaaTint.neutral,
            size: 56,
            iconSize: 26,
          ),
          const SizedBox(height: Gap.md),
          Text(message, textAlign: TextAlign.center),
          const SizedBox(height: Gap.md),
          OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
        ],
      ),
    );
  }
}
