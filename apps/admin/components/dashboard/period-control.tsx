'use client';

import { CalendarRange } from 'lucide-react';
import {
  DASHBOARD_PRESET_LABELS,
  DASHBOARD_PRESETS,
  type DashboardPreset,
} from '@/lib/dashboard';
import { formatDate } from '@/lib/format';
import { daysInPeriod, formatCount, type Period } from '@/lib/reports';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * ONE filter row, above everything it scopes.
 *
 * Every tile, rate and chart on the page is read through this window, so the
 * control sits above all of them rather than inside any one card: two ranges
 * on one screen means two answers to the same question, and the reader has no
 * way to tell which figure was asked which.
 *
 * The resolved dates are spelled out underneath because "last 30 days" is
 * ambiguous everywhere it appears, and a money figure whose period the reader
 * has to guess at is not a figure they can act on.
 *
 * AND THEY ARE THE PAYLOAD'S DATES, NOT THE PICKER'S. The page holds the
 * previous window's frame on screen while a new one loads, so a line drawn
 * from what was ASKED would caption those figures with a window they do not
 * belong to for the whole of every period change. `shownPeriod` is
 * `dashboard.period` — what the server actually answered — and while it
 * differs from what the controls above say, the line says so out loud rather
 * than leaving a dimmed page as the only clue.
 *
 * The comparison sentence is conditional for the same reason: `money` is
 * superadmin-only and ABSENT for a plain admin, so describing a comparison
 * window to someone with no money panel on their page names a period nothing
 * on screen uses.
 */
export function DashboardPeriodControl({
  preset,
  onPresetChange,
  period,
  shownPeriod,
  onCustomChange,
  today,
  problem,
  showsMoney,
}: {
  preset: DashboardPreset;
  onPresetChange: (preset: DashboardPreset) => void;
  /** The REQUESTED window — what the buttons and the date inputs say. */
  period: Period;
  /**
   * The window the figures on screen actually came from (`dashboard.period`),
   * or null before the first answer has arrived.
   */
  shownPeriod: Period | null;
  onCustomChange: (period: Period) => void;
  /** Today as the Maldives has it — the newest date either end may name. */
  today: string;
  /** Why this window cannot be sent, from `periodProblem`. */
  problem: string | null;
  /** Whether the money panel is on this reader's page at all. */
  showsMoney: boolean;
}) {
  const shown = shownPeriod ?? period;
  const days = daysInPeriod(shown.from, shown.to);
  const catchingUp =
    shownPeriod !== null &&
    (shownPeriod.from !== period.from || shownPeriod.to !== period.to);

  return (
    <Card className="mb-5">
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex flex-wrap items-center gap-2">
          {DASHBOARD_PRESETS.map((candidate) => (
            <Button
              key={candidate}
              type="button"
              size="sm"
              variant={preset === candidate ? 'primary' : 'outline'}
              onClick={() => onPresetChange(candidate)}
            >
              {DASHBOARD_PRESET_LABELS[candidate]}
            </Button>
          ))}

          {preset === 'custom' ? (
            <div className="flex flex-wrap items-end gap-3 ps-1">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="dashboard-from" className="text-xs">
                  From
                </Label>
                <Input
                  id="dashboard-from"
                  type="date"
                  variant="sm"
                  className="w-40"
                  value={period.from}
                  max={today}
                  onChange={(event) =>
                    onCustomChange({ ...period, from: event.target.value })
                  }
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="dashboard-to" className="text-xs">
                  To
                </Label>
                <Input
                  id="dashboard-to"
                  type="date"
                  variant="sm"
                  className="w-40"
                  value={period.to}
                  min={period.from}
                  max={today}
                  onChange={(event) =>
                    onCustomChange({ ...period, to: event.target.value })
                  }
                />
              </div>
            </div>
          ) : null}
        </div>

        <div
          className={cn(
            'flex flex-wrap items-center gap-x-2 gap-y-1 text-xs',
            problem === null ? 'text-muted-foreground' : 'text-destructive',
          )}
        >
          <CalendarRange className="size-3.5 shrink-0" />
          {problem === null ? (
            <>
              <span className="font-medium text-foreground">
                {formatDate(shown.from)} – {formatDate(shown.to)}
              </span>
              <span>
                {days === 1 ? 'one day' : `${formatCount(days ?? 0)} days`},
                both ends included, Maldives time.
                {showsMoney ? (
                  <>
                    {' '}
                    The money figures compare against the{' '}
                    {days === 1 ? 'day' : `${formatCount(days ?? 0)} days`}{' '}
                    immediately before.
                  </>
                ) : null}
                {catchingUp ? (
                  <>
                    {' '}
                    <span className="font-medium text-foreground">
                      This is the window the figures below are for
                    </span>{' '}
                    — the one selected above is still loading.
                  </>
                ) : null}
              </span>
            </>
          ) : (
            <span className="font-medium">{problem}</span>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
