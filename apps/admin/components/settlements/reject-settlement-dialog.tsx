'use client';

import { useState } from 'react';
import { rejectAdminSettlement, type Settlement } from '@manfaa/api-client';
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
 * The second review outcome (PLAN §1 receipt-first): the transfer could not be
 * verified. Rejecting cancels the batch and releases its lines — the
 * transactions go straight back to payable and the merchant simply submits a
 * new settlement — so the dialog states those consequences before it asks for
 * the reason, which is required and which the merchant reads verbatim.
 */
export function RejectSettlementDialog({
  settlement,
  onRejected,
}: {
  settlement: Settlement;
  onRejected?: (rejected: Settlement) => void;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const lineCount = settlement.lines?.length ?? 0;

  const reject = useMutation({
    mutationFn: (body: { reason: string }) =>
      rejectAdminSettlement(settlement.id, body),
    onSuccess: (response) => {
      queryClient.setQueryData(
        ['admin', 'settlement', settlement.id],
        response,
      );
      queryClient.invalidateQueries({ queryKey: ['admin', 'settlements'] });
      toast.success(
        `${settlement.reference} rejected — its lines are released and the merchant can submit a new settlement.`,
      );
      setOpen(false);
      setReason('');
      onRejected?.(response.data);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const submit = () => {
    const trimmed = reason.trim();

    if (trimmed.length < MIN_REASON) {
      setReasonError(
        'A reason is required — the merchant reads it verbatim before submitting a corrected settlement.',
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
          className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
        >
          <Ban />
          Reject
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Reject {settlement.reference}?</DialogTitle>
          <DialogDescription>
            Use this when the transfer cannot be verified — the slip does not
            match, the reference is wrong, or nothing arrived.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              <ul className="list-inside list-disc space-y-0.5">
                <li>This settlement is cancelled. No money is booked.</li>
                <li>
                  {lineCount > 0
                    ? `Its ${lineCount} line${lineCount === 1 ? '' : 's'} are released — those transactions become payable again.`
                    : 'Its lines are released — those transactions become payable again.'}
                </li>
                <li>
                  The merchant sees your reason and simply submits a new
                  settlement with a corrected receipt.
                </li>
                <li>Rejecting cannot be undone.</li>
              </ul>
            </AlertDescription>
          </Alert>

          <div className="flex flex-col gap-2">
            <Label htmlFor={`reject-reason-${settlement.id}`}>
              Reason (required)
            </Label>
            <Textarea
              id={`reject-reason-${settlement.id}`}
              rows={4}
              maxLength={MAX_REASON}
              placeholder="e.g. The slip shows MVR 1,200.00 but the amount due is MVR 4,300.00, and the reference does not match any transfer received."
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              disabled={reject.isPending}
            />
            {reasonError !== null ? (
              <p className="text-sm text-destructive">{reasonError}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                The merchant reads this word-for-word on their settlement.
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
            {reject.isPending ? 'Rejecting…' : 'Reject and release lines'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
