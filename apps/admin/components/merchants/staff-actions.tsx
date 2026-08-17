'use client';

import { useState } from 'react';
import {
  resetAdminMerchantStaffPassword,
  setAdminMerchantStaffActive,
  type AdminMerchantStaff,
  type AdminStaffResetPasswordResponse,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, TriangleAlert } from 'lucide-react';
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

/**
 * Superadmin rescue controls for one merchant panel account: the password
 * reset a locked-out owner phones in for (server-generated, shown exactly
 * once in a copyable dialog that will not close by accident) and the
 * activate/deactivate switch. Resetting or deactivating kills the
 * account's live sessions and app sign-ins server-side.
 */
export function StaffActions({
  merchantId,
  staff,
}: {
  merchantId: number;
  staff: AdminMerchantStaff;
}) {
  const queryClient = useQueryClient();
  const [reset, setReset] = useState<AdminStaffResetPasswordResponse | null>(
    null,
  );
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  const invalidate = () => {
    queryClient.invalidateQueries({
      queryKey: ['admin', 'merchant-detail', merchantId],
    });
  };

  const resetPassword = useMutation({
    mutationFn: () => resetAdminMerchantStaffPassword(merchantId, staff.id),
    onSuccess: (response) => {
      invalidate();
      setReset(response);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const toggle = useMutation({
    mutationFn: (isActive: boolean) =>
      setAdminMerchantStaffActive(merchantId, staff.id, isActive),
    onSuccess: (response) => {
      invalidate();
      toast.success(
        response.data.is_active
          ? `${staff.name} reactivated — they can sign in again.`
          : `${staff.name} deactivated — sessions and app sign-ins are revoked.`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const pending = resetPassword.isPending || toggle.isPending;

  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button variant="outline" size="sm" disabled={pending}>
            {resetPassword.isPending ? 'Resetting…' : 'Reset password'}
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Reset the password for {staff.name}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              A new temporary password is generated and shown to you exactly
              once. Their current password stops working, every signed-in
              panel session dies on its next request, and every merchant-app
              sign-in is revoked.
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

      {staff.is_active ? (
        <AlertDialog>
          <AlertDialogTrigger asChild>
            <Button
              variant="destructive"
              size="sm"
              disabled={pending}
            >
              {toggle.isPending ? 'Working…' : 'Deactivate'}
            </Button>
          </AlertDialogTrigger>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Deactivate {staff.name}?</AlertDialogTitle>
              <AlertDialogDescription>
                They can no longer sign in to the merchant panel or app, and
                existing sessions and app tokens are destroyed. The account
                stays on record and can be reactivated at any time. A
                store&apos;s last active owner cannot be deactivated —
                suspend the merchant instead.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancel</AlertDialogCancel>
              <AlertDialogAction
                variant="destructive"
                onClick={() => toggle.mutate(false)}
              >
                Deactivate
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      ) : (
        <Button
          variant="outline"
          size="sm"
          disabled={pending}
          onClick={() => toggle.mutate(true)}
        >
          {toggle.isPending ? 'Working…' : 'Reactivate'}
        </Button>
      )}

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
                  {reset.data.name} ({reset.data.email}) can now sign in with
                  this temporary password.
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
