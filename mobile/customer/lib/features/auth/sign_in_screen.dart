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
/// account?", a question people get wrong. No password exists anywhere, so
/// the brand mockup's password/Google fields are deliberately omitted; the
/// mockup's LOOK (hero, big welcome, styled phone field, ink action, the
/// security note) is kept.
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
  final _referral = TextEditingController();

  var _step = _Step.phone;
  var _busy = false;
  String? _signupToken;

  /// Set only when the typed referral code fails the ONE local rule (six
  /// digits). Empty is always fine — the field is optional, and the server
  /// silently ignores a code that matches nobody.
  String? _referralError;

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
    _referral.dispose();
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
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(_describe(e))));
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
    final outcome = await ref
        .read(apiProvider)
        .verifyOtp(
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
    // Soft validation, client-side only: empty is fine (the code is
    // optional and immutable after signup); anything typed must be the
    // six digits a customer code is, or the server would 422 in prose.
    final referral = _referral.text.trim();
    if (referral.isNotEmpty && !RegExp(r'^\d{6}$').hasMatch(referral)) {
      setState(() => _referralError = context.l10n.referralCodeInvalid);
      return;
    }
    if (_referralError != null) setState(() => _referralError = null);

    try {
      await ref
          .read(apiProvider)
          .registerWithOtp(
            signupToken: _signupToken ?? '',
            name: _name.text.trim(),
            deviceName: await _deviceName(),
            referralCode: referral.isEmpty ? null : referral,
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

    final (heading, sub) = switch (_step) {
      _Step.phone => (l10n.welcomeTitle, l10n.signInSubtitle),
      _Step.code => (l10n.codeTitle, l10n.codeSentTo(_normalizedPhone)),
      _Step.name => (l10n.nameTitle, l10n.nameSubtitle),
    };

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
          children: [
            const ManfaaWordmark(),
            const SizedBox(height: Gap.xl),
            const _AuthHero(),
            const SizedBox(height: Gap.xl),
            Row(
              children: [
                Flexible(
                  child: Text(heading, style: theme.textTheme.headlineSmall),
                ),
                if (_step == _Step.phone) ...[
                  const SizedBox(width: Gap.sm),
                  const Text('👋', style: TextStyle(fontSize: 26)),
                ],
              ],
            ),
            const SizedBox(height: Gap.xs),
            Text(
              sub,
              textDirection: _step == _Step.code ? TextDirection.ltr : null,
              style: theme.textTheme.bodyLarge?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.xl),
            ManfaaCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: switch (_step) {
                  _Step.phone => _phoneStep(l10n, theme),
                  _Step.code => _codeStep(l10n, theme),
                  _Step.name => _nameStep(l10n, theme),
                },
              ),
            ),
            if (_step == _Step.phone) ...[
              const SizedBox(height: Gap.xl),
              const _SecurityNote(),
            ],
          ],
        ),
      ),
    );
  }

  Widget _primary(String label, VoidCallback? onPressed) => FilledButton(
    onPressed: onPressed,
    child: _busy
        ? _spinner()
        : Row(
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
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
              Text(
                '+960',
                textDirection: TextDirection.ltr,
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(width: 10),
              Container(
                width: 1,
                height: 24,
                color: theme.colorScheme.outlineVariant,
              ),
            ],
          ),
        ),
        prefixIconConstraints: const BoxConstraints(minWidth: 0, minHeight: 0),
      ),
    ),
    const SizedBox(height: Gap.sm),
    Text(
      l10n.otpDeliveryNote,
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
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
      style: theme.textTheme.headlineMedium?.copyWith(
        letterSpacing: 8,
        fontWeight: FontWeight.w700,
      ),
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
                  _resendTimer?.cancel();
                  setState(() {
                    _resendIn = 0;
                    _step = _Step.phone;
                  });
                },
          child: Text(l10n.back),
        ),
        TextButton(
          onPressed: _busy || _resendIn > 0 ? null : _requestCode,
          child: Text(
            _resendIn > 0 ? l10n.resendCountdown(_resendIn) : l10n.resendCode,
          ),
        ),
      ],
    ),
  ];

  List<Widget> _nameStep(dynamic l10n, ThemeData theme) => [
    Text(l10n.nameLabel, style: theme.textTheme.labelLarge),
    const SizedBox(height: Gap.sm),
    TextField(
      controller: _name,
      autofocus: true,
      textCapitalization: TextCapitalization.words,
      decoration: InputDecoration(hintText: l10n.nameLabel),
    ),
    const SizedBox(height: Gap.lg),
    // A friend's code — optional, and this signup step is the ONLY moment
    // it can ever be entered (immutable after, per the programme's rules).
    Text(l10n.referralCodeLabel, style: theme.textTheme.labelLarge),
    const SizedBox(height: Gap.sm),
    TextField(
      controller: _referral,
      keyboardType: TextInputType.number,
      maxLength: 6,
      textDirection: TextDirection.ltr,
      decoration: InputDecoration(
        counterText: '',
        hintText: l10n.referralCodeHint,
        errorText: _referralError,
      ),
    ),
    const SizedBox(height: Gap.lg),
    _primary(l10n.finishLabel, _busy ? null : _register),
    const SizedBox(height: Gap.xs),
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

/// A light, playful hero standing in for the mockup's 3D wallet — a soft
/// violet panel with floating "coin" badges. Pure widgets, no asset.
class _AuthHero extends StatelessWidget {
  const _AuthHero();

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      height: 128,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(Corner.card),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: dark
              ? [const Color(0xFF241D3A), const Color(0xFF181B24)]
              : [ManfaaColors.violetSoft, const Color(0xFFF3EEFE)],
        ),
      ),
      child: Stack(
        children: [
          Positioned(
            left: 24,
            top: 34,
            child: BrandLogo(
              shape: BrandLogoShape.square,
              height: 88,
              semanticLabel: 'Manfaa',
            ),
          ),
          const Positioned(top: 26, right: 40, child: _Coin(size: 46)),
          const Positioned(bottom: 24, right: 96, child: _Coin(size: 30)),
          Positioned(
            bottom: 30,
            right: 30,
            child: Container(
              width: 54,
              height: 54,
              decoration: BoxDecoration(
                color: ManfaaColors.violet.withValues(
                  alpha: dark ? 0.35 : 0.16,
                ),
                borderRadius: BorderRadius.circular(16),
              ),
              child: const Icon(
                Icons.percent_rounded,
                color: ManfaaColors.violet,
                size: 26,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Coin extends StatelessWidget {
  const _Coin({required this.size});
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: ManfaaColors.amber,
        shape: BoxShape.circle,
        boxShadow: const [
          BoxShadow(
            color: Color(0x33000000),
            blurRadius: 8,
            offset: Offset(0, 3),
          ),
        ],
      ),
      child: Icon(Icons.percent_rounded, color: Colors.white, size: size * 0.5),
    );
  }
}

class _SecurityNote extends StatelessWidget {
  const _SecurityNote();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const IconTile(
          Icons.verified_user_rounded,
          tint: ManfaaTint.green,
          size: 40,
          iconSize: 20,
        ),
        const SizedBox(width: Gap.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(l10n.securityTitle, style: theme.textTheme.titleMedium),
              const SizedBox(height: 2),
              Text(
                l10n.securityBody,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
