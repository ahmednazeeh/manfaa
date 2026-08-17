import 'package:flutter/material.dart';

import '../tokens.dart';

/// The signature motif from the brand mockups: a soft rounded-square tile
/// holding a single line icon, tinted by [ManfaaTint]. It leads almost every
/// card and list row (wallet=green, code=violet, calendar=amber, …).
class IconTile extends StatelessWidget {
  const IconTile(
    this.icon, {
    super.key,
    this.tint = ManfaaTint.neutral,
    this.size = 40,
    this.iconSize = 21,
  });

  final IconData icon;
  final ManfaaTint tint;
  final double size;
  final double iconSize;

  @override
  Widget build(BuildContext context) {
    final c = tintColors(tint, Theme.of(context).brightness);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: c.bg,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Icon(icon, color: c.fg, size: iconSize),
    );
  }
}
