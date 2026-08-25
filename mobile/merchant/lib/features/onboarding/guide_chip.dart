import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import 'coach_marks.dart';
import 'dashboard_tour.dart';
import 'onboarding_providers.dart';

/// The guided-setup tasklist, as the till app carries it.
///
/// WHERE IT LIVES, AND WHY IT IS NOT WHERE THE OWNER SAID. The ask was
/// "left bottom" — a sidebar idea, and the web panel has a sidebar to put it
/// in. This app has none: it has a floating stadium nav bar across the
/// bottom and five tabs. So the tasklist is anchored at the phone's true
/// bottom-leading corner, as a compact chip sitting directly ABOVE that bar
/// ([OnboardingChipBar]) and mirroring to the right in dv exactly like every
/// other directional thing here. On the ≥840dp slate the app DOES grow a
/// left rail, and there the chip goes literally where it was asked for: the
/// bottom of that rail ([OnboardingRailChip]).
///
/// It is chrome, not a dashboard card, ON PURPOSE. The Dashboard tab is
/// gated on `settlements.view`, so a cashier does not have one — and "credit
/// your first customer" is exactly the instruction a cashier needs. A card
/// low in the Dashboard's scroll would have been invisible to the people the
/// owner wrote it for. In the shell chrome it is equally reachable from
/// every tab, whatever the role.
///
/// The chip carries the progress; the sheet behind it carries the days
/// remaining, the instructional prose and the permanent Skip.

/// The height the chip row occupies above the nav bar. Used BOTH to size the
/// row and to widen every tab screen's bottom clearance ([BottomInsetExtra]),
/// so the two can never drift apart and leave a card under the chip.
const double kGuideChipBarHeight = 52;

/// The chip itself, so a test can find it without going through its text.
const kGuideChipKey = Key('onboarding-guide-chip');

/// The sheet's scroll view.
const kGuideSheetKey = Key('onboarding-guide-sheet');

/// What the sheet asked the chip to do after it closed. Navigation the sheet
/// can do itself; the tour cannot, because it has to point at widgets that
/// only exist once the sheet is gone.
enum GuideSheetExit { tour }

/// The chip row that sits above the floating nav bar. Draws NOTHING — zero
/// height — whenever the guide is not live for this person, which is the
/// state every merchant is in forever after their first week.
class OnboardingChipBar extends ConsumerWidget {
  const OnboardingChipBar({super.key, required this.onTour});

  final VoidCallback onTour;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(onboardingChecklistProvider).show) {
      return const SizedBox.shrink();
    }

    return SizedBox(
      height: kGuideChipBarHeight,
      child: Align(
        // Bottom-LEADING: left in en, right in dv.
        alignment: AlignmentDirectional.centerStart,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
          child: GuideChip(onTour: onTour),
        ),
      ),
    );
  }
}

/// The rail shell's version: the same chip at the bottom of the left rail —
/// the owner's "left bottom", on the one surface that has a left.
class OnboardingRailChip extends ConsumerWidget {
  const OnboardingRailChip({super.key, required this.onTour});

  final VoidCallback onTour;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(onboardingChecklistProvider).show) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(Gap.sm, Gap.md, Gap.sm, Gap.lg),
      child: GuideChip(onTour: onTour, compact: true),
    );
  }
}

/// The pill: a small progress ring, a label and the count. Tapping opens the
/// tasklist.
class GuideChip extends ConsumerWidget {
  const GuideChip({super.key, required this.onTour, this.compact = false});

  final VoidCallback onTour;

  /// The rail is 96dp wide — there is room for the ring and the count, but
  /// not for the words beside them.
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final checklist = ref.watch(onboardingChecklistProvider);
    if (!checklist.show) return const SizedBox.shrink();

    final violet = tintColors(ManfaaTint.violet, theme.brightness);
    final light = theme.brightness == Brightness.light;

    Future<void> open() async {
      final exit = await showGuideSheet(context);
      if (exit == GuideSheetExit.tour) onTour();
    }

    return Semantics(
      button: true,
      label:
          '${l10n.guideChipLabel}. '
          '${l10n.guideProgress(checklist.done, checklist.total)}',
      child: Material(
        color: theme.colorScheme.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(Corner.bar),
        elevation: 0,
        child: InkWell(
          key: kGuideChipKey,
          borderRadius: BorderRadius.circular(Corner.bar),
          onTap: open,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(Corner.bar),
              border: Border.all(
                color: light ? violet.bg : theme.colorScheme.outlineVariant,
              ),
              boxShadow: light
                  ? const [
                      BoxShadow(
                        color: Color(0x141A1F36),
                        blurRadius: 16,
                        offset: Offset(0, 6),
                      ),
                    ]
                  : null,
            ),
            padding: EdgeInsets.symmetric(
              horizontal: compact ? Gap.xs : Gap.md,
              vertical: Gap.sm,
            ),
            // The rail is 96dp wide and the pill has to live inside it
            // whatever the system text size does to the count. scaleDown is
            // a no-op at every normal setting.
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _ProgressRing(
                    done: checklist.done,
                    total: checklist.total,
                    color: violet.fg,
                    track: violet.bg,
                  ),
                  SizedBox(width: compact ? Gap.xs : Gap.sm),
                  if (!compact) ...[
                    // Excluded from text scaling: the chip is a fixed-height
                    // slot above the nav bar, and at 1.3 the words otherwise
                    // push the count off the pill. The sheet behind it scales
                    // normally — that is where the reading happens.
                    MediaQuery.withClampedTextScaling(
                      maxScaleFactor: 1.1,
                      child: Text(
                        l10n.guideChipLabel,
                        style: theme.textTheme.labelLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    const SizedBox(width: Gap.sm),
                  ],
                  MediaQuery.withClampedTextScaling(
                    maxScaleFactor: 1.1,
                    child: Text(
                      '${checklist.done}/${checklist.total}',
                      style:
                          (compact
                                  ? theme.textTheme.labelMedium
                                  : theme.textTheme.labelLarge)
                              ?.copyWith(
                                color: violet.fg,
                                fontWeight: FontWeight.w800,
                              ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// done/total as a ring, with a tick once everything on THIS person's list
/// is done.
class _ProgressRing extends StatelessWidget {
  const _ProgressRing({
    required this.done,
    required this.total,
    required this.color,
    required this.track,
  });

  final int done;
  final int total;
  final Color color;
  final Color track;

  @override
  Widget build(BuildContext context) {
    final complete = total > 0 && done >= total;

    return SizedBox(
      width: 22,
      height: 22,
      child: Stack(
        alignment: Alignment.center,
        children: [
          CircularProgressIndicator(
            value: total == 0 ? 0 : done / total,
            strokeWidth: 3,
            backgroundColor: track,
            valueColor: AlwaysStoppedAnimation(color),
          ),
          if (complete) Icon(Icons.check_rounded, size: 12, color: color),
        ],
      ),
    );
  }
}

/// The tasklist itself. Opened from the chip; returns [GuideSheetExit.tour]
/// when the reader asked for the walkthrough.
Future<GuideSheetExit?> showGuideSheet(BuildContext context) =>
    showModalBottomSheet<GuideSheetExit>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      useSafeArea: true,
      backgroundColor: Theme.of(context).colorScheme.surfaceContainerLowest,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(Corner.sheet)),
      ),
      builder: (context) => const _GuideSheet(),
    );

class _GuideSheet extends ConsumerWidget {
  const _GuideSheet();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final guide = ref.watch(onboardingGuideProvider).valueOrNull;
    final checklist = ref.watch(onboardingChecklistProvider);

    // Skipped from the other surface while the sheet was open, or the five
    // days ran out on the stroke of midnight: close rather than redraw an
    // empty list.
    if (guide == null || !checklist.show) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) Navigator.of(context).maybePop();
      });
      return const SizedBox.shrink();
    }

    final title = guide.title(dhivehi: dhivehi);

    // Is there a walkthrough to offer THIS account, right now? Every step
    // points at the Credit tab, the Settlements tab or a Dashboard card, so
    // a manager who has none of them has no live step — and the engine
    // answers `dismissed` at once for an empty tour. Offering the button
    // anyway would close the sheet and do nothing at all, which reads as a
    // broken control. The panel gates its own "Show me how" the same way.
    final registry = ref.watch(coachRegistryProvider);
    final hasTour = merchantTourSteps(l10n).any(
      (step) => registry.contextOf(step.anchorId) != null,
    );

    return ConstrainedBox(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.sizeOf(context).height * 0.82,
      ),
      child: ListView(
        key: kGuideSheetKey,
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(Gap.xl, 0, Gap.xl, Gap.xl),
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  title.isEmpty ? l10n.guideTitleFallback : title,
                  style: theme.textTheme.titleLarge,
                ),
              ),
              const SizedBox(width: Gap.sm),
              if (guide.daysRemaining > 0)
                _DaysPill(days: guide.daysRemaining),
            ],
          ),
          const SizedBox(height: Gap.md),
          _Progress(done: checklist.done, total: checklist.total),
          const SizedBox(height: Gap.sm),
          Text(
            l10n.guideWindowNote(guide.windowDays),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: Gap.lg),
          if (hasTour) ...[
            FilledButton.tonalIcon(
              style: FilledButton.styleFrom(minimumSize: const Size(0, 46)),
              onPressed: () => Navigator.of(context).pop(GuideSheetExit.tour),
              icon: const Icon(Icons.play_circle_outline_rounded, size: 20),
              label: Text(l10n.guideTourCta),
            ),
            const SizedBox(height: Gap.lg),
          ],
          for (final task in checklist.tasks)
            _TaskRow(task: task, dhivehi: dhivehi),
          const SizedBox(height: Gap.sm),
          Divider(color: theme.colorScheme.outlineVariant),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              onPressed: () => _confirmSkip(context, ref, l10n),
              icon: const Icon(Icons.visibility_off_outlined, size: 18),
              style: TextButton.styleFrom(
                foregroundColor: theme.colorScheme.onSurfaceVariant,
              ),
              label: Text(l10n.guideSkip),
            ),
          ),
        ],
      ),
    );
  }

  /// Skipping is PERMANENT and shared with the website, so it is asked for
  /// once and then never asked about again.
  Future<void> _confirmSkip(
    BuildContext context,
    WidgetRef ref,
    AppLocalizations l10n,
  ) async {
    final messenger = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);

    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.guideSkipTitle),
        content: Text(l10n.guideSkipBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(l10n.back),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(l10n.guideSkipConfirm),
          ),
        ],
      ),
    );

    if (yes != true) return;

    try {
      await ref.read(onboardingGuideProvider.notifier).skip();
      navigator.maybePop();
    } catch (_) {
      // The guide stays exactly as it was — a skip that did not reach the
      // server is not a skip, and pretending otherwise would put it back on
      // the next launch with no explanation.
      messenger.showSnackBar(SnackBar(content: Text(l10n.guideSkipFailed)));
    }
  }
}

class _DaysPill extends StatelessWidget {
  const _DaysPill({required this.days});

  final int days;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // Amber in the final 48 hours, violet before that: the tone changes
    // when the window actually starts running out.
    final tint = tintColors(
      days <= 2 ? ManfaaTint.amber : ManfaaTint.violet,
      theme.brightness,
    );

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: Gap.md, vertical: 6),
      decoration: BoxDecoration(
        color: tint.bg,
        borderRadius: BorderRadius.circular(Corner.bar),
      ),
      child: Text(
        context.l10n.guideDaysLeft(days),
        style: theme.textTheme.labelMedium?.copyWith(
          color: tint.fg,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _Progress extends StatelessWidget {
  const _Progress({required this.done, required this.total});

  final int done;
  final int total;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(Corner.bar),
          child: LinearProgressIndicator(
            value: total == 0 ? 0 : done / total,
            minHeight: 8,
            backgroundColor: theme.colorScheme.surfaceContainer,
            valueColor: AlwaysStoppedAnimation(theme.colorScheme.secondary),
          ),
        ),
        const SizedBox(height: Gap.sm),
        Text(
          context.l10n.guideProgress(done, total),
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    );
  }
}

/// One task. [MerchantOnboardingTask.done] is DERIVED SERVER-SIDE from real
/// data — there is no tick here and there must never be one.
///
/// A done task collapses to a single ticked line; an outstanding one keeps
/// its instructional prose, because that prose IS the guided instruction the
/// owner asked for.
class _TaskRow extends ConsumerWidget {
  const _TaskRow({required this.task, required this.dhivehi});

  final MerchantOnboardingTask task;
  final bool dhivehi;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final session = ref.watch(sessionProvider);
    final where = _destinationFor(task, session);

    Future<void> go() async {
      final path = where.path;
      final web = where.web;
      // Captured BEFORE the sheet closes: this row's own context is
      // deactivated by the pop, and a router looked up through it afterwards
      // is a router looked up through a dead element.
      final router = GoRouter.of(context);
      final navigator = Navigator.of(context);

      navigator.maybePop();

      if (path != null) {
        router.go(path);
        return;
      }
      if (web != null) {
        await launchUrl(Uri.parse(web), mode: LaunchMode.externalApplication);
      }
    }

    final tappable = where.path != null || where.web != null;
    final tint = task.done ? ManfaaTint.green : ManfaaTint.violet;
    final colors = tintColors(tint, theme.brightness);

    return Padding(
      padding: const EdgeInsets.only(bottom: Gap.sm),
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.tile),
        onTap: tappable ? go : null,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: Gap.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // The tick is decorative — a done row also SAYS "Done" below,
              // so a screen reader never has to interpret an icon.
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: colors.bg,
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  task.done
                      ? Icons.check_rounded
                      : Icons.radio_button_unchecked_rounded,
                  size: 16,
                  color: colors.fg,
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      task.label(dhivehi: dhivehi),
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: task.done
                            ? theme.colorScheme.onSurfaceVariant
                            : theme.colorScheme.onSurface,
                        decoration: task.done
                            ? TextDecoration.lineThrough
                            : null,
                        decorationColor: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    if (task.done)
                      Text(
                        l10n.guideTaskDone,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: tintColors(
                            ManfaaTint.green,
                            theme.brightness,
                          ).fg,
                          fontWeight: FontWeight.w700,
                        ),
                      )
                    else ...[
                      const SizedBox(height: 2),
                      Text(
                        task.help(dhivehi: dhivehi),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                          height: 1.35,
                        ),
                      ),
                      if (tappable) ...[
                        const SizedBox(height: Gap.xs),
                        Text(
                          where.path != null
                              ? l10n.guideTaskOpen
                              : l10n.guideTaskOnWeb,
                          style: theme.textTheme.labelMedium?.copyWith(
                            color: theme.colorScheme.secondary,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ],
                  ],
                ),
              ),
              if (tappable && !task.done)
                Icon(
                  where.path != null
                      ? Icons.chevron_right_rounded
                      : Icons.open_in_new_rounded,
                  size: 20,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Where a task's `target` goes on THIS surface.
///
/// The till app does not have every door the website has — there is no
/// bank-account editor here, and an active store cannot re-enter the setup
/// wizard (the router would bounce it straight back). Those rows are not
/// dead ends: they open the same page on merchant.manfaa.app, using the
/// `web_path` the server already publishes for the panel.
///
/// The in-app paths are checked against the ROUTE's own permission, not the
/// task's: a person may hold `settlements.create` without `settlements.view`,
/// and offering them a tab the redirect would bounce is worse than offering
/// them the website.
({String? path, String? web}) _destinationFor(
  MerchantOnboardingTask task,
  MerchantSession session,
) {
  final path = switch (task.target) {
    'credit' when session.can('credits.create') => '/credit',
    'settlements' when session.can('settlements.view') => '/settlements',
    'staff' when session.can('staff.view') => '/more/employees',
    _ => null,
  };

  if (path != null) return (path: path, web: null);

  final webPath = task.webPath;

  return (
    path: null,
    web: webPath.startsWith('/') ? 'https://merchant.manfaa.app$webPath' : null,
  );
}
