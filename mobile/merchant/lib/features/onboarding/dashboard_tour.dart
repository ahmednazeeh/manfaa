import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../l10n/gen/app_localizations.dart';
import 'coach_marks.dart';
import 'onboarding_providers.dart';

/// The two journeys the owner named — "How to Credit A Customer" and "How to
/// settle Due Bills" — walked on the REAL Dashboard, with the real widgets
/// lit up one at a time.
///
/// Four stops, two per journey: what the thing is, and where it lives.
/// Nothing here is a screenshot or a mock; every step points at a widget the
/// merchant can tap the moment the tour ends, which is the whole reason the
/// tour is worth having over a page of text.
///
/// TOUR COPY IS CLIENT-OWNED, and deliberately so: the API ships the
/// tasklist's instructional prose (which the sheet reuses verbatim) but no
/// tour script, because DOM ids and Flutter keys do not share a vocabulary
/// and a server that named steps would be naming widgets it cannot see.

/// The Credit slot in the bottom nav bar (or the rail).
const kCoachNavCredit = 'nav.credit';

/// The Settlements slot in the bottom nav bar (or the rail).
const kCoachNavSettlements = 'nav.settlements';

/// The Dashboard's "Credit customer cashback" card.
const kCoachDashCredit = 'dash.credit';

/// The Dashboard's "Outstanding to settle" card.
const kCoachDashOutstanding = 'dash.outstanding';

/// The script. Steps whose anchor is not on screen are skipped by the
/// engine, which is what makes this ONE list safe for every role: a manager
/// without `credits.create` has no Credit tab and no Credit card, and simply
/// gets the settlement half.
List<CoachStep> merchantTourSteps(AppLocalizations l10n) => [
  CoachStep(
    anchorId: kCoachNavCredit,
    title: l10n.tourCreditTabTitle,
    body: l10n.tourCreditTabBody,
    radius: Corner.bar,
  ),
  CoachStep(
    anchorId: kCoachDashCredit,
    title: l10n.tourCreditCardTitle,
    body: l10n.tourCreditCardBody,
  ),
  CoachStep(
    anchorId: kCoachDashOutstanding,
    title: l10n.tourOutstandingTitle,
    body: l10n.tourOutstandingBody,
  ),
  CoachStep(
    anchorId: kCoachNavSettlements,
    title: l10n.tourSettleTabTitle,
    body: l10n.tourSettleTabBody,
    radius: Corner.bar,
  ),
];

/// Run the walkthrough and tell the server not to offer it again.
///
/// Finishing and skipping both stop the unsolicited prompt — someone who
/// pressed "Skip tour" has answered the question. A stray back gesture
/// deliberately does NOT: an accidental swipe must not silently spend the
/// one offer this person gets, so `dismissed` leaves the state alone.
///
/// The tasklist survives all three. Watching the tour is not the same as
/// having credited anybody, and the server agrees — POST /onboarding/tour is
/// not a skip.
Future<void> startMerchantTour(BuildContext context, WidgetRef ref) async {
  final exit = await showCoachTour(
    context,
    registry: ref.read(coachRegistryProvider),
    steps: merchantTourSteps(context.l10n),
  );

  if (exit == CoachTourExit.dismissed) return;

  try {
    await ref.read(onboardingGuideProvider.notifier).tourFinished();
  } catch (_) {
    // The offer stands until the write lands. Nothing is lost and nothing
    // is worth interrupting a merchant with — the prompt simply appears
    // again next launch, which is the safe direction to fail in.
  }
}

/// The unsolicited offer, on the Dashboard only: one quiet line under the
/// header while the guide is live and this person has neither watched the
/// tour nor waved it away.
///
/// Draws nothing at all otherwise — including for the whole of a merchant's
/// life from day six onwards.
class TourPromptCard extends ConsumerWidget {
  const TourPromptCard({super.key, this.bottomGap = Gap.md});

  final double bottomGap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(onboardingTourOfferProvider)) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final l10n = context.l10n;

    return Padding(
      padding: EdgeInsets.only(bottom: bottomGap),
      child: ManfaaCard(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const IconTile(
              Icons.play_circle_outline_rounded,
              tint: ManfaaTint.violet,
              size: 44,
              iconSize: 22,
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    l10n.guideTourPromptTitle,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    l10n.guideTourPromptBody,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: Gap.sm),
                  // Wrap, not Row: "Not now" and "Start" must move onto two
                  // lines at a large text scale rather than overflow.
                  Wrap(
                    spacing: Gap.sm,
                    runSpacing: Gap.xs,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      FilledButton(
                        key: const Key('tour-prompt-start'),
                        style: FilledButton.styleFrom(
                          minimumSize: const Size(0, 40),
                        ),
                        onPressed: () => startMerchantTour(context, ref),
                        child: Text(l10n.guideTourStart),
                      ),
                      TextButton(
                        key: const Key('tour-prompt-dismiss'),
                        onPressed: () => _notNow(ref),
                        style: TextButton.styleFrom(
                          foregroundColor: theme.colorScheme.onSurfaceVariant,
                        ),
                        child: Text(l10n.guideTourDismiss),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// "Not now" means "stop asking", not "hide the tasklist" — the guide's
  /// own Skip is the only thing that does the second, and it asks first.
  /// The walkthrough itself stays available from the tasklist sheet forever.
  Future<void> _notNow(WidgetRef ref) async {
    try {
      await ref.read(onboardingGuideProvider.notifier).tourFinished();
    } catch (_) {
      // Same as the tour's own write: fail by asking again later.
    }
  }
}
