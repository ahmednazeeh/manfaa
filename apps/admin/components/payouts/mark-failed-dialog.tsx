'use client';

import { useState } from 'react';
import { markAdminPayoutItemFailed, type PayoutItem } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

const MIN_REASON = 3;
const MAX_REASON = 255;

/**
 * The transfer the bank would not make. There is no failure column in the
 * sheet on purpose — a typo in a spreadsheet must never be able to unlink
 * real transactions — so a rejection is recorded here, by hand, with a reason
 * that is the only record of what the bank said.
 *
 * The money is not written off: the rewards behind this item go back to
 * confirmed and unlinked, which is exactly the state the next batch's
 * eligibility sweeps up.
 */
export function MarkFailedDialog({ item }: { item: PayoutItem }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const customer = item.customer_name ?? `Customer #${item.customer_id}`;

  const markFailed = useMutation({
    mutationFn: (failureReason: string) =>
      markAdminPayoutItemFailed(item.batch_id, item.id, failureReason),
    onSuccess: (response) => {
      queryClient.setQueryData(
        ['admin', 'payout-batch', item.batch_id],
        response,
      );
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
      toast.success(
        `${customer} marked failed — the rewards return to the next batch.`,
      );
      setOpen(false);
      setReason('');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const submit = () => {
    const trimmed = reason.trim();

    if (trimmed.length < MIN_REASON) {
      setReasonError(
        'A reason is required — it is the only record of why the bank refused this transfer.',
      );
      return;
    }
    if (trimmed.length > MAX_REASON) {
      setReasonError(`At most ${MAX_REASON} characters.`);
      return;
    }

    setReasonError(null);
    markFailed.mutate(trimmed);
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!markFailed.isPending) {
          setOpen(next);
          setReasonError(null);
        }
      }}
    >
      <DialogTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
        >
          <Ban />
          Mark failed
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Mark {customer} failed?</DialogTitle>
          <DialogDescription>
            {formatMoney(item.amount_laari, item.currency)} to{' '}
            {item.account_name ?? '—'} · {item.bank ?? '—'} {item.account ?? ''}{' '}
            — use this when the bank would not make the transfer.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              <ul className="list-inside list-disc space-y-0.5">
                <li>
                  This item is closed as failed. It cannot be reopened on this
                  batch.
                </li>
                <li>
                  Its rewards are unlinked from the batch and stay confirmed, so
                  the {formatMoney(item.amount_laari, item.currency)} rolls into
                  a future batch. The money is never lost.
                </li>
                <li>
                  No bank reference is recorded — nothing was transferred, and
                  inventing one would put a fiction in the audit trail.
                </li>
                <li>
                  Fix the customer&apos;s account details before the next batch
                  is built, or the same transfer fails again.
                </li>
              </ul>
            </AlertDescription>
          </Alert>

          <div className="flex flex-col gap-2">
            <Label htmlFor={`mark-failed-reason-${item.id}`}>
              Reason (required)
            </Label>
            <Textarea
              id={`mark-failed-reason-${item.id}`}
              rows={3}
              maxLength={MAX_REASON}
              placeholder="e.g. Account number rejected by the bank — name does not match the account."
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={markFailed.isPending}
            />
            {reasonError !== null ? (
              <p className="text-sm text-destructive">{reasonError}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                Shown on this item for as long as the batch exists.
              </p>
            )}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={markFailed.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={submit}
            disabled={markFailed.isPending}
          >
            {markFailed.isPending ? 'Recording…' : 'Mark failed'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
