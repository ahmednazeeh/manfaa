import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../money/money_providers.dart';
import 'settlement_widgets.dart';

/// The bank path of the receipt-first pay flow (PLAN §1): the platform's
/// bank instructions with copy buttons — transfer at your own bank — then
/// the slip and the amount actually transferred. Submitting is the ONLY act
/// that creates a settlement; the batch lands in payment_review and Manfaa
/// verifies the slip.
///
/// The selection the preview priced goes through unchanged: settle-all as
/// the race-proof `settle_all` MODE, a subset as exactly its ids.
class SettlementPayScreen extends ConsumerStatefulWidget {
  const SettlementPayScreen({
    super.key,
    required this.settleAll,
    required this.transactionIds,
    required this.preview,
  });

  final bool settleAll;
  final List<int> transactionIds;
  final SettlementPreviewData preview;

  @override
  ConsumerState<SettlementPayScreen> createState() =>
      _SettlementPayScreenState();
}

class _SettlementPayScreenState extends ConsumerState<SettlementPayScreen> {
  /// WHICH platform account the merchant says they will pay. Null until
  /// they pick (or when only one exists, that one). Sent so the batch is
  /// reconciled against the right statement.
  int? _destinationId;
  var _busy = false;
  MobileApiException? _error;
  MerchantSettlement? _created;

  @override
  void initState() {
    super.initState();
    final accounts = widget.preview.paymentInstructions.bankAccounts;
    // Preselected ONLY when there is nothing to choose (web parity).
    if (accounts.length == 1) _destinationId = accounts.first.id;
  }

  Future<void> _submit(ReceiptSubmission receipt) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final settlement = await ref.read(apiProvider).createSettlement(
            settleAll: widget.settleAll,
            transactionIds: widget.settleAll ? null : widget.transactionIds,
            amountLaari: receipt.amountLaari,
            slipBytes: receipt.slipBytes,
            slipFilename: receipt.slipFilename,
            platformBankAccountId: _destinationId,
          );
      if (!mounted) return;
      invalidateMoney(ref);
      setState(() => _created = settlement);
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
    final created = _created;

    return Scaffold(
      appBar: AppBar(
        title: Text(created == null ? l10n.payBankTitle : ''),
        titleTextStyle: theme.textTheme.titleMedium,
      ),
      body: SafeArea(
        child: created != null
            ? _SuccessView(settlement: created)
            : ListView(
                padding: const EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.sm,
                  Gap.xl,
                  Gap.huge,
                ),
                children: [
                  Text(
                    l10n.payBankLead,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: Gap.lg),
                  ManfaaCard(
                    child: PaymentInstructionsCard(
                      instructions: widget.preview.paymentInstructions,
                      amountDueLaari: widget.preview.amountDueLaari,
                      selectedAccountId: _destinationId,
                      onSelectAccount: (id) =>
                          setState(() => _destinationId = id),
                    ),
                  ),
                  const SizedBox(height: Gap.md),
                  ManfaaCard(
                    child: ReceiptForm(
                      amountDueLaari: widget.preview.amountDueLaari,
                      submitLabel: l10n.submitSlipCta,
                      busy: _busy,
                      error: _error,
                      onSubmit: _submit,
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

/// "Manfaa is verifying your transfer" — the receipt landed the batch in
/// payment_review. Not "settled": nothing is settled until the slip
/// matches, and this screen must not claim otherwise.
class _SuccessView extends ConsumerWidget {
  const _SuccessView({required this.settlement});

  final MerchantSettlement settlement;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ListView(
      padding: const EdgeInsets.all(Gap.xl),
      children: [
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const IconTile(
                    Icons.verified_user_outlined,
                    tint: ManfaaTint.green,
                    size: 48,
                    iconSize: 24,
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Text(
                      l10n.successVerifyingTitle,
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Gap.md),
              Text(
                l10n.successVerifyingBody(settlement.reference),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              if (settlement.discountLaari > 0) ...[
                const SizedBox(height: Gap.md),
                Text(
                  l10n.discountSavedNote(
                    formatMoney(settlement.discountLaari, dhivehi: dhivehi),
                  ),
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: theme.colorScheme.secondary,
                  ),
                ),
              ],
              const SizedBox(height: Gap.lg),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      onPressed: () {
                        Navigator.of(context).pop();
                        context.push('/settlements/${settlement.id}');
                      },
                      child: Text(l10n.viewSettlementCta),
                    ),
                  ),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: Text(l10n.doneCta),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }
}
