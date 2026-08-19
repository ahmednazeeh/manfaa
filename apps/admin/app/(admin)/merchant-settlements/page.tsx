'use client';

import { useState } from 'react';
import {
  approveMerchantPayoutBatch,
  sendMerchantPayoutBatchViaApi,
  buildMerchantPayoutBatch,
  cancelMerchantPayoutBatch,
  getMerchantPayoutBatch,
  listMerchantPayoutBatches,
  sendMerchantPayoutItem,
  type MerchantSettlementBatch,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Landmark, TriangleAlert, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';

/**
 * Merchant Settlements — what the PLATFORM owes shops
 * (PLAN-marketplace.md §5.5).
 *
 * Its own menu, deliberately apart from Settlements: there a merchant pays
 * us, here we pay them. One screen showing both directions is a screen
 * nobody can check.
 *
 * The workflow is the customer payout one, unchanged — build, approve,
 * export, take it to the bank, import the filled sheet.
 */
export default function MerchantSettlementsPage() {
  const queryClient = useQueryClient();
  const [openBatch, setOpenBatch] = useState<number | null>(null);

  const batches = useQuery({
    queryKey: ['admin', 'merchant-settlements'],
    queryFn: ({ signal }) => listMerchantPayoutBatches({ signal }),
  });

  const build = useMutation({
    mutationFn: () => buildMerchantPayoutBatch(),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'merchant-settlements'] });
      toast.success(`Built ${result.data.reference}.`);
      setOpenBatch(result.data.id);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const payable = batches.data?.meta.payable_now_laari ?? 0;

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Merchant settlements"
        description="What Manfaa owes shops for marketplace orders they fulfilled, after cashback and the platform fee. Separate from Settlements, where merchants pay us."
      />

      <Card>
        <CardHeader>
          <CardTitle>Ready to pay</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p className="text-2xl font-semibold">{formatMoney(payable)}</p>
            <p className="text-sm text-muted-foreground">
              Delivered orders past each store&apos;s validation window and not
              yet in a batch.
            </p>
          </div>
          <Button
            disabled={build.isPending || payable === 0}
            onClick={() => build.mutate()}
          >
            {build.isPending ? 'Building…' : 'Build a batch'}
          </Button>
        </CardContent>
      </Card>

      {batches.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : batches.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(batches.error)}</AlertDescription>
        </Alert>
      ) : batches.data.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            No batches yet.
          </CardContent>
        </Card>
      ) : (
        <div className="flex flex-col gap-3">
          {batches.data.data.map((batch) => (
            <BatchCard
              key={batch.id}
              batch={batch}
              open={openBatch === batch.id}
              onToggle={() =>
                setOpenBatch(openBatch === batch.id ? null : batch.id)
              }
            />
          ))}
        </div>
      )}
    </div>
  );
}

const STATE_VARIANT: Record<string, 'secondary' | 'info' | 'success' | 'warning'> = {
  draft: 'secondary',
  approved: 'info',
  processing: 'warning',
  completed: 'success',
  cancelled: 'secondary',
};

function BatchCard({
  batch,
  open,
  onToggle,
}: {
  batch: MerchantSettlementBatch;
  open: boolean;
  onToggle: () => void;
}) {
  const queryClient = useQueryClient();

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['admin', 'merchant-settlements'] });

  /**
   * The whole run through the bank API, in a queue worker
   * (owner requirement 2026-08-19). A refusal is recorded and the pass moves
   * on — never retried — and every row carries the same internal_ref the
   * sheet is matched on, so a re-run cannot pay a shop twice.
   */
  const [confirmSend, setConfirmSend] = useState(false);

  const sendBatch = useMutation({
    mutationFn: () => sendMerchantPayoutBatchViaApi(batch.id),
    onSuccess: (response) => {
      invalidate();
      queryClient.invalidateQueries({
        queryKey: ['admin', 'merchant-settlement', batch.id],
      });
      toast.success(
        `${response.data.queued} transfer${response.data.queued === 1 ? '' : 's'} queued. Outcomes appear against each shop as the bank answers.`,
      );
      setConfirmSend(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const approve = useMutation({
    mutationFn: () => approveMerchantPayoutBatch(batch.id),
    onSuccess: () => {
      invalidate();
      toast.success('Approved. Export the sheet next.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const cancel = useMutation({
    mutationFn: () => cancelMerchantPayoutBatch(batch.id),
    onSuccess: () => {
      invalidate();
      toast.success('Cancelled. The orders return to the next batch.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const detail = useQuery({
    queryKey: ['admin', 'merchant-settlements', batch.id],
    queryFn: ({ signal }) => getMerchantPayoutBatch(batch.id, { signal }),
    enabled: open,
  });

  const importSheet = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData();
      body.append('file', file);

      const response = await fetch(
        `/api/admin/merchant-settlements/${batch.id}/import`,
        { method: 'POST', body, credentials: 'include' },
      );

      const json = await response.json();

      if (!response.ok) {
        throw new Error(json.message ?? 'The sheet could not be imported.');
      }

      return json.data as { matched: number; paid: number; unmatched: string[] };
    },
    onSuccess: (result) => {
      invalidate();
      queryClient.invalidateQueries({
        queryKey: ['admin', 'merchant-settlements', batch.id],
      });

      toast.success(`${result.paid} marked paid, ${result.matched} matched.`);

      // Never silent: a key we did not issue means a row nobody was paid on.
      if (result.unmatched.length > 0) {
        toast.warning(
          `${result.unmatched.length} row(s) had a payout key we did not issue and were skipped.`,
        );
      }
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 py-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <button className="font-medium underline-offset-2 hover:underline" onClick={onToggle}>
                {batch.reference}
              </button>
              <Badge
                variant={STATE_VARIANT[batch.state] ?? 'secondary'}
                appearance="light"
                size="sm"
              >
                {batch.state}
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
              {batch.merchant_count} merchants · built{' '}
              {formatDateTime(batch.cutoff_at)}
            </p>
            {batch.excluded_count > 0 ? (
              <p className="text-xs text-warning">
                {/* Surfaced, never quietly missing. */}
                {formatMoney(batch.excluded_laari)} held back from{' '}
                {batch.excluded_count} merchant(s) with no bank details — those
                orders carry into the next batch.
              </p>
            ) : null}
          </div>
          <p className="text-lg font-semibold">{formatMoney(batch.total_laari)}</p>
        </div>

        <div className="flex flex-wrap gap-2">
          {batch.state === 'draft' ? (
            <>
              <Button size="sm" disabled={approve.isPending} onClick={() => approve.mutate()}>
                Approve
              </Button>
              <Button size="sm" variant="ghost" onClick={() => cancel.mutate()}>
                Cancel
              </Button>
            </>
          ) : null}

          {['approved', 'processing'].includes(batch.state) ? (
            <AlertDialog open={confirmSend} onOpenChange={setConfirmSend}>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setConfirmSend(true)}
              >
                <Landmark className="size-4" />
                Send via API
              </Button>

              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>
                    Send this settlement run to the bank?
                  </AlertDialogTitle>
                  <AlertDialogDescription asChild>
                    <div className="flex flex-col gap-2">
                      <span>
                        A queue worker transfers every shop still waiting in{' '}
                        {batch.reference}. Rows already sent, failed or waiting
                        on an approver are passed over.
                      </span>
                      <span>
                        A refused transfer is recorded and the pass moves on to
                        the next shop — it is never retried, so one bad account
                        number cannot stop everybody else being paid. Every row
                        carries the same reference the sheet is matched on, so
                        no shop can be paid twice.
                      </span>
                    </div>
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel disabled={sendBatch.isPending}>
                    Cancel
                  </AlertDialogCancel>
                  <AlertDialogAction
                    disabled={sendBatch.isPending}
                    onClick={(event) => {
                      // Held open until the request answers, so a failure is
                      // seen rather than dismissed by the dialog closing.
                      event.preventDefault();
                      sendBatch.mutate();
                    }}
                  >
                    {sendBatch.isPending ? 'Queueing…' : 'Send to the bank'}
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          ) : null}

          {['approved', 'processing', 'completed'].includes(batch.state) ? (
            <>
              <Button size="sm" variant="outline" asChild>
                <a href={`/api/admin/merchant-settlements/${batch.id}/export`}>
                  <Download className="size-4" />
                  Export sheet
                </a>
              </Button>
              <Button size="sm" variant="outline" asChild>
                <label className="cursor-pointer">
                  <Upload className="size-4" />
                  {importSheet.isPending ? 'Importing…' : 'Import filled sheet'}
                  <input
                    type="file"
                    accept=".xlsx,.csv,.txt"
                    className="hidden"
                    onChange={(event) => {
                      const file = event.target.files?.[0];
                      if (file) importSheet.mutate(file);
                      event.target.value = '';
                    }}
                  />
                </label>
              </Button>
            </>
          ) : null}

          <Button size="sm" variant="ghost" onClick={onToggle}>
            {open ? 'Hide lines' : 'Show lines'}
          </Button>
        </div>

        {open ? (
          <>
            <Separator />
            {detail.isPending ? (
              <Skeleton className="h-24 w-full" />
            ) : detail.data ? (
              <div className="flex flex-col gap-2">
                {detail.data.data.items.map((item) => (
                  <div
                    key={item.id}
                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-medium">{item.merchant_name}</p>
                      <p className="text-xs text-muted-foreground">
                        {item.bank ?? '—'} {item.account} · {item.order_count}{' '}
                        orders
                      </p>
                      <p className="text-xs text-muted-foreground/80">
                        Key <code>{item.internal_ref}</code>
                      </p>
                      {item.approval_id ? (
                        <p className="text-xs text-warning">
                          Approval queue id {item.approval_id} — parked, not sent
                        </p>
                      ) : null}
                    </div>
                    <div className="flex items-center gap-3">
                      <div className="text-end">
                        <p className="font-medium">{formatMoney(item.amount_laari)}</p>
                        {item.trx_id ? (
                          <p className="text-xs text-muted-foreground">
                            {item.trx_id}
                          </p>
                        ) : null}
                      </div>
                      <Badge
                        variant={
                          item.state === 'sent'
                            ? 'success'
                            : item.state === 'pending_approval'
                              ? 'warning'
                              : item.state === 'failed'
                                ? 'destructive'
                                : 'secondary'
                        }
                        appearance="light"
                        size="sm"
                      >
                        {item.state.replace('_', ' ')}
                      </Badge>
                      <SendItemButton batchId={batch.id} item={item} />
                    </div>
                  </div>
                ))}
              </div>
            ) : null}
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}

function SendItemButton({
  batchId,
  item,
}: {
  batchId: number;
  item: { id: number; state: string };
}) {
  const queryClient = useQueryClient();

  const send = useMutation({
    mutationFn: () => sendMerchantPayoutItem(batchId, item.id),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'merchant-settlements'] });
      // Queued, not done. A transfer can take two minutes, so claiming it
      // transferred here would be a guess dressed as a fact.
      toast.success(
        result.data.queued
          ? 'Transfer queued. The outcome appears against this shop shortly.'
          : result.data.state === 'pending_approval'
            ? 'Sent — waiting for a second approver.'
            : `Transferred. Reference ${result.data.trx_id ?? '—'}`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  if (!['pending', 'failed'].includes(item.state)) {
    return null;
  }

  return (
    <Button size="sm" variant="outline" disabled={send.isPending} onClick={() => send.mutate()}>
      {send.isPending ? 'Sending…' : 'Send'}
    </Button>
  );
}
