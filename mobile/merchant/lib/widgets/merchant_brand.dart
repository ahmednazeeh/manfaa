import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

/// The merchant lockup from the refs: the coral mark, "Manfaa" in ink, and a
/// violet "Merchant" suffix. Composes manfaa_ui's [ManfaaMark] — the design
/// system stays untouched, the suffix is this app's identity.
///
/// The lockup is a BRAND, not a sentence: it keeps its Latin order and glyphs
/// in dv exactly as the logo would, hence the forced LTR row.
class MerchantWordmark extends StatelessWidget {
  const MerchantWordmark({super.key, this.size = 22});

  final double size;

  /// How far the logo's "Manfaa" wordmark sits BELOW the middle of the image
  /// box, as a fraction of the rendered logo height.
  ///
  /// A Row centres the image BOX against the text, but the box is not the
  /// wordmark: the landscape lockup's emblem is taller than the word beside
  /// it and hangs lower, so the word's optical centre is low in the box.
  /// Centring on the box therefore floats "Merchant" ABOVE "Manfaa" — the
  /// misalignment the owner reported on 2026-08-20.
  ///
  /// Measured off the shipped marks and the ones the API serves today, which
  /// agree closely (light 6.6% bundled / 6.9% live, dark 3.9% / 4.1%):
  static const _wordmarkDropLight = 0.068;
  static const _wordmarkDropDark = 0.040;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final logoHeight = size * 1.8;

    return Row(
      mainAxisSize: MainAxisSize.min,
      textDirection: TextDirection.ltr,
      children: [
        // The platform mark, superadmin-replaceable. "Merchant" stays as
        // the product suffix it has always been — the same shape the web
        // panel's lockup settled on.
        BrandLogo(height: logoHeight, semanticLabel: 'Manfaa'),
        SizedBox(width: size * 0.36),
        // Nudged down onto the wordmark's own line. Translate, not padding:
        // the lockup's HEIGHT should stay the logo's, so a header row does
        // not grow by the offset.
        //
        // The two marks need different nudges because the light and dark
        // exports carry different internal padding. Re-exporting them with
        // matching padding would make this one constant — worth doing next
        // time the logos are cut.
        Transform.translate(
          offset: Offset(
            0,
            logoHeight * (dark ? _wordmarkDropDark : _wordmarkDropLight),
          ),
          child: Text(
            'Merchant',
            style: theme.textTheme.titleLarge?.copyWith(
              fontSize: size * 0.8,
              fontWeight: FontWeight.w600,
              letterSpacing: -0.2,
              // Violet in light, the lifted violet in dark — the scheme's
              // secondary is exactly the merchant accent in both.
              color: theme.colorScheme.secondary,
            ),
          ),
        ),
      ],
    );
  }
}

/// The tab-screen header row: merchant wordmark left, the store's initial in
/// the soft-lavender avatar right — Merchant More.png's idiom. manfaa_ui's
/// ManfaaTopBar hardcodes the customer wordmark, so the merchant app carries
/// its own two-liner instead of forking the package.
class MerchantTopBar extends StatelessWidget {
  const MerchantTopBar({super.key, this.initials});

  final String? initials;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        // The wordmark is BRANDING, not content: it carries no information
        // a reader needs bigger. Left to scale with the system text size it
        // outgrew the row and overflowed on a narrow phone at 1.3 (found
        // 2026-08-18); clamped, the header stays intact and every word that
        // actually says something still scales.
        MediaQuery.withClampedTextScaling(
          maxScaleFactor: 1.0,
          child: const MerchantWordmark(),
        ),
        const Spacer(),
        if (initials != null && initials!.isNotEmpty)
          ManfaaAvatar(initials!, size: 40, showDot: false),
      ],
    );
  }
}

/// The detail-screen header from Profile.png / Cashback Settings.png: a
/// back arrow leading, the wordmark beside it, the avatar trailing.
/// [fallbackPath] is where back lands when there is nothing to pop (a
/// deep link straight onto the detail screen).
class MerchantDetailTopBar extends StatelessWidget {
  const MerchantDetailTopBar({
    super.key,
    this.initials,
    this.fallbackPath = '/more',
  });

  final String? initials;
  final String fallbackPath;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        IconButton(
          icon: const Icon(Icons.arrow_back_rounded),
          onPressed: () =>
              context.canPop() ? context.pop() : context.go(fallbackPath),
        ),
        const SizedBox(width: Gap.xs),
        const MerchantWordmark(),
        const Spacer(),
        if (initials != null && initials!.isNotEmpty)
          ManfaaAvatar(initials!, size: 40, showDot: false),
      ],
    );
  }
}
