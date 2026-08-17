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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      mainAxisSize: MainAxisSize.min,
      textDirection: TextDirection.ltr,
      children: [
        ManfaaMark(size: size),
        SizedBox(width: size * 0.42),
        Text(
          'Manfaa',
          style: theme.textTheme.titleLarge?.copyWith(
            fontSize: size * 0.92,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.4,
            color: theme.colorScheme.onSurface,
          ),
        ),
        SizedBox(width: size * 0.36),
        Text(
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
        const MerchantWordmark(),
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
