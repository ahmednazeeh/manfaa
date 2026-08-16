'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { listAdminPayoutBatches } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDate } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
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
import { PageHeader } from '@/components/admin/page-header';
import { Pager } from '@/components/admin/pager';
import { PayoutBatchStateBadge } from '@/components/admin/state-badge';
import { CreateBatchDialog } from '@/components/payouts/create-batch-dialog';

export default function PayoutBatchesPage() {
  const router = useRouter();
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['admin', 'payout-batches', page],
    queryFn: ({ signal }) => listAdminPayoutBatches({ page }, { signal }),
  });

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Payout batches"
        description="Customer payouts, run as often as you like: build a draft to a cutoff date, approve it, export the transfer sheet, then record what the bank paid."
        actions={<CreateBatchDialog />}
      />

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
                    <TableHead>Reference</TableHead>
                    <TableHead>Period</TableHead>
                    <TableHead>State</TableHead>
                    <TableHead className="text-end">Total</TableHead>
                    <TableHead className="text-end">Customers</TableHead>
                    <TableHead>Approved</TableHead>
                    <TableHead>Cutoff</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={7}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={7}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No payout batches yet — create one with today as the
                        cutoff.
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((batch) => (
                      <TableRow
                        key={batch.id}
                        className="cursor-pointer"
                        onClick={() => router.push(`/payouts/${batch.id}`)}
                      >
                        <TableCell className="font-medium">
                          {batch.reference}
                        </TableCell>
                        {/* Since the previous batch's cutoff, up to this
                            one's — a rolling window, not a calendar month. */}
                        <TableCell>
                          {formatDate(batch.period_start)} –{' '}
                          {formatDate(batch.period_end)}
                        </TableCell>
                        <TableCell>
                          <PayoutBatchStateBadge state={batch.state} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={batch.total_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          {batch.customer_count}
                        </TableCell>
                        <TableCell>{formatDate(batch.approved_at)}</TableCell>
                        <TableCell>{formatDate(batch.cutoff_at)}</TableCell>
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
