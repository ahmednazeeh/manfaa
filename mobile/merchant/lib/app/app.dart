import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../features/push/push_registrar.dart';
import '../l10n/gen/app_localizations.dart';
import 'providers.dart';
import 'router.dart';

/// One extension so screens read `context.l10n.x` instead of the mouthful.
extension L10nX on BuildContext {
  AppLocalizations get l10n => AppLocalizations.of(this);
}

/// Root messenger, so anything without a Scaffold in scope (push, MR4) can
/// surface a SnackBar — same seam the customer app carved out.
final rootMessengerKey = GlobalKey<ScaffoldMessengerState>();

class MerchantApp extends ConsumerWidget {
  const MerchantApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);
    final router = ref.watch(routerProvider);
    final dhivehi = locale.languageCode == 'dv';

    // Idempotent (each guards itself): taps route to the screen the message
    // is about, and foreground messages surface instead of being swallowed.
    ref.read(pushRegistrarProvider)
      ..wireTapRouting(router)
      ..wireForeground(rootMessengerKey, router);

    return MaterialApp.router(
      title: 'Manfaa Merchant',
      scaffoldMessengerKey: rootMessengerKey,
      routerConfig: router,
      theme: manfaaTheme(brightness: Brightness.light, dhivehi: dhivehi),
      darkTheme: manfaaTheme(brightness: Brightness.dark, dhivehi: dhivehi),
      // Light-first (owner preference), dark shipped from day one, and the
      // choice is the USER'S — persisted, not dictated by the phone.
      themeMode: ref.watch(themeModeProvider),
      locale: locale,
      supportedLocales: const [Locale('en'), Locale('dv')],
      localizationsDelegates: const [
        AppLocalizations.delegate,
        // Order matters: for dv these fallbacks win (Flutter ships no dv
        // framework strings, and the widgets delegate is what turns the
        // layout RTL); for en they refuse and the Global delegates serve.
        ...dvFallbackDelegates,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ],
      debugShowCheckedModeBanner: false,
    );
  }
}
