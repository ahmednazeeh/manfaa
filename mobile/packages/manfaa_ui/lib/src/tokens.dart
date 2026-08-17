import 'package:flutter/material.dart';

/// The Manfaa design tokens — the ONE place colour lives.
///
/// Rose-led by decision (2026-08-17): the logo's rose finally carried through
/// the whole UI instead of appearing once in the header and never again —
/// the exact criticism the original web-port mockups earned. Warm stone
/// neutrals rather than blue-greys, and money gets semantic colour:
/// emerald = confirmed (yours), amber = pending (conditional — never to be
/// visually summed with confirmed, §10), sky = informational.
///
/// Everything is a token so dark mode is a palette, not a rewrite, and so
/// the merchant app inherits the system unchanged.
abstract final class ManfaaColors {
  // Brand rose
  static const rose50 = Color(0xFFFFF1F2);
  static const rose100 = Color(0xFFFFE4E6);
  static const rose200 = Color(0xFFFECDD3);
  static const rose400 = Color(0xFFFB7185);
  static const rose500 = Color(0xFFF43F5E);
  static const rose600 = Color(0xFFE11D48); // primary
  static const rose700 = Color(0xFFBE123C);
  static const rose900 = Color(0xFF881337);

  // Warm neutrals (stone)
  static const stone50 = Color(0xFFFAFAF9);
  static const stone100 = Color(0xFFF5F5F4);
  static const stone200 = Color(0xFFE7E5E4);
  static const stone300 = Color(0xFFD6D3D1);
  static const stone400 = Color(0xFFA8A29E);
  static const stone500 = Color(0xFF78716C);
  static const stone600 = Color(0xFF57534E);
  static const stone700 = Color(0xFF44403C);
  static const stone800 = Color(0xFF292524);
  static const stone900 = Color(0xFF1C1917);

  // Money semantics
  static const confirmedGreen = Color(0xFF059669); // emerald 600
  static const confirmedGreenSoft = Color(0xFFECFDF5);
  static const pendingAmber = Color(0xFFD97706); // amber 600
  static const pendingAmberSoft = Color(0xFFFFFBEB);
  static const infoSky = Color(0xFF0284C7); // sky 600
  static const infoSkySoft = Color(0xFFF0F9FF);

  // Lightened content colours for dark mode: the 600-weight semantics fail
  // contrast on near-black, so cards and chips switch to the 300 weight and
  // a translucent tint of the strong colour rather than the pale light
  // wash, which would otherwise glow in a dark UI.
  static const confirmedGreenBright = Color(0xFF6EE7B7); // emerald 300
  static const pendingAmberBright = Color(0xFFFCD34D); // amber 300
  static const infoSkyBright = Color(0xFF7DD3FC); // sky 300

  /// Category hues for Discover — assigned by index, stable per category
  /// slug hash so a category keeps its colour between sessions.
  static const categoryHues = <Color>[
    Color(0xFF0D9488), // teal
    Color(0xFF7C3AED), // violet
    Color(0xFFEA580C), // orange
    Color(0xFF0284C7), // sky
    Color(0xFFDB2777), // pink
    Color(0xFF65A30D), // lime
    Color(0xFF4F46E5), // indigo
    Color(0xFFB45309), // amber deep
  ];
}

/// A money/status tone resolved to a (background, foreground) pair for the
/// current brightness. The whole point: a pending-amber card must read the
/// same INTENT in light and dark without a pale wash glowing on black.
enum ToneSurface { pending, confirmed, info, attention, closed }

({Color background, Color foreground}) toneSurface(
  ToneSurface tone,
  Brightness brightness,
) {
  final dark = brightness == Brightness.dark;

  ({Color background, Color foreground}) pair(Color strong, Color soft, Color bright) =>
      dark
          ? (background: strong.withValues(alpha: 0.16), foreground: bright)
          : (background: soft, foreground: strong);

  return switch (tone) {
    ToneSurface.pending => pair(ManfaaColors.pendingAmber,
        ManfaaColors.pendingAmberSoft, ManfaaColors.pendingAmberBright),
    ToneSurface.confirmed => pair(ManfaaColors.confirmedGreen,
        ManfaaColors.confirmedGreenSoft, ManfaaColors.confirmedGreenBright),
    ToneSurface.info => pair(
        ManfaaColors.infoSky, ManfaaColors.infoSkySoft, ManfaaColors.infoSkyBright),
    ToneSurface.attention => dark
        ? (background: ManfaaColors.rose400.withValues(alpha: 0.16),
            foreground: ManfaaColors.rose400)
        : (background: ManfaaColors.rose100, foreground: ManfaaColors.rose700),
    ToneSurface.closed => dark
        ? (background: ManfaaColors.stone700.withValues(alpha: 0.5),
            foreground: ManfaaColors.stone300)
        : (background: ManfaaColors.stone100, foreground: ManfaaColors.stone600),
  };
}

/// Spacing on a 4pt grid. Use these, not magic numbers.
abstract final class Gap {
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 20;
  static const double xxl = 24;
  static const double huge = 32;
}

/// Corner radii. Cards are soft (20), controls are 14, sheets 28.
abstract final class Corner {
  static const double control = 14;
  static const double card = 20;
  static const double sheet = 28;
}

/// The two ColorSchemes. Light-first (owner preference), dark from day one.
abstract final class ManfaaSchemes {
  static final light = ColorScheme.fromSeed(
    seedColor: ManfaaColors.rose600,
    brightness: Brightness.light,
  ).copyWith(
    primary: ManfaaColors.rose600,
    onPrimary: Colors.white,
    primaryContainer: ManfaaColors.rose100,
    onPrimaryContainer: ManfaaColors.rose900,
    secondary: ManfaaColors.stone700,
    surface: Colors.white,
    onSurface: ManfaaColors.stone900,
    onSurfaceVariant: ManfaaColors.stone500,
    outlineVariant: ManfaaColors.stone200,
    surfaceContainerLowest: Colors.white,
    surfaceContainerLow: ManfaaColors.stone50,
    surfaceContainer: ManfaaColors.stone100,
  );

  static final dark = ColorScheme.fromSeed(
    seedColor: ManfaaColors.rose600,
    brightness: Brightness.dark,
  ).copyWith(
    // Rose 400 on dark: rose600 fails contrast on near-black.
    primary: ManfaaColors.rose400,
    onPrimary: ManfaaColors.rose900,
    primaryContainer: ManfaaColors.rose900,
    onPrimaryContainer: ManfaaColors.rose100,
    surface: ManfaaColors.stone900,
    onSurface: ManfaaColors.stone100,
    onSurfaceVariant: ManfaaColors.stone400,
    outlineVariant: ManfaaColors.stone700,
    surfaceContainerLowest: ManfaaColors.stone900,
    surfaceContainerLow: ManfaaColors.stone800,
    surfaceContainer: ManfaaColors.stone800,
  );
}
