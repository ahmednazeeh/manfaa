'use client';

import {
  getAdminFeeTierSchedules,
  type FeeTierSchedule,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { bandsFromWire, formatBpPercent, formatPercent } from '@/lib/fee-tiers';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
  Card,
  CardContent,
  CardHeader,
  CardHeading,
  CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';
import { FeeTierTable } from '@/components/settings/fee-tier-table';
import { ScheduleTiersDialog } from '@/components/settings/schedule-tiers-dialog';

function scheduleStatus(
  schedule: FeeTierSchedule,
  currentId: number | undefined,
): { label: string; variant: 'success' | 'info' | 'secondary' } {
  if (schedule.id === currentId) {
    return { label: 'Current', variant: 'success' };
  }
  if (new Date(schedule.effective_from).getTime() > Date.now()) {
    return { label: 'Scheduled', variant: 'info' };
  }
  return { label: 'Superseded', variant: 'secondary' };
}

/**
 * The schedule as one line. The API already states each edge as a
 * 2-decimal percent (PLAN §1), so this is pure display — nothing is
 * converted to reach it.
 */
function bandSummary(schedule: FeeTierSchedule): string {
  return schedule.tiers
    .map(
      (band) =>
        `${formatPercent(band.from_percent)}–${formatPercent(band.to_percent)} → ${formatPercent(band.fee_percent)}`,
    )
    .join(' · ');
}

export default function FeeTiersPage() {
  const query = useQuery({
    queryKey: ['admin', 'fee-tiers'],
    queryFn: ({ signal }) => getAdminFeeTierSchedules({ signal }),
  });

  const current = query.data?.data.current ?? null;
  const history = query.data?.data.history ?? [];

  // The table, the editor and the worked example all reason about the
  // 4.99%/5.00% boundary, so the wire's percent bands are read as integer
  // basis points once, here.
  const currentBands = current === null ? null : bandsFromWire(current.tiers);

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Fee tiers"
        description="The §4 schedule: the platform fee charged on each cashback rate band. Rates and fees are frozen onto every sale at its occurred_at — schedule changes only ever touch the future."
        actions={<ScheduleTiersDialog currentBands={currentBands} />}
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <div className="flex flex-col gap-5">
          <Card>
            <CardHeader>
              <CardHeading>
                <CardTitle>Current schedule</CardTitle>
              </CardHeading>
              {current ? (
                <span className="text-sm text-muted-foreground">
                  Effective since {formatDateTime(current.effective_from)}
                </span>
              ) : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-3 p-0">
              {query.isPending ? (
                <div className="p-5">
                  <Skeleton className="h-32 w-full" />
                </div>
              ) : currentBands !== null ? (
                <>
                  <FeeTierTable bands={currentBands} />
                  <p className="px-5 pb-5 text-xs text-muted-foreground">
                    Worked example (§4): a {formatBpPercent(200)} cashback rate
                    falls in the{' '}
                    {(() => {
                      const band = currentBands.find(
                        (tier) => 200 >= tier.from_bp && 200 <= tier.to_bp,
                      );
                      return band
                        ? `${formatBpPercent(band.from_bp)} – ${formatBpPercent(band.to_bp)} band, so a MVR 1,000.00 eligible sale earns the customer MVR 20.00 and costs the merchant ${formatBpPercent(band.fee_bp)} in fees — ${formatBpPercent(200 + band.fee_bp)} all-in`
                        : 'no band — the schedule does not cover it';
                    })()}
                    . Every fractional laari rounds up, per line.
                  </p>
                </>
              ) : (
                <p className="px-5 pb-5 text-sm text-muted-foreground">
                  No schedule exists yet — the API falls back to the hardcoded
                  §4 table until the seed row lands.
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardHeading>
                <CardTitle>History</CardTitle>
              </CardHeading>
            </CardHeader>
            <CardContent className="flex flex-col gap-0 p-0">
              {query.isPending ? (
                <div className="p-5">
                  <Skeleton className="h-24 w-full" />
                </div>
              ) : history.length === 0 ? (
                <p className="px-5 pb-5 text-sm text-muted-foreground">
                  No schedules recorded.
                </p>
              ) : (
                <ul className="divide-y divide-border">
                  {history.map((schedule) => {
                    const status = scheduleStatus(schedule, current?.id);
                    return (
                      <li
                        key={schedule.id}
                        className="flex flex-col gap-1 px-5 py-3.5"
                      >
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge
                            variant={status.variant}
                            appearance="light"
                            size="sm"
                          >
                            {status.label}
                          </Badge>
                          <span className="text-sm font-medium">
                            Effective {formatDateTime(schedule.effective_from)}
                          </span>
                        </div>
                        <div className="font-mono text-xs text-muted-foreground">
                          {bandSummary(schedule)}
                        </div>
                        <div className="text-xs text-muted-foreground/80">
                          Created {formatDateTime(schedule.created_at)}
                          {schedule.created_by !== null
                            ? ` · by admin #${schedule.created_by}`
                            : ' · seeded'}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
