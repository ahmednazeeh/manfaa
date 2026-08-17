'use client';

import { useState } from 'react';
import {
  resetAdminCustomerPassword,
  setAdminCustomerStatus,
  type AdminCustomerDetail,
  type AdminCustomerResetPasswordResponse,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, Check, Copy, RotateCcw, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
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
 * Superadmin rescue controls for one customer account, mirroring the
 * merchant staff actions:
 *
 * - Web password reset: server-generated, shown exactly once in a copyable
 *   dialog that will not close by accident. Live web sessions die; the app
 *   deliberately stays signed in (it is passwordless — OTP).
 * - Enable / Disable: disabling refuses sign-in everywhere, kills the web
 *   session on its next request and destroys every app token (their push
 *   registrations die with them). Reversible at any time.
 */
export function CustomerAccountActions({
  customer,
}: {
  customer: AdminCustomerDetail;
}) {
  const queryClient = useQueryClient();
  const [reset, setReset] =
    useState<AdminCustomerResetPasswordResponse | null>(null);
  const [disableOpen, setDisableOpen] = useState(false);
  const [reason, setReason] = useState('');
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['admin', 'customers'] });
    queryClient.invalidateQueries({
      queryKey: ['admin', 'customer-detail', customer.id],
    });
  };

  const resetPassword = useMutation({
    mutationFn: () => resetAdminCustomerPassword(customer.id),
    onSuccess: (response) => {
      queryClient.setQueryData(['admin', 'customer-detail', customer.id], {
        data: response.data,
      });
      invalidate();
      setReset(response);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const setStatus = useMutation({
    mutationFn: (body: { status: 'active' | 'suspended'; reason?: string }) =>
      setAdminCustomerStatus(customer.id, body),
    onSuccess: (response) => {
      invalidate();
      setDisableOpen(false);
      toast.success(
        response.data.status === 'active'
          ? `${customer.name} enabled — they can sign in again.`
          : `${customer.name} disabled — sign-in refused, app devices signed out.`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const pending = resetPassword.isPending || setStatus.isPending;
  const disabled = customer.status === 'suspended';
  const closed = customer.status === 'closed';

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button variant="outline" size="sm" disabled={pending}>
            {resetPassword.isPending ? 'Resetting…' : 'Reset password'}
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Reset the web password for {customer.name}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              A new temporary password is generated and shown to you exactly
              once. Their current password stops working and any signed-in
              website session dies on its next request. The phone app is NOT
              affected — it signs in with an SMS code, not this password.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => resetPassword.mutate()}>
              Reset password
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {closed ? null : disabled ? (
        <Button
          variant="outline"
          size="sm"
          disabled={pending}
          onClick={() => setStatus.mutate({ status: 'active' })}
        >
          <RotateCcw />
          {setStatus.isPending ? 'Working…' : 'Enable'}
        </Button>
      ) : (
        <Button
          variant="destructive"
          size="sm"
          disabled={pending}
          onClick={() => {
            setReason('');
            setDisableOpen(true);
          }}
        >
          <Ban />
          Disable
        </Button>
      )}

      <Dialog
        open={disableOpen}
        onOpenChange={(next) => !pending && setDisableOpen(next)}
      >
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Disable {customer.name}?</DialogTitle>
            <DialogDescription>
              They can no longer sign in anywhere: the website session dies on
              its next request, every app device is signed out for good, and
              their push notifications stop. Their balance and history stay on
              record, and the account can be enabled again at any time.
            </DialogDescription>
          </DialogHeader>
          <DialogBody>
            <div className="flex flex-col gap-2">
              <Label htmlFor={`disable-reason-${customer.id}`}>
                Reason (optional)
              </Label>
              <Textarea
                id={`disable-reason-${customer.id}`}
                rows={3}
                maxLength={1000}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="e.g. Account takeover reported — disabled while we verify the owner."
              />
              <p className="text-xs text-muted-foreground">
                Recorded in the audit log with your admin account.
              </p>
            </div>
          </DialogBody>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setDisableOpen(false)}
              disabled={pending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={pending}
              onClick={() =>
                setStatus.mutate({
                  status: 'suspended',
                  reason: reason.trim() === '' ? undefined : reason.trim(),
                })
              }
            >
              {setStatus.isPending ? 'Disabling…' : 'Disable account'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={reset !== null}
        onOpenChange={(next) => {
          // While the one-time password is on screen, only the Done button
          // closes — an accidental overlay click would lose it forever.
          if (!next) {
            return;
          }
        }}
      >
        <DialogContent
          className="max-w-md"
          showCloseButton={false}
          onInteractOutside={(event) => event.preventDefault()}
          onEscapeKeyDown={(event) => event.preventDefault()}
        >
          {reset !== null ? (
            <>
              <DialogHeader>
                <DialogTitle>Password reset</DialogTitle>
                <DialogDescription>
                  {reset.data.name} ({reset.data.phone}) can now sign in to
                  the website with this temporary password.
                </DialogDescription>
              </DialogHeader>
              <DialogBody className="flex flex-col gap-4">
                <div className="flex items-center gap-2">
                  <code className="flex-1 overflow-x-auto rounded-md border border-border bg-muted/50 px-3 py-2.5 font-mono text-sm">
                    {reset.temp_password}
                  </code>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Copy password"
                    onClick={() => copyToClipboard(reset.temp_password)}
                  >
                    {isCopied ? <Check className="text-success" /> : <Copy />}
                  </Button>
                </div>
                <Alert variant="warning" appearance="light">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertContent>
                    <AlertTitle>Shown once, never again</AlertTitle>
                    <AlertDescription>
                      Only a hash is stored server-side — this password cannot
                      be retrieved after you close this dialog. Pass it to{' '}
                      {reset.data.name} over a secure channel and have them
                      change it after signing in.
                    </AlertDescription>
                  </AlertContent>
                </Alert>
              </DialogBody>
              <DialogFooter>
                <Button type="button" onClick={() => setReset(null)}>
                  Done — I have copied the password
                </Button>
              </DialogFooter>
            </>
          ) : null}
        </DialogContent>
      </Dialog>
    </div>
  );
}
