import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../widgets/tx_format.dart';
import '../money/money_providers.dart';

/// The Wallet (MR3): balance hero + the movements ledger, straight off
/// WalletResource — signed integer laari per movement, the running
/// balance-after beside each, and the type said in words (top-up, spent on
/// a settlement, credit from a settlement), never the raw code.
class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final wallet = ref.watch(walletProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(walletProvider),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              Gap.xl,
              Gap.sm,
              Gap.xl,
              Gap.navClearance,
            ),
            children: [
              Row(
                children: [
                  IconButton(
                    icon: const Icon(Icons.arrow_back_rounded),
                    onPressed: () => context.canPop()
                        ? context.pop()
                        : context.go('/dashboard'),
                  ),
                  Text(
                    l10n.walletScreenTitle,
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Gap.md),
              wallet.when(
                loading: () =>
                    const SkeletonBox(height: 120, radius: Corner.card),
                error: (error, _) => _WalletError(
                  message: error is MobileApiException &&
                          error.message.isNotEmpty
                      ? error.message
                      : l10n.errorGeneric,
                  onRetry: () => ref.invalidate(walletProvider),
                ),
                data: (wallet) => Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _BalanceHero(wallet: wallet),
                    const SizedBox(height: Gap.md),
                    _Movements(wallet: wallet),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BalanceHero extends StatelessWidget {
  const _BalanceHero({required this.wallet});

  final MerchantWalletState wallet;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const IconTile(
            Icons.account_balance_wallet_outlined,
            tint: ManfaaTint.green,
            size: 52,
            iconSize: 26,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.walletBalanceLabel,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                MoneyText(
                  wallet.balanceLaari,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: Gap.xs),
                Text(
                  l10n.walletTopUpHint,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
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

class _Movements extends StatelessWidget {
  const _Movements({required this.wallet});

  final MerchantWalletState wallet;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.movementsTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          if (wallet.movements.isEmpty)
            Text(
              l10n.movementsEmpty,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          for (final (index, movement) in wallet.movements.indexed) ...[
            if (index > 0) const Divider(height: Gap.lg),
            Row(
              children: [
                IconTile(
                  movement.isCredit
                      ? Icons.south_west_rounded
                      : Icons.north_east_rounded,
                  tint: movement.isCredit ? ManfaaTint.green : ManfaaTint.amber,
                  size: 38,
                  iconSize: 19,
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        walletMovementLabel(l10n, movement.type),
                        style: theme.textTheme.titleSmall,
                      ),
                      if ((movement.description ?? '').isNotEmpty)
                        Text(
                          movement.description!,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                          overflow: TextOverflow.ellipsis,
                        ),
                      Text(
                        formatIsoDisplay(movement.createdAt),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: Gap.md),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    MoneyText(
                      movement.amountLaari,
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: movement.isCredit
                            ? ManfaaColors.green
                            : theme.colorScheme.onSurface,
                      ),
                    ),
                    Text(
                      l10n.balanceAfterLabel(
                        formatMoney(
                          movement.balanceAfterLaari,
                          dhivehi: dhivehi,
                        ),
                      ),
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _WalletError extends StatelessWidget {
  const _WalletError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return ManfaaCard(
      child: Column(
        children: [
          Text(message, textAlign: TextAlign.center),
          const SizedBox(height: Gap.md),
          OutlinedButton(
            onPressed: onRetry,
            child: Text(context.l10n.retry),
          ),
        ],
      ),
    );
  }
}
