'use client';

import { ReactNode, useState } from 'react';
import {
  approveStoreReview,
  formatBpPercent,
  formatPercent,
  getAdminFeeTierSchedules,
  percentToBp,
  rejectStoreReview,
  type StoreReview,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Eye, X } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { bandsFromWire, feeBpFor } from '@/lib/fee-tiers';
import { formatDateTime } from '@/lib/format';
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
import { Badge } from '@/components/ui/badge';
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
import { ChannelBadge } from '@/components/store-reviews/channel-badge';
import { StoreLogo } from '@/components/store-reviews/store-logo';

/** Invalidates every review tab AND the sidebar's pending-count badge. */
const REVIEWS_KEY_PREFIX = ['admin', 'store-reviews'] as const;

const STEP_LABELS: Record<string, string> = {
  profile: 'Profile',
  location: 'Location',
  logo: 'Logo',
  rate: 'Rate',
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

function Empty({ children = 'Not provided' }: { children?: ReactNode }) {
  return <span className="text-muted-foreground italic">{children}</span>;
}

/**
 * Everything the merchant entered in the setup wizard, reviewable in one
 * drawer: logo preview, the store's own description (the claim approval
 * publishes, so it leads), category, channel, the offered rate priced against
 * the ACTIVE fee tier schedule (all-in = cashback + platform fee), the map
 * pin, the terms & exclusions text shown verbatim to customers, contacts and
 * step completion. Approve asks for confirmation — it makes the store publicly
 * visible; Reject requires a reason the merchant will read verbatim.
 */
export function ReviewSheet({
  review,
  categoryName,
}: {
  review: StoreReview;
  /** Resolved English name of the curated category; null = unresolved slug. */
  categoryName: string | null;
}) {
  const [open, setOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const queryClient = useQueryClient();

  // The active §4 schedule prices the all-in preview. Cached hard — it
  // changes at most on an admin publish with an hour's lead time.
  const tiers = useQuery({
    queryKey: ['admin', 'fee-tier-schedules'],
    queryFn: ({ signal }) => getAdminFeeTierSchedules({ signal }),
    enabled: open,
    staleTime: 5 * 60_000,
  });

  const approve = useMutation({
    mutationFn: () => approveStoreReview(review.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: REVIEWS_KEY_PREFIX });
      toast.success(
        `${review.name} approved — it is now live on the public directory.`,
      );
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const reject = useMutation({
    mutationFn: (body: { reason: string }) =>
      rejectStoreReview(review.id, body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: REVIEWS_KEY_PREFIX });
      toast.success(
        `${review.name} rejected — the owner sees your reason and can fix and resubmit.`,
      );
      setRejectOpen(false);
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
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

  // The rate arrives as a percent string (PLAN §1); resolving which §4 band
  // it falls in is a comparison, so both sides are read as integer bp.
  const wireBands = tiers.data?.data.current?.tiers ?? null;
  const rateBp =
    review.cashback_rate_percent === null
      ? null
      : percentToBp(review.cashback_rate_percent);
  const feeBp =
    rateBp !== null && wireBands !== null
      ? feeBpFor(bandsFromWire(wireBands), rateBp)
      : null;

  const stepEntries = Object.entries(review.setup_state);

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm">
          <Eye />
          Review
        </Button>
      </SheetTrigger>
      <SheetContent className="sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>Store review</SheetTitle>
          <SheetDescription>
            Everything the merchant entered in the setup wizard. The store stays
            publicly invisible until approved.
          </SheetDescription>
        </SheetHeader>
        <SheetBody className="grow p-0">
          <ScrollArea className="h-full px-5 py-4">
            <div className="flex flex-col gap-5 pb-4">
              <div className="flex items-start gap-4">
                <StoreLogo
                  name={review.name}
                  logoUrl={review.logo_url}
                  size="lg"
                />
                <div className="flex min-w-0 flex-col gap-1.5">
                  <span className="text-base font-semibold">{review.name}</span>
                  <span className="text-xs text-muted-foreground">
                    /store/{review.slug}
                  </span>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <MerchantStatusBadge status={review.status} />
                    <ChannelBadge channel={review.channel} />
                  </div>
                </div>
              </div>

              {review.status === 'rejected' && review.rejected_reason ? (
                <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
                  <div className="mb-1 font-medium text-destructive">
                    Rejected {formatDateTime(review.rejected_at)}
                  </div>
                  <p className="whitespace-pre-wrap text-foreground">
                    {review.rejected_reason}
                  </p>
                </div>
              ) : null}

              {/* The store's own words, first and whole. Everything else on
                  this sheet is a fact the console already knows how to check
                  — a category slug, a rate, a pin. The description is the
                  claim the merchant is asking to publish, so it is what
                  approval actually decides on, and it is read before the
                  numbers rather than after them. */}
              <Field label="About this store (shown to shoppers on its page)">
                {review.description ? (
                  <p className="rounded-md border border-border bg-muted/40 p-3 whitespace-pre-wrap">
                    {review.description}
                  </p>
                ) : (
                  <Empty>
                    Not written — a store cannot be submitted without one
                  </Empty>
                )}
              </Field>

              <div className="grid grid-cols-2 gap-4">
                <Field label="Category">
                  {review.category === null ? (
                    <Empty>Not chosen</Empty>
                  ) : (
                    <span>
                      {categoryName ?? review.category}
                      {categoryName === null ? (
                        <span className="ms-1 text-xs text-muted-foreground">
                          (unknown slug)
                        </span>
                      ) : null}
                    </span>
                  )}
                </Field>
                <Field label="Channel">
                  <ChannelBadge channel={review.channel} />
                </Field>
                <Field label="Cashback rate">
                  {review.cashback_rate_percent === null ? (
                    <Empty>Not set</Empty>
                  ) : (
                    <span className="font-medium">
                      {formatPercent(review.cashback_rate_percent)}
                    </span>
                  )}
                </Field>
                <Field label="Merchant all-in">
                  {rateBp === null || review.cashback_rate_percent === null ? (
                    <Empty>—</Empty>
                  ) : tiers.isPending && open ? (
                    <span className="text-muted-foreground">Pricing…</span>
                  ) : feeBp === null ? (
                    <Empty>Rate not priced by the active fee schedule</Empty>
                  ) : (
                    <span className="font-medium">
                      {formatBpPercent(rateBp + feeBp)}
                      <span className="ms-1 font-normal text-muted-foreground">
                        ({formatPercent(review.cashback_rate_percent)} cashback
                        + {formatBpPercent(feeBp)} fee)
                      </span>
                    </span>
                  )}
                </Field>
                <Field label="Contact email">
                  {review.contact_email ?? <Empty />}
                </Field>
                <Field label="Contact phone">
                  {review.contact_phone ?? <Empty />}
                </Field>
                <Field label="Submitted">
                  {review.submitted_at ? (
                    formatDateTime(review.submitted_at)
                  ) : (
                    <Empty>Not submitted yet</Empty>
                  )}
                </Field>
                <Field label="Signed up">
                  {formatDateTime(review.created_at)}
                </Field>
              </div>

              {/* A pin is asked for in the wizard but is deliberately not an
                  approval requirement (D17), so nothing else on this sheet
                  would tell a reviewer whether the store can be found. */}
              <Field label="Location">
                {review.primary_branch === null ||
                review.primary_branch.lat === null ||
                review.primary_branch.lng === null ? (
                  <Empty>No pin — the store cannot appear in Nearby</Empty>
                ) : (
                  <div className="flex flex-col gap-0.5">
                    <a
                      href={`https://www.google.com/maps/search/?api=1&query=${review.primary_branch.lat},${review.primary_branch.lng}`}
                      target="_blank"
                      rel="noreferrer"
                      className="text-primary underline underline-offset-2"
                    >
                      {review.primary_branch.lat},{' '}
                      {review.primary_branch.lng}
                    </a>
                    {review.primary_branch.address === null ? null : (
                      <span className="text-xs text-muted-foreground">
                        {review.primary_branch.address}
                      </span>
                    )}
                  </div>
                )}
              </Field>

              <Field label="Terms & exclusions (shown to customers verbatim)">
                {review.eligibility_basis ? (
                  <p className="rounded-md border border-border bg-muted/40 p-3 whitespace-pre-wrap">
                    {review.eligibility_basis}
                  </p>
                ) : (
                  <Empty />
                )}
              </Field>

              <Field label="Wizard steps completed">
                <div className="flex flex-wrap gap-1.5">
                  {stepEntries.length === 0 ? (
                    <Empty>No steps recorded</Empty>
                  ) : (
                    stepEntries.map(([step, done]) => (
                      <Badge
                        key={step}
                        variant={done ? 'success' : 'secondary'}
                        appearance="light"
                        size="sm"
                      >
                        {done ? (
                          <Check className="size-3" />
                        ) : (
                          <X className="size-3" />
                        )}
                        {STEP_LABELS[step] ?? step}
                      </Badge>
                    ))
                  )}
                </div>
              </Field>

              {review.status === 'pending_review' ? (
                <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
                  <Button
                    variant="outline"
                    onClick={() => {
                      setReason('');
                      setReasonError(null);
                      setRejectOpen(true);
                    }}
                    disabled={reject.isPending || approve.isPending}
                  >
                    <X />
                    Reject
                  </Button>
                  <AlertDialog>
                    <AlertDialogTrigger asChild>
                      <Button disabled={approve.isPending || reject.isPending}>
                        <Check />
                        {approve.isPending ? 'Approving…' : 'Approve'}
                      </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                      <AlertDialogHeader>
                        <AlertDialogTitle>
                          Approve {review.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                          The store becomes ACTIVE and publicly visible —
                          directory, search and its store page — on the next
                          discovery refresh. Its owner can start crediting
                          cashback at{' '}
                          {review.cashback_rate_percent !== null
                            ? formatPercent(review.cashback_rate_percent)
                            : 'the configured rate'}
                          .
                        </AlertDialogDescription>
                      </AlertDialogHeader>
                      <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => approve.mutate()}>
                          Approve store
                        </AlertDialogAction>
                      </AlertDialogFooter>
                    </AlertDialogContent>
                  </AlertDialog>
                </div>
              ) : null}
            </div>
          </ScrollArea>
        </SheetBody>
      </SheetContent>

      <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Reject {review.name}</DialogTitle>
            <DialogDescription>
              The owner sees this reason word-for-word on their setup screen,
              fixes the listed problems and resubmits. Write it for them.
            </DialogDescription>
          </DialogHeader>
          <DialogBody>
            <div className="flex flex-col gap-2">
              <Label htmlFor={`reject-reason-${review.id}`}>Reason</Label>
              <Textarea
                id={`reject-reason-${review.id}`}
                rows={4}
                maxLength={2000}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="e.g. The logo is unreadable at card size — please upload a square version on a plain background."
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
              {reject.isPending ? 'Rejecting…' : 'Reject store'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Sheet>
  );
}
