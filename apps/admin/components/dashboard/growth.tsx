'use client';

import { type DashboardGrowth } from '@manfaa/api-client';
import { Store, Users } from 'lucide-react';
import { formatCount } from '@/lib/reports';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * WHO JOINED — counts of people and shops, never money, which is why every
 * admin sees this panel and not the one above it.
 *
 * THREE numbers about stores, not two, because "new merchants" is genuinely
 * ambiguous. `active_total` is the estate trading today;
 * `new_active_in_period` registered in this window AND are trading now;
 * `registered_in_period` counts every signup whatever became of it. The gap
 * between the last two IS the approval queue, so it is stated rather than
 * left for the reader to subtract — a signup wave still sitting in review is
 * a fact worth showing, and it is invisible in either number alone.
 */

function Stat({
  label,
  value,
  hint,
}: {
  label: string;
  value: number;
  hint: string;
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs text-muted-foreground">{label}</span>
      {/* Proportional figures — these do not form a vertical column. */}
      <span className="text-2xl leading-none font-semibold">
        {formatCount(value)}
      </span>
      <span className="text-[0.6875rem] leading-snug text-muted-foreground">
        {hint}
      </span>
    </div>
  );
}

export function GrowthPanel({ growth }: { growth: DashboardGrowth }) {
  const inReview =
    growth.merchants.registered_in_period -
    growth.merchants.new_active_in_period;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Users className="size-4 shrink-0 text-muted-foreground" />
            Customers
          </CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 py-5">
          <Stat
            label="Total"
            value={growth.customers.total}
            hint="Every customer account on the platform."
          />
          <Stat
            label="New in this period"
            value={growth.customers.new_in_period}
            hint="Signed up inside the window above."
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Store className="size-4 shrink-0 text-muted-foreground" />
            Merchants
          </CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-3 gap-4 py-5">
          <Stat
            label="Active"
            value={growth.merchants.active_total}
            hint="The estate trading today."
          />
          <Stat
            label="New and trading"
            value={growth.merchants.new_active_in_period}
            hint="Registered in this window and live now."
          />
          <Stat
            label="Registered"
            value={growth.merchants.registered_in_period}
            hint={
              inReview > 0
                ? `Every signup in this window — ${formatCount(inReview)} of them are not trading yet.`
                : 'Every signup in this window, whatever became of it.'
            }
          />
        </CardContent>
      </Card>
    </div>
  );
}
