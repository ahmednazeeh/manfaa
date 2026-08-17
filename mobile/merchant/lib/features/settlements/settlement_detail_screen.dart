import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/tx_format.dart';
import '../money/money_providers.dart';
import 'settlement_widgets.dart';

/// One settlement under the receipt-first flow. The screen answers "what is
/// happening to my transfer" from the API's merchant_status — verifying,
/// settled, partially settled, awaiting, or REJECTED with the admin's
/// reason and the route to a fresh settlement. What remains actionable is
/// paying off a batch still owed money (a §7 partial's remainder, or an
/// admin-built awaiting_payment batch): a further receipt, behind
/// `settlements.receipt_add`.
class SettlementDetailScreen extends ConsumerStatefulWidget {
  const SettlementDetailScreen({super.key, required this.id});

  final int id;

  @override
  ConsumerState<SettlementDetailScreen> createState() =>
      _SettlementDetailScreenState();
}

class _SettlementDetailScreenState
    extends ConsumerState<SettlementDetailScreen> {
  var _receiptOpen = false;
  var _busy = false;
  MobileApiException? _error;

  Future<void> _addReceipt(ReceiptSubmission receipt) async {
    final l10n = context.l10n;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).addSettlementReceipt(
            id: widget.id,
            amountLaari: receipt.amountLaari,
            slipBytes: receipt.slipBytes,
            slipFilename: receipt.slipFilename,
          );
      if (!mounted) return;
      invalidateMoney(ref);
      ref.invalidate(settlementDetailProvider(widget.id));
      setState(() => _receiptOpen = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(l10n.receiptAddedToast)),
      );
    } on MobileApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final detail = ref.watch(settlementDetailProvider(widget.id));

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: detail.when(
          loading: () => ListView(
            padding: const EdgeInsets.all(Gap.xl),
            children: const [
              SkeletonBox(height: 40, radius: Corner.tile),
              SizedBox(height: Gap.md),
              SkeletonBox(height: 120, radius: Corner.card),
              SizedBox(height: Gap.md),
              SkeletonBox(height: 220, radius: Corner.card),
            ],
          ),
          error: (error, _) => _NotFound(
            message: error is MobileApiException &&
                    error.status != 404 &&
                    error.message.isNotEmpty
                ? error.message
                : l10n.settlementNotFound,
          ),
          data: (settlement) => _body(context, theme, l10n, settlement),
        ),
      ),
    );
  }

  Widget _body(
    BuildContext context,
    ThemeData theme,
    AppLocalizations l10n,
    MerchantSettlement settlement,
  ) {
    final session = ref.read(sessionProvider);
    final status = settlement.merchantStatus;
    final owes = settlement.remainingLaari;
    // A further receipt is accepted on an unsettled, submitted batch. While
    // one is under review the honest answer is "we are checking it".
    final canPay = session.can('settlements.receipt_add') &&
        owes > 0 &&
        (settlement.state == 'awaiting_payment' ||
            settlement.state == 'partially_settled');

    return ListView(
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
                  : context.go('/settlements'),
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    settlement.reference,
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    l10n.detailCreated(
                      formatBusinessDate(settlement.createdAt),
                    ),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            StatusChip(
              label: settlementStateLabel(l10n, settlement.state),
              tone: settlementStateTone(settlement.state),
            ),
          ],
        ),
        const SizedBox(height: Gap.md),
        _StatusStory(settlement: settlement),
        if (status.code == 'rejected' &&
            session.can('settlements.create')) ...[
          const SizedBox(height: Gap.md),
          FilledButton.icon(
            onPressed: () => context.go('/settlements'),
            icon: const Icon(Icons.add_rounded, size: 20),
            label: Text(l10n.startNewSettlement),
          ),
        ],
        if (canPay) ...[
          const SizedBox(height: Gap.md),
          ManfaaCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  settlement.state == 'awaiting_payment'
                      ? l10n.statusAwaitingTitle
                      : l10n.remainderTitle,
                  style: theme.textTheme.titleMedium,
                ),
                if (settlement.state == 'partially_settled') ...[
                  const SizedBox(height: 4),
                  Text(
                    l10n.remainderBody(
                      formatMoney(
                        owes,
                        dhivehi: Localizations.localeOf(context)
                                .languageCode ==
                            'dv',
                      ),
                    ),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
                const SizedBox(height: Gap.md),
                PaymentInstructionsCard(
                  instructions: settlement.paymentInstructions,
                  amountDueLaari: owes,
                ),
                const SizedBox(height: Gap.lg),
                if (_receiptOpen)
                  ReceiptForm(
                    amountDueLaari: owes,
                    submitLabel: l10n.submitSlipCta,
                    busy: _busy,
                    error: _error,
                    onSubmit: _addReceipt,
                  )
                else
                  FilledButton(
                    onPressed: () => setState(() => _receiptOpen = true),
                    child: Text(l10n.uploadReceiptCta),
                  ),
              ],
            ),
          ),
        ],
        const SizedBox(height: Gap.md),
        _SummaryCard(settlement: settlement),
        if (settlement.lines.isNotEmpty) ...[
          const SizedBox(height: Gap.md),
          _LinesCard(settlement: settlement),
        ],
        if (settlement.payments.isNotEmpty) ...[
          const SizedBox(height: Gap.md),
          _PaymentsCard(settlement: settlement),
        ],
      ],
    );
  }
}

/// The status story: the state told the way the merchant asked the
/// question, straight off merchant_status. An unknown code renders the
/// server's own sentence — never the raw code.
class _StatusStory extends StatelessWidget {
  const _StatusStory({required this.settlement});

  final MerchantSettlement settlement;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final status = settlement.merchantStatus;

    final (tone, icon, title, body) = switch (status.code) {
      'verifying' => (
          ToneSurface.info,
          Icons.hourglass_top_rounded,
          l10n.successVerifyingTitle,
          l10n.statusVerifyingBody,
        ),
      'settled' => (
          ToneSurface.confirmed,
          Icons.verified_rounded,
          l10n.statusSettledTitle,
          null,
        ),
      'partially_settled' => (
          ToneSurface.pending,
          Icons.rule_rounded,
          l10n.statusPartialTitle,
          null,
        ),
      'awaiting_payment' => (
          ToneSurface.pending,
          Icons.account_balance_rounded,
          l10n.statusAwaitingTitle,
          l10n.statusAwaitingBody,
        ),
      'rejected' => (
          ToneSurface.attention,
          Icons.gpp_bad_outlined,
          l10n.statusRejectedTitle,
          l10n.statusRejectedBody,
        ),
      'cancelled' => (
          ToneSurface.closed,
          Icons.block_rounded,
          l10n.statusCancelledTitle,
          null,
        ),
      'draft' => (
          ToneSurface.closed,
          Icons.hourglass_empty_rounded,
          l10n.statusDraftTitle,
          null,
        ),
      _ => (
          ToneSurface.closed,
          Icons.info_outline_rounded,
          status.message,
          null,
        ),
    };

    final rejection = status.rejection;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ToneBanner(tone: tone, icon: icon, title: title, body: body),
        if (status.code == 'rejected') ...[
          const SizedBox(height: Gap.sm),
          Container(
            padding: const EdgeInsets.all(Gap.lg),
            decoration: BoxDecoration(
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(Corner.control),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.statusRejectedReason.toUpperCase(),
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                    letterSpacing: 0.6,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  rejection?.reason ?? l10n.statusRejectedNoReason,
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.settlement});

  final MerchantSettlement settlement;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.summaryTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          MoneyRow(
            label: l10n.payableCashback,
            laari: settlement.cashbackTotalLaari,
          ),
          MoneyRow(label: l10n.payableFee, laari: settlement.feeTotalLaari),
          MoneyRow(label: l10n.payableGst, laari: settlement.feeGstTotalLaari),
          // The discount as GRANTED at submit — already inside amount_due,
          // shown as the relief it was, never applied again.
          if (settlement.discountLaari > 0) ...[
            MoneyRow(
              label: l10n.discountRow(
                settlement.discountRatePercent == null
                    ? ''
                    : trimRatePercent(settlement.discountRatePercent!),
              ),
              child: MoneyText(
                -settlement.discountLaari,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.secondary,
                ),
              ),
            ),
            Text(
              l10n.discountAppliedHint,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
          const Divider(height: Gap.xl),
          MoneyRow(
            label: l10n.summaryDue,
            laari: settlement.amountDueLaari,
            emphasized: true,
          ),
          MoneyRow(
            label: l10n.summaryReceived,
            laari: settlement.amountReceivedLaari,
          ),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    l10n.summaryMethod,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                Text(
                  settlement.fundingMethod == 'wallet'
                      ? l10n.methodWallet
                      : l10n.methodBank,
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _LinesCard extends StatelessWidget {
  const _LinesCard({required this.settlement});

  final MerchantSettlement settlement;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.linesTitle(settlement.lines.length),
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: Gap.sm),
          for (final (index, line) in settlement.lines.indexed) ...[
            if (index > 0) const Divider(height: Gap.lg),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        line.invoiceNo ?? '#${line.transactionId}',
                        textDirection: TextDirection.ltr,
                        style: theme.textTheme.titleSmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                      if (line.occurredAt != null)
                        Text(
                          formatBusinessDate(line.occurredAt!),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      const SizedBox(height: 2),
                      Text(
                        '${l10n.payableCashback} · ${l10n.feeShort} · '
                        '${l10n.payableGst}',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      Row(
                        children: [
                          MoneyText(
                            line.cashbackLaari,
                            style: theme.textTheme.bodySmall,
                          ),
                          Text(' · ', style: theme.textTheme.bodySmall),
                          MoneyText(
                            line.feeLaari,
                            style: theme.textTheme.bodySmall,
                          ),
                          Text(' · ', style: theme.textTheme.bodySmall),
                          MoneyText(
                            line.feeGstLaari,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: Gap.md),
                MoneyText(
                  line.dueLaari,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ],
          const Divider(height: Gap.xl),
          MoneyRow(
            label: l10n.totalsLabel,
            laari: settlement.amountDueLaari,
            emphasized: true,
          ),
        ],
      ),
    );
  }
}

class _PaymentsCard extends StatelessWidget {
  const _PaymentsCard({required this.settlement});

  final MerchantSettlement settlement;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    (String, StatusTone) stateChip(String state) => switch (state) {
      'matched' => (l10n.paymentMatched, StatusTone.confirmed),
      'rejected' => (l10n.paymentRejected, StatusTone.attention),
      _ => (l10n.paymentPending, StatusTone.pending),
    };

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.paymentsTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          for (final (index, payment) in settlement.payments.indexed) ...[
            if (index > 0) const Divider(height: Gap.lg),
            Row(
              children: [
                IconTile(
                  payment.hasSlip
                      ? Icons.receipt_long_rounded
                      : Icons.receipt_long_outlined,
                  tint: switch (payment.state) {
                    'matched' => ManfaaTint.green,
                    'rejected' => ManfaaTint.coral,
                    _ => ManfaaTint.amber,
                  },
                  size: 38,
                  iconSize: 19,
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      MoneyText(
                        payment.amountLaari,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      Text(
                        [
                          formatBusinessDate(payment.createdAt),
                          payment.hasSlip
                              ? l10n.paymentSlipAttached
                              : l10n.paymentNoSlip,
                          if ((payment.bankRef ?? '').isNotEmpty)
                            payment.bankRef!,
                        ].join(' · '),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      if (payment.state == 'rejected' &&
                          (payment.rejectionReason ?? '').isNotEmpty)
                        Text(
                          payment.rejectionReason!,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.error,
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: Gap.sm),
                StatusChip(
                  label: stateChip(payment.state).$1,
                  tone: stateChip(payment.state).$2,
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _NotFound extends StatelessWidget {
  const _NotFound({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Gap.huge),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const IconTile(
              Icons.search_off_rounded,
              tint: ManfaaTint.neutral,
              size: 56,
              iconSize: 26,
            ),
            const SizedBox(height: Gap.md),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: Gap.md),
            OutlinedButton(
              onPressed: () => context.canPop()
                  ? context.pop()
                  : context.go('/settlements'),
              child: Text(context.l10n.back),
            ),
          ],
        ),
      ),
    );
  }
}
