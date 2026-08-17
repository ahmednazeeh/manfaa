import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../../widgets/tx_format.dart';
import '../money/money_providers.dart';
import '../more/estate_widgets.dart' show PaneHint;
import 'settlement_detail_screen.dart' show SettlementDetailBody;
import 'settlement_pay_screen.dart';
import 'settlement_widgets.dart';

/// MR7 — which settlement the expanded overview | detail split is showing.
/// Session state, deliberately NOT autoDispose: rail navigation away and
/// back keeps the selection exactly as left. Phones never read it — they
/// push the detail route instead.
final settlementsSelectionProvider = StateProvider<int?>((_) => null);

/// The Settlements tab (MR3), drawn to Settlements.png: amount-due hero +
/// Pay now, the discount banner, the payment-method selector (wallet with
/// its Insufficient state vs bank transfer), the breakdown, the included
/// transactions, recent settlements, and the full-width Pay CTA.
///
/// Money law: every figure is a PRICED preview integer. Settle-all is the
/// default and travels as the `settle_all` MODE; narrowing to a preset
/// re-prices the server's own membership ids and submits exactly those ids
/// — the app never derives money or membership itself.
class SettlementsScreen extends ConsumerWidget {
  const SettlementsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final canPreview = session.can('settlements.preview');
    final priced = canPreview ? ref.watch(pricedPreviewProvider) : null;
    final catalogue = canPreview ? ref.watch(settleAllPreviewProvider) : null;

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    // The domain's "nothing to settle" 422 is an empty board, not an error.
    final emptyBoard =
        catalogue?.error is MobileApiException &&
        (catalogue!.error! as MobileApiException).status == 422;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: SCREEN layout reads CONTENT width (the rail shell's 96dp is
        // already gone from these constraints). ≥840dp of content splits
        // into the tab's overview | the selected settlement's detail; the
        // pay flow stays a pushed route — a half-width pane would cramp
        // the receipt form. Phones render the shipped column untouched.
        child: LayoutBuilder(
          builder: (context, constraints) {
            final expanded = constraints.maxWidth >= kExpandedMinWidth;
            final selectedId = expanded
                ? ref.watch(settlementsSelectionProvider)
                : null;

            final board = <Widget>[
              if (canPreview && !emptyBoard) ...[
                ...?priced?.when(
                  loading: () => const [
                    SkeletonBox(height: 108, radius: Corner.card),
                    SizedBox(height: Gap.md),
                    SkeletonBox(height: 220, radius: Corner.card),
                  ],
                  error: (error, _) => [
                    _PreviewError(
                      error: error,
                      onRetry: () {
                        ref.invalidate(settleAllPreviewProvider);
                      },
                    ),
                  ],
                  data: (preview) => _payBlocks(context, ref, session, preview),
                ),
                const SizedBox(height: Gap.md),
              ] else if (emptyBoard) ...[
                _EmptyBoard(),
                const SizedBox(height: Gap.md),
              ],
              _RecentSettlements(
                onSelect: expanded
                    ? (settlement) =>
                          ref
                                  .read(settlementsSelectionProvider.notifier)
                                  .state =
                              settlement.id
                    : null,
              ),
            ];

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
                    Text(
                      l10n.settlementsTitle,
                      style: theme.textTheme.headlineMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: Gap.xs),
                    Text(
                      l10n.settlementsSubtitle,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: Gap.lg),
                    if (!expanded)
                      ...board
                    else
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: board,
                            ),
                          ),
                          const SizedBox(width: Gap.lg),
                          Expanded(
                            child: selectedId == null
                                ? PaneHint(
                                    icon: Icons.account_balance_outlined,
                                    title: l10n.paneSettlementHintTitle,
                                    body: l10n.paneSettlementHintBody,
                                  )
                                : SettlementDetailBody(
                                    key: ValueKey(selectedId),
                                    id: selectedId,
                                    embedded: true,
                                  ),
                          ),
                        ],
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

  List<Widget> _payBlocks(
    BuildContext context,
    WidgetRef ref,
    MerchantSession session,
    SettlementPreviewData preview,
  ) {
    final l10n = context.l10n;
    final preset = ref.watch(settlementsPresetProvider);
    final catalogue = ref.watch(settleAllPreviewProvider).valueOrNull;
    final canSeeWallet = session.can('wallet.view');
    final wallet = canSeeWallet ? ref.watch(walletProvider).valueOrNull : null;
    final method = ref.watch(_methodProvider);

    final nothingDue = preview.amountDueLaari == 0;
    final walletSufficient =
        wallet != null &&
        wallet.balanceLaari >= preview.amountDueLaari &&
        session.can('wallet.settle');
    final canBank = session.can('settlements.create');

    // The effective method: bank unless the wallet was chosen AND can pay.
    final useWallet = method == 'wallet' && walletSufficient;

    void startPay() {
      if (nothingDue || useWallet) {
        _confirmWalletSettle(context, ref, preview, preset, nothingDue);
        return;
      }
      Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => SettlementPayScreen(
            settleAll: preset == 'all',
            transactionIds: preview.transactionIds,
            preview: preview,
          ),
        ),
      );
    }

    final payEnabled = nothingDue
        ? session.can('wallet.settle')
        : (useWallet ? true : canBank);

    return [
      _AmountDueHero(preview: preview, onPayNow: payEnabled ? startPay : null),
      const SizedBox(height: Gap.md),
      if (preview.discount != null) ...[
        DiscountKeepBanner(discount: preview.discount!, dueAt: preview.dueAt),
        const SizedBox(height: Gap.md),
      ],
      if (catalogue != null && catalogue.buckets.length > 1) ...[
        _PresetChips(catalogue: catalogue),
        const SizedBox(height: Gap.md),
      ],
      // The settle-all nudge: while a SUBSET is selected, the catalogue's
      // whole-board verdict says what taking the rest too would earn.
      if (preset != 'all' &&
          catalogue?.discount != null &&
          catalogue!.discount!.eligible &&
          !catalogue.discount!.disabled &&
          catalogue.discount!.discountLaari > 0) ...[
        ToneBanner(
          tone: ToneSurface.info,
          icon: Icons.percent_rounded,
          title: l10n.discountNudgeTitle(
            trimRatePercent(catalogue.discount!.ratePercent),
          ),
          body: l10n.discountNudgeSaving(
            formatMoney(
              catalogue.discount!.discountLaari,
              dhivehi: Localizations.localeOf(context).languageCode == 'dv',
            ),
          ),
          action: OutlinedButton(
            style: OutlinedButton.styleFrom(minimumSize: const Size(0, 40)),
            onPressed: () =>
                ref.read(settlementsPresetProvider.notifier).state = 'all',
            child: Text(l10n.settleEverythingCta),
          ),
        ),
        const SizedBox(height: Gap.md),
      ],
      if (!nothingDue && (canSeeWallet || canBank)) ...[
        _PaymentMethodCard(
          walletBalanceLaari: canSeeWallet ? wallet?.balanceLaari : null,
          amountDueLaari: preview.amountDueLaari,
          walletSelectable: walletSufficient,
          showWallet: canSeeWallet,
          showBank: true,
        ),
        const SizedBox(height: Gap.md),
      ],
      if (nothingDue) ...[
        ToneBanner(
          tone: ToneSurface.confirmed,
          icon: Icons.check_circle_outline_rounded,
          title: l10n.nothingDueTitle,
          body: l10n.nothingDueBody,
        ),
        const SizedBox(height: Gap.md),
      ],
      PreviewBreakdownCard(preview: preview),
      const SizedBox(height: Gap.md),
      _IncludedTransactions(preview: preview),
      const SizedBox(height: Gap.md),
      FilledButton(
        onPressed: payEnabled ? startPay : null,
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
        child: Text(
          nothingDue
              ? l10n.confirmNothingDueCta
              : l10n.payAmountCta(
                  formatMoney(
                    preview.amountDueLaari,
                    dhivehi:
                        Localizations.localeOf(context).languageCode == 'dv',
                  ),
                ),
        ),
      ),
    ];
  }

  Future<void> _confirmWalletSettle(
    BuildContext context,
    WidgetRef ref,
    SettlementPreviewData preview,
    String preset,
    bool nothingDue,
  ) async {
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(
          nothingDue
              ? l10n.confirmNothingDueCta
              : l10n.walletSettleConfirmTitle,
        ),
        content: Text(
          nothingDue
              ? l10n.nothingDueBody
              : l10n.walletSettleConfirmBody(
                  formatMoney(preview.amountDueLaari, dhivehi: dhivehi),
                ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(0, 44)),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(
              nothingDue ? l10n.confirmNothingDueCta : l10n.walletSettleCta,
            ),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      // The selection the PREVIEW priced goes through unchanged: settle-all
      // as the mode, a preset as exactly its ids.
      final settlement = await ref
          .read(apiProvider)
          .walletSettle(
            settleAll: preset == 'all',
            transactionIds: preset == 'all' ? null : preview.transactionIds,
          );
      if (!context.mounted) return;
      invalidateMoney(ref);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.settledOutrightTitle)));
      context.push('/settlements/${settlement.id}');
    } on MobileApiException catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            e.message.isNotEmpty ? e.message : l10n.walletSettleFailed,
          ),
        ),
      );
    }
  }
}

/// bank | wallet — which funding source the merchant picked. Bank is the
/// default (Settlements.png marks it Recommended).
final _methodProvider = StateProvider.autoDispose<String>((_) => 'bank');

class _AmountDueHero extends StatelessWidget {
  const _AmountDueHero({required this.preview, this.onPayNow});

  final SettlementPreviewData preview;
  final VoidCallback? onPayNow;

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
                  l10n.amountDueNow,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: MoneyText(
                    preview.amountDueLaari,
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.outstandingTxCount(preview.transactionCount),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          if (onPayNow != null) ...[
            const SizedBox(width: Gap.sm),
            FilledButton(
              style: FilledButton.styleFrom(
                minimumSize: const Size(0, 44),
                padding: const EdgeInsets.symmetric(horizontal: 18),
              ),
              onPressed: onPayNow,
              child: Text(l10n.payNow),
            ),
          ],
        ],
      ),
    );
  }
}

/// The preset chips: All / Older than 5 / Older than 10 / Overdue — the
/// server's own buckets, an empty one unpickable. Switching sends the
/// bucket's OWN ids back as the selection.
class _PresetChips extends ConsumerWidget {
  const _PresetChips({required this.catalogue});

  final SettlementPreviewData catalogue;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final preset = ref.watch(settlementsPresetProvider);

    Widget chip(String key, String label) {
      final bucket = catalogue.buckets[key];
      final empty = key != 'all' && (bucket?.count ?? 0) == 0;
      final selected = preset == key;

      return Padding(
        padding: const EdgeInsetsDirectional.only(end: Gap.sm),
        child: ChoiceChip(
          label: Text(label),
          selected: selected,
          onSelected: empty
              ? null
              : (_) => ref.read(settlementsPresetProvider.notifier).state = key,
          labelStyle: theme.textTheme.labelLarge?.copyWith(
            color: selected
                ? theme.colorScheme.onSecondaryContainer
                : theme.colorScheme.onSurfaceVariant,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
          ),
          selectedColor: theme.colorScheme.secondaryContainer,
          showCheckmark: false,
        ),
      );
    }

    return SizedBox(
      height: 40,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          chip('all', l10n.presetAllLabel),
          chip('older_than_5', l10n.presetOlder5),
          chip('older_than_10', l10n.presetOlder10),
          chip('overdue', l10n.bucketOverdue),
        ],
      ),
    );
  }
}

/// Payment method (Settlements.png): the wallet option with its balance and
/// the Insufficient chip when it cannot cover the due amount, and bank
/// transfer marked Recommended. Selection is honest — an insufficient
/// wallet cannot be chosen, only seen.
class _PaymentMethodCard extends ConsumerWidget {
  const _PaymentMethodCard({
    required this.walletBalanceLaari,
    required this.amountDueLaari,
    required this.walletSelectable,
    required this.showWallet,
    required this.showBank,
  });

  final int? walletBalanceLaari;
  final int amountDueLaari;
  final bool walletSelectable;
  final bool showWallet;
  final bool showBank;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final method = ref.watch(_methodProvider);
    final insufficient =
        walletBalanceLaari != null && walletBalanceLaari! < amountDueLaari;

    Widget option({
      required String key,
      required IconData icon,
      required ManfaaTint tint,
      required String title,
      Widget? subtitle,
      Widget? trailing,
      required bool selectable,
    }) {
      final selected = method == key && selectable;

      return InkWell(
        borderRadius: BorderRadius.circular(Corner.control),
        onTap: selectable
            ? () => ref.read(_methodProvider.notifier).state = key
            : null,
        child: Container(
          padding: const EdgeInsets.all(Gap.md),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(Corner.control),
            // Blue, as Settlements.png draws the selected method — the bank
            // family's colour, not the merchant accent.
            border: Border.all(
              color: selected
                  ? ManfaaColors.blue
                  : theme.colorScheme.outlineVariant,
              width: selected ? 1.6 : 1,
            ),
          ),
          child: Row(
            children: [
              IconTile(icon, tint: tint, size: 40, iconSize: 21),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: selectable
                            ? theme.colorScheme.onSurface
                            : theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    if (subtitle != null) ...[
                      const SizedBox(height: 2),
                      subtitle,
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[
                const SizedBox(width: Gap.sm),
                trailing,
              ],
              const SizedBox(width: Gap.sm),
              Icon(
                selected
                    ? Icons.radio_button_checked_rounded
                    : Icons.radio_button_off_rounded,
                size: 20,
                color: selected
                    ? ManfaaColors.blue
                    : theme.colorScheme.onSurfaceVariant.withValues(
                        alpha: selectable ? 1 : 0.5,
                      ),
              ),
            ],
          ),
        ),
      );
    }

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(l10n.paymentMethodTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.md),
          if (showWallet) ...[
            option(
              key: 'wallet',
              icon: Icons.account_balance_wallet_outlined,
              tint: insufficient ? ManfaaTint.coral : ManfaaTint.green,
              title: l10n.methodWalletTitle,
              subtitle: walletBalanceLaari == null
                  ? null
                  : MoneyText(
                      walletBalanceLaari!,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
              trailing: insufficient
                  ? StatusChip(
                      label: l10n.methodInsufficient,
                      tone: StatusTone.attention,
                    )
                  : null,
              selectable: walletSelectable,
            ),
            const SizedBox(height: Gap.sm),
          ],
          if (showBank)
            option(
              key: 'bank',
              icon: Icons.account_balance_rounded,
              tint: ManfaaTint.blue,
              title: l10n.methodBankTitle,
              subtitle: Text(
                l10n.methodRecommended,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.secondary,
                  fontWeight: FontWeight.w600,
                ),
              ),
              selectable: true,
            ),
        ],
      ),
    );
  }
}

/// Included transactions (Settlements.png): every selected row with its
/// server-priced cashback/fee and the awaiting-settlement chip.
class _IncludedTransactions extends StatelessWidget {
  const _IncludedTransactions({required this.preview});

  final SettlementPreviewData preview;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final rows = [
      for (final row in preview.transactions)
        if (row.selected) row,
    ];

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.includedTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          for (final (index, row) in rows.indexed) ...[
            if (index > 0) const Divider(height: Gap.lg),
            Row(
              children: [
                const IconTile(
                  Icons.receipt_long_outlined,
                  tint: ManfaaTint.violet,
                  size: 38,
                  iconSize: 19,
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        row.invoiceNo,
                        textDirection: TextDirection.ltr,
                        style: theme.textTheme.titleSmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                      Text(
                        row.occurredAt == null
                            ? '—'
                            : formatBusinessDate(row.occurredAt!),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: 4),
                      StatusChip(
                        label: l10n.awaitingSettlementChip,
                        tone: StatusTone.pending,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: Gap.sm),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      l10n.payableCashback,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    MoneyText(
                      row.cashbackLaari,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      l10n.feeShort,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    MoneyText(
                      row.feeLaari + row.feeGstLaari,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
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

/// Recent settlements — page 1 of the history, each row to its detail.
/// MR7: with [onSelect] set (the expanded split), a row selects into the
/// detail pane instead of pushing the route.
class _RecentSettlements extends ConsumerWidget {
  const _RecentSettlements({this.onSelect});

  final void Function(MerchantSettlement settlement)? onSelect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final page = ref.watch(settlementsPageProvider);

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.recentTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          page.when(
            loading: () => const SkeletonBox(height: 96, radius: Corner.tile),
            error: (error, _) => Text(
              error is MobileApiException && error.message.isNotEmpty
                  ? error.message
                  : l10n.errorGeneric,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            data: (page) => page.items.isEmpty
                ? Text(
                    l10n.noSettlementsYet,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  )
                : Column(
                    children: [
                      for (final (index, settlement) in page.items.indexed) ...[
                        if (index > 0) const Divider(height: 1),
                        SettlementRow(
                          settlement: settlement,
                          onTap: () => onSelect != null
                              ? onSelect!(settlement)
                              : context.push('/settlements/${settlement.id}'),
                        ),
                      ],
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

class _EmptyBoard extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        children: [
          const SizedBox(height: Gap.md),
          const IconTile(
            Icons.check_circle_outline_rounded,
            tint: ManfaaTint.green,
            size: 56,
            iconSize: 26,
          ),
          const SizedBox(height: Gap.md),
          Text(l10n.emptySettleTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.xs),
          Text(
            l10n.emptySettleBody,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: Gap.md),
        ],
      ),
    );
  }
}

class _PreviewError extends StatelessWidget {
  const _PreviewError({required this.error, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final message =
        error is MobileApiException &&
            (error as MobileApiException).message.isNotEmpty
        ? (error as MobileApiException).message
        : l10n.settlePreviewFailed;

    return ManfaaCard(
      child: Column(
        children: [
          Text(message, textAlign: TextAlign.center),
          const SizedBox(height: Gap.md),
          OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
        ],
      ),
    );
  }
}
