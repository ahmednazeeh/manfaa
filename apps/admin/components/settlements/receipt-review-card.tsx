'use client';

import type { ReactNode } from 'react';
import type { Settlement, SettlementPayment } from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { CircleCheck, Info, TriangleAlert } from 'lucide-react';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardHeading,
  CardTitle,
  CardToolbar,
} from '@/components/ui/card';
import { PaymentStateBadge } from '@/components/admin/state-badge';
import { compareClaim, outstandingLaari } from './receipt';
import { RejectSettlementDialog } from './reject-settlement-dialog';
import { SlipPreview } from './slip-preview';

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-border py-2.5 last:border-b-0">
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      <span className="text-sm text-foreground">{children}</span>
    </div>
  );
}

/**
 * What matching this claim will do, stated before the admin commits to it.
 * Purely §7: whole lines oldest-first, sub-MVR-1 forgiveness, overpayment to
 * the wallet. Cash only — any merchant credit already parked in the wallet is
 * counted on top at match time, so a shortfall shown here is the worst case.
 */
function ClaimVerdict({
  claimedLaari,
  outstanding,
}: {
  claimedLaari: number;
  outstanding: number;
}) {
  const comparison = compareClaim(claimedLaari, outstanding);

  if (comparison.kind === 'exact') {
    return (
      <Alert variant="success" appearance="light" size="sm">
        <AlertIcon>
          <CircleCheck />
        </AlertIcon>
        <AlertDescription>
          The claim matches the amount outstanding exactly — matching allocates
          every line and settles the batch.
        </AlertDescription>
      </Alert>
    );
  }

  if (comparison.kind === 'over') {
    return (
      <Alert variant="info" appearance="light" size="sm">
        <AlertIcon>
          <Info />
        </AlertIcon>
        <AlertDescription>
          {formatMoney(comparison.deltaLaari)} more than outstanding. Matching
          allocates every line; the excess is parked as merchant wallet credit
          for the next batch — never refunded.
        </AlertDescription>
      </Alert>
    );
  }

  if (comparison.forgivable) {
    return (
      <Alert variant="info" appearance="light" size="sm">
        <AlertIcon>
          <Info />
        </AlertIcon>
        <AlertDescription>
          {formatMoney(comparison.deltaLaari)} short — under MVR 1, so matching
          forgives the gap (platform-funded), allocates every line and settles
          the batch in full.
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <Alert variant="warning" appearance="light" size="sm">
      <AlertIcon>
        <TriangleAlert />
      </AlertIcon>
      <AlertDescription>
        {formatMoney(comparison.deltaLaari)} short of the amount outstanding.
        Matching confirms whole transactions oldest-first and leaves the
        uncovered lines pending — never pro-rata.
      </AlertDescription>
    </Alert>
  );
}

/**
 * The heart of the matching queue: the merchant's evidence for one claimed
 * transfer — the uploaded slip, the bank reference, and the claim measured
 * against what the batch owes — with the two review outcomes beside it
 * (PLAN §1). Match allocates; Reject cancels the batch and releases its lines.
 */
export function ReceiptReviewCard({
  settlement,
  payment,
  onMatch,
  matching,
  canReject,
}: {
  settlement: Settlement;
  payment: SettlementPayment;
  onMatch: (paymentId: number) => void;
  matching: boolean;
  canReject: boolean;
}) {
  const outstanding = outstandingLaari(settlement);
  const isPending = payment.state === 'pending';

  return (
    <Card className="mb-5">
      <CardHeader>
        <CardHeading>
          <CardTitle>Receipt under review</CardTitle>
        </CardHeading>
        <CardToolbar>
          {canReject ? (
            <RejectSettlementDialog settlement={settlement} />
          ) : null}
          {isPending ? (
            <Button onClick={() => onMatch(payment.id)} disabled={matching}>
              <CircleCheck />
              {matching ? 'Matching…' : 'Match payment'}
            </Button>
          ) : null}
        </CardToolbar>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <SlipPreview settlementId={settlement.id} payment={payment} />

        <div className="flex flex-col gap-4">
          <div className="flex flex-col">
            <Fact label="Bank reference">
              <span className="font-mono text-sm">
                {payment.bank_ref ?? '—'}
              </span>
            </Fact>
            <Fact label="Amount claimed">
              <MoneyText
                laari={payment.amount_laari}
                className="text-base font-semibold"
              />
            </Fact>
            <Fact label="Amount outstanding on the batch">
              <MoneyText laari={outstanding} className="text-base" />
              {settlement.amount_received_laari > 0 ? (
                <span className="ms-2 text-xs text-muted-foreground">
                  ({formatMoney(settlement.amount_due_laari)} due less{' '}
                  {formatMoney(settlement.amount_received_laari)} received)
                </span>
              ) : null}
            </Fact>
            <Fact label="Submitted">{formatDateTime(payment.created_at)}</Fact>
            <Fact label="Payment state">
              <PaymentStateBadge state={payment.state} />
            </Fact>
          </div>

          {isPending ? (
            <ClaimVerdict
              claimedLaari={payment.amount_laari}
              outstanding={outstanding}
            />
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}
