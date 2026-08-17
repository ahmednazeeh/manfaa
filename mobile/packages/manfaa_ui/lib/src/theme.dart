import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'tokens.dart';

/// Builds the app theme for one brightness and one script.
///
/// The look is the brand mockups: a near-white canvas, white cards lifted by
/// a soft shadow, **ink** (near-black) primary buttons, **violet** accent,
/// and Manrope for a modern geometric voice. Thaana switches the family to
/// Faruma under `dhivehi`, with Faruma as the Latin fallback so a Thaana
/// store name inside an English sentence still renders.
ThemeData manfaaTheme({required Brightness brightness, required bool dhivehi}) {
  final light = brightness == Brightness.light;
  final scheme = light ? ManfaaSchemes.light : ManfaaSchemes.dark;
  final canvas = light ? ManfaaColors.canvas : ManfaaSchemes.darkBg;

  final base = ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    fontFamily: dhivehi ? 'Faruma' : 'Manrope',
    fontFamilyFallback: const ['Faruma'],
    scaffoldBackgroundColor: canvas,
    splashFactory: InkSparkle.splashFactory,
  );

  // Manrope reads best a touch tighter; display sizes get negative tracking
  // so the big money figures feel deliberate.
  final text = base.textTheme.copyWith(
    displayMedium: base.textTheme.displayMedium
        ?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -1.2),
    displaySmall: base.textTheme.displaySmall
        ?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -1.0),
    headlineMedium: base.textTheme.headlineMedium
        ?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -0.8),
    headlineSmall: base.textTheme.headlineSmall
        ?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -0.5),
    titleLarge: base.textTheme.titleLarge
        ?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.3),
    titleMedium:
        base.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
    labelLarge: base.textTheme.labelLarge
        ?.copyWith(fontWeight: FontWeight.w700, letterSpacing: 0.1),
    bodyLarge: base.textTheme.bodyLarge?.copyWith(height: 1.45),
    bodyMedium: base.textTheme.bodyMedium?.copyWith(height: 1.45),
  );

  return base.copyWith(
    textTheme: text,
    appBarTheme: AppBarTheme(
      backgroundColor: canvas,
      foregroundColor: scheme.onSurface,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      systemOverlayStyle:
          light ? SystemUiOverlayStyle.dark : SystemUiOverlayStyle.light,
      titleTextStyle: text.headlineSmall?.copyWith(color: scheme.onSurface),
    ),
    // White cards, generous radius, soft drop shadow in light; in dark a
    // clear surface step plus a hairline (shadows read poorly on ink).
    cardTheme: CardThemeData(
      color: scheme.surfaceContainerLowest,
      elevation: light ? 10 : 0,
      shadowColor: light ? const Color(0x141A1F36) : Colors.transparent,
      surfaceTintColor: Colors.transparent,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Corner.card),
        side: light
            ? BorderSide.none
            : BorderSide(color: scheme.outlineVariant, width: 1),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: scheme.primary,
        foregroundColor: scheme.onPrimary,
        minimumSize: const Size.fromHeight(50),
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Corner.control),
        ),
        textStyle: text.labelLarge?.copyWith(fontSize: 16),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: scheme.onSurface,
        minimumSize: const Size.fromHeight(50),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Corner.control),
        ),
        side: BorderSide(color: scheme.outline, width: 1.4),
        textStyle: text.labelLarge?.copyWith(fontSize: 16),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: ManfaaColors.violet,
        textStyle: text.labelLarge,
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: light ? const Color(0xFFF0F0F5) : ManfaaSchemes.darkCardHigh,
      hintStyle: text.bodyLarge?.copyWith(color: ManfaaColors.textFaint),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(Corner.control),
        borderSide: BorderSide.none,
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(Corner.control),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(Corner.control),
        borderSide: const BorderSide(color: ManfaaColors.violet, width: 2),
      ),
      contentPadding:
          const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
    ),
    // A fallback for any stock NavigationBar; the app's real bar is the
    // floating pill built in the shell.
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: scheme.surfaceContainerLowest,
      indicatorColor: Colors.transparent,
      elevation: 0,
      height: 68,
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      labelTextStyle: WidgetStatePropertyAll(
        text.labelSmall?.copyWith(fontWeight: FontWeight.w700),
      ),
      iconTheme: WidgetStateProperty.resolveWith((states) {
        final selected = states.contains(WidgetState.selected);
        return IconThemeData(
          color: selected ? scheme.onSurface : scheme.onSurfaceVariant,
        );
      }),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: scheme.surfaceContainerLowest,
      side: BorderSide(color: scheme.outlineVariant),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(999),
      ),
      labelStyle: text.labelLarge,
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: ManfaaColors.ink,
      contentTextStyle: text.bodyMedium?.copyWith(color: Colors.white),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Corner.control),
      ),
    ),
    dividerTheme: DividerThemeData(
        color: scheme.outlineVariant, thickness: 1, space: 1),
    listTileTheme: const ListTileThemeData(
      contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 4),
    ),
  );
}
