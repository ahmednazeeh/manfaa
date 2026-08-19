'use client';

import { getWallet, requestWithdrawal } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/components/app/async-states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The wallet (PLAN-marketplace.md §21).
 *
 * A real balance beside the derived cashback figure. Refunds land here the
 * moment a shop cuts or refuses an order, and it is always withdrawable.
 */
export default function WalletPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  const wallet = useQuery({
    queryKey: ['wallet'],
    queryFn: ({ signal }) => getWallet({ signal }),
    retry: false,
  });

  const withdraw = useMutation({
    mutationFn: (amount: number) => requestWithdrawal(amount),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['wallet'] });
      toast.success(t('wallet.requested'));
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Something went wrong.')),
  });

  if (wallet.isPending) return <Skeleton className="h-96 w-full" />;

  const data = wallet.data?.data;
  if (!data) return null;

  return (
    <div className="flex flex-col gap-5">
      <Card>
        <CardHeader>
          <CardTitle>{t('wallet.balance')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <p className="text-3xl font-semibold">
            {formatMoney(data.balance_laari)}
          </p>

          {!data.has_bank_account ? (
            <p className="text-sm text-amber-600">{t('wallet.noBank')}</p>
          ) : !data.can_withdraw ? (
            <p className="text-sm text-muted-foreground">
              {t('wallet.minimum', {
                amount: formatMoney(data.minimum_withdrawal_laari),
              })}
            </p>
          ) : (
            <Button
              className="w-fit"
              disabled={withdraw.isPending}
              onClick={() => withdraw.mutate(data.balance_laari)}
            >
              {t('wallet.withdraw')}
            </Button>
          )}
        </CardContent>
      </Card>

      {data.withdrawals.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>{t('wallet.withdrawals')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            {data.withdrawals.map((row) => (
              <div key={row.id} className="flex items-center justify-between gap-3">
                <div>
                  <Badge variant="secondary" appearance="light" size="sm">
                    {row.state.replace(/_/g, ' ')}
                  </Badge>
                  {/* Only the BANK's reference — an approval-queue id shown
                      here would be quoted at a bank and get nowhere. */}
                  {row.bank_reference ? (
                    <p className="text-xs text-muted-foreground">
                      {row.bank_reference}
                    </p>
                  ) : null}
                </div>
                <span>{formatMoney(row.amount_laari)}</span>
              </div>
            ))}
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>{t('wallet.history')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {data.entries.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('wallet.empty')}</p>
          ) : (
            data.entries.map((entry) => (
              <div key={entry.id} className="flex items-center gap-3">
                {entry.amount_laari >= 0 ? (
                  <ArrowDownLeft className="size-4 text-emerald-600" />
                ) : (
                  <ArrowUpRight className="size-4 text-muted-foreground" />
                )}
                <span className="min-w-0 flex-1 truncate text-sm">
                  {entry.description ?? entry.type.replace(/_/g, ' ')}
                </span>
                <span
                  className={
                    entry.amount_laari >= 0 ? 'text-emerald-600' : undefined
                  }
                >
                  {entry.amount_laari >= 0 ? '+' : '−'}{' '}
                  {formatMoney(Math.abs(entry.amount_laari))}
                </span>
              </div>
            ))
          )}
        </CardContent>
      </Card>
    </div>
  );
}
