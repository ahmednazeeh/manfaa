import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show Clipboard, ClipboardData;
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
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Text(
                l10n.payoutAccountIntro,
                style: theme.textTheme.bodyMedium?.copyWith(color: muted),
              ),
            ),
            const SizedBox(width: Gap.md),
            // The mock's floating bank tile — pure decoration.
            Transform.rotate(
              angle: 0.12,
              child: const IconTile(Icons.account_balance_rounded,
                  tint: ManfaaTint.green, size: 54, iconSize: 28),
            ),
          ],
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
              const SizedBox(height: Gap.sm),
              // Locked once a code is out — the bank is part of what the code
              // confirms, so it must not shift underneath the confirmation.
              IgnorePointer(
                ignoring: _busy || _codeSent,
                child: Opacity(
                  opacity: _codeSent ? 0.6 : 1,
                  child: Column(
                    children: [
                      for (final (value, label) in _banks) ...[
                        _BankRow(
                          label: label,
                          logoAsset: 'assets/banks/$value.png',
                          selected: _bank == value,
                          onTap: () => setState(() => _bank = value),
                        ),
                        if (value != _banks.last.$1)
                          const SizedBox(height: Gap.sm),
                      ],
                    ],
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
                decoration: InputDecoration(
                  labelText: l10n.payoutAccountNoLabel,
                  floatingLabelBehavior: FloatingLabelBehavior.always,
                  suffixIcon: _CopySuffix(controller: _accountNo),
                ),
              ),
              const SizedBox(height: Gap.md),
              TextField(
                controller: _accountName,
                enabled: !_codeSent,
                textCapitalization: TextCapitalization.words,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  labelText: l10n.payoutAccountNameLabel,
                  floatingLabelBehavior: FloatingLabelBehavior.always,
                  suffixIcon: _CopySuffix(controller: _accountName),
                ),
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
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.verified_user_outlined, size: 15, color: muted),
              const SizedBox(width: Gap.xs),
              Flexible(
                child: Text(
                  l10n.payoutChangeEffective,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}


/// A bank choice as the mock draws it: radio ring, name, and the bank's real
/// logo trailing. Selected rows fill softly and take the outline.
class _BankRow extends StatelessWidget {
  const _BankRow({
    required this.label,
    required this.logoAsset,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final String logoAsset;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Material(
      color: selected
          ? scheme.surfaceContainer
          : scheme.surfaceContainerLowest,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Corner.control),
        side: BorderSide(
          color: selected ? scheme.outline : scheme.outlineVariant,
          width: selected ? 1.4 : 1,
        ),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.control),
        onTap: onTap,
        child: Padding(
          padding:
              const EdgeInsets.symmetric(horizontal: Gap.md, vertical: Gap.md),
          child: Row(
            children: [
              // The radio ring, drawn to match the ink brand rather than the
              // Material default.
              Container(
                width: 20,
                height: 20,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: selected ? scheme.onSurface : scheme.outline,
                    width: 2,
                  ),
                ),
                child: selected
                    ? Center(
                        child: Container(
                          width: 10,
                          height: 10,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: scheme.onSurface,
                          ),
                        ),
                      )
                    : null,
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Text(label, style: theme.textTheme.titleSmall),
              ),
              const SizedBox(width: Gap.sm),
              ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: Image.asset(
                  logoAsset,
                  width: 28,
                  height: 28,
                  fit: BoxFit.contain,
                  errorBuilder: (_, _, _) => Icon(
                    Icons.account_balance_rounded,
                    size: 22,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The mock's copy affordance on the account fields.
class _CopySuffix extends StatelessWidget {
  const _CopySuffix({required this.controller});

  final TextEditingController controller;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      icon: const Icon(Icons.copy_rounded, size: 18),
      color: Theme.of(context).colorScheme.onSurfaceVariant,
      onPressed: () async {
        final text = controller.text.trim();
        if (text.isEmpty) return;
        await Clipboard.setData(ClipboardData(text: text));
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(context.l10n.copiedToClipboard)),
          );
        }
      },
    );
  }
}
