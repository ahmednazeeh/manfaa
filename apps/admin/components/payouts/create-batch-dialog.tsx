'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { createAdminPayoutBatch } from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { businessToday } from '@/lib/format';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Builds a draft payout batch up to a cutoff date. The server gathers every
 * reward confirmed at or before that day's end, groups by customer and
 * applies the MVR 100 minimum — the dialog only picks the date, which is what
 * lets a run be weekly rather than tied to the calendar month.
 */
export function CreateBatchDialog() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);

  // Held in state rather than read per render so the field cannot move under
  // the admin mid-choice; re-read on every open so a panel left running past
  // midnight does not go on offering yesterday as the newest allowed cutoff.
  const [today, setToday] = useState(businessToday);
  const [cutoffDate, setCutoffDate] = useState(today);

  const create = useMutation({
    mutationFn: () => createAdminPayoutBatch({ cutoff_date: cutoffDate }),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
      toast.success(`Draft batch ${response.data.reference} created.`);
      setOpen(false);
      router.push(`/payouts/${response.data.id}`);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (next) {
          const current = businessToday();
          setToday(current);
          setCutoffDate(current);
        }
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button>
          <Plus />
          Create batch
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Create a payout batch</DialogTitle>
          <DialogDescription>
            Builds a draft covering every reward confirmed up to the cutoff
            date. One admin approves it before export.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="payout-cutoff-date">Cutoff date</Label>
            <Input
              id="payout-cutoff-date"
              type="date"
              value={cutoffDate}
              max={today}
              onChange={(event) => setCutoffDate(event.target.value)}
              disabled={create.isPending}
            />
            <p className="text-xs text-muted-foreground">
              Maldives time. A later date is refused — a batch built ahead of
              its own cutoff would miss confirmations still to come. Rewards
              confirmed after this day wait for the next batch; nothing is lost.
            </p>
          </div>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
          >
            Cancel
          </Button>
          <Button
            onClick={() => create.mutate()}
            disabled={create.isPending || cutoffDate === ''}
          >
            {create.isPending ? 'Building…' : 'Create draft'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
