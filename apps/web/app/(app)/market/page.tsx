'use client';

import Link from 'next/link';
import { listMarketBranches, type MarketBranch } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { Bike, Clock, ShoppingBasket, Star, Store } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The Market (`Market View.png`), on the web.
 *
 * Lists BRANCHES: the branch is the storefront, because stock and fulfilment
 * are physical and a chain's two shops genuinely differ. A card reads brand
 * and island, and a shop that cannot reach your address is labelled
 * pickup-only rather than hidden.
 */
export default function MarketPage() {
  const { t } = useTranslation();

  const branches = useQuery({
    queryKey: ['market', 'branches'],
    queryFn: ({ signal }) => listMarketBranches(undefined, { signal }),
    // With the marketplace off, every route 404s — the page simply shows its
    // empty state rather than an error nobody can act on.
    retry: false,
  });

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-2xl font-semibold">{t('market.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('market.subtitle')}</p>
      </div>

      {branches.isPending ? (
        <div className="grid gap-4 sm:grid-cols-2">
          <Skeleton className="h-36 w-full" />
          <Skeleton className="h-36 w-full" />
        </div>
      ) : branches.isError || branches.data.data.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center gap-2 py-16 text-center">
            <Store className="size-8 text-muted-foreground" />
            <p className="font-medium">{t('market.empty')}</p>
            <p className="max-w-sm text-sm text-muted-foreground">
              {t('market.emptyHint')}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {branches.data.data.map((branch) => (
            <StoreCard key={branch.branch_id} branch={branch} />
          ))}
        </div>
      )}
    </div>
  );
}

function StoreCard({ branch }: { branch: MarketBranch }) {
  const { t } = useTranslation();

  return (
    <Link href={`/market/${branch.branch_id}`}>
      <Card className="h-full transition-colors hover:border-primary">
        <CardContent className="flex flex-col gap-3 py-5">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate font-medium">{branch.store_name}</p>
              {/* Brand AND island — never a bare branch id. */}
              <p className="text-sm text-muted-foreground">{branch.branch_name}</p>
            </div>
            {branch.cashback_rate_percent ? (
              <Badge variant="success" appearance="light" size="sm">
                {t('market.cashback', { rate: branch.cashback_rate_percent })}
              </Badge>
            ) : null}
          </div>

          <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {/* A shop nobody has rated shows nothing here: zero stars would
                libel it on its first day. */}
            {branch.rating !== null ? (
              <span className="inline-flex items-center gap-1">
                <Star className="size-3.5" />
                {branch.rating} ({branch.rating_count})
              </span>
            ) : null}
            {branch.delivery.eta_min !== null ? (
              <span className="inline-flex items-center gap-1">
                <Clock className="size-3.5" />
                {branch.delivery.eta_min}
                {branch.delivery.eta_max ? `–${branch.delivery.eta_max}` : ''} min
              </span>
            ) : null}
            {branch.pickup_only ? (
              <span className="inline-flex items-center gap-1">
                <Store className="size-3.5" />
                {t('market.pickupOnly')}
              </span>
            ) : (
              <>
                <span className="inline-flex items-center gap-1">
                  <Bike className="size-3.5" />
                  {branch.delivery.fee_laari === 0
                    ? t('market.freeDelivery')
                    : formatMoney(branch.delivery.fee_laari)}
                </span>
                {branch.delivery.order_minimum_laari !== null ? (
                  <span className="inline-flex items-center gap-1">
                    <ShoppingBasket className="size-3.5" />
                    {t('market.minOrder', {
                      amount: formatMoney(branch.delivery.order_minimum_laari),
                    })}
                  </span>
                ) : null}
              </>
            )}
          </div>
        </CardContent>
      </Card>
    </Link>
  );
}
