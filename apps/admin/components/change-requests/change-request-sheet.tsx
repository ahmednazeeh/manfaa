'use client';

import { ReactNode, useState } from 'react';
import {
  apiErrorCode,
  type ChangeRequestKind,
  type MerchantChangeRequest,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Eye, TriangleAlert, X } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import {
  approveChangeRequest,
  changeStoreName,
  changeStoreStatus,
  changeTargetName,
  getChangeRequest,
  rejectChangeRequest,
} from '@/lib/change-requests';
import { formatDateTime } from '@/lib/format';
import { changeKindLabel, changeRequestRefusalLabel } from '@/lib/labels';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
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
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { MerchantStatusBadge } from '@/components/admin/state-badge';
import { useAdminUser } from '@/components/auth/admin-guard';
import {
  ChangeKindBadge,
  ChangeStatusBadge,
} from '@/components/change-requests/change-badges';
import { ChangeDiff } from '@/components/change-requests/change-diff';
import { StoreLogo } from '@/components/store-reviews/store-logo';

/** Invalidates every queue tab AND the sidebar's pending-count badge. */
const CHANGE_REQUESTS_KEY_PREFIX = ['admin', 'change-requests'] as const;

/** What approving actually does to the live store, per kind. */
const APPROVE_EFFECT: Record<ChangeRequestKind, string> = {
  profile:
    'The store’s public identity changes at once — the directory, search and its store page follow on the next discovery read.',
  branch_create:
    'The branch is added to the store’s estate and starts appearing in Nearby once it has a pin.',
  branch_update:
    'The branch is updated in place; shoppers see the new details on the next discovery read.',
  branch_delete:
    'The branch is permanently deleted. If transactions or promotions have started pointing at it since the store asked, the approval is refused instead — that history has to keep resolving.',
};

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {label}
      </span>
      <div className="text-sm text-foreground">{children}</div>
    </div>
  );
}

/**
 * One queued store change, reviewed end to end: who asked, when, what the
 * store looks like today and what it would look like approved.
 *
 * The drawer re-reads the request on open rather than trusting the row it
 * was launched from. A queue is decided by more than one person and a
 * merchant can supersede their own request at any moment, so the decision
 * has to be taken against the request as it stands NOW — the server rejects
 * a stale one with 409 `change_not_pending`, and this way the admin sees
 * that before clicking rather than after.
 *
 * Approve and Reject are superadmin-only, exactly as store activation is —
 * the same split of authority, enforced by the server either way. A plain
 * admin gets the full review, read-only.
 */
export function ChangeRequestSheet({
  request,
}: {
  request: MerchantChangeRequest;
}) {
  const [open, setOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';
  const queryClient = useQueryClient();

  const detail = useQuery({
    queryKey: ['admin', 'change-request', request.id],
    queryFn: ({ signal }) => getChangeRequest(request.id, { signal }),
    enabled: open,
  });

  // The list row is a perfectly good render until the fresh read lands —
  // only the DECISION needs the fresh copy, not the reading.
  const current: MerchantChangeRequest = detail.data?.data ?? request;

  const storeName = changeStoreName(current);
  const storeStatus = changeStoreStatus(current);
  const target = changeTargetName(current);

  const settled = () => {
    queryClient.invalidateQueries({ queryKey: CHANGE_REQUESTS_KEY_PREFIX });
    queryClient.invalidateQueries({
      queryKey: ['admin', 'change-request', request.id],
    });
  };

  /** A 409 names a code; the console adds what to do about it. */
  const onDecisionError = (error: unknown) => {
    const advice = changeRequestRefusalLabel(apiErrorCode(error));
    toast.error(advice ?? apiErrorMessage(error));
    if (advice !== null) {
      settled();
    }
  };

  const approve = useMutation({
    mutationFn: () => approveChangeRequest(current.id),
    onSuccess: () => {
      settled();
      toast.success(
        `${changeKindLabel(current.kind)} approved — ${storeName} is updated and its staff have been notified.`,
      );
      setOpen(false);
    },
    onError: onDecisionError,
  });

  const reject = useMutation({
    mutationFn: (body: { reason: string }) =>
      rejectChangeRequest(current.id, body),
    onSuccess: () => {
      settled();
      toast.success(
        `Refused — nothing changed on ${storeName}, and its staff have your reason.`,
      );
      setRejectOpen(false);
      setOpen(false);
    },
    onError: onDecisionError,
  });

  const submitRejection = () => {
    const trimmed = reason.trim();
    if (trimmed.length < 3) {
      setReasonError(
        'A reason is required — the merchant reads it verbatim before fixing and resubmitting.',
      );
      return;
    }
    setReasonError(null);
    reject.mutate({ reason: trimmed });
  };

  const busy = approve.isPending || reject.isPending;

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm">
          <Eye />
          Review
        </Button>
      </SheetTrigger>
      <SheetContent className="sm:max-w-2xl">
        <SheetHeader>
          <SheetTitle>Change request</SheetTitle>
          <SheetDescription>
            What a live store wants to change about what shoppers read. Nothing
            here has moved yet — the store still shows the &ldquo;before&rdquo;
            column.
          </SheetDescription>
        </SheetHeader>
        <SheetBody className="grow p-0">
          <ScrollArea className="h-full px-5 py-4">
            <div className="flex flex-col gap-5 pb-4">
              <div className="flex items-start gap-4">
                <StoreLogo
                  name={storeName}
                  logoUrl={current.merchant?.logo_url ?? null}
                />
                <div className="flex min-w-0 flex-col gap-1.5">
                  <span className="text-base font-semibold">{storeName}</span>
                  {current.merchant ? (
                    <span className="text-xs text-muted-foreground">
                      /store/{current.merchant.slug}
                    </span>
                  ) : null}
                  <div className="flex flex-wrap items-center gap-1.5">
                    {storeStatus !== null ? (
                      <MerchantStatusBadge status={storeStatus} />
                    ) : null}
                    <ChangeKindBadge kind={current.kind} />
                    <ChangeStatusBadge status={current.status} />
                  </div>
                </div>
              </div>

              {current.status === 'rejected' && current.rejected_reason ? (
                <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
                  <div className="mb-1 font-medium text-destructive">
                    Refused {formatDateTime(current.reviewed_at)}
                  </div>
                  <p className="whitespace-pre-wrap text-foreground">
                    {current.rejected_reason}
                  </p>
                </div>
              ) : null}

              {current.status === 'superseded' ? (
                <Alert appearance="light" size="sm">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertDescription>
                    The store replaced this request with a newer one for the
                    same target. It is kept as a record of what was asked first;
                    there is nothing left to decide.
                  </AlertDescription>
                </Alert>
              ) : null}

              <div className="grid grid-cols-2 gap-4">
                <Field label="Change">{changeKindLabel(current.kind)}</Field>
                <Field label="Applies to">{target}</Field>
                <Field label="Submitted by">
                  {current.submitted_by?.name ?? (
                    <span className="text-muted-foreground italic">
                      Unknown
                    </span>
                  )}
                </Field>
                <Field label="Submitted">
                  {formatDateTime(current.submitted_at)}
                </Field>
                {current.reviewed_at !== null ? (
                  <>
                    <Field label="Decided">
                      {formatDateTime(current.reviewed_at)}
                    </Field>
                    <Field label="Decided by">
                      {current.reviewed_by === null
                        ? '—'
                        : `Admin #${current.reviewed_by}`}
                    </Field>
                  </>
                ) : null}
              </div>

              <div className="flex flex-col gap-3 border-t border-border pt-4">
                <h3 className="text-sm font-semibold text-foreground">
                  Before &rarr; after
                </h3>
                <ChangeDiff request={current} />
              </div>

              {current.status === 'pending' ? (
                isSuperadmin ? (
                  <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
                    <Button
                      variant="outline"
                      onClick={() => {
                        setReason('');
                        setReasonError(null);
                        setRejectOpen(true);
                      }}
                      disabled={busy}
                    >
                      <X />
                      Refuse
                    </Button>
                    <AlertDialog>
                      <AlertDialogTrigger asChild>
                        <Button disabled={busy}>
                          <Check />
                          {approve.isPending ? 'Approving…' : 'Approve'}
                        </Button>
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>
                            Approve this{' '}
                            {changeKindLabel(current.kind).toLowerCase()} for{' '}
                            {storeName}?
                          </AlertDialogTitle>
                          <AlertDialogDescription>
                            {APPROVE_EFFECT[current.kind]} The staff who can
                            make this kind of change are notified either way.
                          </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                          <AlertDialogCancel>Cancel</AlertDialogCancel>
                          <AlertDialogAction onClick={() => approve.mutate()}>
                            Approve change
                          </AlertDialogAction>
                        </AlertDialogFooter>
                      </AlertDialogContent>
                    </AlertDialog>
                  </div>
                ) : (
                  <div className="border-t border-border pt-4 text-sm text-muted-foreground">
                    Approving or refusing a store change requires the superadmin
                    role — the same authority that activates a store. Ask a
                    superadmin to decide this one.
                  </div>
                )
              ) : null}
            </div>
          </ScrollArea>
        </SheetBody>
      </SheetContent>

      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Refuse this change</DialogTitle>
            <DialogDescription>
              Nothing is applied and a staged logo is discarded. The staff who
              can make this change read your reason word-for-word on their phone
              — write it for them.
            </DialogDescription>
          </DialogHeader>
          <DialogBody>
            <div className="flex flex-col gap-2">
              <Label htmlFor={`change-reject-reason-${current.id}`}>
                Reason
              </Label>
              <Textarea
                id={`change-reject-reason-${current.id}`}
                rows={4}
                maxLength={2000}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="e.g. The new terms exclude gift cards but the old ones did not — say so on the store page before we change the promise."
              />
              {reasonError ? (
                <p className="text-sm text-destructive">{reasonError}</p>
              ) : null}
            </div>
          </DialogBody>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setRejectOpen(false)}
              disabled={reject.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={submitRejection}
              disabled={reject.isPending}
            >
              {reject.isPending ? 'Refusing…' : 'Refuse change'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Sheet>
  );
}
