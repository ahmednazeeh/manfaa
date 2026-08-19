'use client';

import { useState } from 'react';
import { type MarketplaceProduct } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  apiErrorMessage,
  useArchiveProduct,
  useBranches,
  useMarketplaceCategories,
  useMarketplaceProducts,
  useSaveProduct,
  useSetListing,
} from '@/lib/queries';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Separator } from '@/components/ui/separator';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * The catalogue (`products.png`).
 *
 * A product is DESCRIBED once and STOCKED per shop, so the card carries one
 * definition and a row per branch that sells it. Price and stock apply on
 * the spot; the name, description and pictures queue for review once a shelf
 * carries the product (PLAN-marketplace.md §11.1 Q3).
 */
export default function MarketplaceProductsPage() {
  const products = useMarketplaceProducts();
  const [editing, setEditing] = useState<MarketplaceProduct | null>(null);
  const [creating, setCreating] = useState(false);

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          Everything you sell online. Each shop sets its own price and stock.
        </p>
        <Button onClick={() => setCreating(true)}>
          <Plus className="size-4" />
          Add product
        </Button>
      </div>

      {products.isPending ? (
        <ScreenLoader />
      ) : products.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertDescription>
            {apiErrorMessage(products.error, 'Could not load your products.')}
          </AlertDescription>
        </Alert>
      ) : products.data.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            No products yet. Add one to start selling.
          </CardContent>
        </Card>
      ) : (
        <div className="flex flex-col gap-3">
          {products.data.data.map((product) => (
            <ProductCard
              key={product.id}
              product={product}
              onEdit={() => setEditing(product)}
            />
          ))}
        </div>
      )}

      <ProductDialog
        key={editing?.id ?? 'new'}
        product={editing}
        open={creating || editing !== null}
        onOpenChange={(open) => {
          if (!open) {
            setCreating(false);
            setEditing(null);
          }
        }}
      />
    </div>
  );
}

function ProductCard({
  product,
  onEdit,
}: {
  product: MarketplaceProduct;
  onEdit: () => void;
}) {
  const { t } = useTranslation();
  const branches = useBranches();
  const archive = useArchiveProduct();

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 py-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{product.name}</span>
              {product.archived ? (
                <Badge variant="secondary" appearance="light" size="sm">
                  Archived
                </Badge>
              ) : null}
              {product.marketplace_category ? (
                <Badge variant="secondary" appearance="ghost" size="sm">
                  {product.marketplace_category.name_en}
                </Badge>
              ) : null}
              {/* What it earns, when that is not simply the standing rate.
                  An excluded product is the one a shopkeeper most needs to
                  spot at a glance. */}
              {product.cashback_category ? (
                <Badge
                  variant={
                    product.cashback_category.mode === 'excluded'
                      ? 'destructive'
                      : 'info'
                  }
                  appearance="light"
                  size="sm"
                >
                  {product.cashback_category.mode === 'excluded'
                    ? `${product.cashback_category.name_en} — no cashback`
                    : `${product.cashback_category.name_en} — ${product.cashback_category.rate_percent ?? ''}%`}
                </Badge>
              ) : null}
            </div>
            <p className="text-sm text-muted-foreground">
              {product.sku ?? 'No SKU'}
              {product.cashback_rate_percent
                ? ` · ${product.cashback_rate_percent}% cashback`
                : ''}
            </p>
          </div>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={onEdit}>
              Edit
            </Button>
            {!product.archived ? (
              <Button
                size="sm"
                variant="ghost"
                onClick={() =>
                  archive.mutate(product.id, {
                    onSuccess: () => toast.success('Archived and taken off every shelf.'),
                    onError: (error) =>
                      toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
                  })
                }
              >
                Archive
              </Button>
            ) : null}
          </div>
        </div>

        <Separator />

        {/* Stock is physical: one row per shop, because a chain's two shops
            genuinely differ on price and on what is on the shelf. */}
        <div className="flex flex-col gap-2">
          {(branches.data ?? []).map((branch) => (
            <ListingRow
              key={branch.id}
              productId={product.id}
              branchId={branch.id}
              branchName={branch.name}
              listing={product.listings.find((row) => row.branch_id === branch.id)}
            />
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

function ListingRow({
  productId,
  branchId,
  branchName,
  listing,
}: {
  productId: number;
  branchId: number;
  branchName: string;
  listing?: MarketplaceProduct['listings'][number];
}) {
  const { t } = useTranslation();
  const save = useSetListing();
  const [price, setPrice] = useState(() =>
    listing ? (listing.price_laari / 100).toFixed(2) : '',
  );
  const [stock, setStock] = useState(() => listing?.stock_qty?.toString() ?? '');

  return (
    <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border p-3">
      <div className="min-w-32 grow">
        <p className="text-sm font-medium">{branchName}</p>
        {listing ? (
          <div className="flex items-center gap-2">
            <Badge
              variant={listing.buyable ? 'success' : 'secondary'}
              appearance="light"
              size="sm"
            >
              {listing.state.replace(/_/g, ' ')}
            </Badge>
            {listing.low_stock ? (
              <Badge variant="warning" appearance="light" size="sm">
                Low stock
              </Badge>
            ) : null}
          </div>
        ) : (
          <p className="text-xs text-muted-foreground">Not stocked here</p>
        )}
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor={`price-${productId}-${branchId}`} className="text-xs">
          Price
        </Label>
        <Input
          id={`price-${productId}-${branchId}`}
          className="w-28"
          inputMode="decimal"
          placeholder="0.00"
          value={price}
          onChange={(event) => setPrice(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-1">
        <Label htmlFor={`stock-${productId}-${branchId}`} className="text-xs">
          Stock
        </Label>
        <Input
          id={`stock-${productId}-${branchId}`}
          className="w-24"
          inputMode="numeric"
          placeholder="untracked"
          value={stock}
          onChange={(event) => setStock(event.target.value)}
        />
      </div>

      <Button
        size="sm"
        variant="outline"
        disabled={save.isPending || price.trim() === ''}
        onClick={() => {
          const laari = Math.round(Number(price) * 100);

          if (!Number.isFinite(laari) || laari < 0) {
            toast.error('Enter a price, e.g. 89.00.');
            return;
          }

          save.mutate(
            {
              productId,
              body: {
                branch_id: branchId,
                price_laari: laari,
                // Empty means UNTRACKED, which is availability — a café does
                // not count cappuccinos. Zero is the opposite statement.
                stock_qty: stock.trim() === '' ? null : Number(stock),
                state: 'active',
              },
            },
            {
              onSuccess: () => toast.success(`${branchName} updated.`),
              onError: (error) =>
                toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
            },
          );
        }}
      >
        {listing ? 'Update' : 'Stock it'}
      </Button>

      {listing?.buyable ? (
        <Button
          size="sm"
          variant="ghost"
          onClick={() =>
            save.mutate({
              productId,
              body: {
                branch_id: branchId,
                price_laari: listing.price_laari,
                stock_qty: listing.stock_qty,
                state: 'out_of_stock',
              },
            })
          }
        >
          Mark out of stock
        </Button>
      ) : null}
    </div>
  );
}

function ProductDialog({
  product,
  open,
  onOpenChange,
}: {
  product: MarketplaceProduct | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const categories = useMarketplaceCategories();
  const save = useSaveProduct();

  const [form, setForm] = useState({
    name: product?.name ?? '',
    name_dv: product?.name_dv ?? '',
    description: product?.description ?? '',
    sku: product?.sku ?? '',
    marketplace_category_id: product?.marketplace_category?.id?.toString() ?? '',
    cashback_category_id: product?.cashback_category?.id?.toString() ?? '',
  });

  const live = (product?.listings ?? []).some((row) => row.state === 'active');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{product ? 'Edit product' : 'Add product'}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          {live ? (
            <Alert variant="info" appearance="light" size="sm">
              <AlertDescription>
                This product is on a shelf, so a change to its name, words or
                pictures waits for Manfaa&apos;s review. Price and stock are
                yours to change instantly, on the card behind this dialog.
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor="product-name">Name</Label>
            <Input
              id="product-name"
              value={form.name}
              onChange={(event) => setForm({ ...form, name: event.target.value })}
            />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="product-name-dv">Name in Dhivehi (optional)</Label>
            <Input
              id="product-name-dv"
              dir="rtl"
              value={form.name_dv}
              onChange={(event) => setForm({ ...form, name_dv: event.target.value })}
            />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="product-category">Shelf</Label>
            <select
              id="product-category"
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
              value={form.marketplace_category_id}
              onChange={(event) =>
                setForm({ ...form, marketplace_category_id: event.target.value })
              }
            >
              <option value="">Uncategorised</option>
              {(categories.data?.data.marketplace ?? []).map((aisle) => (
                <option key={aisle.id} value={aisle.id}>
                  {aisle.name_en}
                </option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">
              Where shoppers find it while browsing. The same shelves across
              every store, so a search for rice reaches yours.
            </p>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="product-cashback-category">
              Cashback category (optional)
            </Label>
            <select
              id="product-cashback-category"
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
              value={form.cashback_category_id}
              onChange={(event) =>
                setForm({ ...form, cashback_category_id: event.target.value })
              }
            >
              <option value="">Everything else — your standing rate</option>
              {(categories.data?.data.cashback ?? []).map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name_en}
                  {category.mode === 'excluded'
                    ? ' — earns nothing'
                    : category.rate_percent
                      ? ` — ${category.rate_percent}%`
                      : ''}
                </option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">
              {/* The whole point of the second picker: your own rules, and
                  the same ones your counter uses. */}
              Your own categories, the same ones that price your in-store
              sales. Leave it alone and this product earns your standing
              rate.
            </p>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="product-sku">SKU (optional)</Label>
            <Input
              id="product-sku"
              value={form.sku}
              onChange={(event) => setForm({ ...form, sku: event.target.value })}
            />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="product-description">Description (optional)</Label>
            <Input
              id="product-description"
              value={form.description}
              onChange={(event) => setForm({ ...form, description: event.target.value })}
            />
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            disabled={form.name.trim() === '' || save.isPending}
            onClick={() =>
              save.mutate(
                {
                  id: product?.id,
                  body: {
                    name: form.name.trim(),
                    name_dv: form.name_dv.trim() || null,
                    description: form.description.trim() || null,
                    sku: form.sku.trim() || null,
                    marketplace_category_id:
                      form.marketplace_category_id === ''
                        ? null
                        : Number(form.marketplace_category_id),
                    // Null on purpose when blank: that IS the default
                    // "everything else" bucket, not a missing answer.
                    cashback_category_id:
                      form.cashback_category_id === ''
                        ? null
                        : Number(form.cashback_category_id),
                  },
                },
                {
                  onSuccess: (result) => {
                    const queued =
                      typeof result === 'object' &&
                      result !== null &&
                      'data' in result &&
                      typeof result.data === 'object' &&
                      result.data !== null &&
                      'change_request' in result.data;

                    toast.success(
                      queued
                        ? 'Sent for Manfaa’s review. Customers keep seeing the current details until it is approved.'
                        : 'Saved.',
                    );
                    onOpenChange(false);
                  },
                  onError: (error) =>
                    toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
                },
              )
            }
          >
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
