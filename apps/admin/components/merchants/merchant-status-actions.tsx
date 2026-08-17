'use client';

import { useState } from 'react';
import {
  reinstateAdminMerchant,
  suspendAdminMerchant,
  type AdminMerchantDetail,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * The superadmin's manual suspend / reinstate pair. Suspend requires a
 * reason (it lands in the append-only notice trail as the audit record) and
 * takes the store off the public feed immediately; reinstate requires a
 * note and is the only way back for a manually suspended store or one whose
 * defaulted debt was written off — the automatic sweep never touches either.
 */
export function MerchantStatusActions({
  merchant,
}: {
  merchant: AdminMerchantDetail;
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const [textError, setTextError] = useState<string | null>(null);

  const suspending = merchant.status === 'active';
  const reinstatable = merchant.status === 'suspended';

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['admin', 'merchants'] });
    queryClient.invalidateQueries({
      queryKey: ['admin', 'merchant-detail', merchant.id],
    });
    queryClient.invalidateQueries({
      queryKey: ['admin', 'merchant-notices', merchant.id],
    });
  };

  const suspend = useMutation({
    mutationFn: (reason: string) =>
      suspendAdminMerchant(merchant.id, { reason }),
    onSuccess: () => {
      invalidate();
      toast.success(
        `${merchant.name} suspended — it is off the public feed immediately and its till refuses new cashback.`,
      );
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const reinstate = useMutation({
    mutationFn: (note: string) =>
      reinstateAdminMerchant(merchant.id, { note }),
    onSuccess: () => {
      invalidate();
      toast.success(
        `${merchant.name} reinstated — it is back on the public feed immediately.`,
      );
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  if (!suspending && !reinstatable) {
    return null;
  }

  const pending = suspend.isPending || reinstate.isPending;

  const submit = () => {
    const trimmed = text.trim();
    if (trimmed.length < 3) {
      setTextError(
        suspending
          ? 'A reason is required — it becomes the permanent audit record for this suspension.'
          : 'A note is required — it becomes the permanent audit record for this reinstatement.',
      );
      return;
    }
    setTextError(null);
    if (suspending) {
      suspend.mutate(trimmed);
    } else {
      reinstate.mutate(trimmed);
    }
  };

  return (
    <>
      {suspending ? (
        <Button
          variant="destructive"
          size="sm"
          onClick={() => {
            setText('');
            setTextError(null);
            setOpen(true);
          }}
        >
          <Ban />
          Suspend
        </Button>
      ) : (
        <Button
          variant="primary"
          size="sm"
          onClick={() => {
            setText('');
            setTextError(null);
            setOpen(true);
          }}
        >
          <RotateCcw />
          Reinstate
        </Button>
      )}

      <Dialog open={open} onOpenChange={(next) => !pending && setOpen(next)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>
              {suspending
                ? `Suspend ${merchant.name}?`
                : `Reinstate ${merchant.name}?`}
            </DialogTitle>
            <DialogDescription>
              {suspending
                ? 'The store vanishes from the public directory, search and its store page immediately, and its till can no longer credit cashback. Only a manual reinstate brings it back — the automatic sweep never reopens a manual suspension.'
                : 'The store returns to the public directory, search and its store page immediately, and its till can credit cashback again.'}
            </DialogDescription>
          </DialogHeader>
          <DialogBody>
            <div className="flex flex-col gap-2">
              <Label htmlFor={`status-note-${merchant.id}`}>
                {suspending ? 'Reason' : 'Note'}
              </Label>
              <Textarea
                id={`status-note-${merchant.id}`}
                rows={3}
                maxLength={1000}
                value={text}
                onChange={(event) => setText(event.target.value)}
                placeholder={
                  suspending
                    ? 'e.g. Crediting phantom receipts — suspended pending investigation.'
                    : 'e.g. Debt settled out of band; approved by finance.'
                }
              />
              {textError ? (
                <p className="text-sm text-destructive">{textError}</p>
              ) : null}
              <p className="text-xs text-muted-foreground">
                Recorded permanently in the merchant&apos;s notice trail with
                your admin account.
              </p>
            </div>
          </DialogBody>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
              disabled={pending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant={suspending ? 'destructive' : 'primary'}
              onClick={submit}
              disabled={pending}
            >
              {suspending
                ? suspend.isPending
                  ? 'Suspending…'
                  : 'Suspend store'
                : reinstate.isPending
                  ? 'Reinstating…'
                  : 'Reinstate store'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
