import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../money/money_providers.dart';
import '../../widgets/tx_format.dart';
import '../push/push_registrar.dart';

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
    Future.microtask(() => ref.read(pushRegistrarProvider).ensureRegistered());
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
        // Content width, not window width: at ≥840dp of CONTENT the cards
        // reflow into two columns (the shell's rail has already taken its
        // 96dp by the time these constraints arrive).
        child: LayoutBuilder(
          builder: (context, constraints) {
            final expanded = constraints.maxWidth >= kExpandedMinWidth;
            return RefreshIndicator(
              onRefresh: () async => invalidateMoney(ref),
              child: ContentRail(
                maxWidth: expanded ? kWideContentWidth : kContentRailWidth,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: EdgeInsets.fromLTRB(
                    Gap.xl,
                    Gap.lg,
                    Gap.xl,
                    bottomClearanceOf(context),
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
                      data: (home) => _blocks(
                        context,
                        ref,
                        session,
                        home,
                        expanded: expanded,
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        ),
      ),
    );
  }

  List<Widget> _blocks(
    BuildContext context,
    WidgetRef ref,
    MerchantSession session,
    MerchantHome home, {
    required bool expanded,
  }) {
    final outstanding = home.outstanding;

    if (expanded) {
      // Two balanced card columns under the full-width hero + banner; the
      // ageing buckets go 4-across. Same blocks, same permission gates —
      // only the flow changes.
      final left = <Widget>[
        _MonthCard(month: home.month),
        const SizedBox(height: Gap.md),
        if (session.can('credits.create')) ...[
          _creditCta(context),
          const SizedBox(height: Gap.md),
        ],
      ];
      final right = <Widget>[
        TodayStrip(today: home.today),
        const SizedBox(height: Gap.md),
        if (session.can('wallet.view')) ...[
          const _WalletCard(),
          const SizedBox(height: Gap.md),
        ],
      ];

      return [
        if (outstanding != null) ...[
          _SettlementCard(
            outstanding: outstanding,
            canSettle: session.can('settlements.create'),
            canPreview: session.can('settlements.preview'),
          ),
          const SizedBox(height: Gap.md),
        ],
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: Column(children: left)),
            const SizedBox(width: Gap.md),
            Expanded(child: Column(children: right)),
          ],
        ),
      ];
    }

    return [
      // The money blocks exist only when the server chose to show them —
      // null means this account may not learn the store's standing.
      // ONE card for the whole liability (owner report 2026-08-18): the
      // amount, the saving as an action, ageing, and the accounting behind
      // a disclosure — instead of five cards narrating one number.
      if (outstanding != null) ...[
        _SettlementCard(
          outstanding: outstanding,
          canSettle: session.can('settlements.create'),
          canPreview: session.can('settlements.preview'),
        ),
        const SizedBox(height: Gap.md),
      ],
      // What Manfaa GENERATED, next in line after what it costs.
      _MonthCard(month: home.month),
      const SizedBox(height: Gap.md),
      TodayStrip(today: home.today),
      const SizedBox(height: Gap.md),
      if (session.can('wallet.view')) ...[
        const _WalletCard(),
        const SizedBox(height: Gap.md),
      ],
      if (session.can('credits.create')) _creditCta(context),
    ];
  }

  Widget _creditCta(BuildContext context) {
    final l10n = context.l10n;

    return ManfaaCard(
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
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
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

/// The whole settlement story in ONE card (owner report 2026-08-18): the
/// dashboard was spending its top two thirds telling the same liability
/// five times — hero, discount notice, ageing grid, payable breakdown and
/// wallet all narrating one number. Now the amount leads, the saving reads
/// as an ACTION, the ageing is a quiet four-column strip, and the
/// accounting detail hides behind "View breakdown" until asked for.
class _SettlementCard extends ConsumerStatefulWidget {
  const _SettlementCard({
    required this.outstanding,
    required this.canSettle,
    required this.canPreview,
  });

  final MerchantOutstanding outstanding;
  final bool canSettle;
  final bool canPreview;

  @override
  ConsumerState<_SettlementCard> createState() => _SettlementCardState();
}

class _SettlementCardState extends ConsumerState<_SettlementCard> {
  var _open = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final muted = theme.colorScheme.onSurfaceVariant;
    final total = widget.outstanding.total;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── the amount, and the one button that clears it ──────────────
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.dashOutstandingTitle,
                      style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                    ),
                    const SizedBox(height: 2),
                    MoneyText(
                      total.payableLaari,
                      style: theme.textTheme.headlineMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      l10n.bucketTransactions(total.count),
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
              if (widget.canSettle)
                FilledButton(
                  onPressed: () => context.go('/settlements'),
                  style: FilledButton.styleFrom(
                    minimumSize: const Size(0, 44),
                    padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
                  ),
                  child: Text(l10n.settleNow),
                ),
            ],
          ),

          // ── the saving, as an action ──────────────────────────────────
          if (widget.canPreview && total.count > 0) ...[
            const SizedBox(height: Gap.md),
            const _SavingLine(),
          ],

          // ── ageing, four quiet columns ────────────────────────────────
          if (total.count > 0) ...[
            const SizedBox(height: Gap.md),
            Divider(height: 1, color: theme.colorScheme.outlineVariant),
            const SizedBox(height: Gap.md),
            Text(
              l10n.agingTitle,
              style: theme.textTheme.labelLarge?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.sm),
            _AgingStrip(outstanding: widget.outstanding),
          ],

          // ── the accounting, on request ────────────────────────────────
          const SizedBox(height: Gap.sm),
          InkWell(
            borderRadius: BorderRadius.circular(Corner.tile),
            onTap: () => setState(() => _open = !_open),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: Gap.sm),
              child: Row(
                children: [
                  Text(
                    l10n.viewBreakdown,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.secondary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(width: Gap.xs),
                  Icon(
                    _open
                        ? Icons.keyboard_arrow_up_rounded
                        : Icons.keyboard_arrow_right_rounded,
                    size: 20,
                    color: theme.colorScheme.secondary,
                  ),
                ],
              ),
            ),
          ),
          if (_open) _BreakdownRows(outstanding: widget.outstanding),
          if (dhivehi) const SizedBox.shrink(),
        ],
      ),
    );
  }
}

/// "Save MVR 1.50 by settling before 27 Aug" — the benefit and the
/// deadline in the merchant's own terms, with the fee named underneath so
/// nobody reads 5% off the whole liability (owner report 2026-08-18).
class _SavingLine extends ConsumerWidget {
  const _SavingLine();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final preview = ref.watch(settleAllPreviewProvider).valueOrNull;
    final discount = preview?.discount;

    if (preview == null || discount == null || discount.disabled) {
      return const SizedBox.shrink();
    }

    final deadline = discountDeadlineDate([
      for (final row in preview.transactions) row.clockStartAt,
    ], discount.maxAgeDays);
    if (discount.discountLaari == 0 || deadline == null) {
      return const SizedBox.shrink();
    }

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.check_circle_outline_rounded,
            size: 18,
            color: theme.colorScheme.onSecondaryContainer,
          ),
          const SizedBox(width: Gap.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.discountSaveByTitle(
                    formatMoney(discount.discountLaari, dhivehi: dhivehi),
                    deadline,
                  ),
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: theme.colorScheme.onSecondaryContainer,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.discountSaveBySub(trimRatePercent(discount.ratePercent)),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSecondaryContainer.withValues(
                      alpha: 0.75,
                    ),
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

/// Ageing as four columns of label + amount — the server's own bucket
/// sums, never recomputed here.
class _AgingStrip extends StatelessWidget {
  const _AgingStrip({required this.outstanding});

  final MerchantOutstanding outstanding;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget cell(String key, String label, Color tone) {
      final bucket = outstanding.buckets[key];
      final laari = bucket?.payableLaari ?? 0;
      return Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            const SizedBox(height: 2),
            MoneyText(
              laari,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: laari > 0 ? tone : muted,
              ),
            ),
          ],
        ),
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        cell('0_5', l10n.bucket05, theme.colorScheme.onSurface),
        cell('6_10', l10n.bucket610, theme.colorScheme.onSurface),
        cell('11_15', l10n.bucket1115, ManfaaColors.amber),
        cell('overdue', l10n.bucketOverdue, theme.colorScheme.error),
      ],
    );
  }
}

/// The accounting the owner wanted kept but demoted: cashback, fee, GST
/// and the count, revealed under "View breakdown".
class _BreakdownRows extends StatelessWidget {
  const _BreakdownRows({required this.outstanding});

  final MerchantOutstanding outstanding;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;
    final total = outstanding.total;

    Widget row(String label, Widget value) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ),
          value,
        ],
      ),
    );

    return Column(
      children: [
        const SizedBox(height: Gap.xs),
        row(
          l10n.payableCashback,
          MoneyText(total.cashbackLaari, style: theme.textTheme.bodyMedium),
        ),
        row(
          l10n.payableFee,
          MoneyText(total.feeLaari, style: theme.textTheme.bodyMedium),
        ),
        row(
          l10n.payableGst,
          MoneyText(total.feeGstLaari, style: theme.textTheme.bodyMedium),
        ),
        if (outstanding.pendingAdjustmentCount > 0)
          row(
            l10n.payablePendingCredit,
            MoneyText(
              outstanding.pendingAdjustmentCreditLaari,
              style: theme.textTheme.bodyMedium,
            ),
          ),
        Divider(height: Gap.md, color: theme.colorScheme.outlineVariant),
        row(
          l10n.payableOutstandingCount,
          Text('${total.count}', style: theme.textTheme.titleSmall),
        ),
      ],
    );
  }
}

/// What Manfaa GENERATED this month — the half of the story the dashboard
/// was missing (owner report 2026-08-18). Same business-month boundary and
/// the same reversed/written-off exclusions as the Today strip.
class _MonthCard extends StatelessWidget {
  const _MonthCard({required this.month});

  final MerchantMonth month;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget stat(String label, Widget value) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          maxLines: 2,
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
        const SizedBox(height: 2),
        value,
      ],
    );

    final strong = theme.textTheme.titleMedium?.copyWith(
      fontWeight: FontWeight.w800,
    );

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const IconTile(
                Icons.trending_up_rounded,
                tint: ManfaaTint.green,
                size: 36,
                iconSize: 18,
              ),
              const SizedBox(width: Gap.sm),
              Text(l10n.monthTitle, style: theme.textTheme.titleMedium),
            ],
          ),
          const SizedBox(height: Gap.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: stat(
                  l10n.monthSales,
                  MoneyText(month.eligibleLaari, style: strong),
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: stat(
                  l10n.monthTransactions,
                  Text('${month.creditCount}', style: strong),
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: stat(
                  l10n.monthCashback,
                  MoneyText(
                    month.cashbackLaari,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: stat(
                  l10n.monthAverage,
                  MoneyText(
                    month.averageEligibleLaari,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
