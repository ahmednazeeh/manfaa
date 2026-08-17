import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';

/// MR0 placeholders behind the permission-gated nav. Each tab already wears
/// the real header idiom (wordmark + store avatar, Dashboard.png) so the
/// rounds that follow only replace the body:
/// Credit + Transactions → MR2, Dashboard + Settlements → MR3, More → MR5.
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context) => _PlaceholderTab(
        icon: Icons.home_rounded,
        tint: ManfaaTint.violet,
        body: (l10n) => l10n.dashboardComingBody,
      );
}

class CreditScreen extends StatelessWidget {
  const CreditScreen({super.key});

  @override
  Widget build(BuildContext context) => _PlaceholderTab(
        icon: Icons.person_add_rounded,
        tint: ManfaaTint.green,
        body: (l10n) => l10n.creditComingBody,
      );
}

class TransactionsScreen extends StatelessWidget {
  const TransactionsScreen({super.key});

  @override
  Widget build(BuildContext context) => _PlaceholderTab(
        icon: Icons.receipt_long_rounded,
        tint: ManfaaTint.blue,
        body: (l10n) => l10n.transactionsComingBody,
      );
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
            const IconTile(Icons.logout_rounded,
                tint: ManfaaTint.coral, size: 44, iconSize: 22),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Text(
                l10n.signOut,
                style: theme.textTheme.titleMedium
                    ?.copyWith(color: ManfaaColors.coralDeep),
              ),
            ),
            Icon(Icons.chevron_right_rounded,
                color: theme.colorScheme.onSurfaceVariant),
          ],
        ),
      ),
    );
  }
}

class _PlaceholderTab extends ConsumerWidget {
  const _PlaceholderTab({
    required this.icon,
    required this.tint,
    required this.body,
    this.footer,
  });

  final IconData icon;
  final ManfaaTint tint;
  final String Function(dynamic l10n) body;
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
              Expanded(
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconTile(icon, tint: tint, size: 64, iconSize: 30),
                      const SizedBox(height: Gap.xl),
                      Text(l10n.comingSoonTitle,
                          style: theme.textTheme.headlineSmall,
                          textAlign: TextAlign.center),
                      const SizedBox(height: Gap.sm),
                      Text(
                        body(l10n),
                        style: theme.textTheme.bodyLarge?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant),
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
