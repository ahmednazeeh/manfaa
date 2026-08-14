'use client';

import { ReactNode, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  createAdminPlatformBankAccount,
  updateAdminPlatformBankAccount,
  type PlatformBankAccount,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';

const FormSchema = z.object({
  bank_name: z
    .string()
    .min(1, 'The bank name is required.')
    .max(255, 'At most 255 characters.'),
  account_no: z
    .string()
    .min(1, 'The account number is required.')
    .max(255, 'At most 255 characters.'),
  account_name: z
    .string()
    .min(1, 'The account name is required.')
    .max(255, 'At most 255 characters.'),
});

type FormValues = z.infer<typeof FormSchema>;

/**
 * Create or edit one of the platform's own bank accounts. Accounts are
 * never deleted — old settlement instructions must stay explicable — so
 * the only lifecycle actions elsewhere are set-primary and deactivate.
 */
export function BankAccountDialog({
  account,
  trigger,
}: {
  /** Present = edit; absent = create. */
  account?: PlatformBankAccount;
  trigger: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const [makePrimary, setMakePrimary] = useState(false);
  const queryClient = useQueryClient();
  const editing = account !== undefined;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: {
      bank_name: account?.bank_name ?? '',
      account_no: account?.account_no ?? '',
      account_name: account?.account_name ?? '',
    },
  });

  const save = useMutation({
    mutationFn: (values: FormValues) =>
      editing
        ? updateAdminPlatformBankAccount(account.id, values)
        : createAdminPlatformBankAccount({
            ...values,
            is_primary: makePrimary || undefined,
          }),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'platform-bank-accounts'],
      });
      toast.success(editing ? 'Account updated.' : 'Account added.');
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const formId = editing
    ? `edit-bank-account-${account.id}`
    : 'add-bank-account';

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          form.reset({
            bank_name: account?.bank_name ?? '',
            account_no: account?.account_no ?? '',
            account_name: account?.account_name ?? '',
          });
          setMakePrimary(false);
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>
            {editing ? 'Edit bank account' : 'Add a bank account'}
          </DialogTitle>
          <DialogDescription>
            {editing
              ? 'Corrects the account details. If this is the primary account, merchant settlement instructions update immediately.'
              : 'One of the platform’s own accounts for receiving merchant settlement transfers. MVR only in v1.'}
          </DialogDescription>
        </DialogHeader>
        <DialogBody>
          <Form {...form}>
            <form
              id={formId}
              onSubmit={form.handleSubmit((values) => save.mutate(values))}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="bank_name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Bank</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Bank of Maldives" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="account_no"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Account number</FormLabel>
                    <FormControl>
                      <Input placeholder="Account number" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="account_name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Account name</FormLabel>
                    <FormControl>
                      <Input placeholder="Name on the account" {...field} />
                    </FormControl>
                    <FormDescription>
                      Exactly as the bank shows it — merchants copy this into
                      their transfer.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {!editing ? (
                <div className="flex items-start gap-2.5">
                  <Checkbox
                    id="make-primary"
                    checked={makePrimary}
                    onCheckedChange={(checked) =>
                      setMakePrimary(checked === true)
                    }
                  />
                  <div className="flex flex-col gap-0.5">
                    <Label htmlFor="make-primary">
                      Make this the primary account
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      The primary account is the one merchants see on settlement
                      instructions. Promoting this demotes the current primary.
                    </p>
                  </div>
                </div>
              ) : null}
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
          <Button type="submit" form={formId} disabled={save.isPending}>
            {save.isPending
              ? 'Saving…'
              : editing
                ? 'Save changes'
                : 'Add account'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
