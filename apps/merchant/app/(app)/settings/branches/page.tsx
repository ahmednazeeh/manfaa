'use client';

import { useState } from 'react';
import { ApiError, type MerchantBranch } from '@manfaa/api-client';
import {
  Clock3,
  LoaderCircle,
  MapPin,
  Pencil,
  Plus,
  Trash2,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { isReviewedStatus } from '@/lib/api';
import {
  apiErrorMessage,
  useBranches,
  useCreateBranch,
  useDeleteBranch,
  useMe,
  usePendingBranchChanges,
  useReverseGeocode,
  useUpdateBranch,
} from '@/lib/queries';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTable, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import {
  formatPin,
  isPlacesDropdownEvent,
  LocationPicker,
  type LatLng,
} from '@/components/app/location-picker';
import {
  PendingChangeAlert,
  PendingChangeBadge,
} from '@/components/app/pending-change';

/**
 * Branch management. The pin is optional but travels as a PAIR — the customer
 * app's Nearby discovery sorts by it, and the server rejects a lone latitude
 * or longitude. The picker can only ever hand back both halves or neither,
 * which is what retired the two typed coordinate boxes this page used to
 * carry along with their three separate invalid states.
 *
 * This is also the ONLY surface that can take a pin away again: the wizard's
 * location step writes one or is skipped, so "remove pin" lives here.
 */

interface BranchFormState {
  name: string;
  address: string;
  pin: LatLng | null;
}

function emptyForm(): BranchFormState {
  return { name: '', address: '', pin: null };
}

function formFromBranch(branch: MerchantBranch): BranchFormState {
  return {
    name: branch.name,
    address: branch.address ?? '',
    pin:
      branch.lat !== null && branch.lng !== null
        ? { lat: branch.lat, lng: branch.lng }
        : null,
  };
}

function BranchDialog({
  title,
  submitLabel,
  initial,
  open,
  busy,
  reviewNote,
  onOpenChange,
  onSubmit,
}: {
  title: string;
  submitLabel: string;
  initial: BranchFormState;
  open: boolean;
  busy: boolean;
  /** MR9: what happens to this write on a live store, said before the click. */
  reviewNote?: string;
  onOpenChange: (open: boolean) => void;
  onSubmit: (body: {
    name: string;
    address: string;
    lat: number | null;
    lng: number | null;
  }) => void;
}) {
  const { t } = useTranslation();
  // The parent remounts this dialog (via `key`) whenever it opens, so the
  // initial values are fresh per open.
  const [form, setForm] = useState(initial);
  const geocode = useReverseGeocode();

  // Address joined name as required (owner decision 2026-08-18): a pin with
  // no address reads as a coordinate in every map app we hand off to.
  const canSubmit =
    form.name.trim() !== '' && form.address.trim() !== '' && !busy;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-w-md"
        // Choosing a Places suggestion is a pointerdown on an element Google
        // appends to <body>, which Radix reads as a click away from the
        // dialog — and closing on the search result is the one thing the
        // search box must not do.
        onPointerDownOutside={(event) => {
          if (isPlacesDropdownEvent(event.detail.originalEvent)) {
            event.preventDefault();
          }
        }}
      >
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        {/* The map makes this dialog tall enough to run off a short viewport,
            and DialogBody does not scroll on its own. */}
        <DialogBody className="flex max-h-[65vh] flex-col gap-5 overflow-y-auto">
          {reviewNote !== undefined && (
            <Alert variant="info" appearance="light" size="sm">
              <AlertIcon>
                <Clock3 />
              </AlertIcon>
              <AlertTitle>{reviewNote}</AlertTitle>
            </Alert>
          )}
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="branch-name">{t('branches.nameLabel')}</Label>
            <Input
              id="branch-name"
              value={form.name}
              maxLength={255}
              placeholder={t('branches.namePlaceholder')}
              onChange={(event) =>
                setForm({ ...form, name: event.target.value })
              }
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <div className="flex items-center justify-between gap-2.5">
              <Label htmlFor="branch-address">
                {t('branches.addressLabel')}
              </Label>
              {form.pin !== null && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  disabled={geocode.isPending}
                  onClick={() => {
                    const pin = form.pin;
                    if (pin === null) return;
                    geocode.mutate(
                      { lat: pin.lat, lng: pin.lng },
                      {
                        onSuccess: (address) => {
                          if (address === null) {
                            toast.info(t('branches.addressFromPinEmpty'));
                            return;
                          }
                          // Pre-fill, never overwrite silently: the merchant
                          // sees it land in a field they can retype.
                          setForm((current) => ({ ...current, address }));
                        },
                        onError: () =>
                          toast.info(t('branches.addressFromPinEmpty')),
                      },
                    );
                  }}
                >
                  {geocode.isPending ? (
                    <LoaderCircle className="animate-spin" />
                  ) : (
                    <MapPin />
                  )}
                  {t('branches.addressFromPin')}
                </Button>
              )}
            </div>
            <Input
              id="branch-address"
              value={form.address}
              maxLength={1000}
              placeholder={t('branches.addressPlaceholder')}
              onChange={(event) =>
                setForm({ ...form, address: event.target.value })
              }
            />
            <p className="text-xs text-muted-foreground">
              {t('branches.addressHint')}
            </p>
          </div>
          <div className="flex flex-col gap-2.5">
            <div className="flex items-center justify-between gap-2.5">
              <Label>{t('branches.pinLabel')}</Label>
              {form.pin !== null && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setForm({ ...form, pin: null })}
                >
                  {t('branches.clearPin')}
                </Button>
              )}
            </div>
            <LocationPicker
              value={form.pin}
              onChange={(pin) => setForm({ ...form, pin })}
            />
            <p className="text-xs text-muted-foreground">
              {t('branches.pinHint')}
            </p>
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={!canSubmit}
            onClick={() =>
              onSubmit({
                name: form.name.trim(),
                address: form.address.trim(),
                lat: form.pin?.lat ?? null,
                lng: form.pin?.lng ?? null,
              })
            }
          >
            {busy && <LoaderCircle className="animate-spin" />}
            {submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function BranchesSettingsPage() {
  const { t } = useTranslation();
  const branches = useBranches();
  // MR9. The same GET's other half: what this store has queued for Manfaa's
  // review. The TABLE below is always the estate as it stands — a queued
  // branch has no row yet, a queued rename or removal has not moved one —
  // so the pending state is a banner plus a per-row marker, never a row
  // rendered as though it were live.
  const pending = usePendingBranchChanges();
  const me = useMe();
  const createBranch = useCreateBranch();
  const updateBranch = useUpdateBranch();
  const deleteBranch = useDeleteBranch();

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<MerchantBranch | null>(null);
  const [deleting, setDeleting] = useState<MerchantBranch | null>(null);

  const pendingChanges = pending.data ?? [];
  // A live store's estate is reviewed before it moves; a store still
  // onboarding writes straight through. Used ONLY to word the dialogs — the
  // server's answer decides what actually happened.
  const reviewed =
    me.data !== undefined && isReviewedStatus(me.data.merchant.status);

  /**
   * The branch a queued change is about. `branch_name` is the SNAPSHOT's
   * name, which only exists when the name itself is in play — an edit to an
   * address alone leaves it null — so the live row answers for the rest.
   */
  const subjectName = (branchId: number | null, snapshot: string | null) =>
    snapshot ??
    branches.data?.find((branch) => branch.id === branchId)?.name ??
    undefined;

  /** The queued change against a branch that still has a row, if any. */
  const pendingFor = (branchId: number) =>
    pendingChanges.find((change) => change.branch_id === branchId);

  const handleDelete = (branch: MerchantBranch) => {
    deleteBranch.mutate(branch.id, {
      onSuccess: (queued) => {
        toast.success(
          queued !== null
            ? t('branches.deleteQueued', { name: branch.name })
            : t('branches.deleted', { name: branch.name }),
        );
        setDeleting(null);
      },
      onError: (error) => {
        const referenced = error instanceof ApiError && error.status === 409;
        toast.error(
          referenced
            ? t('branches.deleteReferenced')
            : apiErrorMessage(error, t('branches.deleteFailed')),
        );
        setDeleting(null);
      },
    });
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('branches.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('branches.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <Button onClick={() => setCreating(true)}>
            <Plus />
            {t('branches.add')}
          </Button>
        </ToolbarActions>
      </Toolbar>

      {pendingChanges.length > 0 && (
        <div className="mb-5 flex flex-col gap-3">
          {pendingChanges.map((change) => (
            <PendingChangeAlert
              key={change.id}
              change={change}
              subject={subjectName(change.branch_id, change.branch_name)}
            />
          ))}
        </div>
      )}

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {branches.data
              ? t('branches.count', { count: branches.data.length })
              : t('branches.title')}
          </CardTitle>
        </CardHeader>

        {branches.error ? (
          <ErrorBlock error={branches.error} />
        ) : !branches.data ? (
          <LoadingBlock lines={4} />
        ) : branches.data.length === 0 ? (
          <EmptyBlock>{t('branches.empty')}</EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('branches.headName')}</TableHead>
                    <TableHead>{t('branches.headAddress')}</TableHead>
                    <TableHead>{t('branches.headLocation')}</TableHead>
                    <TableHead className="w-24 text-end">
                      {t('branches.headActions')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {branches.data.map((branch) => {
                    const queued = pendingFor(branch.id);

                    return (
                      <TableRow key={branch.id}>
                        <TableCell className="font-medium">
                          <span className="flex flex-wrap items-center gap-2">
                            {branch.name}
                            {queued !== undefined && (
                              <PendingChangeBadge kind={queued.kind} />
                            )}
                          </span>
                        </TableCell>
                        <TableCell className="text-secondary-foreground">
                          {branch.address ?? (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </TableCell>
                        <TableCell className="text-secondary-foreground whitespace-nowrap">
                          {branch.lat !== null && branch.lng !== null ? (
                            <span className="inline-flex items-center gap-1.5 tabular-nums">
                              <MapPin className="size-3.5 text-muted-foreground" />
                              {formatPin({ lat: branch.lat, lng: branch.lng })}
                            </span>
                          ) : (
                            <span className="text-muted-foreground">
                              {t('branches.notPinned')}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="text-end">
                          <div className="inline-flex gap-1">
                            <Button
                              variant="ghost"
                              mode="icon"
                              size="sm"
                              aria-label={t('branches.editAria', {
                                name: branch.name,
                              })}
                              onClick={() => setEditing(branch)}
                            >
                              <Pencil />
                            </Button>
                            <Button
                              variant="ghost"
                              mode="icon"
                              size="sm"
                              aria-label={t('branches.deleteAria', {
                                name: branch.name,
                              })}
                              onClick={() => setDeleting(branch)}
                            >
                              <Trash2 />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        )}
      </Card>

      <BranchDialog
        key={creating ? 'create-open' : 'create-closed'}
        title={t('branches.add')}
        submitLabel={t('branches.add')}
        initial={emptyForm()}
        open={creating}
        busy={createBranch.isPending}
        reviewNote={reviewed ? t('branches.addReviewNote') : undefined}
        onOpenChange={setCreating}
        onSubmit={(body) =>
          createBranch.mutate(body, {
            // A queued create has no branch row to name — the name the
            // merchant just typed is the one that is waiting.
            onSuccess: (result) => {
              toast.success(
                result.queued !== null
                  ? t('branches.addQueued', { name: body.name })
                  : t('branches.added', {
                      name: result.branch?.name ?? body.name,
                    }),
              );
              setCreating(false);
            },
            onError: (error) =>
              toast.error(apiErrorMessage(error, t('branches.addFailed'))),
          })
        }
      />

      <BranchDialog
        key={editing ? `edit-${editing.id}` : 'edit-closed'}
        title={t('branches.editTitle', { name: editing?.name ?? '' })}
        submitLabel={t('common.save')}
        initial={editing ? formFromBranch(editing) : emptyForm()}
        open={editing !== null}
        busy={updateBranch.isPending}
        reviewNote={
          reviewed
            ? // Editing a branch that already has something queued replaces
              // that request rather than adding a second one.
              editing !== null && pendingFor(editing.id) !== undefined
              ? t('branches.editReplaceNote')
              : t('branches.editReviewNote')
            : undefined
        }
        onOpenChange={(open) => {
          if (!open) setEditing(null);
        }}
        onSubmit={(body) => {
          if (!editing) return;
          updateBranch.mutate(
            { id: editing.id, body },
            {
              onSuccess: (result) => {
                toast.success(
                  result.queued !== null
                    ? t('branches.saveQueued')
                    : t('branches.saved'),
                );
                setEditing(null);
              },
              onError: (error) =>
                toast.error(apiErrorMessage(error, t('branches.saveFailed'))),
            },
          );
        }}
      />

      <AlertDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('branches.deleteTitle', { name: deleting?.name ?? '' })}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('branches.deleteBody')}
              {reviewed && ` ${t('branches.deleteReviewNote')}`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              disabled={deleteBranch.isPending}
              onClick={() => deleting && handleDelete(deleting)}
            >
              {t('branches.deleteConfirm')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
