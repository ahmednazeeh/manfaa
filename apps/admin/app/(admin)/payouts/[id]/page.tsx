'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  approveAdminPayoutBatch,
  cancelAdminPayoutBatch,
  exportAdminPayoutBatch,
  getAdminPayoutBatch,
  type PayoutBatch,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowLeft,
  Download,
  ShieldCheck,
  TriangleAlert,
  XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
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
  PayoutBatchStateBadge,
  PayoutItemStateBadge,
} from '@/components/admin/state-badge';
import { MarkFailedDialog } from '@/components/payouts/mark-failed-dialog';
import { MarkPaidDialog } from '@/components/payouts/mark-paid-dialog';
import { SettleAllButton } from '@/components/payouts/settle-all-button';
import { UploadSheetButton } from '@/components/payouts/upload-sheet-button';

/**
 * The batch states in which a transfer outcome can be recorded, mirroring
 * ItemResultService: the sheet has to be with the bank before the bank can
 * have paid anything.
 */
const RESULT_STATES: PayoutBatch['state'][] = [
  'processing',
  'sent',
  'completed',
  'partially_failed',
];

export default function PayoutBatchDetailPage() {
  const params = useParams<{ id: string }>();
  const batchId = Number(params.id);
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: ['admin', 'payout-batch', batchId],
    queryFn: ({ signal }) => getAdminPayoutBatch(batchId, { signal }),
    enabled: Number.isInteger(batchId),
  });

  type BatchResponse = Awaited<ReturnType<typeof getAdminPayoutBatch>>;

  const setBatch = (response: BatchResponse) => {
    queryClient.setQueryData<BatchResponse>(
      ['admin', 'payout-batch', batchId],
      (previous) => {
        // approve/cancel responses do not eager-load items — keep the ones
        // already on screen until the follow-up refetch lands.
        if (response.data.items === undefined && previous?.data.items) {
          return { data: { ...response.data, items: previous.data.items } };
        }
        return response;
      },
    );
    queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
  };

  const approve = useMutation({
    mutationFn: () => approveAdminPayoutBatch(batchId),
    onSuccess: (response) => {
      setBatch(response);
      toast.success('Batch approved — the transfer sheet can be exported.');
      // The show endpoint eager-loads items; approve does not. Refetch so the
      // items stay on screen.
      queryClient.invalidateQueries({
        queryKey: ['admin', 'payout-batch', batchId],
      });
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const cancel = useMutation({
    mutationFn: () => cancelAdminPayoutBatch(batchId),
    onSuccess: (response) => {
      setBatch(response);
      toast.success('Draft batch cancelled.');
      queryClient.invalidateQueries({
        queryKey: ['admin', 'payout-batch', batchId],
      });
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  // POST + Blob download, never a plain link: the first export mutates state
  // (approved → processing, items → sent), so it must not sit on a GET a
  // browser could prefetch. Re-running while processing (before any outcome
  // is recorded) re-downloads the identical file — a lost download is
  // recoverable. The response's own Blob is saved as it arrived; re-wrapping
  // it in a hand-declared mime would relabel the workbook.
  const exportFile = useMutation({
    mutationFn: () => exportAdminPayoutBatch(batchId),
    onSuccess: (sheet) => {
      const reference =
        queryClient.getQueryData<BatchResponse>([
          'admin',
          'payout-batch',
          batchId,
        ])?.data.reference ?? `payout-batch-${batchId}`;
      const url = URL.createObjectURL(sheet);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `${reference}.xlsx`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      toast.success('Transfer sheet downloaded.');
      queryClient.invalidateQueries({
        queryKey: ['admin', 'payout-batch', batchId],
      });
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
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

  const batch = query.data.data;
  const items = batch.items ?? [];
  const failedItems = items.filter((item) => item.state === 'failed');
  const acceptsResults = RESULT_STATES.includes(batch.state);

  return (
    <div className="flex flex-col">
      <PageHeader
        title={
          <>
            {batch.reference}
            <PayoutBatchStateBadge state={batch.state} />
          </>
        }
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>
              Since {formatDate(batch.period_start)} · up to{' '}
              {formatDate(batch.period_end)}
            </span>
            <span>Cutoff {formatDateTime(batch.cutoff_at)}</span>
            {batch.approved_at ? (
              <span>Approved {formatDateTime(batch.approved_at)}</span>
            ) : null}
            {batch.exported_at ? (
              <span>Exported {formatDateTime(batch.exported_at)}</span>
            ) : null}
          </span>
        }
        actions={
          <>
            <Button variant="outline" asChild>
              <Link href="/payouts">
                <ArrowLeft />
                Back to batches
              </Link>
            </Button>
            {batch.state === 'draft' ? (
              <>
                <Button
                  variant="outline"
                  onClick={() => cancel.mutate()}
                  disabled={cancel.isPending}
                >
                  <XCircle />
                  Cancel draft
                </Button>
                <Button
                  onClick={() => approve.mutate()}
                  disabled={approve.isPending}
                >
                  <ShieldCheck />
                  Approve
                </Button>
              </>
            ) : null}
            {batch.state === 'approved' || batch.state === 'processing' ? (
              <Button
                onClick={() => exportFile.mutate()}
                disabled={exportFile.isPending}
              >
                <Download />
                {batch.state === 'approved'
                  ? 'Export transfer sheet'
                  : 'Re-download transfer sheet'}
              </Button>
            ) : null}
            {acceptsResults ? (
              <>
                <UploadSheetButton batchId={batch.id} />
                <SettleAllButton batch={batch} />
              </>
            ) : null}
          </>
        }
      />

      {batch.state === 'approved' ? (
        <Alert variant="info" appearance="light" size="sm" className="mb-5">
          <AlertIcon>
            <Download />
          </AlertIcon>
          <AlertDescription>
            Exporting downloads the transfer sheet (.xlsx) and moves the batch
            to processing — items are marked sent. The identical sheet can be
            re-downloaded until the first outcome is recorded. Fill in the
            Transfer Reference Number column and upload it back.
          </AlertDescription>
        </Alert>
      ) : null}

      {failedItems.length > 0 ? (
        <Alert variant="destructive" appearance="light" className="mb-5">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>
              {failedItems.length} payout item
              {failedItems.length === 1 ? '' : 's'} failed
            </AlertTitle>
            <AlertDescription>
              Failed items are flagged below with the reason recorded against
              them. Fix the customer&apos;s account details — failed amounts
              roll into a future batch, they are never lost.
            </AlertDescription>
          </AlertContent>
        </Alert>
      ) : null}

      <div className="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Card>
          <CardContent className="flex flex-col gap-1 py-4">
            <span className="text-xs font-medium uppercase text-muted-foreground">
              Total payout
            </span>
            <MoneyText
              laari={batch.total_laari}
              className="text-lg font-semibold"
            />
            <span className="text-xs text-muted-foreground">
              Sum of stored item integers — never recomputed.
            </span>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex flex-col gap-1 py-4">
            <span className="text-xs font-medium uppercase text-muted-foreground">
              Customers
            </span>
            <span className="text-lg font-semibold">
              {batch.customer_count}
            </span>
            <span className="text-xs text-muted-foreground">
              One item per customer, MVR 100 minimum.
            </span>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Items ({items.length})</CardTitle>
        </CardHeader>
        <CardTable>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Idempotency key</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead>Account name</TableHead>
                  <TableHead>Bank</TableHead>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-end">Amount</TableHead>
                  <TableHead>State</TableHead>
                  <TableHead>Bank ref</TableHead>
                  <TableHead>Failure reason</TableHead>
                  <TableHead className="text-end">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={10}
                      className="py-8 text-center text-muted-foreground"
                    >
                      No items on this batch.
                    </TableCell>
                  </TableRow>
                ) : (
                  items.map((item) => (
                    <TableRow
                      key={item.id}
                      className={cn(
                        item.state === 'failed' && 'bg-destructive/5',
                      )}
                    >
                      <TableCell className="font-mono text-xs">
                        {item.idempotency_key}
                      </TableCell>
                      <TableCell>
                        <div className="flex min-w-0 flex-col">
                          <span className="font-medium">
                            {item.customer_name ??
                              `Customer #${item.customer_id}`}
                          </span>
                          <span className="text-xs text-muted-foreground">
                            {item.customer_phone ?? '—'}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell>{item.account_name ?? '—'}</TableCell>
                      <TableCell>{item.bank ?? '—'}</TableCell>
                      <TableCell>{item.account ?? '—'}</TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={item.amount_laari} />
                      </TableCell>
                      <TableCell>
                        <PayoutItemStateBadge state={item.state} />
                      </TableCell>
                      <TableCell className="font-mono text-xs">
                        {item.bank_reference ?? '—'}
                      </TableCell>
                      <TableCell
                        className={cn(
                          item.failure_reason && 'text-destructive',
                        )}
                      >
                        {item.failure_reason ?? '—'}
                      </TableCell>
                      {/* Paid and failed are terminal: an outcome already on
                          record is not re-recorded from here. */}
                      <TableCell className="text-end">
                        {acceptsResults &&
                        (item.state === 'pending' || item.state === 'sent') ? (
                          <div className="flex flex-wrap items-center justify-end gap-1.5">
                            <MarkPaidDialog item={item} />
                            <MarkFailedDialog item={item} />
                          </div>
                        ) : (
                          '—'
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
