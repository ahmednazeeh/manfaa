'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  formatBpPercent,
  getAdminSettlement,
  matchAdminSettlementPayment,
  type Settlement,
} from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CircleCheck, Paperclip, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { fundingMethodLabel, settlementStateLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/admin/page-header';
import {
  PaymentStateBadge,
  SettlementStateBadge,
  TransactionReasonLine,
  TransactionStateBadge,
} from '@/components/admin/state-badge';
import { batchPrice } from '@/components/settlements/discount';
import {
  computeMatchOutcome,
  type MatchOutcome,
} from '@/components/settlements/match-outcome';
import { PromptDiscountBadge } from '@/components/settlements/prompt-discount';
import {
  pendingPayment,
  rejection,
  slipPayment,
} from '@/components/settlements/receipt';
import { ReceiptReviewCard } from '@/components/settlements/receipt-review-card';
import { RecordPaymentDialog } from '@/components/settlements/record-payment-dialog';
import { RejectionNotice } from '@/components/settlements/rejection-notice';

const PAYABLE_STATES: Settlement['state'][] = [
  'awaiting_payment',
  'payment_review',
  'partially_settled',
];

function SummaryStat({
  label,
  laari,
  hint,
}: {
  label: string;
  laari: number;
  hint?: string;
}) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-1 py-4">
        <span className="text-xs font-medium uppercase text-muted-foreground">
          {label}
        </span>
        <MoneyText laari={laari} className="text-lg font-semibold" />
        {hint ? (
          <span className="text-xs text-muted-foreground">{hint}</span>
        ) : null}
      </CardContent>
    </Card>
  );
}

function MatchOutcomeAlert({
  outcome,
  onDismiss,
}: {
  outcome: MatchOutcome;
  onDismiss: () => void;
}) {
  const walletCredited = outcome.walletDeltaLaari > 0;
  const walletConsumed = outcome.walletDeltaLaari < 0;

  return (
    <Alert variant="success" appearance="light" className="mb-5">
      <AlertIcon>
        <CircleCheck />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>Payment matched</AlertTitle>
        <AlertDescription>
          <ul className="list-inside list-disc space-y-0.5">
            <li>
              {outcome.allocatedCount === 0
                ? 'No new transactions were covered — the amount was parked as merchant credit.'
                : `${outcome.allocatedCount} transaction${
                    outcome.allocatedCount === 1 ? '' : 's'
                  } allocated and confirmed (${formatMoney(outcome.allocatedLaari)}).`}
            </li>
            {outcome.discountAppliedLaari > 0 ? (
              <li>
                {formatMoney(outcome.discountAppliedLaari)} of the
                prompt-payment discount
                {outcome.discountRateBp === null
                  ? ''
                  : ` (${formatBpPercent(outcome.discountRateBp)} of the platform fee)`}{' '}
                counted as covered funds and posted against platform fee revenue
                — that much of the lines was funded by the discount, not by cash
                and not by forgiveness.
              </li>
            ) : null}
            {outcome.forgivenLaari > 0 ? (
              <li>
                Shortfall of {formatMoney(outcome.forgivenLaari)} forgiven —
                under MVR 1, absorbed by the platform.
              </li>
            ) : null}
            {walletCredited ? (
              <li>
                {formatMoney(outcome.walletDeltaLaari)} credited to the merchant
                wallet for the next batch.
              </li>
            ) : null}
            {walletConsumed ? (
              <li>
                {formatMoney(-outcome.walletDeltaLaari)} of previously parked
                merchant credit was consumed by the newly covered lines.
              </li>
            ) : null}
            <li>
              Batch is now{' '}
              <span className="font-medium">
                {settlementStateLabel(outcome.state)}
              </span>
              .
            </li>
          </ul>
        </AlertDescription>
      </AlertContent>
      <Button variant="ghost" size="sm" onClick={onDismiss}>
        Dismiss
      </Button>
    </Alert>
  );
}

export default function SettlementDetailPage() {
  const params = useParams<{ id: string }>();
  const settlementId = Number(params.id);
  const queryClient = useQueryClient();
  const [outcome, setOutcome] = useState<MatchOutcome | null>(null);

  const query = useQuery({
    queryKey: ['admin', 'settlement', settlementId],
    queryFn: ({ signal }) => getAdminSettlement(settlementId, { signal }),
    enabled: Number.isInteger(settlementId),
  });

  const match = useMutation({
    mutationFn: (paymentId: number) => matchAdminSettlementPayment(paymentId),
    onSuccess: (response) => {
      const before = query.data?.data;
      if (before) {
        setOutcome(computeMatchOutcome(before, response.data));
      }
      queryClient.setQueryData(['admin', 'settlement', settlementId], response);
      queryClient.invalidateQueries({ queryKey: ['admin', 'settlements'] });
      toast.success('Payment matched.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-28 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (query.isError) {
    return (
      <Alert variant="destructive" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
      </Alert>
    );
  }

  const settlement = query.data.data;
  const lines = settlement.lines ?? [];
  const payments = settlement.payments ?? [];
  const allocatedCount = lines.filter((l) => l.allocated_at !== null).length;

  // PLAN §1: a discounted batch asks for less than its lines add up to, and
  // so does one carrying §7 credit adjustments. The lines keep their full
  // stored dues either way, so the gap is stated rather than left for the
  // matcher to discover.
  const price = batchPrice(settlement);
  const discountLaari = settlement.discount_laari;
  const creditLaari = price?.creditAppliedLaari ?? 0;
  const lineTotals = lines.reduce(
    (totals, line) => ({
      cashback: totals.cashback + line.cashback_laari,
      fee: totals.fee + line.fee_laari,
      gst: totals.gst + line.fee_gst_laari,
      due: totals.due + line.due_laari,
    }),
    { cashback: 0, fee: 0, gst: 0, due: 0 },
  );

  // The receipt this queue exists to review: the claim still awaiting a
  // decision, or — once decided — whatever slip the batch carries, so the
  // evidence stays readable after the fact.
  const receipt = pendingPayment(settlement) ?? slipPayment(settlement);
  const refusal = rejection(settlement);

  // Mirrors the domain guard: only a payment_review batch that has received
  // nothing and holds no matched payment can be rejected. Offering the button
  // anywhere else would just invite a 409.
  const canReject =
    settlement.state === 'payment_review' &&
    settlement.amount_received_laari === 0 &&
    !payments.some((payment) => payment.state === 'matched');

  return (
    <div className="flex flex-col">
      <PageHeader
        title={
          <>
            {settlement.reference}
            <SettlementStateBadge state={settlement.state} />
            <PromptDiscountBadge settlement={settlement} />
          </>
        }
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>
              Funding: {fundingMethodLabel(settlement.funding_method)}
            </span>
            <span>Due {formatDateTime(settlement.due_at)}</span>
            <span>Created {formatDateTime(settlement.created_at)}</span>
          </span>
        }
        actions={
          <>
            <Button variant="outline" asChild>
              <Link href="/settlements">
                <ArrowLeft />
                Back to queue
              </Link>
            </Button>
            {PAYABLE_STATES.includes(settlement.state) ? (
              <RecordPaymentDialog settlementId={settlement.id} />
            ) : null}
          </>
        }
      />

      {outcome ? (
        <MatchOutcomeAlert
          outcome={outcome}
          onDismiss={() => setOutcome(null)}
        />
      ) : null}

      {refusal ? <RejectionNotice payment={refusal} /> : null}

      {receipt ? (
        <ReceiptReviewCard
          settlement={settlement}
          payment={receipt}
          onMatch={(paymentId) => match.mutate(paymentId)}
          matching={match.isPending}
          canReject={canReject}
        />
      ) : null}

      <div
        className={cn(
          'mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2',
          discountLaari > 0 ? 'xl:grid-cols-5' : 'xl:grid-cols-4',
        )}
      >
        <SummaryStat
          label="Amount due"
          laari={settlement.amount_due_laari}
          hint={
            discountLaari > 0
              ? `${settlement.amount_due_mvr} MVR — after the discount`
              : `${settlement.amount_due_mvr} MVR`
          }
        />
        <SummaryStat
          label="Received"
          laari={settlement.amount_received_laari}
        />
        <SummaryStat
          label="Cashback total"
          laari={settlement.cashback_total_laari}
          hint={discountLaari > 0 ? 'Never discounted' : undefined}
        />
        <SummaryStat
          label="Fee total (excl. GST)"
          laari={settlement.fee_total_laari}
          hint={`GST ${formatMoney(settlement.fee_gst_total_laari)}`}
        />
        {discountLaari > 0 ? (
          <SummaryStat
            label="Prompt-payment discount"
            laari={-discountLaari}
            hint={
              settlement.discount_rate_bp === null
                ? 'Off the platform fee'
                : `${formatBpPercent(settlement.discount_rate_bp)} off the platform fee`
            }
          />
        ) : null}
      </div>

      <Card className="mb-5">
        <CardHeader>
          <CardTitle>Payments</CardTitle>
        </CardHeader>
        <CardTable>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-end">Amount</TableHead>
                  <TableHead>Method</TableHead>
                  <TableHead>Bank ref</TableHead>
                  <TableHead>Slip</TableHead>
                  <TableHead>State</TableHead>
                  <TableHead>Recorded</TableHead>
                  <TableHead>Matched</TableHead>
                  <TableHead className="text-end">Action</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {payments.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={8}
                      className="py-8 text-center text-muted-foreground"
                    >
                      No payments recorded yet.
                    </TableCell>
                  </TableRow>
                ) : (
                  payments.map((payment) => (
                    <TableRow key={payment.id}>
                      <TableCell className="text-end font-medium">
                        <MoneyText laari={payment.amount_laari} />
                      </TableCell>
                      <TableCell>
                        {fundingMethodLabel(payment.method)}
                      </TableCell>
                      <TableCell className="font-mono text-xs">
                        {payment.bank_ref ?? '—'}
                      </TableCell>
                      <TableCell>
                        {payment.has_slip ? (
                          <Badge variant="info" appearance="light" size="sm">
                            <Paperclip className="size-3" />
                            Attached
                          </Badge>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <PaymentStateBadge state={payment.state} />
                        {payment.rejection_reason ? (
                          <p
                            className="mt-1 max-w-xs truncate text-xs text-muted-foreground"
                            title={payment.rejection_reason}
                          >
                            {payment.rejection_reason}
                          </p>
                        ) : null}
                      </TableCell>
                      <TableCell>
                        {formatDateTime(payment.created_at)}
                      </TableCell>
                      <TableCell>
                        {formatDateTime(payment.matched_at)}
                      </TableCell>
                      <TableCell className="text-end">
                        {payment.state === 'pending' ? (
                          <Button
                            size="sm"
                            onClick={() => match.mutate(payment.id)}
                            disabled={match.isPending}
                          >
                            {match.isPending ? 'Matching…' : 'Match'}
                          </Button>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </CardTable>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>
            Lines{' '}
            <Badge variant="secondary" appearance="light" size="sm">
              {allocatedCount}/{lines.length} allocated
            </Badge>
          </CardTitle>
        </CardHeader>
        <CardTable>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice</TableHead>
                  <TableHead>Transaction state</TableHead>
                  <TableHead>Occurred</TableHead>
                  <TableHead className="text-end">Cashback</TableHead>
                  <TableHead className="text-end">Fee</TableHead>
                  <TableHead className="text-end">GST</TableHead>
                  <TableHead className="text-end">Due</TableHead>
                  <TableHead>Allocation</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={8}
                      className="py-8 text-center text-muted-foreground"
                    >
                      No lines on this batch.
                    </TableCell>
                  </TableRow>
                ) : (
                  lines.map((line) => (
                    <TableRow key={line.id}>
                      <TableCell className="font-medium">
                        {line.transaction?.invoice_no ??
                          `#${line.transaction_id}`}
                      </TableCell>
                      <TableCell>
                        {line.transaction ? (
                          <>
                            <TransactionStateBadge
                              state={line.transaction.state}
                            />
                            <TransactionReasonLine
                              code={line.transaction.reason_code}
                              className="mt-1 block text-xs text-muted-foreground"
                            />
                          </>
                        ) : (
                          '—'
                        )}
                      </TableCell>
                      <TableCell>
                        {formatDateTime(line.transaction?.occurred_at)}
                      </TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={line.cashback_laari} />
                      </TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={line.fee_laari} />
                      </TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={line.fee_gst_laari} />
                      </TableCell>
                      <TableCell className="text-end font-medium">
                        <MoneyText laari={line.due_laari} />
                      </TableCell>
                      <TableCell>
                        {line.allocated_at !== null ? (
                          <Badge variant="success" appearance="light" size="sm">
                            Allocated
                          </Badge>
                        ) : (
                          <Badge
                            variant="secondary"
                            appearance="light"
                            size="sm"
                          >
                            Unallocated
                          </Badge>
                        )}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
              {lines.length > 0 ? (
                <TableFooter>
                  <TableRow>
                    <TableCell colSpan={3}>Line totals</TableCell>
                    <TableCell className="text-end">
                      <MoneyText laari={lineTotals.cashback} />
                    </TableCell>
                    <TableCell className="text-end">
                      <MoneyText laari={lineTotals.fee} />
                    </TableCell>
                    <TableCell className="text-end">
                      <MoneyText laari={lineTotals.gst} />
                    </TableCell>
                    <TableCell className="text-end">
                      <MoneyText laari={lineTotals.due} />
                    </TableCell>
                    <TableCell />
                  </TableRow>
                  {creditLaari > 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="font-normal">
                        Less credit adjustments netted onto the batch at draft
                      </TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={-creditLaari} />
                      </TableCell>
                      <TableCell />
                    </TableRow>
                  ) : null}
                  {discountLaari > 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="font-normal">
                        Less prompt-payment discount
                        {settlement.discount_rate_bp === null
                          ? ''
                          : ` — ${formatBpPercent(settlement.discount_rate_bp)} of the platform fee`}
                        , covered funds at allocation
                      </TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={-discountLaari} />
                      </TableCell>
                      <TableCell />
                    </TableRow>
                  ) : null}
                  {creditLaari > 0 || discountLaari > 0 ? (
                    <TableRow>
                      <TableCell colSpan={6}>Amount due</TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={settlement.amount_due_laari} />
                      </TableCell>
                      <TableCell />
                    </TableRow>
                  ) : null}
                </TableFooter>
              ) : null}
            </Table>
          </div>
        </CardTable>
      </Card>
    </div>
  );
}
