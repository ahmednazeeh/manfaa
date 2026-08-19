'use client';

import { use, useState } from 'react';
import { getCustomerOrder } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Upload } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';

/** One order, with its shops and the receipt that confirms it. */
export default function OrderPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const orderId = Number(id);
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [uploading, setUploading] = useState(false);

  const order = useQuery({
    queryKey: ['orders', orderId],
    queryFn: ({ signal }) => getCustomerOrder(orderId, { signal }),
  });

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData();
      body.append('receipt', file);

      const response = await fetch(`/api/customer/orders/${orderId}/receipt`, {
        method: 'POST',
        body,
        credentials: 'include',
      });

      if (!response.ok) {
        const json = await response.json().catch(() => ({}));
        throw new Error(json.message ?? 'That receipt could not be uploaded.');
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['orders'] });
      toast.success(t('orders.receiptUploaded'));
    },
    onError: (error) =>
      toast.error(error instanceof Error ? error.message : 'Upload failed.'),
  });

  if (order.isPending) return <Skeleton className="h-96 w-full" />;

  const data = order.data?.data;
  if (!data) return null;

  const needsReceipt =
    data.payment_state === 'awaiting_proof' || data.payment_state === 'refused';

  return (
    <div className="flex flex-col gap-5">
      <Card>
        <CardHeader>
          <CardTitle>{data.reference}</CardTitle>
          <Badge
            variant={data.payment_state === 'verified' ? 'success' : 'warning'}
            appearance="light"
          >
            {data.payment_state.replace(/_/g, ' ')}
          </Badge>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          <div className="flex justify-between">
            <span className="text-sm text-muted-foreground">
              {t('cart.totalPayable')}
            </span>
            <span className="font-semibold">
              {formatMoney(data.total_payable_laari)}
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-sm text-muted-foreground">
              {t('checkout.cashbackAfterValidation')}
            </span>
            <span className="text-emerald-600">
              {formatMoney(data.cashback_total_laari)}
            </span>
          </div>

          {needsReceipt ? (
            <>
              <Separator />
              <Alert appearance="light" size="sm">
                <AlertDescription>{t('checkout.receiptFirst')}</AlertDescription>
              </Alert>
              <Button asChild disabled={uploading || upload.isPending}>
                <label className="cursor-pointer">
                  <Upload className="size-4" />
                  {upload.isPending ? '…' : t('orders.uploadReceipt')}
                  <input
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                    className="hidden"
                    onChange={(event) => {
                      const file = event.target.files?.[0];
                      if (file) upload.mutate(file);
                      event.target.value = '';
                    }}
                  />
                </label>
              </Button>
            </>
          ) : null}
        </CardContent>
      </Card>

      {data.suborders.map((subo) => {
        const refunded = subo.items.reduce((sum, item) => sum + item.refund_laari, 0);

        return (
          <Card key={subo.id}>
            <CardHeader>
              <CardTitle>
                {subo.store_name} — {subo.branch_name}
              </CardTitle>
              <Badge variant="secondary" appearance="light">
                {subo.state.replace(/_/g, ' ')}
              </Badge>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              {subo.pickup_code ? (
                <p className="text-sm">
                  {t('orders.pickupCode')}{' '}
                  <strong className="tracking-widest">{subo.pickup_code}</strong>
                </p>
              ) : null}

              {subo.reject_reason ? (
                <Alert variant="warning" appearance="light" size="sm">
                  <AlertDescription>{subo.reject_reason}</AlertDescription>
                </Alert>
              ) : null}

              {/* The shop cut this order — named, with the refund. */}
              {refunded > 0 ? (
                <Alert variant="warning" appearance="light" size="sm">
                  <AlertDescription>
                    {t('orders.refunded', {
                      store: subo.store_name ?? '',
                      amount: formatMoney(refunded),
                    })}
                  </AlertDescription>
                </Alert>
              ) : null}

              <div className="flex flex-col gap-1">
                {subo.items.map((item) => (
                  <div
                    key={item.id}
                    className="flex items-center justify-between gap-3 text-sm"
                  >
                    {/* A removed line stays visible and struck: it was
                        ordered, and a row that vanishes reads as a bug. */}
                    <span
                      className={
                        item.fulfilled_qty === 0
                          ? 'text-muted-foreground line-through'
                          : ''
                      }
                    >
                      {item.name}
                    </span>
                    <span className="flex items-center gap-2">
                      {item.amended ? (
                        <span className="text-muted-foreground line-through">
                          ×{item.qty}
                        </span>
                      ) : null}
                      <span>×{item.fulfilled_qty}</span>
                      <span className="w-24 text-end">
                        {formatMoney(item.line_total_laari)}
                      </span>
                    </span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
