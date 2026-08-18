import 'dart:async';
import 'dart:io' show Platform;

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../app/router.dart';
import '../../widgets/merchant_brand.dart';

/// Merchant signup (MR1): phone → 6-digit code → store details → signed in
/// as a DRAFT store, straight into the setup wizard.
///
/// Wears the Merchant Login.png language — wordmark header, heading over the
/// canvas, one white card per step, the ink CTA with the arrow, violet
/// accents. The mobile difference from the web signup: register mints a
/// normal 90-day merchant token, so finishing this screen IS being signed in.
///
/// Error handling follows the contract: KNOWN codes render localized
/// sentences (en/dv); an unknown code renders the server's own prose —
/// never a raw snake_case string.
class SignupScreen extends ConsumerStatefulWidget {
  const SignupScreen({super.key});

  @override
  ConsumerState<SignupScreen> createState() => _SignupScreenState();
}

enum _Step { phone, code, details }

class _SignupScreenState extends ConsumerState<SignupScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  final _nameDv = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();

  var _step = _Step.phone;
  var _busy = false;
  var _obscure = true;
  String? _signupToken;

  /// Client-side resend cooldown. The REAL budget is server-side (3/hour a
  /// phone, SHARED with the web signup); this just keeps an impatient thumb
  /// from spending it in the first minute.
  Timer? _resendTimer;
  int _resendIn = 0;

  static const _deviceNameFallback = 'Manfaa Merchant app';

  /// A human-recognisable device name for the 5-device list. The server
  /// hygienises it; this falls back cleanly with no platform channel.
  Future<String> _deviceName() async {
    if (kIsWeb) return _deviceNameFallback;
    try {
      final info = DeviceInfoPlugin();
      if (Platform.isAndroid) {
        final a = await info.androidInfo;
        return '${a.manufacturer} ${a.model}';
      }
      if (Platform.isIOS) {
        final i = await info.iosInfo;
        return i.name.isNotEmpty ? i.name : i.utsname.machine;
      }
    } catch (_) {
      // No channel — fall through.
    }
    return _deviceNameFallback;
  }

  @override
  void dispose() {
    _resendTimer?.cancel();
    _phone.dispose();
    _code.dispose();
    _name.dispose();
    _nameDv.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  String get _normalizedPhone => normalizePhone(_phone.text);

  void _startResendCooldown() {
    _resendTimer?.cancel();
    setState(() => _resendIn = 60);
    _resendTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_resendIn <= 1) {
        timer.cancel();
        setState(() => _resendIn = 0);
      } else {
        setState(() => _resendIn--);
      }
    });
  }

  /// The cooldown only matters while the code field is on screen — cancel
  /// it whenever the flow moves on, so no timer outlives its step.
  void _stopResendCooldown() {
    _resendTimer?.cancel();
    _resendIn = 0;
  }

  /// The contract's error rule, in one place for this screen.
  String _describe(Object error) {
    final l10n = context.l10n;

    if (error is! MobileApiException) return l10n.errorGeneric;

    return switch (error.code) {
      ApiCode.otpInvalid => l10n.errOtpInvalid,
      ApiCode.otpAttemptsExceeded => l10n.errOtpAttempts,
      ApiCode.signupTokenInvalid => l10n.errSignupExpired,
      ApiCode.emailAlreadyRegistered => l10n.errEmailRegistered,
      ApiCode.rateLimited => l10n.errTooManyTries,
      // Unknown code: the server's prose fallback is the contract.
      _ => error.message,
    };
  }

  Future<void> _run(Future<void> Function() action) async {
    setState(() => _busy = true);
    try {
      await action();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(_describe(e))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _requestCode() => _run(() async {
        try {
          await ref.read(apiProvider).requestSignupOtp(_normalizedPhone);
        } on MobileApiException catch (e) {
          // The SMS budget is spent — but a code delivered earlier is still
          // redeemable for its 10-minute life. Move to the code field anyway
          // rather than trapping someone who is holding a valid code.
          if (e.code == ApiCode.rateLimited && _step == _Step.phone) {
            _code.clear();
            if (mounted) setState(() => _step = _Step.code);
          }
          rethrow;
        }
        if (!mounted) return;
        _code.clear();
        setState(() => _step = _Step.code);
        _startResendCooldown();
      });

  Future<void> _verify() => _run(() async {
        final token = await ref.read(apiProvider).verifySignupOtp(
              phone: _normalizedPhone,
              code: _code.text.trim(),
            );

        if (!mounted) return;
        _signupToken = token;
        _stopResendCooldown();
        setState(() => _step = _Step.details);
      });

  Future<void> _register() => _run(() async {
        final l10n = context.l10n;
        final name = _name.text.trim();
        final nameDv = _nameDv.text.trim();
        final email = _email.text.trim();
        final password = _password.text;

        if (name.isEmpty || email.isEmpty || password.length < 8) {
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(l10n.errSignupDetailsMissing)));
          return;
        }

        try {
          await ref.read(apiProvider).registerMerchant(
                signupToken: _signupToken ?? '',
                businessName: name,
                businessNameDv: nameDv.isEmpty ? null : nameDv,
                email: email,
                password: password,
                deviceName: await _deviceName(),
              );
        } on MobileApiException catch (e) {
          // A dead signup token can only earn the same 422 forever — the
          // details step has no other exit, so restart from the phone.
          if (e.code == ApiCode.signupTokenInvalid) {
            _signupToken = null;
            if (mounted) setState(() => _step = _Step.phone);
          }
          rethrow;
        }

        // Signed in as a draft store; the register call wrote the status, so
        // homeLocationFor lands in the wizard.
        if (mounted) context.go(homeLocationFor(ref.read(sessionProvider)));
      });

  /// Signup is ENGLISH ONLY (owner decision 2026-08-18), the same rule the
  /// web signup follows: the account-creation form is one language on every
  /// surface, whatever the app's chosen locale is. The override is scoped to
  /// this screen — the wizard and the rest of the app stay bilingual.
  @override
  Widget build(BuildContext context) => Localizations.override(
        context: context,
        locale: const Locale('en'),
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Builder(builder: _form),
        ),
      );

  Widget _form(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final (heading, sub) = switch (_step) {
      _Step.phone => (l10n.signupTitle, l10n.signupSubtitle),
      _Step.code => (l10n.codeTitle, l10n.codeSentTo(_normalizedPhone)),
      _Step.details => (l10n.detailsTitle, l10n.detailsSubtitle),
    };

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
          children: [
            const MerchantWordmark(),
            const SizedBox(height: Gap.xl),
            Text(heading, style: theme.textTheme.headlineSmall),
            const SizedBox(height: Gap.xs),
            Text(
              sub,
              textDirection: _step == _Step.code ? TextDirection.ltr : null,
              style: theme.textTheme.bodyLarge
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: Gap.xl),
            ManfaaCard(
              padding: const EdgeInsets.all(Gap.xl),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: switch (_step) {
                  _Step.phone => _phoneStep(l10n, theme),
                  _Step.code => _codeStep(l10n, theme),
                  _Step.details => _detailsStep(l10n, theme),
                },
              ),
            ),
            const SizedBox(height: Gap.lg),
            // Wrap, not Row — see the login footer.
            Wrap(
              alignment: WrapAlignment.center,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  l10n.alreadyRegistered,
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
                TextButton(
                  onPressed: _busy ? null : () => context.go('/login'),
                  child: Text(
                    l10n.logIn,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.secondary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _primary(String label, VoidCallback? onPressed) => FilledButton(
        onPressed: onPressed,
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
                    child: Text(label,
                        maxLines: 1, overflow: TextOverflow.ellipsis),
                  ),
                  const SizedBox(width: Gap.sm),
                  const Icon(Icons.arrow_forward_rounded, size: 20),
                ],
              ),
      );

  List<Widget> _phoneStep(dynamic l10n, ThemeData theme) => [
        Text(l10n.phoneLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _phone,
          keyboardType: TextInputType.phone,
          textDirection: TextDirection.ltr,
          style: theme.textTheme.titleMedium,
          decoration: InputDecoration(
            hintText: l10n.phoneHint,
            prefixIcon: Padding(
              padding: const EdgeInsets.only(left: 16, right: 12),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text('🇲🇻', style: TextStyle(fontSize: 20)),
                  const SizedBox(width: 8),
                  Text('+960',
                      textDirection: TextDirection.ltr,
                      style: theme.textTheme.titleMedium),
                  const SizedBox(width: 10),
                  Container(
                      width: 1,
                      height: 24,
                      color: theme.colorScheme.outlineVariant),
                ],
              ),
            ),
            prefixIconConstraints:
                const BoxConstraints(minWidth: 0, minHeight: 0),
          ),
        ),
        const SizedBox(height: Gap.sm),
        Text(l10n.otpDeliveryNote,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
        const SizedBox(height: Gap.lg),
        _primary(l10n.continueLabel, _busy ? null : _requestCode),
      ];

  List<Widget> _codeStep(dynamic l10n, ThemeData theme) => [
        TextField(
          controller: _code,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofocus: true,
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineMedium
              ?.copyWith(letterSpacing: 8, fontWeight: FontWeight.w700),
          decoration: const InputDecoration(counterText: '', hintText: '••••••'),
        ),
        const SizedBox(height: Gap.lg),
        _primary(l10n.verifyLabel, _busy ? null : _verify),
        const SizedBox(height: Gap.xs),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            TextButton(
              onPressed: _busy
                  ? null
                  : () {
                      _stopResendCooldown();
                      setState(() => _step = _Step.phone);
                    },
              child: Text(l10n.back),
            ),
            TextButton(
              onPressed: _busy || _resendIn > 0 ? null : _requestCode,
              child: Text(
                _resendIn > 0
                    ? l10n.resendCountdown(_resendIn)
                    : l10n.resendCode,
              ),
            ),
          ],
        ),
      ];

  List<Widget> _detailsStep(dynamic l10n, ThemeData theme) => [
        Text(l10n.businessNameLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _name,
          textCapitalization: TextCapitalization.words,
          decoration: InputDecoration(
            hintText: l10n.businessNameHint,
            prefixIcon: const Icon(Icons.storefront_rounded),
          ),
        ),
        const SizedBox(height: Gap.lg),
        Text(l10n.businessNameDvLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        // The Thaana name: an RTL input in Faruma regardless of the UI
        // language — Thaana is written right-to-left even inside an English
        // interface.
        TextField(
          controller: _nameDv,
          textDirection: TextDirection.rtl,
          style: theme.textTheme.titleMedium?.copyWith(fontFamily: 'Faruma'),
          decoration: const InputDecoration(
            hintText: 'ފިހާރައިގެ ނަން',
            hintTextDirection: TextDirection.rtl,
            prefixIcon: Icon(Icons.translate_rounded),
          ),
        ),
        const SizedBox(height: Gap.lg),
        Text(l10n.emailLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(
            hintText: l10n.emailHint,
            prefixIcon: const Icon(Icons.mail_outline_rounded),
          ),
        ),
        const SizedBox(height: Gap.lg),
        Text(l10n.passwordLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _password,
          obscureText: _obscure,
          autocorrect: false,
          enableSuggestions: false,
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(
            hintText: l10n.passwordHint,
            prefixIcon: const Icon(Icons.lock_outline_rounded),
            suffixIcon: IconButton(
              tooltip: _obscure ? l10n.showPassword : l10n.hidePassword,
              icon: Icon(_obscure
                  ? Icons.visibility_outlined
                  : Icons.visibility_off_outlined),
              onPressed: () => setState(() => _obscure = !_obscure),
            ),
          ),
        ),
        const SizedBox(height: Gap.xs),
        Text(l10n.passwordRule,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
        const SizedBox(height: Gap.xl),
        _primary(l10n.createStore, _busy ? null : _register),
      ];
}
