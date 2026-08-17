import 'package:flutter/material.dart';

import '../tokens.dart';

/// A white card with the standard padding and (optionally) a tap ripple —
/// the container almost every surface in the app sits inside. Thin wrapper
/// over [Card] so padding and ink-well radius stay consistent everywhere.
class ManfaaCard extends StatelessWidget {
  const ManfaaCard({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.all(Gap.lg),
    this.color,
  });

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;
  final Color? color;

  @override
  Widget build(BuildContext context) {
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
