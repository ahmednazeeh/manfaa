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
    final preview = _preview;

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
                    ManfaaCard(
                      child: PaymentInstructionsCard(
                        instructions: preview.paymentInstructions,
                        amountDueLaari: preview.amountDueLaari,
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
