'use client';

import { MoneyText } from '@manfaa/ui';
import { BadgeCheck, CircleAlert } from 'lucide-react';
import { usePosWaiver } from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardHeading, CardTitle } from '@/components/ui/card';

/**
 * The POS-fee waiver card (owner, 2026-08-23): free IsleBooks POS for a
 * month with ≥1% cashback all month, no overdues, and MVR 200,000 of
 * earning sales OR MVR 5,000 of cashback through Manfaa. Shows last
 * month's verdict and this month's progress on whichever track is closer —
 * the visible nudge to put more sales through Manfaa.
 */
export function PosWaiverCard() {
  const waiver = usePosWaiver();

  if (!waiver.data) {
    return null; // loads quietly; the dashboard never waits on it
  }

  const { criteria, last_month: last, current_month: current } = waiver.data;

  const volumePct = Math.min(
    100,
    (current.volume_laari / criteria.volume_threshold_laari) * 100,
  );
  const cashbackPct = Math.min(
    100,
    (current.cashback_laari / criteria.cashback_threshold_laari) * 100,
  );
  // Show the track the store is closest to clearing.
  const volumeLeads = volumePct >= cashbackPct;
  const pct = Math.round(Math.max(volumePct, cashbackPct));
  const met = pct >= 100 && current.rate_ok && current.overdue_ok;

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>Free IsleBooks POS</CardTitle>
        </CardHeading>
        {last ? (
          <Badge
            variant={last.qualified ? 'success' : 'secondary'}
            appearance="light"
          >
            {last.qualified
              ? `${last.month} invoice waived`
              : `${last.month}: not qualified`}
          </Badge>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="flex items-baseline justify-between gap-3 text-sm">
          <span className="text-muted-foreground">
            {volumeLeads ? 'Sales through Manfaa this month' : 'Cashback given this month'}
          </span>
          <span className="font-medium">
            <MoneyText
              laari={volumeLeads ? current.volume_laari : current.cashback_laari}
            />{' '}
            <span className="text-muted-foreground">
              /{' '}
              <MoneyText
                laari={
                  volumeLeads
                    ? criteria.volume_threshold_laari
                    : criteria.cashback_threshold_laari
                }
              />
            </span>
          </span>
        </div>

        <div className="h-2 overflow-hidden rounded-full bg-muted">
          <div
            className={`h-full rounded-full ${met ? 'bg-green-500' : 'bg-violet-600'}`}
            style={{ width: `${pct}%` }}
          />
        </div>

        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1">
            {current.rate_ok ? (
              <BadgeCheck className="size-3.5 text-green-600" />
            ) : (
              <CircleAlert className="size-3.5 text-amber-600" />
            )}
            Cashback rate ≥ 1% all month
          </span>
          <span className="inline-flex items-center gap-1">
            {current.overdue_ok ? (
              <BadgeCheck className="size-3.5 text-green-600" />
            ) : (
              <CircleAlert className="size-3.5 text-amber-600" />
            )}
            No overdue settlements
          </span>
        </div>

        <p className="text-xs text-muted-foreground">
          Keep at least 1% cashback and reach{' '}
          <MoneyText laari={criteria.volume_threshold_laari} /> in sales or{' '}
          <MoneyText laari={criteria.cashback_threshold_laari} /> in cashback
          through Manfaa, settle on time, and that month&apos;s IsleBooks
          invoice is waived.
        </p>
      </CardContent>
    </Card>
  );
}
