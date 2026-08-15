'use client';

import { useState } from 'react';
import { apiErrorCode, rejectHold, type Hold } from '@manfaa/api-client';
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
const MAX_REASON = 1000;

/**
 * Refusing a sale. This one cancels a customer's cashback and reverses the
 * store's accrual out of the ledger, so the dialog lists both consequences in
 * money terms before it asks for the reason — which is required, because a
 * reversal nobody can explain later is indistinguishable from a mistake.
 *
 * A zeroed credit (`has_accrual` false) never posted an accrual, so there is
 * nothing to mirror; the copy says so rather than promising a journal that
 * will not exist.
 */
export function RejectHoldDialog({ hold }: { hold: Hold }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const reject = useMutation({
    mutationFn: (body: { reason: string }) => rejectHold(hold.id, body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'holds'] });
      toast.success(
        `${hold.invoice_no} rejected — the sale is reversed${
          hold.has_accrual ? ' and its accrual mirrored out of the ledger' : ''
        }.`,
      );
      setOpen(false);
      setReason('');
    },
    onError: (error) => {
      // The one refusal worth explaining rather than echoing: the line is
      // frozen inside a batch that has left draft, so a reversal here would
      // have become a credit memo instead of cancelling the cashback.
      toast.error(
        apiErrorCode(error) === 'locked_in_settlement'
          ? 'This sale is inside a submitted settlement. Reject or cancel that settlement first — its lines are released, and the hold can then be rejected.'
          : apiErrorMessage(error),
      );
    },
  });

  const submit = () => {
    const trimmed = reason.trim();

    if (trimmed.length < MIN_REASON) {
      setReasonError(
        'A reason is required — it is the only record of why this reward was cancelled.',
      );
      return;
    }
    if (trimmed.length > MAX_REASON) {
      setReasonError(`At most ${MAX_REASON} characters.`);
      return;
    }

    setReasonError(null);
    reject.mutate({ reason: trimmed });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!reject.isPending) {
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
          Reject
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Reject {hold.invoice_no}?</DialogTitle>
          <DialogDescription>
            {hold.merchant.name} — use this when the sale should not have earned
            cashback at all.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              <ul className="list-inside list-disc space-y-0.5">
                <li>The sale is reversed. This cannot be undone.</li>
                {hold.has_accrual ? (
                  <li>
                    Its accrual reverses:{' '}
                    {formatMoney(hold.cashback_laari, hold.currency)} of
                    customer cashback and{' '}
                    {formatMoney(
                      hold.fee_laari + hold.fee_gst_laari,
                      hold.currency,
                    )}{' '}
                    of platform fee are mirrored back out of the ledger.
                  </li>
                ) : (
                  <li>
                    Nothing was ever accrued on this row, so no ledger entry is
                    posted — only the state changes.
                  </li>
                )}
                <li>
                  The customer sees the reward as Reversed
                  {hold.customer !== null
                    ? ` (${hold.customer.masked_name}, ${hold.customer.customer_code})`
                    : ''}
                  .
                </li>
                <li>The store is no longer billed for it.</li>
              </ul>
            </AlertDescription>
          </Alert>

          {hold.backdated ? (
            <Alert variant="warning" appearance="light">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertDescription>
                This was a backdated credit. The store cannot reverse it
                themselves — rejecting it here is the platform&apos;s own
                correction, and the reason you write is the whole record of it.
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor={`reject-reason-${hold.id}`}>
              Reason (required)
            </Label>
            <Textarea
              id={`reject-reason-${hold.id}`}
              rows={4}
              maxLength={MAX_REASON}
              placeholder="e.g. Three identical invoices from one till in four minutes; the store could not produce the sales."
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={reject.isPending}
            />
            {reasonError !== null ? (
              <p className="text-sm text-destructive">{reasonError}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                Kept on this transaction&apos;s history with your name.
              </p>
            )}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={reject.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={submit}
            disabled={reject.isPending}
          >
            {reject.isPending ? 'Rejecting…' : 'Reject and reverse'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
