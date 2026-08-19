'use client';

import Link from 'next/link';
import { listCustomerOrders } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { PackageOpen } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Order tracking (`Customer App Order Tracking.png`), on the web.
 *
 * A multi-vendor order lists its SHOPS: across three stores the shops are
 * the status, and one summary word would hide that two are confirmed and one
 * is not.
 */
export default function OrdersPage() {
  const { t } = useTranslation();

  const orders = useQuery({
    queryKey: ['orders'],
    queryFn: ({ signal }) => listCustomerOrders({ signal }),
    retry: false,
  });

  if (orders.isPending) return <Skeleton className="h-96 w-full" />;

  if (orders.isError || orders.data.data.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center gap-2 py-16 text-center">
          <PackageOpen className="size-8 text-muted-foreground" />
          <p className="font-medium">{t('orders.empty')}</p>
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <h1 className="text-2xl font-semibold">{t('orders.title')}</h1>

      {orders.data.data.map((order) => (
        <Link key={order.id} href={`/orders/${order.id}`}>
          <Card className="transition-colors hover:border-primary">
            <CardContent className="flex flex-col gap-3 py-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-medium">{order.reference}</p>
                  <p className="text-sm text-muted-foreground">
                    {t('orders.storeCount', { count: order.store_count })}
                  </p>
                </div>
                <div className="text-end">
                  <p className="font-semibold">
                    {formatMoney(order.total_payable_laari)}
                  </p>
                  <Badge
                    variant={
                      order.payment_state === 'verified' ? 'success' : 'warning'
                    }
                    appearance="light"
                    size="sm"
                  >
                    {order.payment_state.replace(/_/g, ' ')}
                  </Badge>
                </div>
              </div>

              <div className="flex flex-col gap-1">
                {order.suborders.map((subo) => (
                  <div
                    key={subo.id}
                    className="flex items-center justify-between gap-3 text-sm"
                  >
                    <span>
                      {subo.store_name} — {subo.branch_name}
                    </span>
                    <Badge variant="secondary" appearance="light" size="sm">
                      {subo.state.replace(/_/g, ' ')}
                    </Badge>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </Link>
      ))}
    </div>
  );
}
