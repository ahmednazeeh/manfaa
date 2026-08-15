'use client';

import { useState } from 'react';
import { releaseHold, type Hold } from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CircleCheck, Clock, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDate } from '@/lib/format';
import { transactionStateLabel } from '@/lib/labels';
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

const MIN_NOTE = 3;
const MAX_NOTE = 1000;

/**
 * Clearing a review. The consequence the dialog has to state plainly is the
 * §7 clock: past the store's validation window a release makes the sale
 * payable AND starts the 15-day settlement clock FROM NOW — the store's
 * countdown to suspension begins the moment this button is pressed. Inside
 * the window nothing starts; the row simply goes back where it was.
 *
 * One case is neither: a row that was ALREADY on the clock when it was held.
 * Its clock RESUMES where the hold froze it — the review time is credited,
 * the days that had already elapsed are not given back — so a sale that was
 * overdue before the review is still overdue after it, and promising a fresh
 * 15 days here would be a lie an admin might act on.
 *
 * `release_target` is the server's own derivation of all three, sent with
 * every queue row, so the wording here can never drift from what the release
 * actually does.
 */
export function ReleaseHoldDialog({ hold }: { hold: Hold }) {
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const [noteError, setNoteError] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const startsClock = hold.release_target.starts_clock;
  const resumesClock = hold.release_target.resumes_clock;

  const release = useMutation({
    mutationFn: (body: { note?: string }) => releaseHold(hold.id, body),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'holds'] });
      toast.success(
        startsClock
          ? `${hold.invoice_no} released — payable, settlement due ${formatDate(response.data.due_at)}.`
          : `${hold.invoice_no} released back to ${transactionStateLabel(response.data.state).toLowerCase()}.`,
      );
      setOpen(false);
      setNote('');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const submit = () => {
    const trimmed = note.trim();

    // The note is optional — a review that simply cleared needs no essay —
    // but a note that IS written has to be a sentence, not a keystroke.
    if (trimmed !== '' && trimmed.length < MIN_NOTE) {
      setNoteError(`Write at least ${MIN_NOTE} characters, or leave it empty.`);
      return;
    }
    if (trimmed.length > MAX_NOTE) {
      setNoteError(`At most ${MAX_NOTE} characters.`);
      return;
    }

    setNoteError(null);
    release.mutate(trimmed === '' ? {} : { note: trimmed });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!release.isPending) {
          setOpen(next);
          setNoteError(null);
        }
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <CircleCheck />
          Release
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Release {hold.invoice_no}?</DialogTitle>
          <DialogDescription>
            {hold.merchant.name} — the review is cleared and the reward
            continues on its normal path.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant={startsClock ? 'warning' : 'info'} appearance="light">
            <AlertIcon>{startsClock ? <Clock /> : <CircleCheck />}</AlertIcon>
            <AlertDescription>
              {startsClock ? (
                <ul className="list-inside list-disc space-y-0.5">
                  {resumesClock ? (
                    <>
                      <li>
                        This sale was already on the settlement clock when it
                        was held.
                      </li>
                      <li>
                        <strong>
                          The clock resumes where the hold froze it — it does
                          not restart.
                        </strong>{' '}
                        {hold.merchant.name} is credited the{' '}
                        {hold.age_days === null
                          ? 'time'
                          : `${hold.age_days} day${hold.age_days === 1 ? '' : 's'}`}{' '}
                        this review has been open, and nothing more: if the
                        sale was already past due, it stays past due and the
                        store stays suspended.
                      </li>
                    </>
                  ) : (
                    <>
                      <li>
                        This sale is past {hold.merchant.name}&apos;s validation
                        window, so it becomes payable immediately.
                      </li>
                      <li>
                        <strong>
                          The 15-day settlement clock starts now, not when the
                          sale happened.
                        </strong>{' '}
                        The store is due on day 15 and suspends automatically on
                        day 16 if it has not paid.
                      </li>
                    </>
                  )}
                  <li>
                    The customer&apos;s cashback stays Pending until the store
                    settles.
                  </li>
                </ul>
              ) : (
                <ul className="list-inside list-disc space-y-0.5">
                  <li>
                    This sale is still inside {hold.merchant.name}&apos;s
                    validation window, so no clock starts yet.
                  </li>
                  <li>
                    It returns to{' '}
                    {transactionStateLabel(
                      hold.release_target.state,
                    ).toLowerCase()}{' '}
                    and picks up the normal path when the window closes.
                  </li>
                </ul>
              )}
            </AlertDescription>
          </Alert>

          {hold.backdated ? (
            <Alert variant="warning" appearance="light">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertDescription>
                This was a backdated credit — the store was warned it is final
                and cannot reverse it themselves.
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor={`release-note-${hold.id}`}>Note (optional)</Label>
            <Textarea
              id={`release-note-${hold.id}`}
              rows={3}
              maxLength={MAX_NOTE}
              placeholder="e.g. Checked the till roll with the owner — genuine sale, customer present."
              value={note}
              onChange={(event) => setNote(event.target.value)}
              disabled={release.isPending}
            />
            {noteError !== null ? (
              <p className="text-sm text-destructive">{noteError}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                Kept on this transaction&apos;s history with your name — the
                next reviewer reads it.
              </p>
            )}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={release.isPending}
          >
            Cancel
          </Button>
          <Button type="button" onClick={submit} disabled={release.isPending}>
            {release.isPending
              ? 'Releasing…'
              : startsClock
                ? 'Release and start the clock'
                : 'Release'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
