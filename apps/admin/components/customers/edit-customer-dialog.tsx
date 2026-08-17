'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  updateAdminCustomer,
  type AdminCustomerDetail,
  type UpdateAdminCustomerRequest,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Pencil, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { apiErrorMessage } from '@/lib/api-error';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
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

/** Every form the server folds into +960XXXXXXX (Msisdn). */
const PHONE_SHAPE =
  /^(\+?960|00960)?[\s-]*[79][\s-]*(\d[\s-]*){6}$/;

const FormSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, 'A name is required.')
    .max(120, 'At most 120 characters.'),
  email: z
    .string()
    .max(255, 'At most 255 characters.')
    .refine(
      (value) => value === '' || z.string().email().safeParse(value).success,
      'Enter a valid email address.',
    ),
  phone: z
    .string()
    .trim()
    .regex(
      PHONE_SHAPE,
      'A Maldivian mobile: seven digits starting 7 or 9, with or without +960.',
    ),
});

type FormValues = z.infer<typeof FormSchema>;

/**
 * Superadmin edit over a customer's profile. Name and email are routine;
 * PHONE is the account's sign-in identity and OTP destination, so changing
 * it carries a big visible warning and should only follow a properly
 * verified support request (lost SIM, new number). The change deliberately
 * signs nothing out — the customer's own app and web session survive.
 */
export function EditCustomerDialog({
  customer,
}: {
  customer: AdminCustomerDetail;
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    values: {
      name: customer.name,
      email: customer.email ?? '',
      phone: customer.phone,
    },
  });

  const update = useMutation({
    mutationFn: (body: UpdateAdminCustomerRequest) =>
      updateAdminCustomer(customer.id, body),
    onSuccess: (response) => {
      queryClient.setQueryData(
        ['admin', 'customer-detail', customer.id],
        response,
      );
      queryClient.invalidateQueries({ queryKey: ['admin', 'customers'] });
      toast.success(`${response.data.name} saved.`);
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const submit = (values: FormValues) => {
    const email = values.email.trim();
    update.mutate({
      name: values.name.trim(),
      email: email === '' ? null : email,
      // Sent as typed; the server folds it into the stored +960 shape and
      // treats the account's own number restated as a no-op.
      phone: values.phone.trim(),
    });
  };

  // Loose client-side comparison so the warning appears the moment the
  // digits differ — the server's normaliser is the real arbiter.
  const digitsOf = (value: string) =>
    value.replace(/\D/g, '').replace(/^(00960|960)/, '');
  const phoneChanged =
    digitsOf(form.watch('phone') ?? '') !== digitsOf(customer.phone);

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          form.reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <Pencil />
          Edit
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Edit {customer.name}</DialogTitle>
          <DialogDescription>
            Name and email are routine. The phone number is the account
            itself — change it only on a properly verified support request.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="max-h-[65vh] overflow-y-auto">
          <Form {...form}>
            <form
              id={`edit-customer-${customer.id}`}
              onSubmit={form.handleSubmit(submit)}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="email"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Email</FormLabel>
                    <FormControl>
                      <Input type="email" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="phone"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Phone</FormLabel>
                    <FormControl>
                      <Input dir="ltr" className="font-mono" {...field} />
                    </FormControl>
                    <FormDescription>
                      Seven digits starting 7 or 9, with or without +960.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {phoneChanged ? (
                <Alert variant="warning" appearance="light">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertContent>
                    <AlertTitle>You are changing the sign-in number</AlertTitle>
                    <AlertDescription>
                      The phone IS this account: it is how the customer signs
                      in and where every one-time code goes. From the moment
                      you save, the old number can no longer access the
                      account and all codes go to the new one. Nothing is
                      signed out — their app and web session keep working.
                      Make sure you have verified the request is really from
                      the account holder.
                    </AlertDescription>
                  </AlertContent>
                </Alert>
              ) : null}
            </form>
          </Form>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={update.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form={`edit-customer-${customer.id}`}
            variant={phoneChanged ? 'destructive' : 'primary'}
            disabled={update.isPending}
          >
            {update.isPending
              ? 'Saving…'
              : phoneChanged
                ? 'Save & move the number'
                : 'Save changes'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
