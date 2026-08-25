'use client';

import { type ReactNode } from 'react';
import {
  DASHBOARD_TRANSFER_FLOWS,
  DASHBOARD_WAITING_REASONS,
  type DashboardAutoMatch,
  type DashboardTransferFlow,
  type DashboardTransferHealth,
  type DashboardWaitingReason,
} from '@manfaa/api-client';
import {
  CircleCheck,
  Clock,
  Radar,
  TriangleAlert,
  UserRoundSearch,
} from 'lucide-react';
import { formatCount } from '@/lib/reports';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * IS THE BANK MATCHER STILL ALIVE?
 *
 * A pending transfer is one of two completely different things: one the
 * server is still polling the bank for — fine, leave it alone — or one that
 * NOBODY is looking at any more, which is a person's job that nothing on the
 * platform will ever do. A single "8 pending" tile hides that difference, and
 * the difference is the whole reason this panel exists.
 *
 * The two flows are never summed. Settlement payments and wallet top-ups are
 * matched by two different verifiers against two different tables, and one
 * stalling while the other is healthy is precisely the fact worth seeing.
 *
 * LOUD ONLY WHEN STUCK. Nothing waiting on a person reads as an ordinary
 * card; anything waiting takes the warning treatment, and an unrouted bank
 * account — a configuration fault that will strand every future transfer, not
 * just today's — takes the destructive one. Each wears an icon and a written
 * reason, so the state never rests on colour alone.
 */

const FLOW_LABELS: Record<DashboardTransferFlow, string> = {
  settlement_payments: 'Settlement payments',
  wallet_top_ups: 'Wallet top-ups',
};

const FLOW_HINTS: Record<DashboardTransferFlow, string> = {
  settlement_payments:
    'Merchants paying their settlement batches by bank transfer.',
  wallet_top_ups: 'Merchants funding their wallet by bank transfer.',
};

/** The machine reasons, in words an admin can act on. */
const REASON_LABELS: Record<DashboardWaitingReason, string> = {
  window_expired: 'Watch window closed without a match',
  never_watched: 'Arrived while auto-verification was off',
  no_verify_profile: 'Bank account not routed for verification',
  auto_verify_off: 'Auto-verification is switched off',
};

/** What each reason actually asks of somebody. */
const REASON_HINTS: Record<DashboardWaitingReason, string> = {
  window_expired: 'Match it by hand on its queue.',
  never_watched: 'Match it by hand — the poller never saw it.',
  no_verify_profile:
    'A platform bank account has no read profile: fix it in Settings › Bank accounts, or every future transfer lands here too.',
  auto_verify_off:
    'The platform switch is down, so every transfer is manual work until it is back on.',
};

/** The one reason that is a configuration fault rather than a backlog. */
const CONFIG_FAULT: DashboardWaitingReason = 'no_verify_profile';

function Figure({
  label,
  value,
  icon,
  tone = 'plain',
}: {
  label: string;
  value: ReactNode;
  icon: ReactNode;
  tone?: 'plain' | 'quiet' | 'warning' | 'critical';
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
        {icon}
        <span className="min-w-0 flex-1">{label}</span>
      </span>
      <span
        className={cn(
          'text-xl leading-none font-semibold',
          tone === 'quiet' && 'font-normal text-muted-foreground',
          tone === 'warning' && 'text-yellow-700 dark:text-yellow-500',
          tone === 'critical' && 'text-destructive',
        )}
      >
        {value}
      </span>
    </div>
  );
}

/**
 * The period's matches, split by who found them.
 *
 * The bar is a METER, not a series: it is one share of one whole, so it takes
 * a single hue in two steps of the sequential blue ramp (see
 * lib/chart-palette.ts) rather than two categorical colours, which would
 * claim auto and manual are two identities to tell apart rather than two
 * parts of one number. Both parts are written out beside it, so the bar never
 * carries a value on its own.
 */
function AutoRateMeter({ health }: { health: DashboardTransferHealth }) {
  const matched = health.matched_in_period;

  if (matched.total === 0) {
    return (
      <p className="text-xs text-muted-foreground">
        Nothing matched in this period — no rate to report. (A rate over no
        matches would print as 0%, which reads as a stall.)
      </p>
    );
  }

  const rate = Number(matched.auto_rate_percent ?? '0');
  const share = Number.isFinite(rate) ? Math.min(Math.max(rate, 0), 100) : 0;

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <span className="text-xs text-muted-foreground">
          Matched in this period
        </span>
        <span className="text-sm font-semibold">
          {matched.auto_rate_percent === null
            ? '—'
            : `${matched.auto_rate_percent}%`}{' '}
          <span className="text-xs font-normal text-muted-foreground">
            found automatically
          </span>
        </span>
      </div>

      {/* Track and fill are two steps of ONE hue — see METER_TRACK /
          METER_FILL. Tailwind cannot build a class from a runtime string, so
          the steps are written as literal utilities here. */}
      <div className="h-2 w-full overflow-hidden rounded-full bg-[#cde2fb] dark:bg-[#184f95]">
        <div
          className="h-full rounded-full bg-[#2a78d6] dark:bg-[#3987e5]"
          style={{ width: `${share}%` }}
        />
      </div>

      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
        <span>
          <span className="font-medium text-foreground">
            {formatCount(matched.auto)}
          </span>{' '}
          automatic
        </span>
        <span>
          <span className="font-medium text-foreground">
            {formatCount(matched.manual)}
          </span>{' '}
          by hand
        </span>
        <span>
          <span className="font-medium text-foreground">
            {formatCount(matched.total)}
          </span>{' '}
          in total
        </span>
      </div>
    </div>
  );
}

function FlowCard({
  flow,
  health,
}: {
  flow: DashboardTransferFlow;
  health: DashboardTransferHealth;
}) {
  const waiting = health.waiting_on_human;
  const misconfigured = waiting[CONFIG_FAULT] > 0;
  const stuck = waiting.total > 0;

  const reasons = DASHBOARD_WAITING_REASONS.filter(
    (reason) => waiting[reason] > 0,
  );

  return (
    <Card
      className={cn(
        misconfigured
          ? 'border-destructive/40'
          : stuck
            ? 'border-yellow-300 dark:border-yellow-900/70'
            : undefined,
      )}
    >
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {FLOW_LABELS[flow]}
        </CardTitle>
        {misconfigured ? (
          <Badge variant="destructive" appearance="light" size="sm">
            <TriangleAlert className="size-3" />
            Bank not routed
          </Badge>
        ) : stuck ? (
          <Badge variant="warning" appearance="light" size="sm">
            <UserRoundSearch className="size-3" />
            {formatCount(waiting.total)} waiting on a person
          </Badge>
        ) : (
          <Badge variant="success" appearance="light" size="sm">
            <CircleCheck className="size-3" />
            Nothing stuck
          </Badge>
        )}
      </CardHeader>

      <CardContent className="flex flex-col gap-5 py-5">
        <p className="text-xs text-muted-foreground">{FLOW_HINTS[flow]}</p>

        <div className="grid grid-cols-3 gap-4">
          <Figure
            label="Pending"
            value={formatCount(health.pending_total)}
            icon={<Clock className="size-3.5 shrink-0" />}
            tone={health.pending_total === 0 ? 'quiet' : 'plain'}
          />
          <Figure
            label="Being watched"
            value={formatCount(health.watching_now)}
            icon={<Radar className="size-3.5 shrink-0" />}
            tone={health.watching_now === 0 ? 'quiet' : 'plain'}
          />
          <Figure
            label="Waiting on a person"
            value={formatCount(waiting.total)}
            icon={<UserRoundSearch className="size-3.5 shrink-0" />}
            tone={misconfigured ? 'critical' : stuck ? 'warning' : 'quiet'}
          />
        </div>

        {reasons.length > 0 ? (
          <ul className="flex flex-col gap-2 border-t border-border pt-4">
            {reasons.map((reason) => (
              <li key={reason} className="flex items-start gap-2.5 text-xs">
                <span
                  className={cn(
                    'mt-px inline-flex min-w-6 shrink-0 justify-center rounded px-1.5 py-0.5 font-semibold tabular-nums',
                    reason === CONFIG_FAULT
                      ? 'bg-destructive/10 text-destructive'
                      : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-950/60 dark:text-yellow-500',
                  )}
                >
                  {formatCount(waiting[reason])}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="font-medium text-foreground">
                    {REASON_LABELS[reason]}
                  </span>
                  <span className="text-muted-foreground">
                    {' — '}
                    {REASON_HINTS[reason]}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="border-t border-border pt-4 text-xs text-muted-foreground">
            {health.pending_total === 0
              ? 'Nothing pending — the queue is empty.'
              : 'Every pending transfer is still being watched. Nothing needs a person yet.'}
          </p>
        )}

        <div className="flex items-center gap-2 text-xs">
          <Clock
            className={cn(
              'size-3.5 shrink-0',
              health.expired_unmatched_24h > 0
                ? 'text-yellow-700 dark:text-yellow-500'
                : 'text-muted-foreground',
            )}
          />
          <span
            className={cn(
              health.expired_unmatched_24h > 0
                ? 'text-foreground'
                : 'text-muted-foreground',
            )}
          >
            <span className="font-semibold">
              {formatCount(health.expired_unmatched_24h)}
            </span>{' '}
            watch {health.expired_unmatched_24h === 1 ? 'window' : 'windows'}{' '}
            lapsed in the last 24 hours
          </span>
        </div>

        <div className="border-t border-border pt-4">
          <AutoRateMeter health={health} />
        </div>
      </CardContent>
    </Card>
  );
}

export function AutoMatchPanel({
  autoMatch,
}: {
  autoMatch: DashboardAutoMatch;
}) {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      {DASHBOARD_TRANSFER_FLOWS.map((flow) => (
        <FlowCard key={flow} flow={flow} health={autoMatch[flow]} />
      ))}
    </div>
  );
}
