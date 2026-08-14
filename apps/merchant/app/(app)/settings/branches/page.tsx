'use client';

import { useState } from 'react';
import { ApiError, type MerchantBranch } from '@manfaa/api-client';
import { LoaderCircle, MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
import {
  apiErrorMessage,
  useBranches,
  useCreateBranch,
  useDeleteBranch,
  useUpdateBranch,
} from '@/lib/queries';
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
import {
  Card,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
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
import { toast } from 'sonner';
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

/**
 * Branch management. Coordinates are optional but travel as a PAIR — the
 * customer app's Nearby discovery sorts by them, and the server rejects a
 * lone latitude or longitude.
 */

interface BranchFormState {
  name: string;
  address: string;
  lat: string;
  lng: string;
}

function emptyForm(): BranchFormState {
  return { name: '', address: '', lat: '', lng: '' };
}

function formFromBranch(branch: MerchantBranch): BranchFormState {
  return {
    name: branch.name,
    address: branch.address ?? '',
    lat: branch.lat === null ? '' : String(branch.lat),
    lng: branch.lng === null ? '' : String(branch.lng),
  };
}

function parseCoordinate(
  input: string,
  min: number,
  max: number,
): number | null | undefined {
  if (input.trim() === '') return null;
  const value = Number(input);
  if (!Number.isFinite(value) || value < min || value > max) return undefined;
  return value;
}

function BranchDialog({
  title,
  submitLabel,
  initial,
  open,
  busy,
  onOpenChange,
  onSubmit,
}: {
  title: string;
  submitLabel: string;
  initial: BranchFormState;
  open: boolean;
  busy: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (body: {
    name: string;
    address: string | null;
    lat: number | null;
    lng: number | null;
  }) => void;
}) {
  // The parent remounts this dialog (via `key`) whenever it opens, so the
  // initial values are fresh per open.
  const [form, setForm] = useState(initial);

  const lat = parseCoordinate(form.lat, -90, 90);
  const lng = parseCoordinate(form.lng, -180, 180);
  const latInvalid = lat === undefined;
  const lngInvalid = lng === undefined;
  const pairIncomplete =
    !latInvalid && !lngInvalid && (lat === null) !== (lng === null);

  const canSubmit =
    form.name.trim() !== '' &&
    !latInvalid &&
    !lngInvalid &&
    !pairIncomplete &&
    !busy;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="branch-name">Name</Label>
            <Input
              id="branch-name"
              value={form.name}
              maxLength={255}
              placeholder="e.g. Malé — Majeedhee Magu"
              onChange={(event) =>
                setForm({ ...form, name: event.target.value })
              }
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="branch-address">Address</Label>
            <Input
              id="branch-address"
              value={form.address}
              maxLength={1000}
              onChange={(event) =>
                setForm({ ...form, address: event.target.value })
              }
            />
          </div>
          <div className="grid grid-cols-2 gap-5">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="branch-lat">Latitude</Label>
              <Input
                id="branch-lat"
                inputMode="decimal"
                value={form.lat}
                placeholder="4.1755"
                aria-invalid={latInvalid}
                onChange={(event) =>
                  setForm({ ...form, lat: event.target.value })
                }
              />
              {latInvalid && (
                <p className="text-xs text-destructive">
                  Must be between -90 and 90.
                </p>
              )}
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="branch-lng">Longitude</Label>
              <Input
                id="branch-lng"
                inputMode="decimal"
                value={form.lng}
                placeholder="73.5093"
                aria-invalid={lngInvalid}
                onChange={(event) =>
                  setForm({ ...form, lng: event.target.value })
                }
              />
              {lngInvalid && (
                <p className="text-xs text-destructive">
                  Must be between -180 and 180.
                </p>
              )}
            </div>
          </div>
          {pairIncomplete && (
            <p className="text-xs text-destructive">
              Set both coordinates, or leave both empty.
            </p>
          )}
          <p className="text-xs text-muted-foreground">
            Coordinates power Nearby discovery — customers around this
            location see your store first. Leave them empty and the branch
            simply won&apos;t appear in Nearby.
          </p>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            disabled={!canSubmit}
            onClick={() =>
              onSubmit({
                name: form.name.trim(),
                address: form.address.trim() === '' ? null : form.address.trim(),
                lat: lat as number | null,
                lng: lng as number | null,
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
  const branches = useBranches();
  const createBranch = useCreateBranch();
  const updateBranch = useUpdateBranch();
  const deleteBranch = useDeleteBranch();

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<MerchantBranch | null>(null);
  const [deleting, setDeleting] = useState<MerchantBranch | null>(null);

  const handleDelete = (branch: MerchantBranch) => {
    deleteBranch.mutate(branch.id, {
      onSuccess: () => {
        toast.success(`Branch ${branch.name} deleted`);
        setDeleting(null);
      },
      onError: (error) => {
        const referenced =
          error instanceof ApiError && error.status === 409;
        toast.error(
          referenced
            ? 'This branch has recorded sales or promotions, so it must stay on file. Stop using it instead.'
            : apiErrorMessage(error, 'Could not delete the branch.'),
        );
        setDeleting(null);
      },
    });
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Branches</ToolbarPageTitle>
          <ToolbarDescription>
            Your locations — coordinates power Nearby discovery in the
            customer app
          </ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <Button onClick={() => setCreating(true)}>
            <Plus />
            Add branch
          </Button>
        </ToolbarActions>
      </Toolbar>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {branches.data
              ? `${branches.data.length} branch${branches.data.length === 1 ? '' : 'es'}`
              : 'Branches'}
          </CardTitle>
        </CardHeader>

        {branches.error ? (
          <ErrorBlock error={branches.error} />
        ) : !branches.data ? (
          <LoadingBlock lines={4} />
        ) : branches.data.length === 0 ? (
          <EmptyBlock>
            No branches yet — add your first location to appear in Nearby
            discovery.
          </EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Address</TableHead>
                    <TableHead>Coordinates</TableHead>
                    <TableHead className="w-24 text-end">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {branches.data.map((branch) => (
                    <TableRow key={branch.id}>
                      <TableCell className="font-medium">
                        {branch.name}
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
                            {branch.lat}, {branch.lng}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">
                            Not on the map
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="text-end">
                        <div className="inline-flex gap-1">
                          <Button
                            variant="ghost"
                            mode="icon"
                            size="sm"
                            aria-label={`Edit ${branch.name}`}
                            onClick={() => setEditing(branch)}
                          >
                            <Pencil />
                          </Button>
                          <Button
                            variant="ghost"
                            mode="icon"
                            size="sm"
                            aria-label={`Delete ${branch.name}`}
                            onClick={() => setDeleting(branch)}
                          >
                            <Trash2 />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        )}
      </Card>

      <BranchDialog
        key={creating ? 'create-open' : 'create-closed'}
        title="Add branch"
        submitLabel="Add branch"
        initial={emptyForm()}
        open={creating}
        busy={createBranch.isPending}
        onOpenChange={setCreating}
        onSubmit={(body) =>
          createBranch.mutate(body, {
            onSuccess: (response) => {
              toast.success(`Branch ${response.data.name} added`);
              setCreating(false);
            },
            onError: (error) =>
              toast.error(
                apiErrorMessage(error, 'Could not add the branch.'),
              ),
          })
        }
      />

      <BranchDialog
        key={editing ? `edit-${editing.id}` : 'edit-closed'}
        title={`Edit ${editing?.name ?? 'branch'}`}
        submitLabel="Save changes"
        initial={editing ? formFromBranch(editing) : emptyForm()}
        open={editing !== null}
        busy={updateBranch.isPending}
        onOpenChange={(open) => {
          if (!open) setEditing(null);
        }}
        onSubmit={(body) => {
          if (!editing) return;
          updateBranch.mutate(
            { id: editing.id, body },
            {
              onSuccess: () => {
                toast.success('Branch saved');
                setEditing(null);
              },
              onError: (error) =>
                toast.error(
                  apiErrorMessage(error, 'Could not save the branch.'),
                ),
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
              Delete {deleting?.name}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              The branch disappears from discovery and from new sales. A
              branch that already has recorded sales or promotions cannot be
              deleted — history must keep resolving.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              disabled={deleteBranch.isPending}
              onClick={() => deleting && handleDelete(deleting)}
            >
              Delete branch
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
