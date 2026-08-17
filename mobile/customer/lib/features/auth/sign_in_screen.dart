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

/// Passwordless onboarding (R1): phone → code → (new customers: name) → in.
///
/// ONE flow serves sign-in and signup — the server decides which after the
/// code proves possession, so the screen never asks "do you have an
/// account?", a question people get wrong. No password exists anywhere.
///
/// Error handling follows the contract: KNOWN codes render localized
/// sentences (en/dv); an unknown code renders the server's own prose
/// fallback — never a raw snake_case string.
class SignInScreen extends ConsumerStatefulWidget {
  const SignInScreen({super.key});

  @override
  ConsumerState<SignInScreen> createState() => _SignInScreenState();
}

enum _Step { phone, code, name }

class _SignInScreenState extends ConsumerState<SignInScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();

  var _step = _Step.phone;
  var _busy = false;
  String? _signupToken;

  /// Client-side resend cooldown. The REAL budget is server-side (3/hour a
  /// phone, shared with the web); this just keeps an impatient thumb from
  /// spending it in the first minute.
  Timer? _resendTimer;
  int _resendIn = 0;

  static const _deviceNameFallback = 'Manfaa app';

  /// A human-recognisable device name for the device list — the whole point
  /// of that list is telling a stolen phone apart from your own, and every
  /// account minting five identically-named tokens defeats it. Captured once
  /// per flow; the server hygienises it, and this falls back cleanly where
  /// there is no platform channel (tests, desktop).
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

  /// The contract's error rule, in one place for this screen.
  String _describe(Object error) {
    final l10n = context.l10n;

    if (error is! MobileApiException) return l10n.errorGeneric;

    return switch (error.code) {
      'otp_invalid' => l10n.errOtpInvalid,
      'otp_attempts_exceeded' => l10n.errOtpAttempts,
      'signup_token_invalid' => l10n.errSignupExpired,
      'account_unavailable' => l10n.errAccountUnavailable,
      'phone_already_registered' => l10n.errPhoneRegistered,
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
          await ref.read(apiProvider).requestOtp(_normalizedPhone);
        } on MobileApiException catch (e) {
          // The SMS budget is spent — but a code delivered earlier is still
          // redeemable for its 10-minute life (verify has its own throttle).
          // Move to the code field anyway rather than trapping a user who is
          // holding a valid code on the phone step. _run still shows the
          // wait via the snackbar.
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
        final outcome = await ref.read(apiProvider).verifyOtp(
              phone: _normalizedPhone,
              code: _code.text.trim(),
              deviceName: await _deviceName(),
            );

        if (!mounted) return;

        if (outcome.signedIn) {
          context.go('/home');
          return;
        }

        _signupToken = outcome.signupToken;
        setState(() => _step = _Step.name);
      });

  Future<void> _register() => _run(() async {
        try {
          await ref.read(apiProvider).registerWithOtp(
                signupToken: _signupToken ?? '',
                name: _name.text.trim(),
                deviceName: await _deviceName(),
              );
        } on MobileApiException catch (e) {
          // Both of these mean the signup token is DEAD and re-tapping Finish
          // can only earn the same 422/429 forever — the name step has no
          // other exit. Send the user back to the phone step to start clean;
          // _run still surfaces the reason.
          if (e.code == 'signup_token_invalid' ||
              e.code == 'phone_already_registered') {
            _signupToken = null;
            if (mounted) setState(() => _step = _Step.phone);
          }
          rethrow;
        }

        if (mounted) context.go('/home');
      });

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(Gap.xxl),
          children: [
            const SizedBox(height: Gap.huge),
            Text(
              l10n.appTitle,
              style: theme.textTheme.displaySmall?.copyWith(
                color: ManfaaColors.rose600,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.sm),
            Text(
              l10n.signInSubtitle,
              style: theme.textTheme.bodyLarge
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: Gap.huge),
            ...switch (_step) {
              _Step.phone => _phoneStep(l10n, theme),
              _Step.code => _codeStep(l10n, theme),
              _Step.name => _nameStep(l10n, theme),
            },
          ],
        ),
      ),
    );
  }

  List<Widget> _phoneStep(dynamic l10n, ThemeData theme) => [
        Text(l10n.phoneLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _phone,
          keyboardType: TextInputType.phone,
          // Digits are LTR in both languages.
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(hintText: l10n.phoneHint),
        ),
        const SizedBox(height: Gap.lg),
        FilledButton(
          onPressed: _busy ? null : _requestCode,
          child: _busy ? _spinner() : Text(l10n.continueLabel),
        ),
      ];

  List<Widget> _codeStep(dynamic l10n, ThemeData theme) => [
        Text(l10n.codeTitle, style: theme.textTheme.titleLarge),
        const SizedBox(height: Gap.xs),
        Text(
          l10n.codeSentTo(_normalizedPhone),
          textDirection: TextDirection.ltr,
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: Gap.lg),
        TextField(
          controller: _code,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofocus: true,
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineMedium
              ?.copyWith(letterSpacing: 8, fontWeight: FontWeight.w700),
          decoration: InputDecoration(counterText: '', hintText: '••••••'),
        ),
        const SizedBox(height: Gap.lg),
        FilledButton(
          onPressed: _busy ? null : _verify,
          child: _busy ? _spinner() : Text(l10n.verifyLabel),
        ),
        const SizedBox(height: Gap.md),
        TextButton(
          onPressed: _busy || _resendIn > 0 ? null : _requestCode,
          child: Text(
            _resendIn > 0
                ? l10n.resendCountdown(_resendIn)
                : l10n.resendCode,
          ),
        ),
        TextButton(
          onPressed: _busy
              ? null
              : () {
                  _resendTimer?.cancel();
                  setState(() {
                    _resendIn = 0;
                    _step = _Step.phone;
                  });
                },
          child: Text(l10n.back),
        ),
      ];

  List<Widget> _nameStep(dynamic l10n, ThemeData theme) => [
        Text(l10n.nameTitle, style: theme.textTheme.titleLarge),
        const SizedBox(height: Gap.xs),
        Text(
          l10n.nameSubtitle,
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: Gap.lg),
        TextField(
          controller: _name,
          autofocus: true,
          textCapitalization: TextCapitalization.words,
          decoration: InputDecoration(hintText: l10n.nameLabel),
        ),
        const SizedBox(height: Gap.lg),
        FilledButton(
          onPressed: _busy ? null : _register,
          child: _busy ? _spinner() : Text(l10n.finishLabel),
        ),
        TextButton(
          onPressed: _busy
              ? null
              : () => setState(() {
                    _signupToken = null;
                    _step = _Step.phone;
                  }),
          child: Text(l10n.back),
        ),
      ];

  Widget _spinner() => const SizedBox(
        width: 20,
        height: 20,
        child: CircularProgressIndicator(strokeWidth: 2),
      );
}
