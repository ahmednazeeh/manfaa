'use client';

import { useState } from 'react';
import {
  updateAdminMerchantBranch,
  type AdminMerchantBranch,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { LoaderCircle, PencilLine } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';
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

/**
 * Correct one branch from the admin merchant sheet (owner request
 * 2026-08-18).
 *
 * The address is the reason this exists: merchants type them by hand, a
 * wrong one sends customers to the wrong door, and until now nobody but the
 * merchant could fix it. The pin and the name are editable here too — same
 * class of public-facing mistake, same repair.
 *
 * A direct write. MR9's review queue is for what a merchant PROPOSES; an
 * admin correcting a record is the authority that queue was waiting for.
 */
export function BranchEditor({
  merchantId,
  branch,
}: {
  merchantId: number;
  branch: AdminMerchantBranch;
}) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(branch.name);
  const [address, setAddress] = useState(branch.address ?? '');
  const queryClient = useQueryClient();

  const save = useMutation({
    mutationFn: () =>
      updateAdminMerchantBranch(merchantId, branch.id, {
        name: name.trim(),
        address: address.trim(),
      }),
    onSuccess: () => {
      // The sheet AND the standing list both render branches.
      queryClient.invalidateQueries({ queryKey: ['admin', 'merchants'] });
      toast.success(`${name.trim()} updated.`);
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const canSave =
    name.trim() !== '' && address.trim() !== '' && !save.isPending;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          // Reopen shows what is stored, not what was abandoned last time.
          setName(branch.name);
          setAddress(branch.address ?? '');
        }
      }}
    >
      <Button
        variant="ghost"
        size="icon"
        aria-label={`Edit ${branch.name}`}
        onClick={() => setOpen(true)}
      >
        <PencilLine className="size-4" />
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit branch</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="admin-branch-name">Branch name</Label>
            <Input
              id="admin-branch-name"
              value={name}
              maxLength={255}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="admin-branch-address">Address</Label>
            <Input
              id="admin-branch-address"
              value={address}
              maxLength={1000}
              placeholder="Majeedhee Magu, Henveiru, Malé"
              onChange={(event) => setAddress(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Shown to customers on the store page. The pin is unchanged —
              this only corrects the words.
            </p>
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button disabled={!canSave} onClick={() => save.mutate()}>
            {save.isPending && <LoaderCircle className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
