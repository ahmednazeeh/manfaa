'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  getCart,
  listAddresses,
  listPaymentAccounts,
  placeOrder,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/components/app/async-states';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Checkout (`Payment Step.png`), on the web.
 *
 * Receipt-first: the exact amount, our account, and the plain sentence that
 * the order is confirmed once Manfaa verifies the transfer.
 */
export default function CheckoutPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [addressId, setAddressId] = useState<number | null>(null);
  const [method, setMethod] = useState('bml');

  const cart = useQuery({
    queryKey: ['cart'],
    queryFn: ({ signal }) => getCart(undefined, { signal }),
  });
  const addresses = useQuery({
    queryKey: ['addresses'],
    queryFn: ({ signal }) => listAddresses({ signal }),
  });
  const accounts = useQuery({
    queryKey: ['payment-accounts'],
    queryFn: ({ signal }) => listPaymentAccounts({ signal }),
  });

  const place = useMutation({
    mutationFn: () => placeOrder({ payment_method: method, address_id: addressId }),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['cart'] });
      router.push(`/orders/${result.data.id}`);
    },
    // Refusals name the shop that is blocking, which is something a shopper
    // can act on.
    onError: (error) => toast.error(apiErrorMessage(error, 'Something went wrong.')),
  });

  const data = cart.data?.data;

  if (cart.isPending) return <Skeleton className="h-96 w-full" />;
  if (!data || data.subcarts.length === 0) {
    return <p className="text-sm text-muted-foreground">{t('cart.empty')}</p>;
  }

  return (
    <div className="flex flex-col gap-5">
      <h1 className="text-2xl font-semibold">{t('checkout.title')}</h1>

      <Card>
        <CardHeader>
          <CardTitle>{t('checkout.deliveryAddress')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {(addresses.data?.data ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('checkout.noAddresses')}
            </p>
          ) : (
            (addresses.data?.data ?? []).map((address) => (
              <label
                key={address.id}
                className="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3"
              >
                <input
                  type="radio"
                  name="address"
                  className="mt-1"
                  checked={
                    (addressId ??
                      addresses.data!.data.find((row) => row.is_default)?.id) ===
                    address.id
                  }
                  onChange={() => setAddressId(address.id)}
                />
                <span className="min-w-0">
                  <span className="block font-medium">{address.label}</span>
                  <span className="block text-sm text-muted-foreground">
                    {address.recipient_name} · {address.phone}
                  </span>
                  <span className="block text-sm text-muted-foreground">
                    {[address.building, address.area_magu, address.island]
                      .filter(Boolean)
                      .join(', ')}
                  </span>
                  {/* Honest rather than a surprise at the door. */}
                  {address.zone_id === null ? (
                    <span className="block text-xs text-amber-600">
                      {t('checkout.addressNotServed')}
                    </span>
                  ) : null}
                </span>
              </label>
            ))
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('checkout.orderSummary')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {data.subcarts.map((subcart) => (
            <div key={subcart.branch_id} className="flex justify-between gap-3 text-sm">
              <span>
                {subcart.store_name} — {subcart.branch_name}
              </span>
              <span>{formatMoney(subcart.items_laari + subcart.delivery.fee_laari)}</span>
            </div>
          ))}
          <Separator />
          <div className="flex justify-between gap-3">
            <span className="font-medium">{t('cart.totalPayable')}</span>
            <span className="text-lg font-semibold">
              {formatMoney(data.total_payable_laari)}
            </span>
          </div>
          <div className="flex justify-between gap-3 text-sm">
            <span className="text-muted-foreground">
              {t('checkout.cashbackAfterValidation')}
            </span>
            <span className="text-emerald-600">
              {formatMoney(data.cashback_laari)}
            </span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('checkout.paymentMethod')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {(accounts.data?.data ?? []).map((account) => (
            <label
              key={account.id}
              className="flex cursor-pointer flex-col gap-2 rounded-lg border border-border p-3"
            >
              <span className="flex items-center gap-2">
                <input
                  type="radio"
                  name="method"
                  checked={
                    method ===
                    (account.bank_name.toLowerCase().includes('mib') ? 'mib' : 'bml')
                  }
                  onChange={() =>
                    setMethod(
                      account.bank_name.toLowerCase().includes('mib') ? 'mib' : 'bml',
                    )
                  }
                />
                <span className="font-medium">{account.bank_name}</span>
              </span>
              <span className="grid gap-1 text-sm">
                <span className="flex justify-between">
                  <span className="text-muted-foreground">
                    {t('checkout.transferExactly')}
                  </span>
                  <span className="font-medium">
                    {formatMoney(data.total_payable_laari)}
                  </span>
                </span>
                <span className="flex justify-between">
                  <span className="text-muted-foreground">
                    {t('checkout.accountName')}
                  </span>
                  <span>{account.account_name}</span>
                </span>
                <span className="flex justify-between">
                  <span className="text-muted-foreground">
                    {t('checkout.accountNumber')}
                  </span>
                  <span className="font-mono">{account.account_no}</span>
                </span>
              </span>
            </label>
          ))}

          <Alert appearance="light" size="sm">
            <AlertDescription>{t('checkout.receiptFirst')}</AlertDescription>
          </Alert>

          <Button
            disabled={!data.can_checkout || place.isPending}
            onClick={() => place.mutate()}
          >
            {place.isPending ? t('checkout.placing') : t('checkout.placeOrder')}
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
