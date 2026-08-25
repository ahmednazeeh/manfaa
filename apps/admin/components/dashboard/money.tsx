'use client';

import {
  type DashboardMoney,
  type DashboardMoneyTotals,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { formatMovement, movement } from '@/lib/dashboard';
import { formatDate } from '@/lib/format';
import { Card, CardContent } from '@/components/ui/card';

/**
 * THE SIX MONEY FIGURES — superadmin only, and rendered only where the API
 * actually sent them. The gate REMOVES this panel; it never blanks it, because
 * "MVR 0.00 of platform revenue" is an answer, and it is the wrong one.
 *
 * Not one figure is defined here. Each is read from the report class that owns
 * its definition, so the panel cannot disagree with the Reports page.
 *
 * TWO CLOCKS, DELIBERATELY: cashback is dated by the SALE and fees by the
 * LEDGER JOURNAL, because that is what the two reports do. The two are
 * therefore not two views of one month's trade and will not tie — which is
 * why nothing on this panel subtracts one from the other, and why the note
 * under the tiles says so out loud.
 *
 * FEE FORGONE sits on the SALE clock with cashback, not on the journal clock
 * with the fees, because it is not a ledger movement at all: a fee we never
 * charged posts nothing. It is what the platform fee promotions cost — the
 * acquisition spend — and it is NOT part of platform fees earned. Adding the
 * two together states a revenue figure that never existed.
 *
 * DELTAS CARRY DIRECTION, NOT JUDGEMENT. The arrow and the percentage are set
 * in ordinary secondary ink rather than green and red, because "up" is not
 * uniformly good across these five: more cashback generated is more trade,
 * more paid out to customers is simply more payouts, and more GST collected
 * is a larger debt to MIRA. Colouring the arrow would assert a verdict the
 * platform does not own. The reserved status hues stay where they mean
 * something — the queues above.
 */

/** One tile's identity. Order is the order it is read in. */
interface MoneyFigure {
  key: keyof DashboardMoneyTotals;
  label: string;
  hint: string;
}

const FIGURES: MoneyFigure[] = [
  {
    key: 'cashback_generated_laari',
    label: 'Cashback generated',
    hint: 'On sales in this period, by the date of the sale. Reversed sales excluded.',
  },
  {
    key: 'platform_fees_net_laari',
    label: 'Platform fees earned',
    hint: 'From the ledger, net of prompt discounts and forgiven shortfalls.',
  },
  {
    key: 'gst_collected_laari',
    label: 'GST collected',
    hint: 'A liability owed to MIRA — never part of the fees above.',
  },
  {
    key: 'fee_forgone_to_promotions_laari',
    label: 'Fee forgone',
    hint: 'What fee promotions gave away on sales in this period. A memo figure: it is not part of the fees earned above.',
  },
  {
    key: 'collected_from_merchants_laari',
    label: 'Collected from merchants',
    hint: 'Received so far against the batches this period raised — it keeps filling as later receipts are matched.',
  },
  {
    key: 'paid_out_to_customers_laari',
    label: 'Paid out to customers',
    hint: 'Cashback whose payout landed in this period.',
  },
];

function Delta({
  current,
  previous,
  window,
}: {
  current: number;
  previous: number;
  window: string;
}) {
  const moved = movement(current, previous);
  const text = formatMovement(moved);

  const Arrow =
    moved.kind === 'change'
      ? moved.up
        ? ArrowUpRight
        : ArrowDownRight
      : moved.kind === 'first'
        ? ArrowUpRight
        : Minus;

  return (
    <span className="flex items-center gap-1 text-xs text-muted-foreground">
      <Arrow className="size-3.5 shrink-0" />
      <span className="font-medium text-foreground">{text}</span>
      <span className="min-w-0 truncate">vs {window}</span>
    </span>
  );
}

export function MoneyPanel({ money }: { money: DashboardMoney }) {
  const previousWindow = `${formatDate(money.previous.period.from)} – ${formatDate(
    money.previous.period.to,
  )}`;

  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
        {FIGURES.map((figure) => (
          <Card key={figure.key}>
            <CardContent className="flex flex-col gap-2 py-4">
              <span className="text-xs font-medium text-muted-foreground uppercase">
                {figure.label}
              </span>
              <MoneyText
                laari={money[figure.key]}
                className="text-lg font-semibold"
              />
              <Delta
                current={money[figure.key]}
                previous={money.previous[figure.key]}
                window={previousWindow}
              />
              <span className="text-[0.6875rem] leading-snug text-muted-foreground">
                {figure.hint}
              </span>
            </CardContent>
          </Card>
        ))}
      </div>

      <p className="text-xs text-muted-foreground">
        The comparison window is the{' '}
        <span className="font-medium text-foreground">{previousWindow}</span> —
        the equal-length period immediately before this one, so a month in
        progress is answered by the same number of days rather than by a whole
        month. Cashback is dated by the sale and fees by the ledger journal: the
        two are honest about different things and are not meant to tie.
      </p>

      <p className="text-xs text-muted-foreground">
        One caveat on{' '}
        <span className="font-medium text-foreground">
          Collected from merchants
        </span>
        : it counts what has been received against the batches this period
        RAISED, and a receipt matched next week is added to this period&apos;s
        figure when it lands. A closed comparison window has finished filling
        and a window in progress has not, so that one delta reads low while the
        period is still open.
      </p>
    </div>
  );
}
