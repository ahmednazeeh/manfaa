import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';

/// Where cashback is sent (R5). Changing it is money-critical, so the write
/// demands a fresh code to the number on file — a stolen 365-day token must
/// not be enough to point someone's payouts at another bank. The screen
/// mirrors that server gate: fill the form → send a code → confirm.
final payoutAccountProvider = FutureProvider.autoDispose<PayoutAccount>(
  (ref) => ref.watch(apiProvider).payoutAccount(),
);

/// The banks the platform pays to (Bank enum server-side). A deploy adds a
/// case there and a row here.
const _banks = [('bml', 'Bank of Maldives'), ('mib', 'Maldives Islamic Bank')];

class PayoutAccountScreen extends ConsumerWidget {
  const PayoutAccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final account = ref.watch(payoutAccountProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.payoutAccountTitle)),
      body: account.when(
        loading: () => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
          children: const [
            SkeletonBox(height: 60, width: 240),
            SizedBox(height: Gap.lg),
            SkeletonBox(height: 320, radius: Corner.card),
          ],
        ),
        error: (e, _) => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
          children: [
            ManfaaCard(
              child: Column(
                children: [
                  Text(
                    e is MobileApiException ? e.message : l10n.errorGeneric,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: Gap.lg),
                  OutlinedButton(
                    onPressed: () => ref.invalidate(payoutAccountProvider),
                    child: Text(l10n.retry),
                  ),
                ],
              ),
            ),
          ],
        ),
        data: (account) => _Form(account: account),
      ),
    );
  }
}

class _Form extends ConsumerStatefulWidget {
  const _Form({required this.account});

  final PayoutAccount account;

  @override
  ConsumerState<_Form> createState() => _FormState();
}

class _FormState extends ConsumerState<_Form> {
  late String _bank = widget.account.bankName ?? 'bml';
  late final _accountNo =
      TextEditingController(text: widget.account.accountNo ?? '');
  late final _accountName =
      TextEditingController(text: widget.account.accountName ?? '');
  final _code = TextEditingController();

  var _busy = false;
  var _codeSent = false;
  String? _error;

  @override
  void dispose() {
    _accountNo.dispose();
    _accountName.dispose();
    _code.dispose();
    super.dispose();
  }

  bool get _formValid =>
      RegExp(r'^\d{6,32}$').hasMatch(_accountNo.text.trim()) &&
      _accountName.text.trim().isNotEmpty;

  String _describe(Object e) {
    final l10n = context.l10n;
    if (e is! MobileApiException) return l10n.errorGeneric;

    return switch (e.code) {
      'otp_invalid' => l10n.errOtpInvalid,
      'otp_attempts_exceeded' => l10n.errOtpAttempts,
      ApiCode.rateLimited => l10n.errTooManyTries,
      _ => e.message,
    };
  }

  Future<void> _sendCode() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).requestPayoutAccountOtp();
      if (mounted) setState(() => _codeSent = true);
    } catch (e) {
      if (mounted) setState(() => _error = _describe(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).updatePayoutAccount(
            bankName: _bank,
            accountNo: _accountNo.text.trim(),
            accountName: _accountName.text.trim(),
            otpCode: _code.text.trim(),
          );
      if (!mounted) return;
      ref.invalidate(payoutAccountProvider);
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(context.l10n.payoutAccountSaved)));
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = _describe(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final infoTone = toneSurface(ToneSurface.info, theme.brightness);

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
      children: [
        Text(
          l10n.payoutAccountIntro,
          style: theme.textTheme.bodyMedium?.copyWith(color: muted),
        ),
        const SizedBox(height: Gap.lg),

        // The account details, one white card.
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const IconTile(Icons.account_balance_rounded,
                      tint: ManfaaTint.green, size: 40, iconSize: 20),
                  const SizedBox(width: Gap.md),
                  Text(l10n.payoutBankLabel,
                      style: theme.textTheme.titleMedium),
                ],
              ),
              const SizedBox(height: Gap.sm),
              // Locked once a code is out — the bank is part of what the code
              // confirms, so it must not shift underneath the confirmation.
              IgnorePointer(
                ignoring: _busy || _codeSent,
                child: Opacity(
                  opacity: _codeSent ? 0.6 : 1,
                  child: RadioGroup<String>(
                    groupValue: _bank,
                    onChanged: (v) => setState(() => _bank = v ?? _bank),
                    child: Column(
                      children: [
                        for (final (value, label) in _banks)
                          RadioListTile<String>(
                            value: value,
                            title: Text(label),
                            contentPadding: EdgeInsets.zero,
                          ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: Gap.lg),
              TextField(
                controller: _accountNo,
                enabled: !_codeSent,
                keyboardType: TextInputType.number,
                textDirection: TextDirection.ltr,
                onChanged: (_) => setState(() {}),
                decoration:
                    InputDecoration(labelText: l10n.payoutAccountNoLabel),
              ),
              const SizedBox(height: Gap.md),
              TextField(
                controller: _accountName,
                enabled: !_codeSent,
                textCapitalization: TextCapitalization.words,
                onChanged: (_) => setState(() {}),
                decoration:
                    InputDecoration(labelText: l10n.payoutAccountNameLabel),
              ),
            ],
          ),
        ),
        const SizedBox(height: Gap.lg),

        // Step two appears only once a code is on its way — the gate made
        // visible: you cannot change the destination without proving the
        // phone.
        if (_codeSent) ...[
          ManfaaCard(
            color: infoTone.background,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.sms_rounded,
                        size: 20, color: infoTone.foreground),
                    const SizedBox(width: Gap.md),
                    Expanded(
                      child: Text(l10n.payoutOtpSent,
                          style: theme.textTheme.bodyMedium),
                    ),
                  ],
                ),
                const SizedBox(height: Gap.md),
                TextField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  autofocus: true,
                  textAlign: TextAlign.center,
                  textDirection: TextDirection.ltr,
                  style:
                      theme.textTheme.headlineSmall?.copyWith(letterSpacing: 6),
                  decoration:
                      const InputDecoration(counterText: '', hintText: '••••••'),
                  onChanged: (_) => setState(() {}),
                ),
              ],
            ),
          ),
          const SizedBox(height: Gap.lg),
        ],

        if (_error != null) ...[
          Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          const SizedBox(height: Gap.md),
        ],

        FilledButton(
          onPressed: _busy || !_formValid || (_codeSent && _code.text.trim().length != 6)
              ? null
              : (_codeSent ? _save : _sendCode),
          child: _busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Flexible(
                      child: Text(
                        _codeSent ? l10n.payoutSaveAction : l10n.payoutSendCode,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: Gap.sm),
                    const Icon(Icons.arrow_forward_rounded, size: 20),
                  ],
                ),
        ),
        Padding(
          padding: const EdgeInsets.only(top: Gap.md),
          child: Text(
            l10n.payoutChangeEffective,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        ),
      ],
    );
  }
}
