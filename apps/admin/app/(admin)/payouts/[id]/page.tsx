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
  CircleCheck,
  CircleDashed,
  Download,
  ShieldCheck,
  TriangleAlert,
  XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime, formatMonth } from '@/lib/format';
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
import { useAdminUser } from '@/components/auth/admin-guard';
import { ImportResultsButton } from '@/components/payouts/import-results-button';

const IMPORTABLE_STATES: PayoutBatch['state'][] = [
  'processing',
  'sent',
  'completed',
  'partially_failed',
];

function ApprovalSlot({
  label,
  approvedBy,
  approvedAt,
  isCurrentAdmin,
}: {
  label: string;
  approvedBy: number | null;
  approvedAt: string | null;
  isCurrentAdmin: boolean;
}) {
  const approved = approvedBy !== null;
  return (
    <div className="flex items-center gap-3 rounded-lg border border-border p-3">
      {approved ? (
        <CircleCheck className="size-5 shrink-0 text-[var(--color-success-accent,var(--color-green-600))]" />
      ) : (
        <CircleDashed className="size-5 shrink-0 text-muted-foreground" />
      )}
      <div className="flex flex-col">
        <span className="text-sm font-medium">{label}</span>
        <span className="text-xs text-muted-foreground">
          {approved
            ? `Admin #${approvedBy}${isCurrentAdmin ? ' (you)' : ''} · ${formatDateTime(approvedAt)}`
            : 'Awaiting approval'}
        </span>
      </div>
    </div>
  );
}

export default function PayoutBatchDetailPage() {
  const params = useParams<{ id: string }>();
  const batchId = Number(params.id);
  const queryClient = useQueryClient();
  const admin = useAdminUser();

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
      toast.success(
        response.data.state === 'approved'
          ? 'Second approval recorded — the batch is approved and ready to export.'
          : 'First approval recorded — a second, different admin must approve.',
      );
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
  // browser could prefetch. Re-running while processing (before any result
  // import) re-downloads the identical file — a lost download is recoverable.
  const exportFile = useMutation({
    mutationFn: () => exportAdminPayoutBatch(batchId),
    onSuccess: (csv) => {
      const reference =
        queryClient.getQueryData<BatchResponse>([
          'admin',
          'payout-batch',
          batchId,
        ])?.data.reference ?? `payout-batch-${batchId}`;
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `${reference}.csv`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      toast.success('Bank file downloaded.');
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
  const alreadyApprovedByMe =
    batch.approved_by_first === admin.id ||
    batch.approved_by_second === admin.id;

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
            <span>Period: {formatMonth(batch.period_start)}</span>
            <span>Cutoff {formatDateTime(batch.cutoff_at)}</span>
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
                  disabled={approve.isPending || alreadyApprovedByMe}
                >
                  <ShieldCheck />
                  {alreadyApprovedByMe
                    ? 'Awaiting second approver'
                    : batch.approved_by_first === null
                      ? 'Approve (1 of 2)'
                      : 'Approve (2 of 2)'}
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
                  ? 'Export bank file'
                  : 'Re-download bank file'}
              </Button>
            ) : null}
            {IMPORTABLE_STATES.includes(batch.state) ? (
              <ImportResultsButton batchId={batch.id} />
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
            Exporting downloads the bank CSV and moves the batch to processing —
            items are marked sent. The identical file can be re-downloaded until
            the bank&apos;s results are imported.
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
              Failed items are flagged below with the bank&apos;s reason. Fix
              the customer&apos;s account details — failed amounts roll into a
              future batch, they are never lost.
            </AlertDescription>
          </AlertContent>
        </Alert>
      ) : null}

      <div className="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
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
        <Card>
          <CardHeader className="pb-0">
            <CardTitle className="text-xs font-medium uppercase text-muted-foreground">
              Dual approval
            </CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 py-3">
            <ApprovalSlot
              label="First approval"
              approvedBy={batch.approved_by_first}
              approvedAt={batch.first_approved_at}
              isCurrentAdmin={batch.approved_by_first === admin.id}
            />
            <ApprovalSlot
              label="Second approval"
              approvedBy={batch.approved_by_second}
              approvedAt={batch.second_approved_at}
              isCurrentAdmin={batch.approved_by_second === admin.id}
            />
            <span className="text-xs text-muted-foreground">
              Two distinct admins are required — the server rejects a second
              approval from the same account.
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
                  <TableHead>Customer</TableHead>
                  <TableHead>Account name</TableHead>
                  <TableHead>Bank</TableHead>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-end">Amount</TableHead>
                  <TableHead>State</TableHead>
                  <TableHead>Bank ref</TableHead>
                  <TableHead>Failure reason</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={8}
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
                      <TableCell className="font-medium">
                        #{item.customer_id}
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
                      <TableCell>{item.bank_reference ?? '—'}</TableCell>
                      <TableCell
                        className={cn(
                          item.failure_reason && 'text-destructive',
                        )}
                      >
                        {item.failure_reason ?? '—'}
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
