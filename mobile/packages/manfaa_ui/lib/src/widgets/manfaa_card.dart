import 'package:flutter/material.dart';

import '../tokens.dart';

/// A rounded surface card. By default it takes the app's Card theme.
///
/// [soft] renders a quieter card — a 1px hairline border and a much gentler
/// shadow — for dense grids and shelves where the default elevation makes
/// every card look like it is floating on its own (owner feedback,
/// 2026-08-23, the Discover redesign). Dark mode drops the shadow and keeps
/// the border, exactly as the Card theme already does.
class ManfaaCard extends StatelessWidget {
  const ManfaaCard({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.all(Gap.lg),
    this.color,
    this.soft = false,
  });

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;
  final Color? color;
  final bool soft;

  @override
  Widget build(BuildContext context) {
    if (soft) {
      return _SoftCard(
        onTap: onTap,
        padding: padding,
        color: color,
        child: child,
      );
    }

    final card = Card(
      color: color,
      child: onTap == null
          ? Padding(padding: padding, child: child)
          : InkWell(
              borderRadius: BorderRadius.circular(Corner.card),
              onTap: onTap,
              child: Padding(padding: padding, child: child),
            ),
    );
    return card;
  }
}

class _SoftCard extends StatelessWidget {
  const _SoftCard({
    required this.child,
    required this.padding,
    this.onTap,
    this.color,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final light = theme.brightness == Brightness.light;
    final radius = BorderRadius.circular(Corner.card);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: color ?? theme.colorScheme.surfaceContainerLowest,
        borderRadius: radius,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        boxShadow: light
            // Far softer than the theme's elevation:10 — a low, tight
            // shadow that grounds the card instead of floating it.
            ? const [
                BoxShadow(
                  color: Color(0x0D1A1F36),
                  blurRadius: 6,
                  offset: Offset(0, 1),
                ),
              ]
            : const [],
      ),
      child: Material(
        type: MaterialType.transparency,
        child: onTap == null
            ? Padding(padding: padding, child: child)
            : InkWell(
                borderRadius: radius,
                onTap: onTap,
                child: Padding(padding: padding, child: child),
              ),
      ),
    );
  }
}
