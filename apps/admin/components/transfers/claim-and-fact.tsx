'use client';

import type { ReactNode } from 'react';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';

/**
 * TWO FIGURES, ONE TRANSFER (owner, 2026-08-25) — rendered the same way on
 * BOTH review queues, because the wire carries the same three fields on a
 * wallet top-up and on a settlement payment and a reviewer moving between
 * the two screens should not have to re-learn where the money is.
 *
 * `amount_laari` is the merchant's CLAIM: what they typed on the upload
 * form. `received_laari` is the FACT: what the bank actually credited. The
 * fact is what funded the wallet or the batch, so it is the figure these
 * components LEAD with once it is known — the claim stays beside it rather
 * than being replaced, because "what they said" and "what arrived" are two
 * facts and an auditor asks about both.
 *
 * A DISCREPANCY IS NOT AN ERROR. A merchant who typed MVR 20.00 and
 * transferred MVR 10.00 is correctly credited the 10.00 that arrived; the
 * money is real and the typo cost nobody anything. So the marker below is a
 * quiet one — an amber pill with the gap written out, never the destructive
 * red an actual fault earns. It is there to catch an eye, not to raise an
 * alarm.
 *
 * `amount_differs` comes from the server and is FALSE whenever no bank
 * figure is known — an unknown is not a discrepancy. Nothing here recomputes
 * it: a screen must never announce a mismatch the server has not confirmed.
 */

/** The three money fields both claim tables carry. */
export interface ClaimAndFact {
  /** THE CLAIM: what the merchant typed. Never rewritten. */
  amount_laari: number;
  /** THE FACT: what the bank credited. Null until matched. */
  received_laari: number | null;
  /** True only when both figures are known and disagree. */
  amount_differs: boolean;
}

/**
 * Integer laari as a plain editable decimal ("2000" → "20.00") — what a
 * money INPUT is prefilled with, as opposed to what a label displays.
 * `formatMoney` is wrong here twice over: it prefixes the currency word and
 * wraps the digits in bidi isolates, neither of which `parseMvrToLaari` will
 * take back. Decomposed with integer arithmetic, like every other laari
 * conversion in the house.
 */
export function laariToInput(laari: number): string {
  const sign = laari < 0 ? '-' : '';
  const abs = Math.abs(laari);
  return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

export interface Discrepancy {
  /** Whether the bank sent MORE than was claimed, or less. */
  kind: 'more' | 'less';
  /** The gap, always positive integer laari. */
  deltaLaari: number;
}

/**
 * The gap between the claim and the bank, or null when there is none to
 * report. Gated on the SERVER's `amount_differs` so this can never invent a
 * discrepancy the server does not see.
 */
export function discrepancy(row: ClaimAndFact): Discrepancy | null {
  if (!row.amount_differs || row.received_laari === null) {
    return null;
  }
  const delta = row.received_laari - row.amount_laari;
  return delta > 0
    ? { kind: 'more', deltaLaari: delta }
    : { kind: 'less', deltaLaari: -delta };
}

/**
 * The quiet marker: how far the bank was from the claim, and which way.
 * Amber-light, never destructive — see the note at the top of this file.
 */
export function DiscrepancyBadge({
  row,
  className,
}: {
  row: ClaimAndFact;
  className?: string;
}) {
  const gap = discrepancy(row);

  if (gap === null) {
    return null;
  }

  return (
    <Badge
      variant="warning"
      appearance="light"
      size="sm"
      className={className}
      title={`The merchant typed ${formatMoney(row.amount_laari)}; the bank sent ${formatMoney(
        row.received_laari ?? 0,
      )}. The bank's figure is what was credited.`}
    >
      {gap.kind === 'more' ? (
        <ArrowUpRight className="size-3" />
      ) : (
        <ArrowDownRight className="size-3" />
      )}
      {formatMoney(gap.deltaLaari)} {gap.kind === 'more' ? 'more' : 'less'}
    </Badge>
  );
}

/**
 * What the bank actually sent, for a table cell — with the gap beside it
 * when there is one. A pending or hand-matched row has no bank figure at
 * all, and says so in words rather than printing the claim a second time
 * and letting a reader take it for a confirmation.
 */
export function ReceivedAmount({
  row,
  unknown = 'Not yet known',
  className,
}: {
  row: ClaimAndFact;
  /** What to say when no bank figure exists. */
  unknown?: string;
  className?: string;
}) {
  if (row.received_laari === null) {
    return (
      <span className={cn('text-sm text-muted-foreground', className)}>
        {unknown}
      </span>
    );
  }

  return (
    <span className={cn('flex flex-col items-end gap-1', className)}>
      <MoneyText
        laari={row.received_laari}
        className={cn('font-medium', row.amount_differs && 'text-foreground')}
      />
      <DiscrepancyBadge row={row} />
    </span>
  );
}

/**
 * One sentence a reviewer can act on, for the detail panels. Says which
 * figure was credited and why, in the order that matters: the fact first,
 * the claim second, then the rule.
 */
export function DiscrepancyNote({
  row,
  credited,
}: {
  row: ClaimAndFact;
  /**
   * What the discrepancy funded, in words — "credited to the wallet",
   * "allocated to the batch". Named by the caller because the two queues
   * spend the money on different things.
   */
  credited: string;
}): ReactNode {
  const gap = discrepancy(row);

  if (gap === null) {
    return null;
  }

  return (
    <>
      The bank sent {formatMoney(row.received_laari ?? 0)} —{' '}
      {formatMoney(gap.deltaLaari)} {gap.kind === 'more' ? 'more' : 'less'} than
      the {formatMoney(row.amount_laari)} the merchant typed. The bank&apos;s
      figure is what was {credited}: the typed amount is a claim, the statement
      is the money.
    </>
  );
}
