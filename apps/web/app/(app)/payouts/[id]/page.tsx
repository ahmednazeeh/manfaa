'use client';

import { use } from 'react';
import Link from 'next/link';
import { MoneyText } from '@manfaa/ui';
import { ArrowLeft, Store } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { usePayout } from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { useStoreName } from '@/components/app/store-labels';

/**
 * One payout, opened onto the purchases it covered — the screen a customer
 * reaches from a line on their bank statement, so the reference and the
 * masked account are as prominent as the amount.
 *
 * Each purchase links to its store, because "which shop was that?" is the
 * actual question behind opening this page.
 */
export default function PayoutDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const { t } = useTranslation();
  const storeName = useStoreName();
  const { data: payout, isPending, error } = usePayout(Number(id));

  return (
    <div className="flex flex-col gap-5">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('payouts.detailTitle')}</ToolbarPageTitle>
          <ToolbarDescription>{t('payouts.detailSubtitle')}</ToolbarDescription>
        </ToolbarHeading>
        <Button variant="outline" size="sm" asChild>
          <Link href="/payouts">
            <ArrowLeft className="rtl:rotate-180" />
            {t('payouts.backToPayouts')}
          </Link>
        </Button>
      </Toolbar>

      {isPending ? (
        <LoadingBlock lines={5} />
      ) : error ? (
        <ErrorBlock error={error} />
      ) : (
        <>
          <Card>
            <CardContent className="flex flex-col gap-4 py-5">
              <div className="flex flex-wrap items-center gap-3">
                <MoneyText
                  laari={payout.amount_laari}
                  currency={payout.currency}
                  className="text-3xl font-bold tracking-tight text-mono"
                />
                <Badge
                  variant={
                    payout.status === 'paid'
                      ? 'success'
                      : payout.status === 'sent'
                        ? 'warning'
                        : 'destructive'
                  }
                  appearance="light"
                >
                  {t(`payouts.status.${payout.status}`)}
                </Badge>
              </div>

              <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                {payout.period_start !== null && payout.period_end !== null && (
                  <div className="flex justify-between gap-3 sm:block">
                    <dt className="text-muted-foreground">
                      {t('payouts.periodLabel')}
                    </dt>
                    <dd className="text-mono">
                      {t('payouts.forPeriod', {
                        from: formatDate(payout.period_start),
                        to: formatDate(payout.period_end),
                      })}
                    </dd>
                  </div>
                )}
                {payout.reference !== null && (
                  <div className="flex justify-between gap-3 sm:block">
                    <dt className="text-muted-foreground">
                      {t('payouts.referenceLabel')}
                    </dt>
                    <dd className="text-mono" dir="ltr">
                      {payout.reference}
                    </dd>
                  </div>
                )}
                {payout.account_masked !== null && (
                  <div className="flex justify-between gap-3 sm:block">
                    <dt className="text-muted-foreground">
                      {t('payouts.accountLabel')}
                    </dt>
                    <dd className="text-mono" dir="ltr">
                      {payout.bank !== null && `${payout.bank} `}
                      {payout.account_masked}
                    </dd>
                  </div>
                )}
                {payout.paid_at !== null && (
                  <div className="flex justify-between gap-3 sm:block">
                    <dt className="text-muted-foreground">
                      {t('payouts.paidAtLabel')}
                    </dt>
                    <dd className="text-mono">{formatDate(payout.paid_at)}</dd>
                  </div>
                )}
              </dl>

              {payout.status === 'failed' && (
                <p className="text-sm text-destructive">
                  {t('payouts.failedDetail')}
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>
                {t('payouts.includedTitle', {
                  count: payout.transactions.length,
                })}
              </CardTitle>
            </CardHeader>
            <CardContent className="divide-y divide-border py-0">
              {payout.transactions.map((line) => (
                <div
                  key={line.id}
                  className="flex flex-wrap items-center justify-between gap-3 py-4"
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary text-secondary-foreground">
                      <Store className="size-4" />
                    </span>
                    <div className="flex min-w-0 flex-col">
                      <Link
                        href={`/store/${line.merchant.slug}`}
                        className="truncate text-sm font-medium text-mono hover:underline"
                      >
                        {storeName(line.merchant)}
                      </Link>
                      <span className="text-xs text-muted-foreground">
                        {formatDate(line.occurred_at)}
                        {line.invoice_no !== null && (
                          <>
                            {' · '}
                            {t('transactions.invoice', {
                              invoiceNo: line.invoice_no,
                            })}
                          </>
                        )}
                      </span>
                    </div>
                  </div>

                  <MoneyText
                    laari={line.cashback_laari}
                    currency={payout.currency}
                    className="text-sm font-semibold text-primary"
                  />
                </div>
              ))}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
