'use client';

import { useMemo } from 'react';
import { Compass } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useCompleteOnboardingTour, useOnboardingGuide } from '@/lib/queries';
import { can } from '@/lib/roles';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useLayout } from '@/components/app-layout/context';
import { useTour } from './tour-provider';
import { TOURS } from './tours';

/**
 * The offer of a walkthrough, on the dashboard the walkthrough is about.
 *
 * Shown while the guide is live and this person has not already been
 * through it — the sidebar tasklist keeps both tours available for replay
 * afterwards, so this card is the invitation rather than the only door.
 *
 * "No thanks" marks the tour as done on the SERVER, deliberately. It is not
 * a local dismissal: a merchant who says they do not want a walkthrough
 * means it on their phone as well as their laptop, and `tour_completed` is
 * exactly the field for "stop offering this". It does not skip the
 * tasklist — declining a tour is not the same as saying the five tasks do
 * not matter, and skipping is its own control with its own confirmation.
 */
export function TourPrompt() {
  const { t } = useTranslation();
  const { me } = useLayout();
  const guide = useOnboardingGuide();
  const { startTour } = useTour();
  const { mutate: markTourCompleted, isPending } = useCompleteOnboardingTour();

  const tours = useMemo(
    () => TOURS.filter((tour) => can(me, tour.permission)),
    [me],
  );

  const data = guide.data;

  // Nothing to offer is not a card saying there is nothing to offer.
  if (data === undefined || !data.show || data.tour_completed) {
    return null;
  }

  if (tours.length === 0) {
    return null;
  }

  return (
    <Card className="border-primary/30 bg-primary/[0.03]">
      <CardContent className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Compass className="size-4" />
          </span>
          <div className="flex flex-col gap-0.5">
            <span className="text-sm font-semibold text-mono">
              {t('onboarding.prompt.title')}
            </span>
            <span className="text-xs text-muted-foreground">
              {t('onboarding.prompt.body')}
            </span>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {tours.map((tour, index) => (
            <Button
              key={tour.id}
              size="sm"
              variant={index === 0 ? 'primary' : 'outline'}
              onClick={() => startTour(tour.id)}
            >
              {t(`${tour.i18nKey}.name`)}
            </Button>
          ))}
          <Button
            size="sm"
            variant="ghost"
            className="text-muted-foreground"
            disabled={isPending}
            onClick={() => markTourCompleted()}
          >
            {t('onboarding.prompt.decline')}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
