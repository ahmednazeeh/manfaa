import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';

/// Shared chrome for the MR5 management-estate screens (Employees, Roles,
/// Branches, Promotions) — the violet system's stat tiles, search field,
/// soft pills and error card, so the four screens speak one dialect.

/// One stat tile from Manage Employees.png / Branches.png: tinted icon,
/// muted label, the big count. REAL counts only — a tile with no backing
/// data is not drawn.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.icon,
    required this.tint,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final ManfaaTint tint;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ManfaaCard(
      child: Row(
        children: [
          IconTile(icon, tint: tint, size: 44, iconSize: 22),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  value,
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
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

/// The estate list screens' search box (Manage Employees.png) — client-side
/// filtering only; the lists are short enough that the server serves them
/// whole.
class EstateSearchField extends StatelessWidget {
  const EstateSearchField({
    super.key,
    required this.hint,
    required this.onChanged,
  });

  final String hint;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return TextField(
      onChanged: onChanged,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: const Icon(Icons.search_rounded),
      ),
    );
  }
}

/// The lavender initial circle from the employees ref — tint cycled by row
/// index so neighbouring rows read apart, initials never invented (the
/// first grapheme of the real name).
class TintedInitialAvatar extends StatelessWidget {
  const TintedInitialAvatar({
    super.key,
    required this.name,
    required this.index,
    this.size = 44,
  });

  static const _tints = [
    ManfaaTint.violet,
    ManfaaTint.blue,
    ManfaaTint.amber,
    ManfaaTint.coral,
    ManfaaTint.green,
  ];

  final String name;
  final int index;
  final double size;

  @override
  Widget build(BuildContext context) {
    final colors = tintColors(
      _tints[index % _tints.length],
      Theme.of(context).brightness,
    );
    final initial =
        name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: colors.bg, shape: BoxShape.circle),
      child: Text(
        initial,
        style: TextStyle(
          fontSize: size * 0.4,
          fontWeight: FontWeight.w800,
          color: colors.fg,
        ),
      ),
    );
  }
}

/// The soft violet pill the refs hang role names and permission counts on.
class SoftPill extends StatelessWidget {
  const SoftPill({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: dark
            ? ManfaaColors.violet.withValues(alpha: 0.22)
            : ManfaaColors.violetSoft,
        borderRadius: BorderRadius.circular(Corner.bar),
      ),
      child: Text(
        label,
        style: theme.textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: dark ? const Color(0xFFB79CF6) : ManfaaColors.violetDeep,
        ),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }
}

/// A section-level load failure with a retry — the cashback screen's
/// pattern, shared so every estate screen renders errors identically.
class SectionErrorCard extends StatelessWidget {
  const SectionErrorCard({
    super.key,
    required this.error,
    required this.onRetry,
  });

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        children: [
          Text(
            error is MobileApiException
                ? (error as MobileApiException).message
                : l10n.errorGeneric,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: Gap.md),
          OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
        ],
      ),
    );
  }
}

/// The violet solid CTA from Manage Employees.png ("Add employee") — the
/// merchant accent, never the ink primary, so the management actions read
/// as one family across the four screens.
class VioletCta extends StatelessWidget {
  const VioletCta({
    super.key,
    required this.icon,
    required this.label,
    required this.onPressed,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return FilledButton.icon(
      onPressed: onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: ManfaaColors.violet,
        foregroundColor: Colors.white,
        disabledBackgroundColor: ManfaaColors.violet.withValues(alpha: 0.35),
        disabledForegroundColor: Colors.white70,
        minimumSize: const Size(0, 50),
        padding: const EdgeInsets.symmetric(horizontal: Gap.md),
      ),
      icon: Icon(icon, size: 20),
      label: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
    );
  }
}

/// Its outline sibling (the ref's "Roles" button).
class VioletOutlineCta extends StatelessWidget {
  const VioletOutlineCta({
    super.key,
    required this.icon,
    required this.label,
    required this.onPressed,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;

    return OutlinedButton.icon(
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        foregroundColor:
            dark ? const Color(0xFFB79CF6) : ManfaaColors.violetDeep,
        side: BorderSide(
          color: dark ? ManfaaColors.violet : const Color(0xFFD9CEF9),
        ),
        minimumSize: const Size(0, 50),
        padding: const EdgeInsets.symmetric(horizontal: Gap.md),
      ),
      icon: Icon(icon, size: 20),
      label: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
    );
  }
}
