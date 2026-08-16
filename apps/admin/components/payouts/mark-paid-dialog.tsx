'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { markAdminPayoutItemPaid, type PayoutItem } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { BadgeCheck } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
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
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';

const FormSchema = z.object({
  bank_reference: z
    .string()
    .min(1, 'The bank reference is required.')
    .max(255, 'At most 255 characters.'),
});

type FormValues = z.infer<typeof FormSchema>;

/**
 * Records a transfer that went out on its own, outside the uploaded sheet.
 * The reference is required for the same reason the sheet has a column for
 * it: paying a customer with nothing to quote back to the bank leaves a
 * settled row that cannot be traced to a transfer.
 */
export function MarkPaidDialog({ item }: { item: PayoutItem }) {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();
  const customer = item.customer_name ?? `Customer #${item.customer_id}`;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: { bank_reference: '' },
  });

  const markPaid = useMutation({
    mutationFn: (values: FormValues) =>
      markAdminPayoutItemPaid(item.batch_id, item.id, values.bank_reference),
    onSuccess: (response) => {
      queryClient.setQueryData(
        ['admin', 'payout-batch', item.batch_id],
        response,
      );
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });
      toast.success(`${customer} marked paid.`);
      form.reset();
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!markPaid.isPending) {
          setOpen(next);
        }
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <BadgeCheck />
          Mark paid
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Mark {customer} paid?</DialogTitle>
          <DialogDescription>
            <MoneyText laari={item.amount_laari} currency={item.currency} /> to{' '}
            {item.account_name ?? '—'} · {item.bank ?? '—'} {item.account ?? ''}
          </DialogDescription>
        </DialogHeader>
        <DialogBody>
          <Form {...form}>
            <form
              id={`mark-paid-${item.id}`}
              onSubmit={form.handleSubmit((values) => markPaid.mutate(values))}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="bank_reference"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Bank reference</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="Transfer reference from the bank"
                        disabled={markPaid.isPending}
                        {...field}
                      />
                    </FormControl>
                    <FormDescription>
                      The customer&apos;s rewards move to paid and the transfer
                      is posted to the ledger. It cannot be undone from here.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </form>
          </Form>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={markPaid.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form={`mark-paid-${item.id}`}
            disabled={markPaid.isPending}
          >
            {markPaid.isPending ? 'Recording…' : 'Mark paid'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
