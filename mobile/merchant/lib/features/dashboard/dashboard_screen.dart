import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../fee_promotion/fee_promotion_banner.dart';
import '../money/money_providers.dart';
import '../onboarding/coach_marks.dart';
import '../onboarding/dashboard_tour.dart';
import '../../widgets/tx_format.dart';
import '../push/push_registrar.dart';

/// The Dashboard (MR3), drawn to Dashboard.png and reordered to the owner's
/// counter report (MR11): the outstanding hero with Settle now and the
/// saving as an action, the Credit customer card beside/under it, this
/// month, today, and the wallet last.
///
/// Every block renders exactly what the server serves and nothing else:
/// the money cards exist only when /merchant/home carries `outstanding`
/// (the server withholds it without `settlements.view`), the wallet card
/// only behind `wallet.view`, and the deadline banner only when the
/// settle-all preview both may be read (`settlements.preview`) and has
/// something to price.
/// A single-tint diagonal wash for a dashboard card (owner, 2026-08-24):
/// the tint's soft colour fading into plain surface — "almost white", a
/// hint of what the card is about, never a banner. Same recipe as the
/// customer app's money card, one colour instead of two, and fainter.
LinearGradient cardWash(BuildContext context, ManfaaTint tint) {
  final theme = Theme.of(context);
  final surface = theme.colorScheme.surfaceContainerLowest;
  final alpha = theme.brightness == Brightness.light ? 0.40 : 0.13;
  final soft = tintColors(tint, theme.brightness).bg;

  return LinearGradient(
    begin: AlignmentDirectional.topStart,
    end: AlignmentDirectional.bottomEnd,
    colors: [Color.alphaBlend(soft.withValues(alpha: alpha), surface), surface],
    stops: const [0.0, 0.85],
  );
}

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
    // Show the cached board instantly; refetch in the background only when
    // it is older than moneyStaleAfter (owner report 2026-08-23 — the
    // Dashboard used to cold-fetch on every visit).
    Future.microtask(() => refreshStaleMoney(ref));
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
                    // The walkthrough's offer (owner, 2026-08-25). Draws
                    // nothing once it has been watched, waved away, or the
                    // person's five days are up — and nothing at all for a
                    // merchant past their first week.
                    const TourPromptCard(bottomGap: Gap.md),
                    // The fee promotion sits ABOVE the money blocks and
                    // OUTSIDE home.when: it is the platform's announcement
                    // to this store, and a /merchant/home that failed is no
                    // reason to withhold it. Draws nothing at all when
                    // nothing is running (FeePromotionBanner).
                    const FeePromotionBanner(bottomGap: Gap.md),
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

    // The owner's running order (MR11): what the store OWES, then the till's
    // way in, then the takings — month, day — and the wallet last, because
    // it is the least-used card at a counter. Same permission gates as
    // before, block for block.
    //
    // The money blocks exist only when the server chose to show them — null
    // means this account may not learn the store's standing.
    final settlementCard = outstanding == null
        ? null
        : _SettlementCard(
            outstanding: outstanding,
            canSettle: session.can('settlements.create'),
            canPreview: session.can('settlements.preview'),
          );
    final creditCta = session.can('credits.create') ? _creditCta(context) : null;

    if (expanded) {
      // "Move Credit customer next to Outstanding card" — on the slate that
      // is literally beside it; the takings pair up underneath and the
      // wallet closes the page.
      final head = <Widget>[?settlementCard, ?creditCta];

      return [
        if (head.length == 2)
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: head.first),
              const SizedBox(width: Gap.md),
              Expanded(child: head.last),
            ],
          )
        else if (head.isNotEmpty)
          head.single,
        if (head.isNotEmpty) const SizedBox(height: Gap.md),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: _MonthCard(month: home.month)),
            const SizedBox(width: Gap.md),
            Expanded(child: TodayStrip(today: home.today)),
          ],
        ),
        if (session.can('wallet.view')) ...[
          const SizedBox(height: Gap.md),
          const _WalletCard(),
        ],
      ];
    }

    return [
      // ONE card for the whole liability (owner report 2026-08-18): the
      // amount, the saving as an action, and the accounting behind a
      // disclosure — instead of five cards narrating one number.
      if (settlementCard != null) ...[
        settlementCard,
        const SizedBox(height: Gap.md),
      ],
      // The till's way in, right under what it costs.
      if (creditCta != null) ...[creditCta, const SizedBox(height: Gap.md)],
      // What Manfaa GENERATED — the month, then the day.
      _MonthCard(month: home.month),
      const SizedBox(height: Gap.md),
      TodayStrip(today: home.today),
      if (session.can('wallet.view')) ...[
        const SizedBox(height: Gap.md),
        const _WalletCard(),
      ],
    ];
  }

  /// The till's way in — and the guided tour's second stop, hence the
  /// anchor: the walkthrough lights up THIS card, not a picture of it.
  Widget _creditCta(BuildContext context) {
    final l10n = context.l10n;

    return CoachAnchor(
      id: kCoachDashCredit,
      child: ManfaaCard(
        onTap: () => context.go('/credit'),
        gradient: cardWash(context, ManfaaTint.green),
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
      gradient: cardWash(context, ManfaaTint.blue),
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
/// as an ACTION, and the accounting detail hides behind "View breakdown"
/// until asked for. MR11 took the ageing strip out too: age only matters
/// where it changes what gets paid, which is the Settlements tab.
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

    // Anchored for the guided tour (owner, 2026-08-25): the walkthrough's
    // "what you owe Manfaa" step lights up THIS card.
    return CoachAnchor(
      id: kCoachDashOutstanding,
      child: ManfaaCard(
        gradient: cardWash(context, ManfaaTint.violet),
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

            // ── the accounting, on request ────────────────────────────────
            // (MR11, owner: "Remove ageing bucket from outstanding to settle
            // card" — the ageing story lives in Settlements, where the age
            // presets actually change what gets paid.)
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
        // The tax on the fee, on its own line and only when there is one —
        // a standing MVR 0.00 row told the merchant nothing.
        if (total.feeGstLaari > 0)
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
