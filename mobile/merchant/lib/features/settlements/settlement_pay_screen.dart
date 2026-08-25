import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../fee_promotion/fee_promotion_banner.dart';
import '../money/money_providers.dart';
import '../money/transfer_progress_view.dart';
import 'settlement_widgets.dart';

/// The bank path of the receipt-first pay flow (PLAN §1): the platform's
/// bank instructions with copy buttons — transfer at your own bank — then
/// the slip and the amount actually transferred. Submitting is the ONLY act
/// that creates a settlement; the batch lands in payment_review and Manfaa
/// verifies the slip.
///
/// MR8 (owner report): the screen carries only the SELECTION, never a
/// preview object — it prices itself FRESH on every open, so "Transfer
/// exactly this amount" can never show the figure the tab was opened with
/// hours ago. Before submitting it re-prices once more: a changed amount is
/// surfaced with a fresh confirm instead of letting a mismatched transfer
/// happen (the server prices the batch server-side regardless).
///
/// The selection goes through unchanged: settle-all as the race-proof
/// `settle_all` MODE, a subset as exactly its ids.
class SettlementPayScreen extends ConsumerStatefulWidget {
  const SettlementPayScreen({
    super.key,
    required this.settleAll,
    required this.transactionIds,
  });

  final bool settleAll;
  final List<int> transactionIds;

  @override
  ConsumerState<SettlementPayScreen> createState() =>
      _SettlementPayScreenState();
}

class _SettlementPayScreenState extends ConsumerState<SettlementPayScreen> {
  /// The FRESH preview this open fetched — everything on screen prices
  /// from this, never from the tab's copy.
  SettlementPreviewData? _preview;
  MobileApiException? _loadError;

  /// WHICH platform account the merchant says they will pay. Null until
  /// they pick (or when only one exists, that one). Sent so the batch is
  /// reconciled against the right statement.
  int? _destinationId;
  var _busy = false;
  MobileApiException? _error;

  /// Set when the pre-submit re-price came back with a DIFFERENT amount:
  /// the screen re-renders from the fresh preview and demands one more
  /// explicit confirm before anything is created.
  var _amountChanged = false;
  MerchantSettlement? _created;

  /// What the merchant SAID they transferred on the slip — not the batch's
  /// due, which an under- or over-payment does not equal. Shown while the
  /// server's own answer (which carries the same figure) is still in the
  /// air.
  int? _sentLaari;

  @override
  void initState() {
    super.initState();
    _fetchPreview();
  }

  Future<SettlementPreviewData> _price() =>
      ref.read(apiProvider).settlementPreview(
            settleAll: widget.settleAll,
            transactionIds: widget.settleAll ? null : widget.transactionIds,
          );

  Future<void> _fetchPreview() async {
    setState(() {
      _preview = null;
      _loadError = null;
    });
    try {
      final preview = await _price();
      if (!mounted) return;
      setState(() {
        _preview = preview;
        // Preselected ONLY when there is nothing to choose (web parity).
        final accounts = preview.paymentInstructions.bankAccounts;
        _destinationId = accounts.length == 1 ? accounts.first.id : null;
      });
    } on MobileApiException catch (e) {
      if (mounted) setState(() => _loadError = e);
    }
  }

  Future<void> _submit(ReceiptSubmission receipt) async {
    final shown = _preview;
    if (shown == null) return;

    setState(() {
      _busy = true;
      _error = null;
      _amountChanged = false;
    });
    try {
      // Re-validate against a LIVE price first: a credit or another
      // settlement may have moved the board since this screen rendered.
      final fresh = await _price();
      if (!mounted) return;
      if (fresh.amountDueLaari != shown.amountDueLaari) {
        setState(() {
          _preview = fresh;
          _amountChanged = true;
        });
        return;
      }

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
      setState(() {
        _created = settlement;
        _sentLaari = receipt.amountLaari;
      });
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
    final preview = _preview;

    return Scaffold(
      appBar: AppBar(
        title: Text(created == null ? l10n.payBankTitle : ''),
        titleTextStyle: theme.textTheme.titleMedium,
      ),
      body: SafeArea(
        child: created != null
            // The transfer is uploaded; from here the screen OBSERVES the
            // server's own bank watch and then says what actually happened
            // — settled, partly settled, or refused. It drives nothing:
            // the poll and the push + SMS run whether this is open or not.
            ? TransferProgressView.settlement(
                settlementId: created.id,
                reference: created.reference,
                amountLaari: _sentLaari ?? created.amountDueLaari,
                discountLaari: created.discountLaari,
                padding: const EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.xl,
                  Gap.xl,
                  Gap.huge,
                ),
              )
            : ListView(
                padding: const EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.sm,
                  Gap.xl,
                  Gap.huge,
                ),
                children: [
                  // MR11 (owner report): no lead paragraph. The AppBar
                  // already says "Transfer exactly this amount"; the card
                  // below says how much and where.
                  if (_loadError != null)
                    ManfaaCard(
                      child: Column(
                        children: [
                          Text(
                            _loadError!.message.isNotEmpty
                                ? _loadError!.message
                                : l10n.settlePreviewFailed,
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: Gap.md),
                          OutlinedButton(
                            onPressed: _fetchPreview,
                            child: Text(l10n.retry),
                          ),
                        ],
                      ),
                    )
                  else if (preview == null) ...[
                    // Pricing the fresh preview this open asked for.
                    const SkeletonBox(height: 220, radius: Corner.card),
                    const SizedBox(height: Gap.md),
                    const SkeletonBox(height: 280, radius: Corner.card),
                  ] else ...[
                    if (_amountChanged) ...[
                      ToneBanner(
                        tone: ToneSurface.pending,
                        icon: Icons.published_with_changes_rounded,
                        title: l10n.payAmountChangedTitle,
                        body: l10n.payAmountChangedBody(
                          formatMoney(
                            preview.amountDueLaari,
                            dhivehi:
                                Localizations.localeOf(context).languageCode ==
                                    'dv',
                          ),
                        ),
                      ),
                      const SizedBox(height: Gap.md),
                    ],
                    // Beside the itemised bill, for the same reason it sits
                    // on the board: the fee line the merchant is reading is
                    // the PROMOTIONAL one the server priced, and this is
                    // the only thing on the screen that says so.
                    const FeePromotionBanner(bottomGap: Gap.md),
                    ManfaaCard(
                      child: PaymentInstructionsCard(
                        instructions: preview.paymentInstructions,
                        amountDueLaari: preview.amountDueLaari,
                        // The bill itemised on the screen the merchant
                        // reads before walking to the bank: the cashback,
                        // Manfaa's fee, and the GST on that fee as its own
                        // line whenever GST applies.
                        breakdown: OwedBreakdown.fromPreview(preview),
                        selectedAccountId: _destinationId,
                        onSelectAccount: (id) =>
                            setState(() => _destinationId = id),
                      ),
                    ),
                    const SizedBox(height: Gap.md),
                    ManfaaCard(
                      // Re-keyed on the amount so a re-priced preview
                      // re-seeds the form's prefilled transfer amount.
                      child: ReceiptForm(
                        key: ValueKey('receipt-${preview.amountDueLaari}'),
                        amountDueLaari: preview.amountDueLaari,
                        submitLabel: l10n.submitSlipCta,
                        busy: _busy,
                        error: _error,
                        onSubmit: _submit,
                      ),
                    ),
                  ],
                ],
              ),
      ),
    );
  }
}
