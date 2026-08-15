'use client';

import type { ReactNode } from 'react';
import { formatBpPercent, type Settlement } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { BadgePercent } from 'lucide-react';
import { promptDiscountReasonLabel } from '@/lib/labels';
import { Badge } from '@/components/ui/badge';
import { batchPrice } from './discount';

/**
 * The PLAN §1 prompt-payment discount, explained where a payment is matched.
 *
 * A discounted batch is the one case where the line table and the amount due
 * disagree on purpose: the lines keep their full stored dues, and the batch
 * asks for less. An admin comparing a claimed transfer against the due needs
 * that difference named — otherwise a correct, fully-covering payment reads
 * as a merchant underpaying by a few rufiyaa.
 *
 * Nothing here computes money. The rate and the relief are the ones the
 * server granted at submit and stamped on the row; the reconciliation is a
 * subtraction over stored integer laari.
 */

/** The rate a batch was priced at, as a percent — null when nothing was granted. */
function rateLabel(settlement: Settlement): string | null {
  return settlement.discount_rate_bp === null
    ? null
    : formatBpPercent(settlement.discount_rate_bp);
}

/** Compact marker for a batch carrying a discount — headers and stat hints. */
export function PromptDiscountBadge({
  settlement,
}: {
  settlement: Settlement;
}) {
  if (settlement.discount_laari === 0) {
    return null;
  }

  const rate = rateLabel(settlement);

  return (
    <Badge variant="info" appearance="light" size="sm">
      <BadgePercent className="size-3" />
      {rate === null ? 'Prompt discount' : `${rate} prompt discount`}
    </Badge>
  );
}

/**
 * One muted line for a queue cell: the amount beside it is already net of the
 * discount, so a due that looks light against the line sum is explained
 * without opening the batch.
 */
export function DiscountedDueHint({ settlement }: { settlement: Settlement }) {
  if (settlement.discount_laari === 0) {
    return null;
  }

  const rate = rateLabel(settlement);

  return (
    <span className="block text-xs font-normal text-muted-foreground">
      after {rate === null ? '' : `${rate} `}prompt discount
    </span>
  );
}

function PriceRow({
  label,
  laari,
  strong,
}: {
  label: ReactNode;
  laari: number;
  strong?: boolean;
}) {
  return (
    <div
      className={`flex items-baseline justify-between gap-4 ${
        strong
          ? 'border-t border-border pt-1.5 font-medium text-foreground'
          : ''
      }`}
    >
      <dt className={strong ? '' : 'text-muted-foreground'}>{label}</dt>
      <dd>
        <MoneyText laari={laari} />
      </dd>
    </div>
  );
}

/**
 * Why the amount due is lower than the lines add up to: the line total, the
 * §7 credit adjustments netted on at draft time, the prompt-payment discount,
 * and what is left to transfer. Renders nothing when the two agree — a batch
 * at full price needs no arithmetic — or when the endpoint did not load the
 * lines.
 */
export function BatchPriceBreakdown({
  settlement,
}: {
  settlement: Settlement;
}) {
  const price = batchPrice(settlement);

  if (price === null) {
    return null;
  }

  const { lineTotalLaari, creditAppliedLaari, discountLaari, amountDueLaari } =
    price;

  if (creditAppliedLaari === 0 && discountLaari === 0) {
    return null;
  }

  const lineCount = settlement.lines?.length ?? 0;
  const rate = rateLabel(settlement);

  return (
    <div className="rounded-lg border border-border bg-muted/30 p-3">
      <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        Why the due is lower than the line sum
      </p>
      <dl className="flex flex-col gap-1.5 text-sm tabular-nums">
        <PriceRow
          label={`Line total (${lineCount} line${lineCount === 1 ? '' : 's'})`}
          laari={lineTotalLaari}
        />
        {creditAppliedLaari > 0 ? (
          <PriceRow
            label="Less credit adjustments"
            laari={-creditAppliedLaari}
          />
        ) : null}
        {discountLaari > 0 ? (
          <PriceRow
            label={
              rate === null
                ? 'Less prompt-payment discount'
                : `Less prompt-payment discount (${rate} of the fee)`
            }
            laari={-discountLaari}
          />
        ) : null}
        <PriceRow label="Amount due" laari={amountDueLaari} strong />
      </dl>
    </div>
  );
}

/**
 * The discount decision in prose — granted or refused, and why. Shown on
 * refusals too: a merchant who transferred 5% less than the due did so
 * believing they had earned it, and "not granted: the merchant still has
 * payable transactions this batch does not cover" is the whole explanation
 * for the shortfall sitting in front of the admin.
 */
export function PromptDiscountNote({ settlement }: { settlement: Settlement }) {
  const reason = settlement.discount_reason;

  if (reason === null) {
    return null;
  }

  const rate = rateLabel(settlement);
  const granted = settlement.discount_laari > 0;

  return (
    <div className="flex flex-col gap-1 text-xs text-muted-foreground">
      <p>
        <span className="font-medium text-foreground">
          Prompt-payment discount
        </span>{' '}
        — {promptDiscountReasonLabel(reason)}
      </p>
      {granted ? (
        <p>
          {rate === null ? 'A share' : rate} of the{' '}
          <MoneyText laari={settlement.fee_total_laari} /> platform fee,{' '}
          <MoneyText laari={settlement.discount_laari} /> off
          {settlement.fee_gst_total_laari > 0
            ? ', with fee GST reduced in the same proportion'
            : ''}
          . The customer&rsquo;s cashback is untouched — the relief comes out of
          our own fee revenue as a sales discount, and it counts as covered
          funds when matching, so the discounted transfer allocates every line
          with no shortfall to forgive.
        </p>
      ) : null}
    </div>
  );
}
