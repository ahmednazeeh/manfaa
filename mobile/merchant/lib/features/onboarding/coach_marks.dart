/// The coachmark engine: a dimmed screen with a hole cut around a REAL
/// widget, and a bubble beside it.
///
/// WHY THIS IS HAND-BUILT AND NOT A PACKAGE. The two candidates
/// (tutorial_coach_mark, showcaseview) both want to own the widget tree —
/// showcaseview wraps every target in its own `Showcase` widget under a
/// `ShowCaseWidget` ancestor, tutorial_coach_mark takes a list of
/// `TargetFocus` built from GlobalKeys and paints with its own theme. What
/// is actually needed here is ~200 lines: a key registry, a difference
/// path, and a bubble that picks a side. Against that, a dependency buys a
/// second animation vocabulary, a second set of colours to fight into the
/// house tokens, an RTL story neither package tests, and one more package
/// to hold the merchant app's Flutter upgrades hostage. The engine below
/// speaks Gap/Corner/ManfaaTint natively and mirrors in dv for free,
/// because it only ever positions with `left`/`right` symmetric margins.
///
/// THE RULES THIS THING OBEYS, all of them tested:
///  * a step whose target is not on screen is SKIPPED, never drawn as a
///    hole over nothing;
///  * every step is skippable, and the system back gesture closes the whole
///    tour — a walkthrough must never be a trap;
///  * a tap anywhere on the dim advances, so a screen that looks frozen
///    never is;
///  * the bubble picks the side with more room, never leaves the safe area
///    whatever the ring is doing, and scrolls its own body — so a 320×568
///    phone at a 1.6 system text scale gets the same tour as a tall one;
///  * the screen changing shape mid-step (rotation, unfolding, a font-size
///    change) re-measures everything rather than leaving the hole and the
///    bubble on coordinates that no longer exist.
library;

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../l10n/gen/app_localizations.dart';

/// Where the anchors live. One registry per app, so a screen can register a
/// widget without knowing which tour (if any) will point at it.
///
/// The registry OWNS the keys rather than taking them, because a GlobalKey
/// must survive rebuilds to keep pointing at the same element — a key minted
/// inside a build method would be a new key every frame and the hole would
/// land on nothing.
class CoachRegistry {
  final _keys = <String, GlobalKey>{};

  /// The stable key for [id], minted on first use.
  GlobalKey anchor(String id) =>
      _keys.putIfAbsent(id, () => GlobalKey(debugLabel: 'coach:$id'));

  /// The element currently wearing [id]'s key, or null when nothing on
  /// screen does.
  BuildContext? contextOf(String id) => _keys[id]?.currentContext;

  /// [id]'s rectangle in global (screen) coordinates, or null when it is
  /// absent, unlaid-out, or has collapsed to nothing.
  Rect? rectOf(String id) {
    final context = contextOf(id);
    if (context == null) return null;

    final box = context.findRenderObject();
    if (box is! RenderBox || !box.hasSize || box.size.isEmpty) return null;
    if (!box.attached) return null;

    return box.localToGlobal(Offset.zero) & box.size;
  }
}

final coachRegistryProvider = Provider<CoachRegistry>((_) => CoachRegistry());

/// Marks its child as a tour target. A no-op at every other moment — it
/// adds a keyed subtree and nothing else, no builder, no wrapper box.
class CoachAnchor extends ConsumerWidget {
  const CoachAnchor({super.key, required this.id, required this.child});

  final String id;
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) => KeyedSubtree(
    key: ref.watch(coachRegistryProvider).anchor(id),
    child: child,
  );
}

/// One stop on the tour: what to point at, and what to say about it.
class CoachStep {
  const CoachStep({
    required this.anchorId,
    required this.title,
    required this.body,
    this.radius = Corner.card,
  });

  /// The [CoachAnchor] id this step points at. A step whose anchor is not
  /// on screen is skipped.
  final String anchorId;

  final String title;
  final String body;

  /// The corner radius of the cut-out. Clamped to half the hole's short
  /// side, so a stadium nav slot reads as a stadium and a card as a card.
  final double radius;
}

/// How a tour ended. The caller decides what each one means to the server —
/// see the dashboard tour, where finishing and skipping both stop the
/// unsolicited prompt but a stray back gesture deliberately does not.
enum CoachTourExit {
  /// Walked to the end.
  completed,

  /// "Skip tour" pressed.
  skipped,

  /// Closed some other way — the system back gesture, or a route pop.
  dismissed,
}

/// Run the tour. Returns how it ended; never throws, and never returns
/// before the overlay is gone.
///
/// Answers [CoachTourExit.dismissed] immediately when NOT ONE step's target
/// is on screen — there is nothing to point at, and a dimmed screen with an
/// empty bubble is worse than no tour at all.
Future<CoachTourExit> showCoachTour(
  BuildContext context, {
  required CoachRegistry registry,
  required List<CoachStep> steps,
}) async {
  // Resolved ONCE, here, and it is the resolved list the tour runs — so
  // "Step 1 of 2" is what a manager without a Credit tab reads, rather than
  // opening on "Step 3 of 4" and being left to wonder what they missed.
  // Steps can still vanish mid-tour (a card scrolled out of a rebuilt list);
  // the walk skips those too.
  final live = [
    for (final step in steps)
      if (registry.contextOf(step.anchorId) != null) step,
  ];
  if (live.isEmpty) return CoachTourExit.dismissed;

  final exit = await Navigator.of(
    context,
    rootNavigator: true,
  ).push<CoachTourExit>(_CoachTourRoute(registry: registry, steps: live));

  return exit ?? CoachTourExit.dismissed;
}

/// A PopupRoute, so the system back gesture pops it for free (with a null
/// result — which is exactly [CoachTourExit.dismissed]) and the framework
/// keeps the screen underneath mounted and measurable.
class _CoachTourRoute extends PopupRoute<CoachTourExit> {
  _CoachTourRoute({required this.registry, required this.steps});

  final CoachRegistry registry;
  final List<CoachStep> steps;

  // We paint our own dim (with a hole in it), so the framework's flat
  // barrier colour must stay off. The barrier itself is still inserted and
  // still swallows taps meant for the screen below.
  @override
  Color? get barrierColor => null;

  @override
  bool get barrierDismissible => false;

  @override
  String? get barrierLabel => null;

  @override
  bool get semanticsDismissible => false;

  @override
  Duration get transitionDuration => const Duration(milliseconds: 180);

  @override
  Widget buildPage(
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
  ) => _CoachTourView(registry: registry, steps: steps);

  @override
  Widget buildTransitions(
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) => FadeTransition(
    opacity: CurvedAnimation(parent: animation, curve: Curves.easeOut),
    child: child,
  );
}

class _CoachTourView extends StatefulWidget {
  const _CoachTourView({required this.registry, required this.steps});

  final CoachRegistry registry;
  final List<CoachStep> steps;

  @override
  State<_CoachTourView> createState() => _CoachTourViewState();
}

class _CoachTourViewState extends State<_CoachTourView>
    with WidgetsBindingObserver {
  /// The step being shown, and its target's rectangle. Null while the first
  /// one is still being resolved — the frame in which the route appears has
  /// no measurement yet, and a hole guessed at is a hole in the wrong place.
  int _index = 0;
  Rect? _rect;

  /// Guards against two settles racing (a fast double tap through the
  /// ensureVisible animation).
  bool _moving = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _settle(0, 1));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// The screen changed shape under the tour: a rotation, a foldable
  /// opening, a split-screen drag, a system font-size change. Every anchor
  /// has moved, so the rectangle this step was measured at is now fiction —
  /// and both the hole and the bubble are drawn from it. Measure again on
  /// the next frame, when the new layout exists.
  ///
  /// The app locks no orientation and the Android activity is not recreated
  /// (configChanges lists orientation, screenSize and fontScale), so this
  /// callback is the only notice the tour gets.
  @override
  void didChangeMetrics() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _settle(_index, 1);
    });
  }

  /// Walk from [from] in direction [step] until a target actually resolves,
  /// scrolling it into view on the way. Falling off the END finishes the
  /// tour; falling off the START simply stays put, because "back" from the
  /// first step is not an exit.
  Future<void> _settle(int from, int step) async {
    if (_moving) return;
    _moving = true;

    // Read BEFORE the first await: nothing below may touch `context` after
    // one. A window that DOES resize mid-step re-enters through
    // didChangeMetrics above, which runs this again against the new size.
    final screen = Offset.zero & MediaQuery.sizeOf(context);

    try {
      var i = from;

      while (i >= 0 && i < widget.steps.length) {
        final id = widget.steps[i].anchorId;
        // Re-read every pass: an ensureVisible on the previous step may
        // have rebuilt the list under us, and `mounted` on the anchor's own
        // context is the only honest test of whether it is still there.
        final target = widget.registry.contextOf(id);

        if (target != null && target.mounted) {
          // Bring it into the viewport first when it lives in a scroll view;
          // a card two screens down is "present" but not pointable-at.
          if (Scrollable.maybeOf(target) != null) {
            await Scrollable.ensureVisible(
              target,
              alignment: 0.5,
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeOut,
            );
            if (!mounted) return;
          }

          final rect = widget.registry.rectOf(id);
          if (rect != null && rect.overlaps(screen)) {
            setState(() {
              _index = i;
              _rect = rect;
            });
            return;
          }
        }

        i += step;
      }

      // Nothing left ahead: the tour is over. Nothing left behind: stay.
      if (step > 0 && mounted) _close(CoachTourExit.completed);
    } finally {
      _moving = false;
    }
  }

  void _close(CoachTourExit exit) {
    if (!mounted) return;
    Navigator.of(context).pop(exit);
  }

  void _next() => _settle(_index + 1, 1);
  void _back() => _settle(_index - 1, -1);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final rect = _rect;

    // The dim without a hole while the first target is being resolved: one
    // frame, and it reads as the tour opening rather than as a flash.
    final dim = theme.brightness == Brightness.light
        ? ManfaaColors.ink.withValues(alpha: 0.74)
        : Colors.black.withValues(alpha: 0.76);

    final hole = rect?.inflate(6);
    final screen = MediaQuery.sizeOf(context);
    final safe = MediaQuery.paddingOf(context);

    return Semantics(
      container: true,
      explicitChildNodes: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        // Tapping the dim advances. A modal surface that answers nothing at
        // all reads as a frozen app, and the two ways out (Skip, back) are
        // both still one gesture away.
        onTap: _next,
        child: Stack(
          children: [
            Positioned.fill(
              child: CustomPaint(
                painter: _SpotlightPainter(
                  hole: hole,
                  radius: hole == null
                      ? 0
                      : math.min(
                          widget.steps[_index].radius,
                          hole.shortestSide / 2,
                        ),
                  dim: dim,
                  ring: theme.colorScheme.secondary,
                ),
              ),
            ),
            if (hole != null)
              // Fills the screen and hands the bubble a budget it cannot
              // exceed; where the bubble actually lands is decided AFTER it
              // has been measured, by the delegate below.
              Positioned.fill(
                child: CustomSingleChildLayout(
                  delegate: _BubbleLayout(
                    hole: hole,
                    screen: screen,
                    safe: safe,
                  ),
                  child: _bubble(theme, l10n),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _bubble(ThemeData theme, AppLocalizations l10n) {
    final step = widget.steps[_index];
    final first = _index == 0;
    final last = _index == widget.steps.length - 1;

    return Container(
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(Corner.card),
        boxShadow: const [
          BoxShadow(
            color: Color(0x33000000),
            blurRadius: 28,
            offset: Offset(0, 10),
          ),
        ],
      ),
      padding: const EdgeInsets.all(Gap.lg),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.tourStepOf(_index + 1, widget.steps.length),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.secondary,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: Gap.xs),
          // The prose scrolls rather than overflowing: on a 320×568
          // phone with the system font scaled up there is not always
          // room for four lines, and a clipped instruction is worse
          // than a scrollbar.
          Flexible(
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(step.title, style: theme.textTheme.titleMedium),
                  const SizedBox(height: Gap.xs),
                  Text(
                    step.body,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: Gap.sm),
          // OverflowBar, not Row: the three controls sit on one line
          // wherever they fit and STACK — right-aligned, one per line —
          // where they do not, which is the 320dp phone at a large
          // system text size. A Row would have overflowed there.
          OverflowBar(
            alignment: MainAxisAlignment.end,
            overflowAlignment: OverflowBarAlignment.end,
            spacing: Gap.xs,
            overflowSpacing: Gap.xs,
            children: [
              TextButton(
                onPressed: () => _close(CoachTourExit.skipped),
                child: Text(l10n.tourSkip),
              ),
              if (!first)
                TextButton(onPressed: _back, child: Text(l10n.tourBack)),
              FilledButton(
                // The house FilledButton is a full-width block
                // (Size.fromHeight(50)); inside a bubble it has to be a
                // button, or OverflowBar sees an infinitely wide child
                // and stacks all three controls onto their own lines.
                style: FilledButton.styleFrom(
                  minimumSize: const Size(0, 44),
                  padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
                ),
                onPressed: last ? () => _close(CoachTourExit.completed) : _next,
                child: Text(last ? l10n.tourDone : l10n.tourNext),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Where the bubble goes, decided AFTER it has been measured.
///
/// This is the whole answer to "Skip tour is off the top of the screen".
/// The bubble used to be a `Positioned` anchored hard to the ring with a
/// height budget floored at 120dp: when the room beside the ring was
/// smaller than that — a 320dp phone at a 1.6 system text scale, where the
/// card the ring is round is itself much taller — it was laid out past the
/// edge of the screen and the `Stack` clipped it, taking the step's own
/// words and every control with it.
///
/// A [SingleChildLayoutDelegate] gets the child's real size before it has
/// to name a position, so nothing has to be guessed:
///
///  * the budget is the safe area, never a floor, so the bubble's own
///    scrolling body absorbs whatever does not fit;
///  * below the ring, else above it — reading order runs downwards;
///  * and if it fits beside the ring on neither side, it is pinned INSIDE
///    the safe area at the end the ring is not, overlapping the ring.
///    Covering part of what a step describes is survivable; being off the
///    screen with the only way out is not.
///
/// The panel's guided-tour.tsx `placeBubble()` decides the same thing in
/// the same order, having measured the bubble the same way.
class _BubbleLayout extends SingleChildLayoutDelegate {
  const _BubbleLayout({
    required this.hole,
    required this.screen,
    required this.safe,
  });

  final Rect hole;
  final Size screen;
  final EdgeInsets safe;

  double get _topEdge => safe.top + Gap.lg;

  double get _bottomEdge => screen.height - safe.bottom - Gap.lg;

  @override
  BoxConstraints getConstraintsForChild(BoxConstraints constraints) {
    // Symmetric side margins, so this mirrors in dv with nothing to change.
    final width = math.max(constraints.maxWidth - Gap.lg * 2, 0.0);

    return BoxConstraints(
      minWidth: width,
      maxWidth: width,
      maxHeight: math.max(_bottomEdge - _topEdge, 0.0),
    );
  }

  @override
  Offset getPositionForChild(Size size, Size childSize) {
    final below = hole.bottom + Gap.md;
    final above = hole.top - Gap.md - childSize.height;

    double top;
    if (below + childSize.height <= _bottomEdge) {
      top = below;
    } else if (above >= _topEdge) {
      top = above;
    } else {
      // No room on either side: sit at the end of the screen the ring is
      // furthest from.
      top = hole.center.dy < screen.height / 2
          ? _bottomEdge - childSize.height
          : _topEdge;
    }

    final lowest = math.max(_topEdge, _bottomEdge - childSize.height);

    return Offset(Gap.lg, top.clamp(_topEdge, lowest));
  }

  @override
  bool shouldRelayout(_BubbleLayout old) =>
      old.hole != hole || old.screen != screen || old.safe != safe;
}

/// The dim, with the target punched out of it and a ring drawn round the
/// hole. One path difference — no clip layers, no saveLayer per frame.
class _SpotlightPainter extends CustomPainter {
  const _SpotlightPainter({
    required this.hole,
    required this.radius,
    required this.dim,
    required this.ring,
  });

  final Rect? hole;
  final double radius;
  final Color dim;
  final Color ring;

  @override
  void paint(Canvas canvas, Size size) {
    final screen = Offset.zero & size;
    final paint = Paint()..color = dim;

    if (hole == null) {
      canvas.drawRect(screen, paint);
      return;
    }

    final cut = RRect.fromRectAndRadius(hole!, Radius.circular(radius));

    canvas.drawPath(
      Path.combine(
        PathOperation.difference,
        Path()..addRect(screen),
        Path()..addRRect(cut),
      ),
      paint,
    );

    canvas.drawRRect(
      cut,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..color = ring,
    );
  }

  @override
  bool shouldRepaint(_SpotlightPainter old) =>
      old.hole != hole ||
      old.radius != radius ||
      old.dim != dim ||
      old.ring != ring;
}
