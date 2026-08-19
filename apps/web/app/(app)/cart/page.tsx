'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { clearCart, getCart, setCartQty, type Subcart } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ChevronDown,
  ChevronUp,
  Minus,
  Plus,
  ShoppingCart,
  Trash2,
  TriangleAlert,
  Truck,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/components/app/async-states';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The multi-vendor cart (`Cart Page Collapsible By Merchant.png`).
 *
 * The owner's three requirements, all here: each shop's card is COLLAPSED by
 * default, a shop short of its minimum says exactly how short, and the order
 * summary expands.
 */
export default function CartPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);

  const cart = useQuery({
    queryKey: ['cart'],
    queryFn: ({ signal }) => getCart(undefined, { signal }),
    retry: false,
  });

  const empty = useMutation({
    mutationFn: () => clearCart(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['cart'] }),
  });

  const data = cart.data?.data;

  if (cart.isPending) return <Skeleton className="h-96 w-full" />;

  if (!data || data.subcarts.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center gap-2 py-16 text-center">
          <ShoppingCart className="size-8 text-muted-foreground" />
          <p className="font-medium">{t('cart.empty')}</p>
        </CardContent>
      </Card>
    );
  }

  const blocking = data.subcarts.filter(
    (subcart) => !subcart.delivery.minimum_met || !subcart.all_available,
  );

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">
          {t('cart.titleWithStores', { count: data.store_count })}
        </h1>
        <Button size="sm" variant="ghost" onClick={() => empty.mutate()}>
          {t('cart.clear')}
        </Button>
      </div>

      {data.cashback_laari > 0 ? (
        <Card className="border-emerald-500/30 bg-emerald-500/5">
          <CardContent className="flex items-center justify-between gap-3 py-4">
            <span className="text-sm">{t('cart.youWillEarn')}</span>
            <span className="text-lg font-semibold text-emerald-600">
              {formatMoney(data.cashback_laari)}
            </span>
          </CardContent>
        </Card>
      ) : null}

      {data.subcarts.map((subcart) => (
        <SubcartCard key={subcart.branch_id} subcart={subcart} />
      ))}

      {data.store_count > 1 ? (
        <Alert appearance="light" size="sm">
          <Truck className="size-4" />
          <AlertDescription>{t('cart.separateOrders')}</AlertDescription>
        </Alert>
      ) : null}

      <Card>
        <CardContent className="flex flex-col gap-3 py-4">
          {open ? (
            <>
              <Row label={t('cart.items')} value={data.items_laari} />
              <Row label={t('cart.delivery')} value={data.delivery_laari} />
              <Row
                label={t('cart.youWillEarn')}
                value={data.cashback_laari}
                tone="text-emerald-600"
              />
              <Separator />
            </>
          ) : null}

          <div className="flex items-center justify-between gap-3">
            <button
              className="flex items-center gap-1 text-start"
              onClick={() => setOpen(!open)}
            >
              <span>
                <span className="block text-xs text-muted-foreground">
                  {t('cart.totalPayable')}
                </span>
                <span className="text-xl font-semibold">
                  {formatMoney(data.total_payable_laari)}
                </span>
              </span>
              {open ? (
                <ChevronDown className="size-4 text-muted-foreground" />
              ) : (
                <ChevronUp className="size-4 text-muted-foreground" />
              )}
            </button>

            <Button
              disabled={!data.can_checkout}
              onClick={() => router.push('/checkout')}
            >
              {data.can_checkout
                ? t('cart.checkout', { count: data.store_count })
                : /* Names the shop rather than refusing silently. */
                  t('cart.blockedBy', { store: blocking[0]?.store_name ?? '' })}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function SubcartCard({ subcart }: { subcart: Subcart }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  // Collapsed by default (owner decision): the header alone answers "what am
  // I buying here and does it qualify".
  const [open, setOpen] = useState(false);

  const setQty = useMutation({
    mutationFn: ({ id, qty }: { id: number; qty: number }) => setCartQty(id, qty),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['cart'] }),
    onError: (error) => toast.error(apiErrorMessage(error, 'Something went wrong.')),
  });

  const short = !subcart.delivery.minimum_met;

  return (
    <Card>
      <CardContent className="flex flex-col gap-0 p-0">
        <button
          className="flex items-start justify-between gap-3 p-4 text-start"
          onClick={() => setOpen(!open)}
        >
          <div className="min-w-0">
            <p className="font-medium">
              {subcart.store_name} — {subcart.branch_name}
            </p>
            <p className="text-sm text-muted-foreground">
              {t('cart.itemsAnd', {
                count: subcart.items.length,
                amount: formatMoney(subcart.items_laari),
              })}
            </p>
            <Badge variant="secondary" appearance="light" size="sm" className="mt-1">
              {subcart.delivery.delivers ? t('cart.delivery') : t('cart.pickup')}
            </Badge>
          </div>
          <div className="flex items-center gap-2">
            {subcart.cashback_laari > 0 ? (
              <span className="text-sm text-emerald-600">
                {t('market.earn', { amount: formatMoney(subcart.cashback_laari) })}
              </span>
            ) : null}
            {open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
          </div>
        </button>

        {/* THE WARNING: exactly how short, with the running total beside it. */}
        {short ? (
          <div className="flex items-center justify-between gap-3 bg-amber-500/10 px-4 py-3 text-sm text-amber-700">
            <span className="inline-flex items-center gap-2">
              <TriangleAlert className="size-4" />
              {t('cart.addMore', {
                amount: formatMoney(subcart.delivery.shortfall_laari),
              })}
            </span>
            <span>
              {formatMoney(subcart.items_laari)} /{' '}
              {formatMoney(subcart.delivery.order_minimum_laari ?? 0)}
            </span>
          </div>
        ) : null}

        {!subcart.all_available ? (
          <div className="bg-amber-500/10 px-4 py-3 text-sm text-amber-700">
            {t('cart.unavailable')}
          </div>
        ) : null}

        {open ? (
          <>
            <Separator />
            <div className="flex flex-col gap-3 p-4">
              {subcart.items.map((line) => (
                <div key={line.cart_item_id} className="flex items-center gap-3">
                  <div className="min-w-0 flex-1">
                    <p
                      className={
                        line.available ? 'text-sm' : 'text-sm text-muted-foreground line-through'
                      }
                    >
                      {line.name}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {formatMoney(line.unit_price_laari)}
                      {/* Said out loud, never applied silently. */}
                      {line.price_changed && line.price_was_laari !== null ? (
                        <span className="ms-2 line-through">
                          {formatMoney(line.price_was_laari)}
                        </span>
                      ) : null}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setQty.mutate({ id: line.cart_item_id, qty: 0 })}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                  <div className="flex items-center rounded-md border border-input">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setQty.mutate({ id: line.cart_item_id, qty: line.qty - 1 })
                      }
                    >
                      <Minus className="size-3.5" />
                    </Button>
                    <span className="px-1 text-sm">{line.qty}</span>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setQty.mutate({ id: line.cart_item_id, qty: line.qty + 1 })
                      }
                    >
                      <Plus className="size-3.5" />
                    </Button>
                  </div>
                  <span className="w-24 text-end text-sm">
                    {formatMoney(line.line_total_laari)}
                  </span>
                </div>
              ))}

              <Separator />
              <Row label={t('cart.items')} value={subcart.items_laari} />
              <Row label={t('cart.delivery')} value={subcart.delivery.fee_laari} />
              {subcart.cashback_laari > 0 ? (
                <Row
                  label={t('cart.cashbackFrom', { store: subcart.store_name })}
                  value={subcart.cashback_laari}
                  tone="text-emerald-600"
                />
              ) : null}
            </div>
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}

function Row({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone?: string;
}) {
  return (
    <div className="flex items-center justify-between gap-3 text-sm">
      <span className={tone ?? 'text-muted-foreground'}>{label}</span>
      <span className={tone}>{formatMoney(value)}</span>
    </div>
  );
}
