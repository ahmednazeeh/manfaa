'use client';

import { type ReactNode } from 'react';
import {
  reportSummaryList,
  reportSummaryNumber,
  type ReportKind,
  type ReportSummary,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { formatCount } from '@/lib/reports';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import {
  Card,
  CardContent,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

/**
 * The totals block above each report's table.
 *
 * Every figure comes out of the API's `summary` through `reportSummaryNumber`,
 * which answers null for a key the report does not carry — so a missing key
 * renders a dash rather than NaN, and nothing here recomputes a total the
 * server already stated. Money is integer laari on the wire and MVR on the
 * screen; laari never reaches the reader.
 */

const DASH = <span className="text-muted-foreground">—</span>;

/** A money figure by summary path, or a dash when the report omits it. */
function money(summary: ReportSummary, ...path: string[]): ReactNode {
  const laari = reportSummaryNumber(summary, ...path);
  return laari === null ? DASH : <MoneyText laari={laari} />;
}

/** A counted figure by summary path, grouped, or a dash. */
function count(summary: ReportSummary, ...path: string[]): ReactNode {
  const value = reportSummaryNumber(summary, ...path);
  return value === null ? DASH : formatCount(value);
}

function Tile({
  label,
  value,
  hint,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
}) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-1 py-4">
        <span className="text-xs font-medium uppercase text-muted-foreground">
          {label}
        </span>
        <span className="text-lg font-semibold">{value}</span>
        {hint ? (
          <span className="text-xs text-muted-foreground">{hint}</span>
        ) : null}
      </CardContent>
    </Card>
  );
}

function TileRow({ children }: { children: ReactNode }) {
  return (
    <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {children}
    </div>
  );
}

/** A labelled figure inside a breakdown card — the non-headline numbers. */
function Figure({
  label,
  value,
  hint,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-sm font-medium">{value}</span>
      {hint ? (
        <span className="text-[0.6875rem] text-muted-foreground">{hint}</span>
      ) : null}
    </div>
  );
}

function FigureCard({
  title,
  note,
  children,
}: {
  title: string;
  note?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Card className="mb-4">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {note}
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-4 py-4 sm:grid-cols-3 xl:grid-cols-6">
        {children}
      </CardContent>
    </Card>
  );
}

/**
 * The "these two numbers must be the same number" chip. A payout that appears
 * on three sheets at three amounts is worse than one that appears nowhere, so
 * the tie is checked on screen rather than left for the reader to spot.
 */
function TieBadge({ values }: { values: (number | null)[] }) {
  const known = values.filter((value): value is number => value !== null);
  const ties = known.length > 1 && known.every((value) => value === known[0]);

  if (known.length < 2) {
    return null;
  }

  return ties ? (
    <Badge variant="success" appearance="light" size="sm">
      <CheckCircle2 className="size-3" />
      Ties
    </Badge>
  ) : (
    <Badge variant="destructive" appearance="light" size="sm">
      <TriangleAlert className="size-3" />
      Does not tie
    </Badge>
  );
}

function CashbackSummary({ summary }: { summary: ReportSummary }) {
  const byState = reportSummaryList(summary, 'by_state');

  return (
    <>
      <TileRow>
        <Tile
          label="Transactions"
          value={count(summary, 'transactions', 'count')}
          hint="By the date the sale happened."
        />
        <Tile
          label="Eligible sales"
          value={money(summary, 'transactions', 'eligible_laari')}
          hint="The base the rate was applied to."
        />
        <Tile
          label="Cashback"
          value={money(summary, 'transactions', 'cashback_laari')}
          hint="Earned by customers on those sales."
        />
        <Tile
          label="Collected"
          value={money(summary, 'transactions', 'collected_laari')}
          hint="Cash actually received against these lines."
        />
      </TileRow>

      <FigureCard title="What the stores owed">
        <Figure
          label="Platform fee"
          value={money(summary, 'transactions', 'fee_laari')}
        />
        <Figure
          label="GST"
          value={money(summary, 'transactions', 'gst_laari')}
          hint="Owed onward to MIRA."
        />
        <Figure
          label="Gross due"
          value={money(summary, 'transactions', 'gross_due_laari')}
          hint="Cashback + fee + GST."
        />
        <Figure
          label="Prompt discounts"
          value={money(summary, 'transactions', 'discount_laari')}
          hint="Priced at each settlement's own stamped rate."
        />
        <Figure
          label="Shortfall forgiveness"
          value={money(summary, 'transactions', 'forgiveness_laari')}
        />
        <Figure
          label="Collected"
          value={money(summary, 'transactions', 'collected_laari')}
        />
      </FigureCard>

      {/*
        Deliberately NO tie chip between "Collected" above and "Received"
        here. The two sheets run on different clocks — transactions are
        periodised on when the sale happened, settlements on when the receipt
        was submitted — so a sale in this period settled by a batch submitted
        in the next one lands on one side and not the other. The figures are
        each exact; they are simply not the same question, and a chip claiming
        they must match would cry wolf on every ordinary month. The tie the
        server does guarantee is per settlement: the collected column of one
        batch's lines sums to that batch's amount received, to the laari.
      */}
      <FigureCard
        title="Settlements submitted in the period"
        note={
          <span className="text-xs text-muted-foreground">
            By submission date, so this need not match the collected figure
            above — that one follows the sale date. Draft and cancelled
            batches are left out: a draft is a basket nobody has committed
            to, and a cancelled batch is money owed on a different row.
          </span>
        }
      >
        <Figure
          label="Settlements"
          value={count(summary, 'settlements', 'count')}
        />
        <Figure
          label="Lines"
          value={count(summary, 'settlements', 'line_count')}
        />
        <Figure
          label="Amount due"
          value={money(summary, 'settlements', 'amount_due_laari')}
        />
        <Figure
          label="Discount"
          value={money(summary, 'settlements', 'discount_laari')}
        />
        <Figure
          label="Forgiveness"
          value={money(summary, 'settlements', 'forgiveness_laari')}
        />
        <Figure
          label="Received"
          value={money(summary, 'settlements', 'amount_received_laari')}
        />
      </FigureCard>

      {byState.length > 0 ? (
        <Card className="mb-5">
          <CardHeader>
            <CardTitle>By state</CardTitle>
          </CardHeader>
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>State</TableHead>
                    <TableHead className="text-end">Count</TableHead>
                    <TableHead className="text-end">Eligible sales</TableHead>
                    <TableHead className="text-end">Cashback</TableHead>
                    <TableHead className="text-end">Fee</TableHead>
                    <TableHead className="text-end">GST</TableHead>
                    <TableHead className="text-end">Gross due</TableHead>
                    <TableHead className="text-end">Collected</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {byState.map((entry, index) => (
                    <TableRow key={index}>
                      <TableCell className="font-medium">
                        {typeof entry.state === 'string' ? entry.state : '—'}
                      </TableCell>
                      <TableCell className="text-end">
                        {count(entry, 'count')}
                      </TableCell>
                      <TableCell className="text-end">
                        {money(entry, 'eligible_laari')}
                      </TableCell>
                      <TableCell className="text-end">
                        {money(entry, 'cashback_laari')}
                      </TableCell>
                      <TableCell className="text-end">
                        {money(entry, 'fee_laari')}
                      </TableCell>
                      <TableCell className="text-end">
                        {money(entry, 'gst_laari')}
                      </TableCell>
                      <TableCell className="text-end">
                        {money(entry, 'gross_due_laari')}
                      </TableCell>
                      <TableCell className="text-end">
                        {money(entry, 'collected_laari')}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      ) : null}
    </>
  );
}

function PayoutSummary({
  summary,
  merchantFiltered,
}: {
  summary: ReportSummary;
  merchantFiltered: boolean;
}) {
  return (
    <>
      <TileRow>
        <Tile
          label="Rewards paid"
          value={count(summary, 'transactions', 'count')}
          hint="Transactions that reached paid in the period."
        />
        <Tile
          label="Cashback paid"
          value={money(summary, 'transactions', 'cashback_laari')}
          hint="What those rewards were worth."
        />
        <Tile
          label="Payout items"
          value={count(summary, 'payout_items', 'count')}
          hint="One per customer per batch."
        />
        <Tile
          label="Wallet withdrawals"
          value={money(summary, 'wallet_withdrawals', 'amount_laari')}
          hint={
            merchantFiltered
              ? 'Empty under a merchant filter — a wallet withdrawal belongs to no store.'
              : 'Customer wallet cash-outs, outside the batches.'
          }
        />
      </TileRow>

      <FigureCard
        title="The tie"
        note={
          <TieBadge
            values={[
              reportSummaryNumber(
                summary,
                'ties',
                'transactions_cashback_laari',
              ),
              reportSummaryNumber(summary, 'ties', 'payout_items_paid_laari'),
              reportSummaryNumber(summary, 'ties', 'batches_paid_laari'),
            ]}
          />
        }
      >
        <Figure
          label="From transactions"
          value={money(summary, 'ties', 'transactions_cashback_laari')}
        />
        <Figure
          label="From payout items"
          value={money(summary, 'ties', 'payout_items_paid_laari')}
        />
        <Figure
          label="From batches"
          value={money(summary, 'ties', 'batches_paid_laari')}
        />
      </FigureCard>

      <FigureCard title="Batches and items">
        <Figure label="Batches" value={count(summary, 'batches', 'count')} />
        <Figure
          label="Batch total"
          value={money(summary, 'batches', 'total_laari')}
        />
        <Figure
          label="Batch paid"
          value={money(summary, 'batches', 'paid_laari')}
        />
        <Figure
          label="Item amount"
          value={money(summary, 'payout_items', 'amount_laari')}
          hint="Sent, before outcomes."
        />
        <Figure
          label="Excluded customers"
          value={count(summary, 'batches', 'excluded_customer_count')}
          hint="Under the minimum, or without bank details."
        />
        <Figure
          label="Excluded total"
          value={money(summary, 'batches', 'excluded_total_laari')}
          hint="Rolls into a later batch; never lost."
        />
      </FigureCard>
    </>
  );
}

function EarningsSummary({ summary }: { summary: ReportSummary }) {
  const debit = reportSummaryNumber(summary, 'postings', 'debit_laari');
  const credit = reportSummaryNumber(summary, 'postings', 'credit_laari');

  return (
    <>
      <TileRow>
        <Tile
          label="Net fee income"
          value={money(summary, 'net_fee_income_laari')}
          hint="Fee revenue less discounts and forgiveness."
        />
        <Tile
          label="Fees collected"
          value={money(summary, 'accrued_vs_collected', 'fees_collected_laari')}
          hint="Cash: the fee inside settlements received, net of prompt discount."
        />
        <Tile
          label="GST collected"
          value={money(summary, 'gst_collected_laari')}
          hint="A liability to MIRA, not income."
        />
        <Tile
          label="Net platform earnings"
          value={money(summary, 'net_platform_earnings_laari')}
          hint="After referral rewards and bad debt."
        />
      </TileRow>

      <FigureCard title="Fee income">
        <Figure
          label="Fee revenue (4100)"
          value={money(summary, 'fee_revenue_laari')}
        />
        <Figure
          label="Prompt discounts"
          value={money(summary, 'prompt_discounts_laari')}
        />
        <Figure
          label="Shortfall forgiveness"
          value={money(summary, 'shortfall_forgiveness_laari')}
        />
        <Figure
          label="Net fee income"
          value={money(summary, 'net_fee_income_laari')}
        />
        <Figure
          label="Referral rewards (5100)"
          value={money(summary, 'platform_funded_rewards_laari')}
          hint="Platform expense."
        />
        <Figure
          label="Bad debt (5900)"
          value={money(summary, 'bad_debt_laari')}
          hint="Platform expense."
        />
      </FigureCard>

      <FigureCard
        title="Accrued vs collected"
        note={
          <span className="text-xs text-muted-foreground">
            Fees recognised on this period&apos;s transactions, against the fee
            inside the cash that actually arrived.
          </span>
        }
      >
        <Figure
          label="Fees accrued"
          value={money(summary, 'accrued_vs_collected', 'fees_accrued_laari')}
        />
        <Figure
          label="Collected — bank"
          value={money(
            summary,
            'accrued_vs_collected',
            'fees_collected_bank_laari',
          )}
        />
        <Figure
          label="Collected — wallet"
          value={money(
            summary,
            'accrued_vs_collected',
            'fees_collected_wallet_laari',
          )}
        />
        <Figure
          label="Collected — total"
          value={money(summary, 'accrued_vs_collected', 'fees_collected_laari')}
        />
      </FigureCard>

      <FigureCard
        title="Journal postings"
        note={<TieBadge values={[debit, credit]} />}
      >
        <Figure label="Lines" value={count(summary, 'postings', 'count')} />
        <Figure
          label="Debits"
          value={money(summary, 'postings', 'debit_laari')}
        />
        <Figure
          label="Credits"
          value={money(summary, 'postings', 'credit_laari')}
        />
      </FigureCard>
    </>
  );
}

export function ReportSummaryPanel({
  kind,
  summary,
  merchantFiltered = false,
  className,
}: {
  kind: ReportKind;
  summary: ReportSummary;
  /**
   * Whether a merchant filter is on. The payouts report answers one figure
   * differently in that case — a wallet withdrawal belongs to a customer, not
   * to a store, so it cannot be attributed to one and the sheet is empty by
   * design. Said on the tile, because a zero nobody explains reads as a bug.
   */
  merchantFiltered?: boolean;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col', className)}>
      {kind === 'cashback' ? <CashbackSummary summary={summary} /> : null}
      {kind === 'payouts' ? (
        <PayoutSummary summary={summary} merchantFiltered={merchantFiltered} />
      ) : null}
      {kind === 'earnings' ? <EarningsSummary summary={summary} /> : null}
    </div>
  );
}
