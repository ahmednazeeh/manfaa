'use client';

import { CalendarRange } from 'lucide-react';
import { formatDate } from '@/lib/format';
import {
  daysInPeriod,
  formatCount,
  PERIOD_PRESET_LABELS,
  PERIOD_PRESETS,
  type Period,
  type PeriodPreset,
} from '@/lib/reports';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

/**
 * The window every report is read through: three named periods and a
 * hand-picked one, with the resolved range spelled out underneath — plus the
 * one switch that changes which rows the window contains.
 *
 * The range is stated in words on purpose. "Last 3 months" is ambiguous
 * everywhere it appears — it could mean three whole calendar months or the
 * ninety days behind you — and a finance figure whose period the reader has
 * to guess at is not a figure they can act on. The dates below the buttons
 * settle it before anyone reads a total.
 *
 * The reversed-rows switch lives here, beside the dates, because it is the
 * same kind of control: both decide WHICH ROWS the report is built from, and
 * both change every total on the screen. One drives the preview and the
 * export together — see ReportsPage.
 */
export function PeriodControl({
  preset,
  onPresetChange,
  period,
  onCustomChange,
  today,
  problem,
  includeReversed,
  onIncludeReversedChange,
  disabled = false,
}: {
  preset: PeriodPreset;
  onPresetChange: (preset: PeriodPreset) => void;
  /** The resolved window — the preset's dates, or the typed ones. */
  period: Period;
  onCustomChange: (period: Period) => void;
  /** Today as the Maldives has it — the newest date either end may name. */
  today: string;
  /** Why this window cannot be sent, from `periodProblem`. */
  problem: string | null;
  /** Whether reversed transaction rows are in the report. Default off. */
  includeReversed: boolean;
  onIncludeReversedChange: (includeReversed: boolean) => void;
  disabled?: boolean;
}) {
  const days = daysInPeriod(period.from, period.to);

  return (
    <Card className="mb-5">
      <CardContent className="flex flex-col gap-4 py-4">
        <div className="flex flex-wrap items-center gap-2">
          {PERIOD_PRESETS.map((candidate) => (
            <Button
              key={candidate}
              type="button"
              size="sm"
              variant={preset === candidate ? 'primary' : 'outline'}
              onClick={() => onPresetChange(candidate)}
              disabled={disabled}
            >
              {PERIOD_PRESET_LABELS[candidate]}
            </Button>
          ))}

          {preset === 'custom' ? (
            <div className="flex flex-wrap items-end gap-3 ps-1">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="report-from" className="text-xs">
                  From
                </Label>
                <Input
                  id="report-from"
                  type="date"
                  variant="sm"
                  className="w-40"
                  value={period.from}
                  max={today}
                  disabled={disabled}
                  onChange={(event) =>
                    onCustomChange({ ...period, from: event.target.value })
                  }
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="report-to" className="text-xs">
                  To
                </Label>
                <Input
                  id="report-to"
                  type="date"
                  variant="sm"
                  className="w-40"
                  value={period.to}
                  min={period.from}
                  max={today}
                  disabled={disabled}
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
                {formatDate(period.from)} – {formatDate(period.to)}
              </span>
              <span>
                {days === 1 ? 'one day' : `${formatCount(days ?? 0)} days`},
                both ends included, Maldives time.
              </span>
            </>
          ) : (
            <span className="font-medium">{problem}</span>
          )}
        </div>

        <div className="flex items-start justify-between gap-4 border-t border-border pt-4">
          <div className="flex flex-col gap-1">
            <Label
              htmlFor="report-include-reversed"
              className="cursor-pointer text-sm font-medium"
            >
              Include reversed transactions
            </Label>
            <p className="max-w-3xl text-xs text-muted-foreground">
              Off by default — a reversed sale was undone, so its row is left
              out of this table and of the .xlsx alike. The earnings report is
              the exception and always keeps its reversal postings: on the
              ledger, the reversal is the entry that takes the fee back out of
              income.
            </p>
          </div>

          <Switch
            id="report-include-reversed"
            checked={includeReversed}
            onCheckedChange={onIncludeReversedChange}
            disabled={disabled}
          />
        </div>
      </CardContent>
    </Card>
  );
}
