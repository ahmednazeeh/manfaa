import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../money/money_providers.dart';
import '../settlements/settlement_widgets.dart';

/// The domain's code for a claim under the platform floor. In practice the
/// Laravel `min` rule on the same field fires first and arrives as
/// `validation_failed` with meta.fields.amount — both land on the amount
/// box, so the constant stays for the day the rule is dropped.
const _kTopUpBelowMinimum = 'top_up_below_minimum';

/// Funding the wallet by bank transfer (owner, 2026-08-24) — the SAME
/// receipt-first flow settlements use: type the amount, pick the platform
/// bank and transfer at your own bank, upload the slip (the bank's
/// reference too if you have it), submit. Submitting creates a PENDING
/// claim; the balance moves only once the transfer is matched against the
/// bank's history (or by an admin), and the screen says exactly that.
///
/// The bank details are the WALLET payload's (`bank_accounts` — the same
/// active accounts a settlement's payment instructions name), so a store
/// with nothing payable and no settlement history can still fund itself;
/// the screen only says the details could not be loaded when the wallet
/// itself would not.
class WalletTopUpScreen extends ConsumerStatefulWidget {
  const WalletTopUpScreen({super.key});

  @override
  ConsumerState<WalletTopUpScreen> createState() => _WalletTopUpScreenState();
}

class _WalletTopUpScreenState extends ConsumerState<WalletTopUpScreen> {
  final _amount = TextEditingController();
  final _bankRef = TextEditingController();

  /// WHICH platform account the merchant says they will pay — REQUIRED on
  /// this route. Null until they pick; with one account on offer that one
  /// is preselected at read time (see [_chosenDestination]).
  int? _destinationId;

  var _amountTouched = false;
  String? _amountError;
  var _busy = false;
  MobileApiException? _error;
  WalletTopUpClaim? _created;

  @override
  void dispose() {
    _amount.dispose();
    _bankRef.dispose();
    super.dispose();
  }

  /// The platform's accounts off the wallet payload, or null while it is
  /// still loading (or failed).
  List<PlatformBankAccount>? get _accounts =>
      ref.read(walletProvider).valueOrNull?.bankAccounts;

  /// Preselected ONLY when there is nothing to choose (web parity): with
  /// two banks on offer a default decides where someone's money goes.
  int? _chosenDestination(List<PlatformBankAccount>? accounts) =>
      _destinationId ??
      (accounts != null && accounts.length == 1 ? accounts.first.id : null);

  /// The floor the wallet payload reports — zero while unknown (the server
  /// enforces it again regardless).
  int get _minimum => ref.read(walletProvider).valueOrNull?.topUpMinLaari ?? 0;

  int? get _amountLaari => parseMvrToLaari(_amount.text);

  String? _validateAmount() {
    final l10n = context.l10n;
    final laari = _amountLaari;
    if (laari == null || laari <= 0) return l10n.topUpAmountInvalid;
    final minimum = _minimum;
    if (laari < minimum) {
      return l10n.topUpBelowMinimum(
        formatMoney(
          minimum,
          dhivehi: Localizations.localeOf(context).languageCode == 'dv',
        ),
      );
    }
    return null;
  }

  Future<void> _submit(ReceiptSubmission receipt) async {
    final l10n = context.l10n;
    final amountError = _validateAmount();
    final destination = _chosenDestination(_accounts);

    setState(() {
      _amountTouched = true;
      _amountError = amountError;
      _error = null;
    });
    if (amountError != null) {
      // Said beside the submit button too — the box is a screen away.
      setState(
        () => _error = MobileApiException(
          code: ApiCode.validationFailed,
          message: amountError,
        ),
      );
      return;
    }
    if (destination == null) {
      setState(
        () => _error = MobileApiException(
          code: ApiCode.validationFailed,
          message: l10n.chooseBankFirst,
        ),
      );
      return;
    }

    setState(() => _busy = true);
    try {
      final claim = await ref.read(apiProvider).createWalletTopUp(
            amountLaari: receipt.amountLaari,
            platformBankAccountId: destination,
            slipBytes: receipt.slipBytes,
            slipFilename: receipt.slipFilename,
            bankRef: _bankRef.text,
          );
      if (!mounted) return;
      // The claim now sits on the wallet's pending list.
      ref.invalidate(walletProvider);
      setState(() => _created = claim);
    } on MobileApiException catch (e) {
      if (!mounted) return;
      final fieldMessage = e.fieldMessages('amount').firstOrNull;
      if (e.code == _kTopUpBelowMinimum || fieldMessage != null) {
        // The platform floor moved under the form (or the box was wrong):
        // the refusal belongs on the amount, in the server's own words.
        final message = e.message.isNotEmpty ? e.message : fieldMessage;
        setState(() {
          _amountError = message;
          _error = MobileApiException(
            code: ApiCode.validationFailed,
            message: message ?? l10n.topUpAmountInvalid,
          );
        });
      } else {
        setState(() => _error = e);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final created = _created;
    final amount = _amountLaari ?? 0;
    // The wallet payload carries the floor AND the accounts; watched so a
    // refreshed wallet moves both without a rebuild of this screen's own.
    final wallet = ref.watch(walletProvider);
    final minimum = wallet.valueOrNull?.topUpMinLaari ?? 0;
    final accounts = wallet.valueOrNull?.bankAccounts;
    final chosenId = _chosenDestination(accounts);
    final loadError = wallet.hasError && accounts == null
        ? wallet.error
        : null;

    return Scaffold(
      appBar: AppBar(
        title: Text(created == null ? l10n.walletTopUpTitle : ''),
        titleTextStyle: theme.textTheme.titleMedium,
      ),
      body: SafeArea(
        child: created != null
            ? _SuccessView(claim: created)
            : ListView(
                // The floating nav bar overlays this branch's screens: the
                // submit button must scroll clear of it, not under it.
                padding: EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.sm,
                  Gap.xl,
                  bottomClearanceOf(context),
                ),
                children: [
                  ManfaaCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          l10n.topUpAmountLabel,
                          style: theme.textTheme.labelLarge,
                        ),
                        const SizedBox(height: Gap.sm),
                        TextField(
                          key: const Key('top-up-amount'),
                          controller: _amount,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          textDirection: TextDirection.ltr,
                          style: theme.textTheme.bodyLarge?.copyWith(
                            fontFeatures: const [FontFeature.tabularFigures()],
                          ),
                          onChanged: (_) => setState(() {
                            // Re-validate live once the box has been judged
                            // — the refusal clears the moment it is fixed,
                            // and so does a submit refusal, which was about
                            // a figure that no longer exists.
                            _amountError = _amountTouched
                                ? _validateAmount()
                                : null;
                            _error = null;
                          }),
                          decoration: InputDecoration(
                            prefixText: 'MVR ',
                            hintText: '0.00',
                            helperText: minimum > 0
                                ? l10n.topUpMinimumHint(
                                    formatMoney(minimum, dhivehi: dhivehi),
                                  )
                                : null,
                            errorText: _amountError,
                          ),
                        ),
                        const SizedBox(height: Gap.lg),
                        Text(
                          l10n.topUpBankRefLabel,
                          style: theme.textTheme.labelLarge,
                        ),
                        const SizedBox(height: Gap.sm),
                        TextField(
                          key: const Key('top-up-bank-ref'),
                          controller: _bankRef,
                          textDirection: TextDirection.ltr,
                          maxLength: 128,
                          decoration: InputDecoration(
                            counterText: '',
                            helperText: l10n.topUpBankRefHint,
                            helperMaxLines: 2,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: Gap.md),
                  if (loadError != null)
                    ManfaaCard(
                      child: Column(
                        children: [
                          Text(
                            loadError is MobileApiException &&
                                    loadError.message.isNotEmpty
                                ? loadError.message
                                : l10n.topUpBankUnavailable,
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: Gap.md),
                          OutlinedButton(
                            onPressed: () => ref.invalidate(walletProvider),
                            child: Text(l10n.retry),
                          ),
                        ],
                      ),
                    )
                  else if (accounts == null) ...[
                    const SkeletonBox(height: 220, radius: Corner.card),
                    const SizedBox(height: Gap.md),
                    const SkeletonBox(height: 280, radius: Corner.card),
                  ] else ...[
                    ManfaaCard(
                      child: PaymentInstructionsCard(
                        instructions: SettlementInstructions(
                          // NO reference, ever: the claim is matched by
                          // amount and the bank's reference, not by a
                          // number of ours.
                          amountDueLaari: amount,
                          bankAccount: accounts.firstOrNull,
                          bankAccounts: accounts,
                          needsConfiguration: accounts.isEmpty,
                        ),
                        amountDueLaari: amount,
                        selectedAccountId: chosenId,
                        onSelectAccount: (id) =>
                            setState(() => _destinationId = id),
                        referenceNote: l10n.topUpReferenceNote,
                      ),
                    ),
                    const SizedBox(height: Gap.md),
                    ManfaaCard(
                      // NOT re-keyed on the amount: the merchant may fix
                      // the figure after picking the slip, and the form
                      // reads the live amount at submit anyway.
                      child: ReceiptForm(
                        amountDueLaari: amount,
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

/// "Manfaa is verifying your transfer" — the claim is PENDING. Not
/// "topped up": nothing is balance until the transfer matches, and this
/// screen must not claim otherwise.
class _SuccessView extends StatelessWidget {
  const _SuccessView({required this.claim});

  final WalletTopUpClaim claim;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ListView(
      padding: EdgeInsets.fromLTRB(
        Gap.xl,
        Gap.xl,
        Gap.xl,
        bottomClearanceOf(context),
      ),
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
                l10n.topUpSuccessBody(
                  formatMoney(claim.amountLaari, dhivehi: dhivehi),
                ),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: Gap.lg),
              FilledButton(
                onPressed: () =>
                    context.canPop() ? context.pop() : context.go('/wallet'),
                child: Text(l10n.doneCta),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
