'use client';

import { useState } from 'react';
import {
  settleAllAdminPayoutItems,
  type PayoutBatch,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CheckCheck } from 'lucide-react';
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
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const MAX_REFERENCE = 255;

/**
 * Settles everything still waiting under one reference — the honest record of
 * a bulk transfer, which the bank confirms as a single transaction covering
 * many payees. The reference is required for that reason: without it this
 * would paint items paid with nothing to trace them back to.
 *
 * The figures quoted are the batch's own stored integers, never a total
 * recomputed here. Items already paid or failed are passed over server-side,
 * so the button disappears once nothing is left to settle.
 */
export function SettleAllButton({ batch }: { batch: PayoutBatch }) {
  const [open, setOpen] = useState(false);
  const [reference, setReference] = useState('');
  const [referenceError, setReferenceError] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const waiting = (batch.items ?? []).filter(
    (item) => item.state === 'pending' || item.state === 'sent',
  ).length;

  const settleAll = useMutation({
    mutationFn: (bankReference: string) =>
      settleAllAdminPayoutItems(batch.id, bankReference),
    onSuccess: (response) => {
      queryClient.setQueryData(['admin', 'payout-batch', batch.id], response);
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
      toast.success(
        `${waiting} item${waiting === 1 ? '' : 's'} settled against ${reference.trim()}.`,
      );
      setOpen(false);
      setReference('');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  if (waiting === 0) {
    return null;
  }

  const submit = () => {
    const trimmed = reference.trim();

    if (trimmed === '') {
      setReferenceError(
        'The bank reference is required — one bulk transfer, one reference.',
      );
      return;
    }
    if (trimmed.length > MAX_REFERENCE) {
      setReferenceError(`At most ${MAX_REFERENCE} characters.`);
      return;
    }

    setReferenceError(null);
    settleAll.mutate(trimmed);
  };

  return (
    <AlertDialog
      open={open}
      onOpenChange={(next) => {
        if (!settleAll.isPending) {
          setOpen(next);
          setReferenceError(null);
        }
      }}
    >
      <AlertDialogTrigger asChild>
        <Button variant="outline">
          <CheckCheck />
          Settle all
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            Settle the {waiting} item{waiting === 1 ? '' : 's'} still waiting?
          </AlertDialogTitle>
          <AlertDialogDescription>
            Each one is marked paid against the reference below, its rewards
            move to paid, and the transfer is posted to the ledger. Items
            already paid or failed are passed over. This batch totals{' '}
            <MoneyText laari={batch.total_laari} currency={batch.currency} />{' '}
            across {batch.customer_count} customer
            {batch.customer_count === 1 ? '' : 's'}.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`settle-all-reference-${batch.id}`}>
            Bank reference (required)
          </Label>
          <Input
            id={`settle-all-reference-${batch.id}`}
            maxLength={MAX_REFERENCE}
            placeholder="Reference of the bulk transfer"
            value={reference}
            onChange={(event) => setReference(event.target.value)}
            disabled={settleAll.isPending}
          />
          {referenceError !== null ? (
            <p className="text-sm text-destructive">{referenceError}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Recorded on every item this settles.
            </p>
          )}
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={settleAll.isPending}>
            Cancel
          </AlertDialogCancel>
          <AlertDialogAction
            // The dialog closes on its own success, not on the click: a
            // missing reference has to be able to keep it open.
            onClick={(event) => {
              event.preventDefault();
              submit();
            }}
            disabled={settleAll.isPending}
          >
            {settleAll.isPending ? 'Settling…' : 'Settle all'}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
