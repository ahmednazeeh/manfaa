'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  listStoreCategories,
  updateAdminMerchant,
  type AdminMerchantDetail,
  type MerchantChannel,
  type UpdateAdminMerchantRequest,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { apiErrorMessage } from '@/lib/api-error';
import { merchantChannelLabel } from '@/lib/labels';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

const CHANNELS: MerchantChannel[] = ['in_store', 'online', 'both'];

/** Sentinel for "no category" — a Radix SelectItem value cannot be ''. */
const NO_CATEGORY = '__none__';

const FormSchema = z.object({
  name: z
    .string()
    .trim()
    .min(2, 'At least 2 characters.')
    .max(120, 'At most 120 characters.'),
  name_dv: z.string().max(120, 'At most 120 characters.'),
  category: z.string(),
  channel: z.enum(['in_store', 'online', 'both']),
  eligibility_basis: z.string().max(2000, 'At most 2000 characters.'),
  contact_email: z
    .string()
    .max(255, 'At most 255 characters.')
    .refine(
      (value) => value === '' || z.string().email().safeParse(value).success,
      'Enter a valid email address.',
    ),
  contact_phone: z.string().max(32, 'At most 32 characters.'),
  support_phone: z.string().max(32, 'At most 32 characters.'),
  website_url: z.string().max(255, 'At most 255 characters.'),
});

type FormValues = z.infer<typeof FormSchema>;

function nullable(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * Superadmin edit over the merchant's public profile — the same fields and
 * rules as the merchant's own settings save, plus the rename that stays
 * admin-only. The server drops the discovery read model on save, so the
 * public storefront follows immediately; the slug (every printed QR link)
 * never moves with a rename.
 */
export function EditMerchantDialog({
  merchant,
}: {
  merchant: AdminMerchantDetail;
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);

  const categories = useQuery({
    queryKey: ['admin', 'store-categories'],
    queryFn: ({ signal }) => listStoreCategories({ signal }),
    enabled: open,
    staleTime: 5 * 60_000,
  });

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    values: {
      name: merchant.name,
      name_dv: merchant.name_dv ?? '',
      category: merchant.category ?? NO_CATEGORY,
      channel: merchant.channel,
      eligibility_basis: merchant.eligibility_basis ?? '',
      contact_email: merchant.contact_email ?? '',
      contact_phone: merchant.contact_phone ?? '',
      support_phone: merchant.support_phone ?? '',
      website_url: merchant.website_url ?? '',
    },
  });

  const update = useMutation({
    mutationFn: (body: UpdateAdminMerchantRequest) =>
      updateAdminMerchant(merchant.id, body),
    onSuccess: (response) => {
      queryClient.setQueryData(
        ['admin', 'merchant-detail', merchant.id],
        response,
      );
      queryClient.invalidateQueries({ queryKey: ['admin', 'merchants'] });
      toast.success(`${response.data.name} saved — the storefront follows immediately.`);
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const submit = (values: FormValues) => {
    update.mutate({
      name: values.name.trim(),
      name_dv: nullable(values.name_dv),
      category: values.category === NO_CATEGORY ? null : values.category,
      channel: values.channel,
      eligibility_basis: nullable(values.eligibility_basis),
      contact_email: nullable(values.contact_email),
      contact_phone: nullable(values.contact_phone),
      support_phone: nullable(values.support_phone),
      website_url: nullable(values.website_url),
    });
  };

  // The picker offers ACTIVE curated categories, plus the value the store
  // already holds even when retired — the server tolerates the unchanged
  // value, and hiding it would silently clear it on the next save.
  const options = (categories.data?.data ?? []).filter(
    (category) => category.active || category.slug === merchant.category,
  );
  const currentIsUnlisted =
    merchant.category !== null &&
    !options.some((category) => category.slug === merchant.category);

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
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Edit {merchant.name}</DialogTitle>
          <DialogDescription>
            Changes reach the public storefront immediately. The address
            (/store/{merchant.slug}) never changes — printed QR links keep
            working after a rename.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="max-h-[65vh] overflow-y-auto">
          <Form {...form}>
            <form
              id={`edit-merchant-${merchant.id}`}
              onSubmit={form.handleSubmit(submit)}
              className="flex flex-col gap-4"
            >
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                  name="name_dv"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Name (Dhivehi)</FormLabel>
                      <FormControl>
                        <Input dir="rtl" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="category"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Category</FormLabel>
                      <Select
                        value={field.value}
                        onValueChange={field.onChange}
                      >
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue placeholder="Choose a category" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          <SelectItem value={NO_CATEGORY}>
                            No category
                          </SelectItem>
                          {currentIsUnlisted && merchant.category !== null ? (
                            <SelectItem value={merchant.category}>
                              {merchant.category} (retired)
                            </SelectItem>
                          ) : null}
                          {options.map((category) => (
                            <SelectItem
                              key={category.slug}
                              value={category.slug}
                            >
                              {category.name_en}
                              {category.active ? '' : ' (retired)'}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="channel"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Channel</FormLabel>
                      <Select
                        value={field.value}
                        onValueChange={field.onChange}
                      >
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {CHANNELS.map((channel) => (
                            <SelectItem key={channel} value={channel}>
                              {merchantChannelLabel(channel)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="contact_email"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Contact email</FormLabel>
                      <FormControl>
                        <Input type="email" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="contact_phone"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Contact phone</FormLabel>
                      <FormControl>
                        <Input {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="support_phone"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Support phone</FormLabel>
                      <FormControl>
                        <Input {...field} />
                      </FormControl>
                      <FormDescription>
                        Shown to shoppers on the store page.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="website_url"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Website</FormLabel>
                      <FormControl>
                        <Input placeholder="https://…" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
              <FormField
                control={form.control}
                name="eligibility_basis"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Terms &amp; exclusions</FormLabel>
                    <FormControl>
                      <Textarea rows={3} {...field} />
                    </FormControl>
                    <FormDescription>
                      Shown to customers verbatim on the store page.
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
            disabled={update.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form={`edit-merchant-${merchant.id}`}
            disabled={update.isPending}
          >
            {update.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
