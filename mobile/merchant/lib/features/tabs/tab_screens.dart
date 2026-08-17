import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';

/// MR0 placeholders behind the permission-gated nav (Credit + Transactions
/// grew into real screens in MR2 — features/credit, features/transactions).
/// Each remaining tab wears the real header idiom (wordmark + store avatar,
/// Dashboard.png) so the rounds that follow only replace the body:
/// Dashboard + Settlements → MR3, More → MR5.

/// The full /merchant/home answer; the Dashboard needs today's tally now,
/// and MR3 will read outstanding + the open batch from this same provider.
final homeProvider = FutureProvider.autoDispose<MerchantHome>(
  (ref) => ref.watch(apiProvider).home(),
);

/// Dashboard: the MR3 money screen is still on its way, but the TODAY
/// strip — the till worker's glance (credit count, eligible, cashback) —
/// ships with MR2.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final home = ref.watch(homeProvider);

    return _PlaceholderTab(
      icon: Icons.home_rounded,
      tint: ManfaaTint.violet,
      body: (l10n) => l10n.dashboardComingBody,
      header: home.when(
        loading: () => const SkeletonBox(height: 92, radius: Corner.card),
        // A failed tally is not a failed screen — MR3 owns the full
        // error surface; the strip simply sits out.
        error: (_, _) => null,
        data: (home) => _TodayStrip(today: home.today),
      ),
    );
  }
}

class SettlementsScreen extends StatelessWidget {
  const SettlementsScreen({super.key});

  @override
  Widget build(BuildContext context) => _PlaceholderTab(
    icon: Icons.account_balance_rounded,
    tint: ManfaaTint.amber,
    body: (l10n) => l10n.settlementsComingBody,
  );
}

/// More is MR5, but the log-out row from Merchant More.png ships now — an
/// active merchant must always have the exit in hand.
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return _PlaceholderTab(
      icon: Icons.more_horiz_rounded,
      tint: ManfaaTint.ink,
      body: (l10n) => l10n.moreComingBody,
      footer: ManfaaCard(
        onTap: () => ref.read(apiProvider).signOut(),
        child: Row(
          children: [
            const IconTile(
              Icons.logout_rounded,
              tint: ManfaaTint.coral,
              size: 44,
              iconSize: 22,
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Text(
                l10n.signOut,
                style: theme.textTheme.titleMedium?.copyWith(
                  color: ManfaaColors.coralDeep,
                ),
              ),
            ),
            Icon(
              Icons.chevron_right_rounded,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ],
        ),
      ),
    );
  }
}

/// The Today strip from Dashboard.png: three cells — credits keyed, the
/// eligible total, and the cashback owed to customers — in the BUSINESS day
/// (Malé time), reversed and written-off sales already excluded server-side.
class _TodayStrip extends StatelessWidget {
  const _TodayStrip({required this.today});

  final MerchantToday today;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    // The count needs less room than the two money figures.
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

class _PlaceholderTab extends ConsumerWidget {
  const _PlaceholderTab({
    required this.icon,
    required this.tint,
    required this.body,
    this.header,
    this.footer,
  });

  final IconData icon;
  final ManfaaTint tint;
  final String Function(dynamic l10n) body;

  /// An optional card above the coming-soon block (the Dashboard's Today
  /// strip).
  final Widget? header;
  final Widget? footer;

  String _initials(MerchantSession session) {
    final name = session.merchantName ?? session.userName ?? '';
    return name.isEmpty ? 'M' : name.characters.first.toUpperCase();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: Gap.xxl),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(vertical: Gap.lg),
                child: MerchantTopBar(initials: _initials(session)),
              ),
              if (header != null) ...[header!, const SizedBox(height: Gap.md)],
              Expanded(
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconTile(icon, tint: tint, size: 64, iconSize: 30),
                      const SizedBox(height: Gap.xl),
                      Text(
                        l10n.comingSoonTitle,
                        style: theme.textTheme.headlineSmall,
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: Gap.sm),
                      Text(
                        body(l10n),
                        style: theme.textTheme.bodyLarge?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ),
                ),
              ),
              ?footer,
              const SizedBox(height: Gap.navClearance),
            ],
          ),
        ),
      ),
    );
  }
}
