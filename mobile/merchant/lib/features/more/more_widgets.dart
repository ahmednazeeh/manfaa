import 'package:flutter/material.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';

/// The store's round avatar: the logo when one is on file, the lavender
/// initial otherwise — and the initial again if the logo bytes fail to
/// arrive (a golden run, a dead network). Never a broken-image glyph.
class StoreLogoAvatar extends StatelessWidget {
  const StoreLogoAvatar({
    super.key,
    required this.name,
    this.logoUrl,
    this.size = 56,
  });

  final String name;
  final String? logoUrl;
  final double size;

  @override
  Widget build(BuildContext context) {
    final initial = name.isEmpty ? 'M' : name.characters.first.toUpperCase();
    final url = logoUrl;

    if (url == null || url.isEmpty) {
      return ManfaaAvatar(initial, size: size, showDot: false);
    }

    return ClipOval(
      child: SizedBox(
        width: size,
        height: size,
        child: Image.network(
          url,
          fit: BoxFit.cover,
          errorBuilder: (_, _, _) =>
              ManfaaAvatar(initial, size: size, showDot: false),
        ),
      ),
    );
  }
}

/// The violet "Verified" pill from the refs — drawn ONLY for status=active
/// (the caller gates; this widget just paints).
class VerifiedChip extends StatelessWidget {
  const VerifiedChip({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final fg = dark ? const Color(0xFFB79CF6) : ManfaaColors.violetDeep;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: dark
            ? ManfaaColors.violet.withValues(alpha: 0.22)
            : ManfaaColors.violetSoft,
        borderRadius: BorderRadius.circular(Corner.bar),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.verified_outlined, size: 14, color: fg),
          const SizedBox(width: 4),
          Text(
            context.l10n.verifiedChip,
            style: theme.textTheme.labelSmall?.copyWith(
              color: fg,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}
