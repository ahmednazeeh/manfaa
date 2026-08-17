import 'package:flutter/material.dart';

/// The Manfaa design tokens — the ONE place colour lives.
///
/// Redrawn 2026-08-17 to the brand mockups: a near-white canvas, near-black
/// **ink** for text and primary actions, **violet** as the single accent
/// (cashback %, featured, links), the logo's **coral** reserved for the mark
/// and the Discover motif, and money kept semantic — green = available
/// (yours), amber = pending/next payout (conditional, never summed with
/// green §10), blue = paid. The signature UI motif is a soft rounded-square
/// pastel "icon tile" ([IconTile]); cards are white with a soft shadow.
///
/// Everything is a token so dark mode is a palette, not a rewrite, and the
/// merchant app inherits the system unchanged.
abstract final class ManfaaColors {
  // ── Ink: primary text & the near-black primary button ──────────────────
  static const ink = Color(0xFF0F1424);
  static const inkSoft = Color(0xFF272C42);

  // ── Brand mark (coral twin-peak M) & the Discover motif ────────────────
  static const coral = Color(0xFFF4626B);
  static const coralDeep = Color(0xFFE04653);
  static const coralSoft = Color(0xFFFFE6E8);

  // ── Accent: violet — cashback %, featured cards, links ─────────────────
  static const violet = Color(0xFF7C3AED);
  static const violetDeep = Color(0xFF6D28D9);
  static const violetSoft = Color(0xFFEDE7FE);
  static const lavender = Color(0xFFCFC4F2); // avatar / soft feature washes

  // ── Neutrals ───────────────────────────────────────────────────────────
  static const canvas = Color(0xFFF6F6F9); // app background, near-white
  static const surface = Color(0xFFFFFFFF); // cards
  static const line = Color(0xFFECEDF2); // hairlines / dividers
  static const textMuted = Color(0xFF6B7280); // secondary text
  static const textFaint = Color(0xFF9AA0AE); // tertiary / hints

  // ── Money & semantic families (strong + soft tile wash) ────────────────
  static const green = Color(0xFF10B981); // available / verified / success
  static const greenSoft = Color(0xFFDBF5EA);
  static const blue = Color(0xFF3B82F6); // paid / informational
  static const blueSoft = Color(0xFFE3EDFE);
  static const amber = Color(0xFFF59E0B); // pending / next payout window
  static const amberSoft = Color(0xFFFBEFD6);

  // ── Legacy aliases (kept so screens compile during the redesign) ───────
  // The old rose-led palette; new screens must NOT use these.
  static const rose50 = Color(0xFFFFF1F2);
  static const rose100 = Color(0xFFFFE4E6);
  static const rose200 = Color(0xFFFECDD3);
  static const rose400 = Color(0xFFFB7185);
  static const rose500 = Color(0xFFF43F5E);
  static const rose600 = Color(0xFFE11D48);
  static const rose700 = Color(0xFFBE123C);
  static const rose900 = Color(0xFF881337);
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

  // Semantic names used across existing widgets — repointed to the new
  // families so the whole app shifts palette in one place.
  static const confirmedGreen = green;
  static const confirmedGreenSoft = greenSoft;
  static const pendingAmber = amber;
  static const pendingAmberSoft = amberSoft;
  static const infoSky = blue;
  static const infoSkySoft = blueSoft;
  static const confirmedGreenBright = Color(0xFF6EE7B7); // emerald 300 (dark)
  static const pendingAmberBright = Color(0xFFFCD34D); // amber 300 (dark)
  static const infoSkyBright = Color(0xFF93C5FD); // blue 300 (dark)

  /// Category hues for Discover — assigned by index, stable per category
  /// slug hash so a category keeps its colour between sessions.
  static const categoryHues = <Color>[
    Color(0xFF10B981), // grocery green
    Color(0xFFF59E0B), // dining amber/orange
    Color(0xFFF4626B), // fashion coral
    Color(0xFF3B82F6), // electronics blue
    Color(0xFF14B8A6), // health teal
    Color(0xFF7C3AED), // violet
    Color(0xFFEC4899), // pink
    Color(0xFF65A30D), // lime
  ];
}

/// The pastel "icon tile" tints — the signature motif from the mockups.
/// Each resolves to a (foreground icon, background wash) pair per brightness.
enum ManfaaTint { green, blue, violet, amber, coral, ink, neutral }

({Color fg, Color bg}) tintColors(ManfaaTint tint, Brightness brightness) {
  final dark = brightness == Brightness.dark;
  ({Color fg, Color bg}) pair(Color strong, Color soft) => dark
      ? (fg: strong, bg: strong.withValues(alpha: 0.20))
      : (fg: strong, bg: soft);

  return switch (tint) {
    ManfaaTint.green => pair(ManfaaColors.green, ManfaaColors.greenSoft),
    ManfaaTint.blue => pair(ManfaaColors.blue, ManfaaColors.blueSoft),
    ManfaaTint.violet => pair(ManfaaColors.violet, ManfaaColors.violetSoft),
    ManfaaTint.amber => pair(ManfaaColors.amber, ManfaaColors.amberSoft),
    ManfaaTint.coral => pair(ManfaaColors.coral, ManfaaColors.coralSoft),
    ManfaaTint.ink => dark
        ? (fg: Colors.white, bg: Colors.white.withValues(alpha: 0.12))
        : (fg: ManfaaColors.ink, bg: const Color(0xFFEDEEF3)),
    ManfaaTint.neutral => dark
        ? (fg: const Color(0xFFB9BFCE), bg: Colors.white.withValues(alpha: 0.08))
        : (fg: ManfaaColors.textMuted, bg: const Color(0xFFF0F1F5)),
  };
}

/// A money/status tone resolved to a (background, foreground) pair for the
/// current brightness — the same INTENT reads in light and dark.
enum ToneSurface { pending, confirmed, info, attention, closed }

({Color background, Color foreground}) toneSurface(
  ToneSurface tone,
  Brightness brightness,
) {
  final dark = brightness == Brightness.dark;

  ({Color background, Color foreground}) pair(
          Color strong, Color soft, Color bright) =>
      dark
          ? (background: strong.withValues(alpha: 0.18), foreground: bright)
          : (background: soft, foreground: strong);

  return switch (tone) {
    ToneSurface.pending => pair(ManfaaColors.pendingAmber,
        ManfaaColors.pendingAmberSoft, ManfaaColors.pendingAmberBright),
    ToneSurface.confirmed => pair(ManfaaColors.confirmedGreen,
        ManfaaColors.confirmedGreenSoft, ManfaaColors.confirmedGreenBright),
    ToneSurface.info => pair(ManfaaColors.infoSky, ManfaaColors.infoSkySoft,
        ManfaaColors.infoSkyBright),
    ToneSurface.attention => dark
        ? (background: ManfaaColors.coral.withValues(alpha: 0.18),
            foreground: ManfaaColors.coral)
        : (background: ManfaaColors.coralSoft, foreground: ManfaaColors.coralDeep),
    ToneSurface.closed => dark
        ? (background: Colors.white.withValues(alpha: 0.08),
            foreground: const Color(0xFFB9BFCE))
        : (background: const Color(0xFFF0F1F5), foreground: ManfaaColors.textMuted),
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

  /// Bottom padding for every TAB screen's scroll view: the floating nav bar
  /// overlays content (`extendBody`), so the last card must scroll clear of
  /// it instead of touching it.
  static const double navClearance = 104;
}

/// Corner radii. Cards are soft (18), controls 14, tiles 12; the floating
/// nav bar alone is a full stadium — deliberately rounder than any card.
abstract final class Corner {
  static const double tile = 12;
  static const double control = 14;
  static const double card = 18;
  static const double sheet = 26;
  static const double bar = 999;
}

/// The two ColorSchemes. Light-first (owner preference), dark from day one.
///
/// Primary is **ink** (the near-black button in the mockups), the accent is
/// **violet**. Surfaces step from a near-white canvas up to white cards.
abstract final class ManfaaSchemes {
  static final light = ColorScheme.fromSeed(
    seedColor: ManfaaColors.violet,
    brightness: Brightness.light,
  ).copyWith(
    primary: ManfaaColors.ink,
    onPrimary: Colors.white,
    primaryContainer: ManfaaColors.ink,
    onPrimaryContainer: Colors.white,
    secondary: ManfaaColors.violet,
    onSecondary: Colors.white,
    secondaryContainer: ManfaaColors.violetSoft,
    onSecondaryContainer: ManfaaColors.violetDeep,
    tertiary: ManfaaColors.coral,
    surface: Colors.white,
    onSurface: ManfaaColors.ink,
    onSurfaceVariant: ManfaaColors.textMuted,
    outline: const Color(0xFFD9DBE3),
    outlineVariant: ManfaaColors.line,
    surfaceContainerLowest: Colors.white,
    surfaceContainerLow: ManfaaColors.canvas,
    surfaceContainer: const Color(0xFFEFF0F4),
    surfaceContainerHigh: const Color(0xFFE9EAEF),
    error: ManfaaColors.coralDeep,
  );

  // Dark: a cool ink canvas with cards stepped clearly above it.
  static const darkBg = Color(0xFF0E1017); // scaffold
  static const darkCard = Color(0xFF181B24); // elevated card
  static const darkCardHigh = Color(0xFF212530);

  static final dark = ColorScheme.fromSeed(
    seedColor: ManfaaColors.violet,
    brightness: Brightness.dark,
  ).copyWith(
    primary: Colors.white,
    onPrimary: ManfaaColors.ink,
    primaryContainer: Colors.white,
    onPrimaryContainer: ManfaaColors.ink,
    secondary: const Color(0xFFB79CF6),
    onSecondary: ManfaaColors.ink,
    secondaryContainer: const Color(0xFF2E2547),
    onSecondaryContainer: const Color(0xFFE3D8FC),
    tertiary: ManfaaColors.coral,
    surface: darkBg,
    onSurface: const Color(0xFFF3F4F8),
    onSurfaceVariant: const Color(0xFFA6ACBC),
    outline: const Color(0xFF343A48),
    outlineVariant: const Color(0xFF262B36),
    surfaceContainerLowest: darkCard,
    surfaceContainerLow: const Color(0xFF14171F),
    surfaceContainer: darkCardHigh,
    surfaceContainerHigh: const Color(0xFF272C38),
    error: ManfaaColors.coral,
  );
}
