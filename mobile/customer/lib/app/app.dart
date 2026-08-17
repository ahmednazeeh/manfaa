import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../l10n/gen/app_localizations.dart';
import 'providers.dart';
import 'router.dart';

/// One extension so screens read `context.l10n.x` instead of the mouthful.
extension L10nX on BuildContext {
  AppLocalizations get l10n => AppLocalizations.of(this);
}

class ManfaaApp extends ConsumerWidget {
  const ManfaaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);
    final router = ref.watch(routerProvider);
    final dhivehi = locale.languageCode == 'dv';

    return MaterialApp.router(
      title: 'Manfaa',
      routerConfig: router,
      theme: manfaaTheme(brightness: Brightness.light, dhivehi: dhivehi),
      darkTheme: manfaaTheme(brightness: Brightness.dark, dhivehi: dhivehi),
      // Light-first (owner preference) but the dark palette ships from day
      // one — following the system is the modern default.
      themeMode: ThemeMode.system,
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
