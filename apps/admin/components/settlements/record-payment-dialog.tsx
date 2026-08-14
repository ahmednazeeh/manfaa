'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  parseMvrToLaari,
  recordAdminSettlementPayment,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
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

/**
 * The amount is entered as an MVR string ("1,234.56") and decomposed into
 * integer laari by string parsing — it never passes through a float.
 */
const FormSchema = z.object({
  amount: z
    .string()
    .min(1, 'Enter the amount received.')
    .refine(
      (value) => {
        try {
          return parseMvrToLaari(value) >= 1;
        } catch {
          return false;
        }
      },
      { message: 'Enter a valid positive MVR amount, e.g. 1,234.56.' },
    ),
  bank_ref: z
    .string()
    .min(1, 'The bank reference is required.')
    .max(128, 'At most 128 characters.'),
  slip_path: z.string().max(255, 'At most 255 characters.').optional(),
});

type FormValues = z.infer<typeof FormSchema>;

function laariPreview(amount: string): string | null {
  try {
    const laari = parseMvrToLaari(amount);
    if (laari < 1) {
      return null;
    }
    return `= ${formatMoney(laari)} (${laari.toLocaleString('en-US')} laari)`;
  } catch {
    return null;
  }
}

export function RecordPaymentDialog({
  settlementId,
  onRecorded,
}: {
  settlementId: number;
  onRecorded?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: { amount: '', bank_ref: '', slip_path: '' },
  });

  const record = useMutation({
    mutationFn: (values: FormValues) =>
      recordAdminSettlementPayment(settlementId, {
        amount: parseMvrToLaari(values.amount),
        bank_ref: values.bank_ref,
        slip_path:
          values.slip_path && values.slip_path !== ''
            ? values.slip_path
            : undefined,
      }),
    onSuccess: () => {
      toast.success('Payment recorded — it now sits in the matching queue.');
      queryClient.invalidateQueries({
        queryKey: ['admin', 'settlement', settlementId],
      });
      queryClient.invalidateQueries({ queryKey: ['admin', 'settlements'] });
      form.reset();
      setOpen(false);
      onRecorded?.();
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const amountValue = form.watch('amount');
  const preview = laariPreview(amountValue);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Plus />
          Record payment
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Record a claimed bank payment</DialogTitle>
          <DialogDescription>
            The payment stays pending until matched. Matching allocates whole
            transactions oldest-first.
          </DialogDescription>
        </DialogHeader>
        <DialogBody>
          <Form {...form}>
            <form
              id={`record-payment-${settlementId}`}
              onSubmit={form.handleSubmit((values) => record.mutate(values))}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Amount received (MVR)</FormLabel>
                    <FormControl>
                      <Input
                        inputMode="decimal"
                        placeholder="e.g. 4,300.00"
                        {...field}
                      />
                    </FormControl>
                    {preview ? (
                      <FormDescription>{preview}</FormDescription>
                    ) : null}
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="bank_ref"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Bank reference</FormLabel>
                    <FormControl>
                      <Input placeholder="Transfer reference" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="slip_path"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Slip path (optional)</FormLabel>
                    <FormControl>
                      <Input placeholder="Uploaded slip reference" {...field} />
                    </FormControl>
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
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form={`record-payment-${settlementId}`}
            disabled={record.isPending}
          >
            {record.isPending ? 'Recording…' : 'Record payment'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
