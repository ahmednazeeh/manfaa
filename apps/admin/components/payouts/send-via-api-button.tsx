'use client';

import { useState } from 'react';
import {
  sendAdminPayoutBatchViaApi,
  type PayoutBatch,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Landmark } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
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

/**
 * The third road to the bank, beside the exported sheet and the per-row
 * Mark paid (owner requirement 2026-08-19): a queue worker transfers every
 * pending row through the bank API.
 *
 * It asks first, and names the money before it does. Sending is not
 * undoable, and a batch is a lot of it.
 *
 * The two facts an operator most needs at that moment are both in the
 * dialog: a refusal is recorded and the pass MOVES ON — never retried, so
 * one bad account number cannot stop everyone else being paid — and every
 * row carries its own idempotency key, so a re-run after a crashed worker
 * cannot pay anybody twice.
 */
export function SendViaApiButton({ batch }: { batch: PayoutBatch }) {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();

  const send = useMutation({
    mutationFn: () => sendAdminPayoutBatchViaApi(batch.id),
    onSuccess: () => {
      toast.success(
        'Transfers queued. Outcomes appear against each row as the bank answers.',
      );
      queryClient.invalidateQueries({
        queryKey: ['admin', 'payout-batch', batch.id],
      });
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  // Only rows nothing has claimed. A paid, failed or parked row is passed
  // over by the sweep, so it must not be counted in what we promise.
  const outstanding = (batch.items ?? []).filter(
    (item) => item.state === 'pending',
  );
  const total = outstanding.reduce((sum, item) => sum + item.amount_laari, 0);

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <Button
        variant="outline"
        disabled={outstanding.length === 0}
        onClick={() => setOpen(true)}
      >
        <Landmark />
        Send via API
      </Button>

      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            Send {outstanding.length} transfer
            {outstanding.length === 1 ? '' : 's'} to the bank?
          </AlertDialogTitle>
          <AlertDialogDescription asChild>
            <div className="flex flex-col gap-2">
              <span>
                A queue worker will transfer{' '}
                <MoneyText laari={total} className="font-medium" /> across{' '}
                {outstanding.length} row{outstanding.length === 1 ? '' : 's'}.
                Rows already paid, failed or waiting on an approver are passed
                over.
              </span>
              <span>
                A refused transfer is recorded and the pass moves on to the
                next one — it is never retried, so one bad account number
                cannot stop everybody else being paid. Every row carries its
                own idempotency key, so no transfer can be made twice.
              </span>
            </div>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={send.isPending}>Cancel</AlertDialogCancel>
          <AlertDialogAction
            disabled={send.isPending}
            onClick={(event) => {
              // Kept open until the request answers, so a failure is seen
              // rather than dismissed by the dialog closing itself.
              event.preventDefault();
              send.mutate();
            }}
          >
            {send.isPending ? 'Queueing…' : 'Send to the bank'}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
