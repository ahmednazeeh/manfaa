'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  getAdminSettlement,
  matchAdminSettlementPayment,
  type Settlement,
} from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CircleCheck, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
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
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/admin/page-header';
import {
  PaymentStateBadge,
  SettlementStateBadge,
  TransactionStateBadge,
} from '@/components/admin/state-badge';
import {
  computeMatchOutcome,
  type MatchOutcome,
} from '@/components/settlements/match-outcome';
import { RecordPaymentDialog } from '@/components/settlements/record-payment-dialog';

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
              Batch is now <span className="font-medium">{outcome.state}</span>.
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

  return (
    <div className="flex flex-col">
      <PageHeader
        title={
          <>
            {settlement.reference}
            <SettlementStateBadge state={settlement.state} />
          </>
        }
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>
              Funding:{' '}
              <span className="capitalize">{settlement.funding_method}</span>
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

      <div className="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryStat
          label="Amount due"
          laari={settlement.amount_due_laari}
          hint={`${settlement.amount_due_mvr} MVR`}
        />
        <SummaryStat
          label="Received"
          laari={settlement.amount_received_laari}
        />
        <SummaryStat
          label="Cashback total"
          laari={settlement.cashback_total_laari}
        />
        <SummaryStat
          label="Fee total (excl. GST)"
          laari={settlement.fee_total_laari}
          hint={`GST ${formatMoney(settlement.fee_gst_total_laari)}`}
        />
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
                      colSpan={7}
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
                      <TableCell className="capitalize">
                        {payment.method}
                      </TableCell>
                      <TableCell>{payment.bank_ref ?? '—'}</TableCell>
                      <TableCell>
                        <PaymentStateBadge state={payment.state} />
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
                          <TransactionStateBadge
                            state={line.transaction.state}
                          />
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
            </Table>
          </div>
        </CardTable>
      </Card>
    </div>
  );
}
