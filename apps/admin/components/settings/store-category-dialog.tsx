'use client';

import { ReactNode, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  createStoreCategory,
  updateStoreCategory,
  type StoreCategory,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
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
import { StoreCategoryIconField } from '@/components/settings/store-category-icon';

const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const FormSchema = z.object({
  name_en: z
    .string()
    .min(1, 'The English name is required.')
    .max(120, 'At most 120 characters.'),
  name_dv: z.string().max(120, 'At most 120 characters.'),
  slug: z
    .string()
    .min(1, 'The slug is required.')
    .max(80, 'At most 80 characters.')
    .regex(
      SLUG_PATTERN,
      'Lowercase letters, digits and hyphens, e.g. food-drink.',
    ),
  sort: z
    .string()
    .regex(/^\d*$/, 'A whole number.')
    .refine(
      (value) => value === '' || Number(value) <= 100000,
      'At most 100000.',
    ),
});

type FormValues = z.infer<typeof FormSchema>;

/** "Food & Drink" -> "food-drink" — mirrors the server's slug constraint. */
export function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80)
    .replace(/-+$/g, '');
}

function defaultsFor(category: StoreCategory | undefined): FormValues {
  return {
    name_en: category?.name_en ?? '',
    name_dv: category?.name_dv ?? '',
    slug: category?.slug ?? '',
    sort: String(category?.sort ?? 0),
  };
}

/**
 * Create or edit one curated store category. The slug auto-fills from the
 * English name until it is edited by hand, and is IMMUTABLE after creation
 * — merchants store it by value, so editing shows it read-only. There is no
 * delete anywhere; deactivation on the listing is the only removal.
 */
export function StoreCategoryDialog({
  category,
  trigger,
}: {
  /** Present = edit; absent = create. */
  category?: StoreCategory;
  trigger: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const [slugTouched, setSlugTouched] = useState(false);
  const queryClient = useQueryClient();
  const editing = category !== undefined;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: defaultsFor(category),
  });

  const save = useMutation({
    mutationFn: (values: FormValues) => {
      const shared = {
        name_en: values.name_en,
        name_dv: values.name_dv.trim() === '' ? null : values.name_dv.trim(),
        sort: values.sort === '' ? 0 : Number(values.sort),
      };
      return editing
        ? updateStoreCategory(category.id, shared)
        : createStoreCategory({ ...shared, slug: values.slug });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'store-categories'],
      });
      toast.success(editing ? 'Category updated.' : 'Category created.');
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const formId = editing
    ? `edit-store-category-${category.id}`
    : 'add-store-category';

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          form.reset(defaultsFor(category));
          setSlugTouched(false);
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>
            {editing ? 'Edit store category' : 'Add a store category'}
          </DialogTitle>
          <DialogDescription>
            {editing
              ? 'Renames or reorders the category. The slug never changes — active stores reference it by value.'
              : 'Stores pick exactly one category from this curated list in the setup wizard — there is no free text.'}
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
                name="name_en"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name (English)</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="e.g. Food & Drink"
                        {...field}
                        onChange={(event) => {
                          field.onChange(event);
                          if (!editing && !slugTouched) {
                            form.setValue('slug', slugify(event.target.value));
                          }
                        }}
                      />
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
                      <Input
                        dir="rtl"
                        lang="dv"
                        placeholder="ދިވެހި ނަން"
                        {...field}
                      />
                    </FormControl>
                    <FormDescription>
                      Optional — the English name shows wherever this is empty.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="slug"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Slug</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="food-drink"
                        disabled={editing}
                        {...field}
                        onChange={(event) => {
                          setSlugTouched(true);
                          field.onChange(event);
                        }}
                      />
                    </FormControl>
                    <FormDescription>
                      {editing
                        ? 'Immutable — stores carry this value.'
                        : 'Auto-filled from the English name. Immutable after creation.'}
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {/* Editing only: the upload needs a row to attach to, so a
                  new category gets its icon on the next open rather than
                  making creation a two-request transaction that can half
                  fail. */}
              {editing ? (
                <StoreCategoryIconField category={category} />
              ) : (
                <p className="text-xs text-muted-foreground">
                  You can upload the category icon once it is created.
                </p>
              )}
              <FormField
                control={form.control}
                name="sort"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Sort order</FormLabel>
                    <FormControl>
                      <Input inputMode="numeric" placeholder="0" {...field} />
                    </FormControl>
                    <FormDescription>
                      Lower numbers list first in the wizard and directory
                      filters.
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
          >
            Cancel
          </Button>
          <Button type="submit" form={formId} disabled={save.isPending}>
            {save.isPending
              ? 'Saving…'
              : editing
                ? 'Save changes'
                : 'Add category'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
