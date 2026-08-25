'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { dashboardShowsMoney, getAdminDashboard } from '@manfaa/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import {
  SERIES_AQUA,
  SERIES_BLUE,
  SERIES_ORANGE,
  SERIES_YELLOW,
} from '@/lib/chart-palette';
import {
  ADMIN_ATTENTION_QUERY_KEY,
  dashboardPresetPeriod,
  dashboardWindow,
  type DashboardPreset,
} from '@/lib/dashboard';
import { businessToday, formatDateTime } from '@/lib/format';
import { periodProblem, type Period } from '@/lib/reports';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';
import { useAdminUser } from '@/components/auth/admin-guard';
import { AttentionRow } from '@/components/dashboard/attention';
import { AutoMatchPanel } from '@/components/dashboard/auto-match';
import { DailySeriesChart } from '@/components/dashboard/daily-chart';
import { GrowthPanel } from '@/components/dashboard/growth';
import { MoneyPanel } from '@/components/dashboard/money';
import { DashboardPeriodControl } from '@/components/dashboard/period-control';

/**
 * The console's landing page.
 *
 * ONE REQUEST, ONE INSTANT. Every figure here comes from a single
 * `/api/admin/dashboard` call, so no two tiles can disagree about a
 * settlement that matched while they were in flight — which is exactly what
 * eight parallel fetches would produce.
 *
 * SCANNED, NOT READ, so the order is the order of urgency: what is waiting on
 * a person, then whether the bank matcher is still alive, then the money,
 * then who joined, then the daily trace. A reader who stops after the first
 * screenful has still seen everything that needs doing today.
 *
 * THE HEADING IS READ OFF THE PAYLOAD, NOT OFF THE PICKER. Refetching holds
 * the previous window's frame on screen, so labelling the figures from the
 * local period state would caption six money tiles and two charts with a
 * window they do not belong to for the whole of every period change. The
 * resolved-window line is driven by `dashboard.period` — which the server
 * echoes back for exactly this purpose — and the money panel's comparison
 * label by `money.previous.period`, so every caption names the window its
 * numbers came from.
 *
 * TWO AUDIENCES. The operational half is open to every admin; `money` and
 * `series` are superadmin-only and arrive ABSENT — not zeroed — for anyone
 * else. Those sections are therefore not rendered at all rather than rendered
 * empty: "MVR 0.00 of platform revenue" is an answer, and it is the wrong
 * one.
 */

function Section({
  title,
  hint,
  children,
}: {
  title: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-0.5">
        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
          {title}
        </h2>
        {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      </div>
      {children}
    </section>
  );
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-8">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        {Array.from({ length: 6 }).map((_, index) => (
          <Skeleton key={index} className="h-24 w-full" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Skeleton className="h-72 w-full" />
        <Skeleton className="h-72 w-full" />
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
        {Array.from({ length: 6 }).map((_, index) => (
          <Skeleton key={index} className="h-32 w-full" />
        ))}
      </div>
      <Skeleton className="h-80 w-full" />
    </div>
  );
}

export default function DashboardPage() {
  const me = useAdminUser();

  const [preset, setPreset] = useState<DashboardPreset>('this_month');

  // Held in state rather than read per render so the window cannot move under
  // the reader mid-choice, and re-read whenever a preset is picked so a panel
  // left open past midnight stops offering yesterday as the newest date.
  const [today, setToday] = useState(businessToday);
  const [period, setPeriod] = useState<Period>(() =>
    dashboardPresetPeriod('this_month', businessToday()),
  );

  const choosePreset = (next: DashboardPreset) => {
    const current = businessToday();
    setToday(current);
    setPreset(next);

    // Picking Custom deliberately leaves the dates alone: it opens on the
    // window already on screen, so switching to it is a nudge of one end
    // rather than starting over from a blank pair of fields.
    if (next !== 'custom') {
      setPeriod(dashboardPresetPeriod(next, current));
    }
  };

  const problem = periodProblem(period);

  const query = useQuery({
    queryKey: ['admin', 'dashboard', period.from, period.to],
    queryFn: ({ signal }) =>
      getAdminDashboard(dashboardWindow(period), { signal }),
    enabled: problem === null,
    // The queue counts go stale the moment somebody else works one, and this
    // is the screen an admin leaves open all day.
    refetchInterval: 60_000,
    staleTime: 30_000,
    // Refetching HOLDS THE FRAME: the previous answer stays on screen at
    // reduced opacity instead of collapsing into skeletons, so changing the
    // period never bounces the page's height.
    placeholderData: (previous) => previous,
  });

  const dashboard = query.data;

  // The window the figures ON SCREEN came from, which is not the requested
  // one while a new period is in flight or while a half-typed custom date is
  // holding the fetch back.
  const shownPeriod = dashboard?.period ?? null;
  const showsMoney = dashboard !== undefined && dashboardShowsMoney(dashboard);

  // The nav badges read four of these six counts. Seeding their shared key
  // from the payload this page already fetched means the badge and the tile
  // it links to are ONE read at ONE instant, and the landing page polls once
  // instead of five times a minute.
  const queryClient = useQueryClient();
  const attention = dashboard?.attention;

  useEffect(() => {
    if (attention !== undefined) {
      queryClient.setQueryData(ADMIN_ATTENTION_QUERY_KEY, attention);
    }
  }, [attention, queryClient]);

  // Why this window cannot be sent, as a banner rather than as a replacement
  // for the page. A native date input emits '' for every incomplete edit, so
  // clearing one end of a Custom window used to collapse everything below
  // into a single line of warning and then re-inflate it — the exact bounce
  // holding the frame exists to prevent.
  const periodWarning =
    problem === null ? null : (
      <Alert variant="warning" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertDescription>
          {problem} Nothing is fetched until the period is a window the
          dashboard can be built over
          {dashboard === undefined
            ? '.'
            : ', so the figures below are still the last window that loaded.'}
        </AlertDescription>
      </Alert>
    );

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Dashboard"
        description={
          dashboard === undefined
            ? 'What is waiting, whether the bank matcher is keeping up, and what the period did.'
            : `Every figure below was read in one request, at ${formatDateTime(
                dashboard.generated_at,
              )}.`
        }
      />

      <DashboardPeriodControl
        preset={preset}
        onPresetChange={choosePreset}
        period={period}
        shownPeriod={shownPeriod}
        onCustomChange={setPeriod}
        today={today}
        problem={problem}
        showsMoney={showsMoney}
      />

      {dashboard === undefined ? (
        problem !== null ? (
          periodWarning
        ) : query.isError ? (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
          </Alert>
        ) : (
          <DashboardSkeleton />
        )
      ) : (
        <div className="flex flex-col gap-4">
          {periodWarning}

          {query.isError ? (
            // The figures below are the last good answer, not this one — say
            // so rather than letting a stale screen pass for a live one.
            <Alert variant="destructive" appearance="light">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertDescription>
                {apiErrorMessage(query.error)} The figures below are the last
                answer that arrived.
              </AlertDescription>
            </Alert>
          ) : null}

          {/* Dimmed only while the frame belongs to a DIFFERENT window —
              `isPlaceholderData`, not `isFetching`. The silent 60-second
              poll is an `isFetching` too, and gating on it pulsed the whole
              page to 60% and back once a minute on the screen an admin
              leaves open all day. */}
          <div
            className={cn(
              'flex flex-col gap-8 transition-opacity',
              query.isPlaceholderData && 'opacity-60',
            )}
          >
            <Section
              title="Needs attention"
              hint="Every count is its own queue's own predicate — the number here is the number on the screen it links to."
            >
              <AttentionRow attention={dashboard.attention} />
            </Section>

            <Section
              title="Auto-matching health"
              hint="A pending transfer is either one the server is still polling the bank for, or one nobody is looking at any more. Only the second kind is work."
            >
              <AutoMatchPanel autoMatch={dashboard.auto_match} />
            </Section>

            {/* Superadmin only. Where the API omits these keys the sections
                are absent entirely — never a row of placeholder zeros. */}
            {dashboardShowsMoney(dashboard) ? (
              <Section
                title="Money"
                hint={`Superadmin only — ${me.name} can see this because of the role, not the page.`}
              >
                <MoneyPanel money={dashboard.money} />
              </Section>
            ) : null}

            <Section
              title="Growth"
              hint="Who joined. Counts of people and shops, never money — which is why every admin sees them."
            >
              <GrowthPanel growth={dashboard.growth} />
            </Section>

            {dashboardShowsMoney(dashboard) ? (
              <Section
                title="Daily trace"
                hint="One row per day of the period, zero-filled, in Maldives business days. What a sale ACCRUED and what money actually moved are two different clocks, so they are two different charts — never two axes on one."
              >
                <div className="flex flex-col gap-4">
                  <DailySeriesChart
                    title="Accrued on sales"
                    description="Dated by the sale. Reversed sales excluded."
                    entries={dashboard.series}
                    series={[
                      {
                        key: 'cashback_laari',
                        label: 'Cashback',
                        color: SERIES_BLUE,
                      },
                      {
                        key: 'fee_accrued_laari',
                        label: 'Platform fee accrued',
                        color: SERIES_ORANGE,
                      },
                    ]}
                  />

                  {/* NOT titled "cash moved", and the collections line is
                      not labelled as if it were. It sums
                      settlements.amount_received_laari on the day the BATCH
                      WAS RAISED, and that column keeps growing as receipts
                      are matched afterwards — so a point drawn for 4 August
                      is larger on the 9th than it was on the 5th. Only the
                      payout line is dated by its own cash event. The names
                      say which is which rather than letting a reader take
                      "MVR X moved on the 4th" at face value; the basis is
                      kept because it is the one the money tile and the
                      Reports page are built on, and a chart that quietly
                      disagreed with its own tile would be the worse trade. */}
                  <DailySeriesChart
                    title="Collections and payouts"
                    description="Collections sit on the day the BATCH was raised and keep filling as its receipts are matched later; payouts sit on the day the transfer landed."
                    entries={dashboard.series}
                    series={[
                      {
                        key: 'collected_laari',
                        label: 'Received on batches raised',
                        color: SERIES_AQUA,
                      },
                      {
                        key: 'paid_out_laari',
                        label: 'Paid out to customers',
                        color: SERIES_YELLOW,
                      },
                    ]}
                  />
                </div>

                <p className="text-xs text-muted-foreground">
                  The fee line is the ACCRUAL on those sales, not the ledger net
                  above — the money panel&apos;s{' '}
                  <span className="font-medium text-foreground">
                    Platform fees earned
                  </span>{' '}
                  is what the ledger recognised after prompt discounts and
                  forgiven shortfalls. Two honest numbers about fees; they are
                  not meant to tie. The other three lines do: summed over the
                  period they equal their tiles exactly.
                </p>
              </Section>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}
