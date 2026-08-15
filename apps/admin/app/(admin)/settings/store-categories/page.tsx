'use client';

import {
  listStoreCategories,
  updateStoreCategory,
  type StoreCategory,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImageOff, Pencil, Plus, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardTable } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/admin/page-header';
import { StoreCategoryDialog } from '@/components/settings/store-category-dialog';

const QUERY_KEY = ['admin', 'store-categories'] as const;

function useCategoryUpdate(successMessage: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      updateStoreCategory(id, { active }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: QUERY_KEY });
      toast.success(successMessage);
    },
    // A 409 category_in_use lands here — the server's message carries the
    // active-merchant count ("in use by N active store(s)").
    onError: (error) => toast.error(apiErrorMessage(error)),
  });
}

function DeactivateAction({ category }: { category: StoreCategory }) {
  const deactivate = useCategoryUpdate(
    'Category deactivated — the wizard no longer offers it.',
  );
  const inUse = category.active_merchant_count > 0;

  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="outline" size="sm" disabled={deactivate.isPending}>
          Deactivate
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Deactivate “{category.name_en}”?</AlertDialogTitle>
          <AlertDialogDescription>
            New stores can no longer pick it in the setup wizard. The category
            row stays on record — there is no delete.
            {inUse ? (
              <>
                {' '}
                <span className="font-medium text-destructive">
                  {category.active_merchant_count} active store
                  {category.active_merchant_count === 1 ? '' : 's'} still carr
                  {category.active_merchant_count === 1 ? 'ies' : 'y'} this
                  category
                </span>{' '}
                — the server will refuse until every one is re-homed to another
                category.
              </>
            ) : null}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            variant="destructive"
            onClick={() =>
              deactivate.mutate({ id: category.id, active: false })
            }
          >
            Deactivate
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

function ReactivateAction({ category }: { category: StoreCategory }) {
  const reactivate = useCategoryUpdate(
    'Category reactivated — the wizard offers it again.',
  );
  return (
    <Button
      variant="outline"
      size="sm"
      disabled={reactivate.isPending}
      onClick={() => reactivate.mutate({ id: category.id, active: true })}
    >
      {reactivate.isPending ? 'Reactivating…' : 'Reactivate'}
    </Button>
  );
}

export default function StoreCategoriesPage() {
  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: ({ signal }) => listStoreCategories({ signal }),
  });

  const categories = query.data?.data ?? [];

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Store categories"
        description="The curated list stores pick from in the setup wizard — no free text. Categories deactivate rather than delete, and never while an active store still carries one."
        actions={
          <StoreCategoryDialog
            trigger={
              <Button>
                <Plus />
                Add category
              </Button>
            }
          />
        }
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <Card>
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-16 text-end">Sort</TableHead>
                    <TableHead className="w-14">Icon</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Slug</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-end">Active stores</TableHead>
                    <TableHead className="text-end">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 4 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={7}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : categories.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={7}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No categories yet. Add the list stores will pick from
                        during signup.
                      </TableCell>
                    </TableRow>
                  ) : (
                    categories.map((category) => (
                      <TableRow key={category.id}>
                        <TableCell className="text-end text-muted-foreground">
                          {category.sort}
                        </TableCell>
                        <TableCell>
                          {/* What the storefront rail actually draws. An
                              empty frame means no artwork — the rail falls
                              back to a glyph, which is not worth mirroring
                              here. */}
                          <span className="flex size-9 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
                            {category.icon_url === null ? (
                              <ImageOff className="size-4 text-muted-foreground" />
                            ) : (
                              <img
                                src={category.icon_url}
                                alt=""
                                className="size-full object-cover"
                              />
                            )}
                          </span>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-col">
                            <span className="font-medium">
                              {category.name_en}
                            </span>
                            {category.name_dv ? (
                              <span
                                dir="rtl"
                                lang="dv"
                                className="text-xs text-muted-foreground"
                              >
                                {category.name_dv}
                              </span>
                            ) : null}
                          </div>
                        </TableCell>
                        <TableCell>
                          <code className="text-xs text-muted-foreground">
                            {category.slug}
                          </code>
                        </TableCell>
                        <TableCell>
                          {category.active ? (
                            <Badge
                              variant="success"
                              appearance="light"
                              size="sm"
                            >
                              Active
                            </Badge>
                          ) : (
                            <Badge
                              variant="secondary"
                              appearance="light"
                              size="sm"
                            >
                              Deactivated
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell className="text-end">
                          {category.active_merchant_count}
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-wrap items-center justify-end gap-1.5">
                            <StoreCategoryDialog
                              category={category}
                              trigger={
                                <Button variant="outline" size="sm">
                                  <Pencil />
                                  Edit
                                </Button>
                              }
                            />
                            {category.active ? (
                              <DeactivateAction category={category} />
                            ) : (
                              <ReactivateAction category={category} />
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      )}
    </div>
  );
}
