'use client';

import { bankLabel, type WalletPendingTopUp } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import { useTranslation } from 'react-i18next';
import { walletTopUpStateLabel } from '@/lib/labels';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BankLogo } from '@/components/app/bank-select';

/**
 * Top-up claims still in flight: money the merchant has sent that is not
 * yet balance. The chip answers the question they came to ask — "did it
 * arrive?" — in three words or fewer, and a refused claim shows the reason
 * Manfaa gave, verbatim, when the payload carries one.
 */

const STATE_VARIANTS: Record<WalletPendingTopUp['state'], BadgeProps['variant']> =
  {
    pending: 'info',
    matched: 'success',
    rejected: 'destructive',
  };

export function PendingTopUps({ topUps }: { topUps: WalletPendingTopUp[] }) {
  const { t } = useTranslation();

  if (topUps.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('wallet.pending.title')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm text-secondary-foreground">
          {t('wallet.pending.lead')}
        </p>
        <ul className="flex flex-col divide-y divide-border">
          {topUps.map((topUp) => (
            <li
              key={topUp.id}
              className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
            >
              <BankLogo bank={topUp.bank?.bank_name} />
              <div className="flex min-w-0 grow flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-2">
                  <MoneyText
                    laari={topUp.amount_laari}
                    className="text-sm font-semibold text-mono"
                  />
                  <Badge
                    size="sm"
                    appearance="light"
                    variant={STATE_VARIANTS[topUp.state]}
                  >
                    {walletTopUpStateLabel(t, topUp.state)}
                  </Badge>
                </span>
                <span className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                  {topUp.bank ? (
                    <span className="flex items-center gap-1.5">
                      <span>{bankLabel(topUp.bank.bank_name)}</span>
                      <span dir="ltr">{topUp.bank.account_no}</span>
                    </span>
                  ) : (
                    <span>{t('wallet.pending.noBank')}</span>
                  )}
                  <span>
                    {t('wallet.pending.submitted', {
                      date: format(new Date(topUp.created_at), 'dd MMM yyyy, HH:mm'),
                    })}
                  </span>
                  {topUp.bank_ref !== null && (
                    <span dir="ltr" className="text-mono">
                      {topUp.bank_ref}
                    </span>
                  )}
                </span>
                {topUp.state === 'rejected' && (
                  <span className="text-xs text-destructive">
                    {t('wallet.pending.reason')}:{' '}
                    {topUp.rejected_reason ?? t('wallet.pending.noReason')}
                  </span>
                )}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
