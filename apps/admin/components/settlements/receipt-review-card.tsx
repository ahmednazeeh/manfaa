'use client';

import type { ReactNode } from 'react';
import type { Settlement, SettlementPayment } from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { formatDateTime } from '@/lib/format';
import {
  Card,
  CardContent,
  CardHeader,
  CardHeading,
  CardTitle,
  CardToolbar,
} from '@/components/ui/card';
import { PaymentStateBadge } from '@/components/admin/state-badge';
import {
  DiscrepancyBadge,
  DiscrepancyNote,
} from '@/components/transfers/claim-and-fact';
import { ClaimVerdict } from './claim-verdict';
import { MatchPaymentDialog } from './match-payment-dialog';
import { BatchPriceBreakdown, PromptDiscountNote } from './prompt-discount';
import { outstandingLaari } from './receipt';
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
  /**
   * Runs the match with WHAT THE STATEMENT SAYS ARRIVED — collected by the
   * dialog, never assumed from the claim. Resolves when the server answers.
   */
  onMatch: (
    paymentId: number,
    receivedLaari: number | null,
    bankRef: string | null,
  ) => Promise<unknown>;
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
            <MatchPaymentDialog
              payment={payment}
              outstanding={outstanding}
              matching={matching}
              onConfirm={(receivedLaari, bankRef) =>
                onMatch(payment.id, receivedLaari, bankRef)
              }
            />
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
            {/* THE CLAIM AND THE FACT, side by side (owner, 2026-08-25).
                The claim is what the merchant typed and is never rewritten;
                the received figure is the bank's, and is what allocated. */}
            <Fact label="Amount claimed">
              <MoneyText
                laari={payment.amount_laari}
                className={
                  payment.amount_differs
                    ? 'text-base text-muted-foreground'
                    : 'text-base font-semibold'
                }
              />
              {payment.amount_differs ? (
                <span className="ms-2 text-xs text-muted-foreground">
                  what the merchant typed
                </span>
              ) : null}
            </Fact>
            <Fact label="Amount received">
              {payment.received_laari === null ? (
                <span className="text-muted-foreground">
                  {isPending
                    ? 'Not yet known — the bank has not been matched to this payment.'
                    : 'Not recorded; the claim is what funded the batch.'}
                </span>
              ) : (
                <span className="flex flex-wrap items-center gap-2">
                  <MoneyText
                    laari={payment.received_laari}
                    className="text-base font-semibold"
                  />
                  <DiscrepancyBadge row={payment} />
                </span>
              )}
              {payment.amount_differs ? (
                <span className="mt-1 block text-xs text-muted-foreground">
                  <DiscrepancyNote
                    row={payment}
                    credited="allocated to the batch"
                  />
                </span>
              ) : null}
            </Fact>
            <Fact label="Amount outstanding on the batch">
              <MoneyText laari={outstanding} className="text-base" />
              {settlement.amount_received_laari > 0 ? (
                <span className="ms-2 text-xs text-muted-foreground">
                  ({formatMoney(settlement.amount_due_laari)} due less{' '}
                  {formatMoney(settlement.amount_received_laari)} received)
                </span>
              ) : null}
              {settlement.discount_laari > 0 ? (
                <span className="ms-2 text-xs text-muted-foreground">
                  — already net of the {formatMoney(settlement.discount_laari)}{' '}
                  prompt-payment discount
                </span>
              ) : null}
            </Fact>
            <Fact label="Submitted">{formatDateTime(payment.created_at)}</Fact>
            <Fact label="Payment state">
              <PaymentStateBadge state={payment.state} />
            </Fact>
          </div>

          <BatchPriceBreakdown settlement={settlement} />
          <PromptDiscountNote settlement={settlement} />

          {isPending ? (
            // The figure on the row: the bank's where the verifier stamped
            // one, the claim while nobody has anything better. The match
            // dialog recomputes this live against what the reviewer types.
            <ClaimVerdict
              amountLaari={payment.received_laari ?? payment.amount_laari}
              outstanding={outstanding}
            />
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}
