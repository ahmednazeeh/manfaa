import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/tx_format.dart';
import '../money/money_providers.dart';

/// The Wallet (MR3): balance hero + the movements ledger, straight off
/// WalletResource — signed integer laari per movement, the running
/// balance-after beside each, and the type said in words (top-up, spent on
/// a settlement, credit from a settlement), never the raw code.
///
/// Since 2026-08-24 the wallet is PRE-FUNDABLE (owner decision, reversing
/// "wallet is not pre-funding"): the hero carries the Top up CTA into the
/// receipt-first claim flow, the claims still waiting on the bank sit under
/// it, and the auto-settle switch says whether the hourly run may spend the
/// balance on validated cashback.
class WalletScreen extends ConsumerStatefulWidget {
  const WalletScreen({super.key});

  @override
  ConsumerState<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends ConsumerState<WalletScreen> {
  @override
  void initState() {
    super.initState();
    // Serve the cached answers instantly; background-refresh anything past
    // its staleness window (same pattern as the Dashboard).
    Future.microtask(() => refreshStaleMoney(ref));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final session = ref.watch(sessionProvider);
    final wallet = ref.watch(walletProvider);

    // Drawn from the session's permissions exactly as the API gates the
    // routes: the claim behind wallet.top_up, the switch behind
    // preferences.update. Hiding is a courtesy — the server refuses anyway.
    final canTopUp = session.can('wallet.top_up');
    final canToggle = session.can('preferences.update');

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: a ledger is a single column at any size — the shared ~640dp
        // rail centers it on tablets; phones render exactly as shipped.
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(walletProvider),
          child: ContentRail(
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.fromLTRB(
                Gap.xl,
                Gap.sm,
                Gap.xl,
                bottomClearanceOf(context),
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
                    message:
                        error is MobileApiException && error.message.isNotEmpty
                        ? error.message
                        : l10n.errorGeneric,
                    onRetry: () => ref.invalidate(walletProvider),
                  ),
                  data: (wallet) => Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _BalanceHero(
                        wallet: wallet,
                        onTopUp: canTopUp
                            ? () => context.push('/wallet/top-up')
                            : null,
                      ),
                      if (wallet.pendingTopUps.isNotEmpty) ...[
                        const SizedBox(height: Gap.md),
                        _PendingTopUps(claims: wallet.pendingTopUps),
                      ],
                      const SizedBox(height: Gap.md),
                      _AutoSettleCard(wallet: wallet, enabled: canToggle),
                      const SizedBox(height: Gap.md),
                      _Movements(wallet: wallet),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The balance, and the one action on it (Dashboard.png's hero anatomy:
/// tile, label, figure, the dark button on the right). No "our team records
/// top-ups" hint any more — the merchant records them, right here.
class _BalanceHero extends StatelessWidget {
  const _BalanceHero({required this.wallet, this.onTopUp});

  final MerchantWalletState wallet;

  /// Null hides the CTA (no `wallet.top_up` on this account).
  final VoidCallback? onTopUp;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Row(
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
              ],
            ),
          ),
          if (onTopUp != null) ...[
            const SizedBox(width: Gap.md),
            FilledButton.icon(
              style: FilledButton.styleFrom(minimumSize: const Size(0, 44)),
              onPressed: onTopUp,
              icon: const Icon(Icons.add_rounded, size: 18),
              label: Text(l10n.walletTopUpCta),
            ),
          ],
        ],
      ),
    );
  }
}

/// Money the merchant has SENT that is not yet balance: each claim with
/// the bank it named, when it was raised, and where it stands. A refused
/// claim carries Manfaa's reason in a sentence, never a bare state.
class _PendingTopUps extends StatelessWidget {
  const _PendingTopUps({required this.claims});

  final List<WalletTopUpClaim> claims;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.pendingTopUpsTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          for (final (index, claim) in claims.indexed) ...[
            if (index > 0) const Divider(height: Gap.lg),
            _TopUpRow(claim: claim),
          ],
        ],
      ),
    );
  }
}

class _TopUpRow extends StatelessWidget {
  const _TopUpRow({required this.claim});

  final WalletTopUpClaim claim;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final (icon, tint, chipLabel, chipTone) = switch (claim.state) {
      'matched' => (
        Icons.check_circle_outline_rounded,
        ManfaaTint.green,
        l10n.topUpStateMatched,
        StatusTone.confirmed,
      ),
      'rejected' => (
        Icons.error_outline_rounded,
        ManfaaTint.coral,
        l10n.topUpStateRejected,
        StatusTone.attention,
      ),
      _ => (
        Icons.hourglass_empty_rounded,
        ManfaaTint.amber,
        l10n.topUpStateVerifying,
        StatusTone.pending,
      ),
    };

    final bank = claim.bank;
    final when = formatBusinessDateTime(claim.createdAt);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        IconTile(icon, tint: tint, size: 38, iconSize: 19),
        const SizedBox(width: Gap.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              MoneyText(
                claim.amountLaari,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              Text(
                bank == null
                    ? when
                    : '${l10n.topUpToBank(bankShortName(bank.bankName))}'
                          ' · $when',
                style: muted,
              ),
              if ((claim.bankRef ?? '').isNotEmpty)
                Text(
                  claim.bankRef!,
                  textDirection: TextDirection.ltr,
                  style: muted,
                  overflow: TextOverflow.ellipsis,
                ),
              if (claim.isRejected)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    (claim.rejectedReason ?? '').isNotEmpty
                        ? l10n.topUpRejectedReason(claim.rejectedReason!)
                        : l10n.topUpRejectedNoReason,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(width: Gap.sm),
        StatusChip(label: chipLabel, tone: chipTone),
      ],
    );
  }
}

/// The auto-settle switch. Rendered from the WALLET payload, written
/// through PATCH /merchant/preferences — the one write path (the API
/// exposes no wallet-scoped PATCH). Optimistic: the switch moves at once
/// and snaps back with a word if the save is refused.
class _AutoSettleCard extends ConsumerStatefulWidget {
  const _AutoSettleCard({required this.wallet, required this.enabled});

  final MerchantWalletState wallet;

  /// False without `preferences.update`: the state still shows, the switch
  /// does not move.
  final bool enabled;

  @override
  ConsumerState<_AutoSettleCard> createState() => _AutoSettleCardState();
}

class _AutoSettleCardState extends ConsumerState<_AutoSettleCard> {
  /// The value the merchant just chose, until the wallet re-reads it.
  bool? _override;
  var _busy = false;

  Future<void> _change(bool on) async {
    final l10n = context.l10n;
    setState(() {
      _override = on;
      _busy = true;
    });
    try {
      final prefs = await ref.read(apiProvider).setAutoSettle(on);
      if (!mounted) return;
      setState(() => _override = prefs.autoSettleFromWallet);
      // The wallet payload is the screen's source: refresh it so a return
      // within the cache window does not show yesterday's switch.
      ref.invalidate(walletProvider);
    } on MobileApiException catch (e) {
      if (!mounted) return;
      setState(() => _override = null);
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(
              e.message.isNotEmpty ? e.message : l10n.autoSettleFailed,
            ),
          ),
        );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final on = _override ?? widget.wallet.autoSettleFromWallet;

    return ManfaaCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.autoSettleTitle, style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  l10n.autoSettleBody,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.md),
          Switch(
            value: on,
            activeThumbColor: Colors.white,
            activeTrackColor: ManfaaColors.violet,
            onChanged: widget.enabled && !_busy ? _change : null,
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
          OutlinedButton(onPressed: onRetry, child: Text(context.l10n.retry)),
        ],
      ),
    );
  }
}
