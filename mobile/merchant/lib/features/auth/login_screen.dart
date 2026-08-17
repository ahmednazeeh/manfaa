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

/// Login per Merchant Login.png: the merchant wordmark, the heading beside a
/// storefront hero, email + password in a white panel, the ink CTA, and the
/// security note. Deliberately omitted from the mock: "Continue with Google"
/// and "Forgot password?" have no backend, "Register your store" is MR1, and
/// the field says Email — merchant accounts have no phone identity.
///
/// Sign-in does not return the merchant's STATUS, so the flow calls
/// /merchant/me right after and only then routes — a store under review must
/// land on the status screen, not flash a dashboard.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  var _busy = false;
  var _obscure = true;

  static const _deviceNameFallback = 'Manfaa Merchant app';

  /// A human-recognisable device name for the device list — the whole point
  /// of that list (5-device cap) is telling the counter tablet apart from a
  /// stolen phone. The server hygienises it, and this falls back cleanly
  /// where there is no platform channel (tests, desktop).
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
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  /// The contract's error rule: KNOWN codes render localized sentences, an
  /// unknown code renders the server's own prose — never raw snake_case.
  /// A wrong password arrives as `validation_failed` (the server refuses
  /// with a field message on purpose, not naming which half was wrong).
  String _describe(Object error) {
    final l10n = context.l10n;

    if (error is! MobileApiException) return l10n.errorGeneric;

    return switch (error.code) {
      ApiCode.validationFailed => l10n.errBadCredentials,
      ApiCode.rateLimited => l10n.errTooManyTries,
      _ => error.message,
    };
  }

  Future<void> _submit() async {
    final l10n = context.l10n;
    final email = _email.text.trim();
    final password = _password.text;

    if (email.isEmpty || password.isEmpty) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(l10n.errMissingCredentials)));
      return;
    }

    setState(() => _busy = true);
    try {
      final api = ref.read(apiProvider);
      await api.signIn(
        email: email,
        password: password,
        deviceName: await _deviceName(),
      );

      try {
        // Status + fresh permissions. Offline here is survivable: the
        // sign-in payload already carried the permissions, and the next
        // connected launch refreshes the status.
        await api.me();
      } catch (_) {
        // Deliberate: routing falls back to what the session holds.
      }

      if (mounted) context.go(homeLocationFor(ref.read(sessionProvider)));
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(_describe(e))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
          children: [
            const MerchantWordmark(),
            const SizedBox(height: Gap.huge),
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l10n.loginTitle,
                          style: theme.textTheme.headlineMedium),
                      const SizedBox(height: Gap.md),
                      Text(
                        l10n.loginSubtitle,
                        style: theme.textTheme.bodyLarge?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: Gap.lg),
                const _StorefrontHero(),
              ],
            ),
            const SizedBox(height: Gap.xl),
            ManfaaCard(
              padding: const EdgeInsets.all(Gap.xl),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
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
                    onSubmitted: (_) => _busy ? null : _submit(),
                    decoration: InputDecoration(
                      hintText: l10n.passwordHint,
                      prefixIcon: const Icon(Icons.lock_outline_rounded),
                      suffixIcon: IconButton(
                        tooltip:
                            _obscure ? l10n.showPassword : l10n.hidePassword,
                        icon: Icon(_obscure
                            ? Icons.visibility_outlined
                            : Icons.visibility_off_outlined),
                        onPressed: () => setState(() => _obscure = !_obscure),
                      ),
                    ),
                  ),
                  const SizedBox(height: Gap.xl),
                  FilledButton(
                    onPressed: _busy ? null : _submit,
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
                                child: Text(l10n.logIn,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis),
                              ),
                              const SizedBox(width: Gap.sm),
                              const Icon(Icons.arrow_forward_rounded, size: 20),
                            ],
                          ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: Gap.xl),
            const _SecurityNote(),
          ],
        ),
      ),
    );
  }
}

/// The mock's 3D storefront-and-wallet illustration, composed from widgets —
/// a soft violet blob, a white storefront tile, the ink wallet, a coin — no
/// assets, and it recolours itself in dark.
class _StorefrontHero extends StatelessWidget {
  const _StorefrontHero();

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;

    return SizedBox(
      width: 148,
      height: 164,
      child: Stack(
        children: [
          Positioned(
            top: 8,
            left: 4,
            child: Container(
              width: 136,
              height: 136,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: dark
                      ? [const Color(0xFF241D3A), const Color(0xFF181B24)]
                      : [ManfaaColors.violetSoft, const Color(0xFFF3EEFE)],
                ),
              ),
            ),
          ),
          Positioned(
            top: 30,
            left: 22,
            child: Container(
              width: 74,
              height: 74,
              decoration: BoxDecoration(
                color: dark ? const Color(0xFF2E2547) : Colors.white,
                borderRadius: BorderRadius.circular(20),
                boxShadow: const [
                  BoxShadow(
                      color: Color(0x1F1A1F36),
                      blurRadius: 14,
                      offset: Offset(0, 6)),
                ],
              ),
              child: const Icon(Icons.storefront_rounded,
                  color: ManfaaColors.violet, size: 38),
            ),
          ),
          Positioned(
            top: 12,
            right: 10,
            child: Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: ManfaaColors.violet.withValues(alpha: dark ? 0.9 : 1),
                shape: BoxShape.circle,
                boxShadow: const [
                  BoxShadow(
                      color: Color(0x33000000),
                      blurRadius: 8,
                      offset: Offset(0, 3)),
                ],
              ),
              child: const Icon(Icons.attach_money_rounded,
                  color: Colors.white, size: 22),
            ),
          ),
          Positioned(
            bottom: 8,
            right: 14,
            child: Container(
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                color: dark ? Colors.white : ManfaaColors.ink,
                borderRadius: BorderRadius.circular(16),
                boxShadow: const [
                  BoxShadow(
                      color: Color(0x2E1A1F36),
                      blurRadius: 12,
                      offset: Offset(0, 5)),
                ],
              ),
              child: Icon(Icons.account_balance_wallet_rounded,
                  color: dark ? ManfaaColors.ink : Colors.white, size: 26),
            ),
          ),
          const Positioned(
            top: 0,
            left: 6,
            child: Icon(Icons.auto_awesome_rounded,
                color: ManfaaColors.violet, size: 16),
          ),
        ],
      ),
    );
  }
}

/// The violet trust card from the mock: shield tile, the reassurance copy,
/// and the "Protected" pill.
class _SecurityNote extends StatelessWidget {
  const _SecurityNote();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: dark
            ? ManfaaColors.violet.withValues(alpha: 0.10)
            : const Color(0xFFF4F1FE),
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const IconTile(Icons.verified_user_rounded,
              tint: ManfaaTint.violet, size: 40, iconSize: 20),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.securityTitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  l10n.securityBody,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.md),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: dark
                  ? ManfaaColors.violet.withValues(alpha: 0.22)
                  : ManfaaColors.violetSoft,
              borderRadius: BorderRadius.circular(Corner.bar),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.lock_rounded,
                    size: 13,
                    color: dark
                        ? const Color(0xFFB79CF6)
                        : ManfaaColors.violetDeep),
                const SizedBox(width: 4),
                Text(
                  l10n.protectedBadge,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: dark
                        ? const Color(0xFFB79CF6)
                        : ManfaaColors.violetDeep,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
