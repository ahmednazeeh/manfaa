'use client';

import { use, useState } from 'react';
import {
  addToCart,
  getCart,
  getMarketStore,
  setCartQty,
  type MarketProduct,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Minus, Package, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/components/app/async-states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { FloatingCart } from '@/components/market/floating-cart';

/** One shop's shelves. */
export default function StorePage({
  params,
}: {
  params: Promise<{ branchId: string }>;
}) {
  const { branchId } = use(params);
  const id = Number(branchId);
  const { t } = useTranslation();
  const [category, setCategory] = useState<string | undefined>();

  const store = useQuery({
    queryKey: ['market', 'store', id, category],
    queryFn: ({ signal }) => getMarketStore(id, { category }, { signal }),
  });

  const data = store.data?.data;

  return (
    <div className="flex flex-col gap-5 pb-24">
      {store.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : data ? (
        <>
          <Card>
            <CardContent className="flex flex-col gap-2 py-5">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h1 className="text-xl font-semibold">{data.store_name}</h1>
                  <p className="text-sm text-muted-foreground">
                    {data.branch_name}
                    {data.address ? ` · ${data.address}` : ''}
                  </p>
                </div>
                {data.cashback_rate_percent ? (
                  <Badge variant="success" appearance="light">
                    {t('market.cashback', { rate: data.cashback_rate_percent })}
                  </Badge>
                ) : null}
              </div>

              {!data.delivery.delivers ? (
                <p className="text-sm text-warning">
                  {t('market.noDeliveryHere')}
                </p>
              ) : (
                <p className="text-sm text-muted-foreground">
                  {data.delivery.fee_laari === 0
                    ? t('market.freeDelivery')
                    : t('market.deliveryFee', {
                        amount: formatMoney(data.delivery.fee_laari),
                      })}
                  {data.delivery.order_minimum_laari !== null
                    ? ` · ${t('market.minOrder', {
                        amount: formatMoney(data.delivery.order_minimum_laari),
                      })}`
                    : ''}
                </p>
              )}
            </CardContent>
          </Card>

          {data.categories.length > 0 ? (
            <div className="flex flex-wrap gap-2">
              <Button
                size="sm"
                variant={category === undefined ? 'primary' : 'outline'}
                onClick={() => setCategory(undefined)}
              >
                {t('market.allProducts')}
              </Button>
              {/* Only the aisles this shop stocks — an empty chip is a
                  promise the shelf cannot keep. */}
              {data.categories.map((aisle) => (
                <Button
                  key={aisle.slug}
                  size="sm"
                  variant={category === aisle.slug ? 'primary' : 'outline'}
                  onClick={() => setCategory(aisle.slug)}
                >
                  {aisle.name_en}
                </Button>
              ))}
            </div>
          ) : null}

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {data.products.map((product) => (
              <ProductCard key={product.branch_product_id} product={product} />
            ))}
          </div>
        </>
      ) : null}

      <FloatingCart branchId={id} />
    </div>
  );
}

function ProductCard({ product }: { product: MarketProduct }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  const cart = useQuery({
    queryKey: ['cart'],
    queryFn: ({ signal }) => getCart(undefined, { signal }),
  });

  const line = cart.data?.data.subcarts
    .flatMap((subcart) => subcart.items)
    .find((item) => item.branch_product_id === product.branch_product_id);

  const mutate = useMutation({
    mutationFn: (next: number) =>
      line === undefined
        ? addToCart(product.branch_product_id, 1)
        : setCartQty(line.cart_item_id, next),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['cart'] }),
    onError: (error) => toast.error(apiErrorMessage(error, 'Something went wrong.')),
  });

  return (
    <Card className="h-full">
      <CardContent className="flex h-full flex-col gap-2 py-4">
        <div className="flex h-28 items-center justify-center">
          {product.image_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={product.image_url}
              alt={product.name}
              className="max-h-28 object-contain"
            />
          ) : (
            <Package className="size-10 text-muted-foreground/40" />
          )}
        </div>
        <p className="line-clamp-2 text-sm">{product.name}</p>
        <div className="flex items-baseline gap-2">
          <span className="font-semibold">{formatMoney(product.price_laari)}</span>
          {product.compare_at_laari ? (
            <span className="text-xs text-muted-foreground line-through">
              {formatMoney(product.compare_at_laari)}
            </span>
          ) : null}
        </div>
        {product.cashback_rate_percent ? (
          <Badge variant="info" appearance="light" size="sm" className="w-fit">
            {t('market.cashback', { rate: product.cashback_rate_percent })}
          </Badge>
        ) : null}

        <div className="mt-auto pt-2">
          {!product.in_stock ? (
            <Button size="sm" variant="outline" className="w-full" disabled>
              {t('market.outOfStock')}
            </Button>
          ) : line === undefined ? (
            <Button
              size="sm"
              className="w-full"
              disabled={mutate.isPending}
              onClick={() => mutate.mutate(1)}
            >
              {t('market.add')}
            </Button>
          ) : (
            <div className="flex items-center justify-between rounded-md border border-input">
              <Button
                size="sm"
                variant="ghost"
                onClick={() => mutate.mutate(line.qty - 1)}
              >
                <Minus className="size-4" />
              </Button>
              <span className="text-sm font-medium">{line.qty}</span>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => mutate.mutate(line.qty + 1)}
              >
                <Plus className="size-4" />
              </Button>
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
