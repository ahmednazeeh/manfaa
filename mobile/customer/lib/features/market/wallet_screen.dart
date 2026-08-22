import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';

/// The wallet (PLAN-marketplace.md §21).
///
/// A REAL balance, distinct from the derived cashback figure on Home.
/// Refunds land here the moment a shop cuts or refuses an order, and the
/// balance is always withdrawable — money that can get trapped in a wallet
/// is money somebody will rightly argue about.
final walletProvider = FutureProvider.autoDispose<WalletState>((ref) {
  return ref.watch(apiProvider).wallet();
});

class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final wallet = ref.watch(walletProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.walletTitle)),
      body: SafeArea(
        child: wallet.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Center(
            child: Text(
              error is MobileApiException ? error.message : l10n.errorGeneric,
            ),
          ),
          data: (data) => _Body(wallet: data),
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.wallet});

  final WalletState wallet;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                l10n.walletBalance,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              const SizedBox(height: Gap.xs),
              MoneyText(
                wallet.balanceLaari,
                style: theme.textTheme.displaySmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.md),
              if (!wallet.hasBankAccount)
                Text(
                  l10n.walletNoBank,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: ManfaaColors.amber,
                  ),
                )
              else if (!wallet.canWithdraw)
                Text(
                  l10n.walletMinimum(
                    formatMoney(
                      wallet.minimumWithdrawalLaari,
                      dhivehi: dhivehi,
                    ),
                  ),
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                )
              else
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: ManfaaColors.violet,
                    ),
                    onPressed: () => _withdraw(context, ref, wallet),
                    child: Text(l10n.walletWithdraw),
                  ),
                ),
            ],
          ),
        ),
        if (wallet.withdrawals.isNotEmpty) ...[
          const SizedBox(height: Gap.lg),
          SectionHeader(l10n.walletWithdrawals),
          const SizedBox(height: Gap.sm),
          for (final withdrawal in wallet.withdrawals)
            ManfaaCard(
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          withdrawal.state.replaceAll('_', ' '),
                          style: theme.textTheme.bodyMedium,
                        ),
                        // Only ever the BANK's reference. An approval-queue
                        // id shown here would be quoted at a bank and get
                        // nowhere.
                        if (withdrawal.bankReference != null)
                          Text(
                            withdrawal.bankReference!,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: muted,
                            ),
                          ),
                      ],
                    ),
                  ),
                  MoneyText(
                    withdrawal.amountLaari,
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
              ),
            ),
        ],
        const SizedBox(height: Gap.lg),
        SectionHeader(l10n.walletHistory),
        const SizedBox(height: Gap.sm),
        if (wallet.entries.isEmpty)
          Text(
            l10n.walletEmpty,
            style: theme.textTheme.bodyMedium?.copyWith(color: muted),
          )
        else
          for (final entry in wallet.entries)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: Gap.sm),
              child: Row(
                children: [
                  IconTile(
                    entry.amountLaari >= 0
                        ? Icons.south_west_rounded
                        : Icons.north_east_rounded,
                    tint: entry.amountLaari >= 0
                        ? ManfaaTint.green
                        : ManfaaTint.ink,
                    size: 36,
                    iconSize: 18,
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Text(
                      entry.description ?? entry.type.replaceAll('_', ' '),
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                  Text(
                    '${entry.amountLaari >= 0 ? '+' : '−'} '
                    '${formatMoney(entry.amountLaari.abs(), dhivehi: dhivehi)}',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: entry.amountLaari >= 0 ? ManfaaColors.green : null,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
      ],
    );
  }

  Future<void> _withdraw(
    BuildContext context,
    WidgetRef ref,
    WalletState wallet,
  ) async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.walletWithdraw),
        content: Text(
          formatMoney(
            wallet.balanceLaari,
            dhivehi: Localizations.localeOf(context).languageCode == 'dv',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(l10n.walletWithdraw),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      await ref.read(apiProvider).requestWithdrawal(wallet.balanceLaari);
      ref.invalidate(walletProvider);
      messenger.showSnackBar(SnackBar(content: Text(l10n.withdrawalRequested)));
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            e is MobileApiException ? e.message : l10n.errorGeneric,
          ),
        ),
      );
    }
  }
}
