'use client';

import Link from 'next/link';
import { getCart } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { ShoppingCart } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';

/**
 * The floating cart bar (`Market View.png`), on the web.
 *
 * Disappears when the basket is empty — a permanent empty cart is a
 * permanent reminder of nothing. On a shop's page it scopes to THAT shop and
 * tracks THAT minimum, which is the number a shopper looking at those
 * shelves can actually close.
 */
export function FloatingCart({ branchId }: { branchId?: number }) {
  const { t } = useTranslation();

  const cart = useQuery({
    queryKey: ['cart'],
    queryFn: ({ signal }) => getCart(undefined, { signal }),
    retry: false,
  });

  const data = cart.data?.data;

  if (!data || data.subcarts.length === 0) {
    return null;
  }

  const focus =
    branchId === undefined
      ? undefined
      : data.subcarts.find((subcart) => subcart.branch_id === branchId);

  const count = focus
    ? focus.items.length
    : data.subcarts.reduce((sum, subcart) => sum + subcart.items.length, 0);

  if (count === 0) {
    return null;
  }

  const amount = focus ? focus.items_laari : data.items_laari;
  const cashback = focus ? focus.cashback_laari : data.cashback_laari;
  const minimum = focus?.delivery.order_minimum_laari ?? null;

  return (
    <div className="fixed inset-x-0 bottom-20 z-40 px-4 lg:bottom-6 lg:start-auto lg:end-6 lg:w-96 lg:px-0">
      <div className="flex items-center gap-3 rounded-xl bg-foreground p-3 text-background shadow-lg">
        <div className="relative">
          <ShoppingCart className="size-6" />
          <span className="absolute -end-2 -top-2 flex size-4 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">
            {count}
          </span>
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold">
            {count} · {formatMoney(amount)}
          </p>
          {cashback > 0 ? (
            <p className="truncate text-xs text-emerald-400">
              {t('market.earn', { amount: formatMoney(cashback) })}
            </p>
          ) : null}
          {minimum !== null && minimum > 0 ? (
            <div className="mt-1">
              <p className="truncate text-[11px]">
                {focus!.delivery.minimum_met
                  ? t('market.minimumMet')
                  : t('market.toMinimum', {
                      amount: formatMoney(focus!.delivery.shortfall_laari),
                    })}
              </p>
              <Progress
                value={Math.min(100, (amount / minimum) * 100)}
                className="mt-1 h-1"
              />
            </div>
          ) : null}
        </div>
        <Button size="sm" asChild>
          <Link href="/cart">{t('market.viewCart')}</Link>
        </Button>
      </div>
    </div>
  );
}
