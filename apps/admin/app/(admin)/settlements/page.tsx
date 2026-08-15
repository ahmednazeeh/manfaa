'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  listAdminSettlements,
  SettlementStateSchema,
  type Settlement,
  type SettlementState,
} from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { Paperclip, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { fundingMethodLabel } from '@/lib/labels';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardTable } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PageHeader } from '@/components/admin/page-header';
import { Pager } from '@/components/admin/pager';
import { SettlementStateBadge } from '@/components/admin/state-badge';
import {
  claimedLaari,
  compareClaim,
  outstandingLaari,
  pendingPayment,
  rejection,
} from '@/components/settlements/receipt';

type StateFilter = SettlementState | 'all';

const TABS: { value: StateFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'payment_review', label: 'Payment review' },
  { value: 'awaiting_payment', label: 'Awaiting payment' },
  { value: 'partially_settled', label: 'Partially settled' },
  { value: 'settled', label: 'Settled' },
  { value: 'draft', label: 'Draft' },
  { value: 'cancelled', label: 'Cancelled' },
];

const DESCRIPTIONS: Record<StateFilter, string> = {
  all: 'Merchant settlement batches by state — review the uploaded receipt, then match it to confirm rewards oldest-first, or reject it and release the lines.',
  payment_review:
    'Receipts waiting on a decision. Open one to read the slip, then Match to allocate oldest-first or Reject to cancel the batch and release its lines.',
  awaiting_payment:
    'Admin-built batches (the fallback path) with no receipt yet — record the transfer once it lands on the bank statement.',
  partially_settled:
    'Batches where a payment covered some lines in full; the uncovered lines stay pending until more money arrives.',
  settled: 'Fully allocated batches — every line confirmed.',
  draft: 'Unsubmitted batches. Lines freeze on leaving draft.',
  cancelled:
    'Cancelled batches, including receipts rejected here — their lines were released and those transactions are payable again.',
};

/** How a claimed transfer measures against what the batch still owes. */
function ClaimVarianceCell({ settlement }: { settlement: Settlement }) {
  const claimed = claimedLaari(settlement);

  if (claimed === 0) {
    return <span className="text-muted-foreground">—</span>;
  }

  const comparison = compareClaim(claimed, outstandingLaari(settlement));

  if (comparison.kind === 'exact') {
    return (
      <Badge variant="success" appearance="light" size="sm">
        Exact
      </Badge>
    );
  }

  if (comparison.kind === 'over') {
    return (
      <Badge variant="info" appearance="light" size="sm">
        Over by {formatMoney(comparison.deltaLaari)}
      </Badge>
    );
  }

  return (
    <Badge
      variant={comparison.forgivable ? 'info' : 'warning'}
      appearance="light"
      size="sm"
    >
      Short by {formatMoney(comparison.deltaLaari)}
      {comparison.forgivable ? ' (forgiven)' : ''}
    </Badge>
  );
}

/**
 * The settlement matching queue. Someone works this daily, so the columns
 * follow the tab: a receipt under review is read by its bank reference, its
 * slip and its claim against the amount due, while a cancelled batch is read
 * by WHY it was refused.
 */
export default function SettlementsPage() {
  const router = useRouter();
  const [state, setState] = useState<StateFilter>('all');
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['admin', 'settlements', state, page],
    queryFn: ({ signal }) =>
      listAdminSettlements(
        { state: state === 'all' ? undefined : state, page },
        { signal },
      ),
  });

  const onTabChange = (value: string) => {
    const parsed = SettlementStateSchema.safeParse(value);
    setState(parsed.success ? parsed.data : 'all');
    setPage(1);
  };

  const headings =
    state === 'payment_review'
      ? [
          'Reference',
          'Bank ref',
          'Slip',
          'Amount due',
          'Claimed',
          'Vs due',
          'Submitted',
        ]
      : state === 'cancelled'
        ? ['Reference', 'Outcome', 'Amount due', 'Reason', 'Decided']
        : [
            'Reference',
            'State',
            'Funding',
            'Amount due',
            'Received',
            'Due at',
            'Created',
          ];

  const endAligned = new Set(['Amount due', 'Claimed', 'Received']);

  const renderCells = (settlement: Settlement) => {
    if (state === 'payment_review') {
      const claim = pendingPayment(settlement);
      return (
        <>
          <TableCell className="font-medium">{settlement.reference}</TableCell>
          <TableCell className="font-mono text-xs">
            {claim?.bank_ref ?? '—'}
          </TableCell>
          <TableCell>
            {claim?.has_slip ? (
              <Badge variant="info" appearance="light" size="sm">
                <Paperclip className="size-3" />
                Attached
              </Badge>
            ) : (
              <span className="text-muted-foreground">None</span>
            )}
          </TableCell>
          <TableCell className="text-end">
            <MoneyText laari={settlement.amount_due_laari} />
          </TableCell>
          <TableCell className="text-end font-medium">
            {claim ? <MoneyText laari={claim.amount_laari} /> : '—'}
          </TableCell>
          <TableCell>
            <ClaimVarianceCell settlement={settlement} />
          </TableCell>
          <TableCell>{formatDateTime(claim?.created_at)}</TableCell>
        </>
      );
    }

    if (state === 'cancelled') {
      const refusal = rejection(settlement);
      return (
        <>
          <TableCell className="font-medium">{settlement.reference}</TableCell>
          <TableCell>
            {refusal ? (
              <Badge variant="destructive" appearance="light" size="sm">
                Receipt rejected
              </Badge>
            ) : (
              <Badge variant="secondary" appearance="light" size="sm">
                Cancelled
              </Badge>
            )}
          </TableCell>
          <TableCell className="text-end">
            <MoneyText laari={settlement.amount_due_laari} />
          </TableCell>
          <TableCell className="max-w-md">
            {refusal ? (
              <span
                className="line-clamp-2 text-muted-foreground"
                title={refusal.rejection_reason ?? undefined}
              >
                {refusal.rejection_reason}
              </span>
            ) : (
              <span className="text-muted-foreground">
                Cancelled without a receipt decision.
              </span>
            )}
          </TableCell>
          <TableCell>
            {formatDateTime(refusal?.rejected_at ?? settlement.created_at)}
          </TableCell>
        </>
      );
    }

    const claim = pendingPayment(settlement);
    return (
      <>
        <TableCell className="font-medium">
          <span className="flex items-center gap-1.5">
            {settlement.reference}
            {claim?.has_slip ? (
              <Paperclip
                className="size-3.5 text-muted-foreground"
                aria-label="A receipt is attached and awaiting review"
              />
            ) : null}
          </span>
        </TableCell>
        <TableCell>
          <SettlementStateBadge state={settlement.state} />
        </TableCell>
        <TableCell>{fundingMethodLabel(settlement.funding_method)}</TableCell>
        <TableCell className="text-end">
          <MoneyText laari={settlement.amount_due_laari} />
        </TableCell>
        <TableCell className="text-end">
          <MoneyText laari={settlement.amount_received_laari} />
        </TableCell>
        <TableCell>{formatDateTime(settlement.due_at)}</TableCell>
        <TableCell>{formatDateTime(settlement.created_at)}</TableCell>
      </>
    );
  };

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Settlement matching queue"
        description={DESCRIPTIONS[state]}
      />

      <Tabs value={state} onValueChange={onTabChange} className="mb-4">
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <Card>
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    {headings.map((heading) => (
                      <TableHead
                        key={heading}
                        className={endAligned.has(heading) ? 'text-end' : ''}
                      >
                        {heading}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={headings.length}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={headings.length}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No settlements
                        {state === 'all' ? '' : ' in this state'}.
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((settlement) => (
                      <TableRow
                        key={settlement.id}
                        className="cursor-pointer"
                        onClick={() =>
                          router.push(`/settlements/${settlement.id}`)
                        }
                      >
                        {renderCells(settlement)}
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      )}

      {query.data ? (
        <Pager meta={query.data.meta} onPageChange={setPage} />
      ) : null}
    </div>
  );
}
