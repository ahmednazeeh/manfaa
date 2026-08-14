'use client';

import { useMemo, useState } from 'react';
import {
  createAdminFeeTierSchedule,
  type FeeTierBand,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import {
  bpToPercentString,
  formatBpPercent,
  MINIMUM_LEAD_TIME_MINUTES,
  parsePercentToBp,
  section4Preview,
  TIER_RANGE_MAX_BP,
  TIER_RANGE_MIN_BP,
  validateTierRows,
  type TierRowInput,
} from '@/lib/fee-tiers';
import { formatDateTime } from '@/lib/format';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Section4PreviewTable } from '@/components/settings/fee-tier-table';

const MALDIVES_OFFSET_MS = 5 * 60 * 60 * 1000;

/** "YYYY-MM-DDTHH:mm" in Maldives (UTC+5, no DST) for a datetime-local input. */
function toMaldivesLocalInput(date: Date): string {
  return new Date(date.getTime() + MALDIVES_OFFSET_MS)
    .toISOString()
    .slice(0, 16);
}

/** datetime-local value (Maldives wall clock) -> ISO with explicit +05:00. */
function toIsoWithOffset(local: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) {
    return null;
  }
  const iso = `${local}:00+05:00`;
  return Number.isNaN(new Date(iso).getTime()) ? null : iso;
}

function bandsToRows(bands: FeeTierBand[]): TierRowInput[] {
  return bands.map((band) => ({
    from_pct: bpToPercentString(band.from_bp),
    to_pct: bpToPercentString(band.to_bp),
    fee_pct: bpToPercentString(band.fee_bp),
  }));
}

const FALLBACK_ROWS: TierRowInput[] = bandsToRows([
  { from_bp: 50, to_bp: 99, fee_bp: 25 },
  { from_bp: 100, to_bp: 199, fee_bp: 50 },
  { from_bp: 200, to_bp: 499, fee_bp: 75 },
  { from_bp: 500, to_bp: 2000, fee_bp: 100 },
]);

/** Fine print under an input: the exact integer bp behind the percent string. */
function BpFinePrint({ pct }: { pct: string }) {
  const bp = parsePercentToBp(pct);
  return (
    <span className="text-[11px] leading-4 text-muted-foreground">
      {bp !== null ? `= ${bp} bp` : ' '}
    </span>
  );
}

/**
 * "Schedule new tiers": a future-dated, append-only replacement for the §4
 * fee table. Humans type percent (up to 2 decimals, converted to integer bp
 * by string decomposition — never a float); validation mirrors the server
 * rule for rule — contiguity from 0.50%, the schedule's own last band sets
 * its ceiling (absolute maximum 20.00%), fee never above the band's lowest
 * rate, effective date at least an hour out — and the §4 fixture is
 * recomputed live under the candidate tiers before anything is submitted.
 */
export function ScheduleTiersDialog({
  currentBands,
}: {
  currentBands: FeeTierBand[] | null;
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [step, setStep] = useState<'edit' | 'confirm'>('edit');
  const [rows, setRows] = useState<TierRowInput[]>([]);
  const [effectiveLocal, setEffectiveLocal] = useState('');

  const validation = useMemo(() => validateTierRows(rows), [rows]);
  const preview = validation.bands ? section4Preview(validation.bands) : null;

  const effectiveIso = toIsoWithOffset(effectiveLocal);
  const effectiveError =
    effectiveIso === null
      ? 'Pick the date and time the new tiers take effect.'
      : new Date(effectiveIso).getTime() <
          Date.now() + MINIMUM_LEAD_TIME_MINUTES * 60 * 1000
        ? 'Must be at least one hour from now — vendors and tills need notice.'
        : null;

  const formValid =
    validation.bands !== null &&
    effectiveIso !== null &&
    effectiveError === null;

  const reset = () => {
    setStep('edit');
    setRows(currentBands ? bandsToRows(currentBands) : FALLBACK_ROWS);
    // Default to tomorrow at this time in Maldives wall-clock — safely past
    // the one-hour minimum while still visibly editable.
    setEffectiveLocal(
      toMaldivesLocalInput(new Date(Date.now() + 24 * 60 * 60 * 1000)),
    );
  };

  const create = useMutation({
    mutationFn: () =>
      createAdminFeeTierSchedule({
        effective_from: effectiveIso as string,
        tiers: validation.bands as FeeTierBand[],
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'fee-tiers'] });
      toast.success('New fee tier schedule published.');
      setOpen(false);
    },
    onError: (error) => {
      toast.error(apiErrorMessage(error));
      setStep('edit');
    },
  });

  const updateRow = (
    index: number,
    field: keyof TierRowInput,
    value: string,
  ) => {
    setRows((current) =>
      current.map((row, i) => (i === index ? { ...row, [field]: value } : row)),
    );
  };

  const addRow = () => {
    setRows((current) => {
      const last = current[current.length - 1];
      const lastToBp = last ? parsePercentToBp(last.to_pct) : null;
      const nextFrom = bpToPercentString(
        lastToBp !== null ? lastToBp + 1 : TIER_RANGE_MIN_BP,
      );
      return [...current, { from_pct: nextFrom, to_pct: '', fee_pct: '' }];
    });
  };

  const removeRow = (index: number) => {
    setRows((current) => current.filter((_, i) => i !== index));
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button>
          <CalendarClock />
          Schedule new tiers
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-3xl">
        {step === 'edit' ? (
          <>
            <DialogHeader>
              <DialogTitle>Schedule a new fee tier table</DialogTitle>
              <DialogDescription>
                Rates are percentages with up to two decimals. Bands must be
                contiguous starting at {formatBpPercent(TIER_RANGE_MIN_BP)}; the
                last band&apos;s end is the schedule&apos;s own ceiling — the
                highest rate merchants can set (absolute maximum{' '}
                {formatBpPercent(TIER_RANGE_MAX_BP)}) — and a band&apos;s fee
                can never exceed its lowest cashback rate. Published schedules
                are append-only — they are never edited or deleted.
              </DialogDescription>
            </DialogHeader>
            <DialogBody className="flex max-h-[65vh] flex-col gap-5 overflow-y-auto">
              <div className="flex flex-col gap-2">
                <div className="grid grid-cols-[1fr_1fr_1fr_2rem] items-center gap-2 text-xs font-medium text-muted-foreground">
                  <span>From (%)</span>
                  <span>To (%)</span>
                  <span>Fee (%)</span>
                  <span />
                </div>
                {rows.map((row, index) => {
                  const issues = validation.rowIssues[index] ?? {};
                  return (
                    <div key={index} className="flex flex-col gap-1">
                      <div className="grid grid-cols-[1fr_1fr_1fr_2rem] items-start gap-2">
                        <div className="flex flex-col gap-0.5">
                          <Input
                            inputMode="decimal"
                            aria-label={`Band ${index + 1} from (%)`}
                            aria-invalid={issues.from_pct !== undefined}
                            value={row.from_pct}
                            onChange={(event) =>
                              updateRow(index, 'from_pct', event.target.value)
                            }
                          />
                          <BpFinePrint pct={row.from_pct} />
                        </div>
                        <div className="flex flex-col gap-0.5">
                          <Input
                            inputMode="decimal"
                            aria-label={`Band ${index + 1} to (%)`}
                            aria-invalid={issues.to_pct !== undefined}
                            value={row.to_pct}
                            onChange={(event) =>
                              updateRow(index, 'to_pct', event.target.value)
                            }
                          />
                          <BpFinePrint pct={row.to_pct} />
                        </div>
                        <div className="flex flex-col gap-0.5">
                          <Input
                            inputMode="decimal"
                            aria-label={`Band ${index + 1} fee (%)`}
                            aria-invalid={issues.fee_pct !== undefined}
                            value={row.fee_pct}
                            onChange={(event) =>
                              updateRow(index, 'fee_pct', event.target.value)
                            }
                          />
                          <BpFinePrint pct={row.fee_pct} />
                        </div>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          aria-label={`Remove band ${index + 1}`}
                          onClick={() => removeRow(index)}
                          disabled={rows.length === 1}
                        >
                          <Trash2 />
                        </Button>
                      </div>
                      {issues.from_pct || issues.to_pct || issues.fee_pct ? (
                        <div className="flex flex-col gap-0.5 text-xs text-destructive">
                          {issues.from_pct ? (
                            <span>From: {issues.from_pct}</span>
                          ) : null}
                          {issues.to_pct ? (
                            <span>To: {issues.to_pct}</span>
                          ) : null}
                          {issues.fee_pct ? (
                            <span>Fee: {issues.fee_pct}</span>
                          ) : null}
                        </div>
                      ) : null}
                    </div>
                  );
                })}
                <div className="flex items-center justify-between">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addRow}
                  >
                    <Plus />
                    Add band
                  </Button>
                  {validation.scheduleError ? (
                    <span className="text-xs text-destructive">
                      {validation.scheduleError}
                    </span>
                  ) : null}
                </div>
              </div>

              <div className="flex flex-col gap-1.5">
                <Label htmlFor="effective-from">
                  Effective from (Maldives time, UTC+5)
                </Label>
                <Input
                  id="effective-from"
                  type="datetime-local"
                  className="w-fit"
                  value={effectiveLocal}
                  onChange={(event) => setEffectiveLocal(event.target.value)}
                  aria-invalid={effectiveError !== null}
                />
                {effectiveError ? (
                  <p className="text-xs text-destructive">{effectiveError}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    Sales occurring after this instant are priced under the new
                    tiers; everything earlier keeps its frozen terms.
                  </p>
                )}
              </div>

              {preview ? (
                <div className="flex flex-col gap-2">
                  <div className="text-sm font-medium">
                    §4 example under these tiers
                  </div>
                  <p className="text-xs text-muted-foreground">
                    The plan&apos;s fixture batch — four invoices at{' '}
                    {formatBpPercent(200)} cashback — with each fee line
                    recomputed at your {formatBpPercent(preview.fee_bp)} band
                    (ceiling per line, then summed).
                  </p>
                  <Section4PreviewTable preview={preview} />
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">
                  Fix the bands above to see the §4 example recomputed under the
                  new tiers.
                </p>
              )}
            </DialogBody>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
              >
                Cancel
              </Button>
              <Button
                type="button"
                disabled={!formValid}
                onClick={() => setStep('confirm')}
              >
                Review and publish
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Publish this fee schedule?</DialogTitle>
              <DialogDescription>
                This is a pricing change for every merchant on the platform.
              </DialogDescription>
            </DialogHeader>
            <DialogBody className="flex flex-col gap-4">
              <Alert variant="warning" appearance="light">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>
                    Applies to sales occurring after{' '}
                    {effectiveIso ? formatDateTime(effectiveIso) : '—'}
                  </AlertTitle>
                  <AlertDescription>
                    Past sales keep their frozen fees — every recorded
                    transaction settles at the rate and fee frozen onto it at
                    sale time, and nothing already recorded is recomputed. Once
                    published, this schedule cannot be edited or deleted; to
                    change course, schedule another future-dated table.
                  </AlertDescription>
                </AlertContent>
              </Alert>
              <div className="text-sm text-muted-foreground">
                {validation.bands?.map((band) => (
                  <div key={band.from_bp}>
                    {formatBpPercent(band.from_bp)} –{' '}
                    {formatBpPercent(band.to_bp)} cashback →{' '}
                    {formatBpPercent(band.fee_bp)} fee (all-in{' '}
                    {formatBpPercent(band.from_bp + band.fee_bp)} –{' '}
                    {formatBpPercent(band.to_bp + band.fee_bp)})
                  </div>
                ))}
              </div>
            </DialogBody>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setStep('edit')}
                disabled={create.isPending}
              >
                Back
              </Button>
              <Button
                type="button"
                variant="destructive"
                onClick={() => create.mutate()}
                disabled={create.isPending || !formValid}
              >
                {create.isPending ? 'Publishing…' : 'Publish schedule'}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
