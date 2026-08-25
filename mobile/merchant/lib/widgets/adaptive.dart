import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

/// MR7 — the merchant app's width story, in one place.
///
/// Material 3 window classes, applied conservatively:
///   compact  < 600dp  — the shipped phone app, byte-for-byte;
///   medium   600–839  — same shell (stadium bottom nav), but scrolling
///                       content sits in a centered ~640dp rail;
///   expanded ≥ 840    — the bottom bar hands over to a left NavigationRail
///                       and the Dashboard/Credit screens go two-column.
///
/// Two different widths matter and they are NOT the same number:
///  * the WINDOW width decides the shell (bottom bar vs rail) — read it via
///    [railShell] / MediaQuery, because the shell owns the whole window;
///  * the CONTENT width (window minus the rail) decides a screen's layout —
///    read it from the LayoutBuilder constraints the screen already sits in,
///    so a screen never has to know whether a rail is eating 96dp beside it.
const double kMediumMinWidth = 600;
const double kExpandedMinWidth = 840;

/// Single-column screens cap their content at this width (the ~640dp rail).
const double kContentRailWidth = 640;

/// Screens with a two-column expanded layout (Dashboard, Credit) cap at this
/// instead — two comfortable card columns, not two stretched ones.
const double kWideContentWidth = 1040;

/// True when the shell draws the left NavigationRail instead of the floating
/// bottom bar. Window width on purpose — this must agree with the shell.
bool railShell(BuildContext context) =>
    MediaQuery.sizeOf(context).width >= kExpandedMinWidth;

/// Extra bottom clearance published BY the shell TO its tab screens, for
/// chrome the shell grew that the fixed [Gap.navClearance] does not cover —
/// today, the guided-setup chip riding above the floating nav bar.
///
/// An InheritedWidget rather than another constant, because the chip is only
/// there for a merchant's first five days: every screen's padding has to
/// follow it appearing and disappearing, and none of them should have to
/// know it exists. Zero when nothing extra is showing, which is what keeps
/// every shipped golden byte-identical.
class BottomInsetExtra extends InheritedWidget {
  const BottomInsetExtra({
    super.key,
    required this.extra,
    required super.child,
  });

  final double extra;

  static double of(BuildContext context) =>
      context
          .dependOnInheritedWidgetOfExactType<BottomInsetExtra>()
          ?.extra ??
      0;

  @override
  bool updateShouldNotify(BottomInsetExtra oldWidget) =>
      oldWidget.extra != extra;
}

/// Bottom padding for a tab screen's scroll view. On phones the floating
/// bottom bar overlays content (`extendBody`), so the last card needs
/// [Gap.navClearance] to scroll clear of it; with the rail shell there is no
/// bar to clear and the same 104dp would be dead space — a normal end-of-page
/// breath is enough.
///
/// Plus whatever the shell says it has stacked above that bar
/// ([BottomInsetExtra]) — normally nothing.
double bottomClearanceOf(BuildContext context) =>
    (railShell(context) ? Gap.xxl : Gap.navClearance) +
    BottomInsetExtra.of(context);

/// Centers its child in a rail of at most [maxWidth] — the shared width cap
/// every tab screen adopts around its scroll view. Below the cap it is a
/// no-op (zero padding), so phone layouts render exactly as shipped.
class ContentRail extends StatelessWidget {
  const ContentRail({
    super.key,
    this.maxWidth = kContentRailWidth,
    required this.child,
  });

  final double maxWidth;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final inset = math.max(0.0, (constraints.maxWidth - maxWidth) / 2);
        return Padding(
          padding: EdgeInsets.symmetric(horizontal: inset),
          child: child,
        );
      },
    );
  }
}
