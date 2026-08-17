import 'package:flutter/material.dart';

import 'tokens.dart';

/// Builds the app theme for one brightness and one script.
///
/// `dhivehi` swaps the type stack: Faruma carries Thaana body text (bundled
/// by the APP under that family name — packages declare no fonts so each app
/// controls its binary size), MV Waheed is reserved for display use by
/// screens that opt in. The Latin stack stays on the platform default, with
/// Faruma as fallback so a Thaana store name inside an English sentence
/// still renders.
ThemeData manfaaTheme({required Brightness brightness, required bool dhivehi}) {
  final scheme =
      brightness == Brightness.light ? ManfaaSchemes.light : ManfaaSchemes.dark;

  final base = ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    fontFamily: dhivehi ? 'Faruma' : null,
    fontFamilyFallback: const ['Faruma'],
    scaffoldBackgroundColor: scheme.surfaceContainerLow,
  );

  final text = base.textTheme.copyWith(
    // Money and codes read in tabular figures everywhere; individual widgets
    // add the fontFeature, but the weights are set here so screens agree.
    displaySmall: base.textTheme.displaySmall
        ?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.5),
    headlineMedium:
        base.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w700),
    titleLarge: base.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
    labelLarge: base.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
  );

  return base.copyWith(
    textTheme: text,
    appBarTheme: AppBarTheme(
      backgroundColor: scheme.surfaceContainerLow,
      foregroundColor: scheme.onSurface,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: text.titleLarge?.copyWith(color: scheme.onSurface),
    ),
    cardTheme: CardThemeData(
      color: scheme.surfaceContainerLowest,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Corner.card),
        side: BorderSide(color: scheme.outlineVariant),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Corner.control),
        ),
        textStyle: text.labelLarge,
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Corner.control),
        ),
        side: BorderSide(color: scheme.outlineVariant),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: scheme.surfaceContainer,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(Corner.control),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(Corner.control),
        borderSide: BorderSide(color: scheme.primary, width: 1.5),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: scheme.surfaceContainerLowest,
      indicatorColor: scheme.primaryContainer,
      elevation: 0,
      height: 68,
      labelTextStyle: WidgetStatePropertyAll(text.labelMedium),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Corner.control),
      ),
    ),
    dividerTheme: DividerThemeData(color: scheme.outlineVariant, thickness: 1),
  );
}
