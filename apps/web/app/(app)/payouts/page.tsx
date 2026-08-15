'use client';

import { useState } from 'react';
import Link from 'next/link';
import { type CustomerPayout } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { ChevronRight, Landmark } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { usePayouts } from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { ListPagination } from '@/components/app/list-pagination';

/**
 * Money that actually reached the customer's bank — distinct from the
 * dashboard's balance, which is money still owed to them.
 *
 * A payout in `sent` is stated as on its way rather than arrived: the bank
 * has the file but has not confirmed, and telling someone their money has
 * landed when it may still bounce is exactly the promise §9 forbids.
 */
const STATUS_VARIANT: Record<CustomerPayout['status'], 'success' | 'warning' | 'destructive'> = {
  paid: 'success',
  sent: 'warning',
  failed: 'destructive',
};

function PayoutRow({ payout }: { payout: CustomerPayout }) {
  const { t } = useTranslation();

  return (
    <Link
      href={`/payouts/${payout.id}`}
      className="group flex flex-wrap items-center justify-between gap-3 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <MoneyText
            laari={payout.amount_laari}
            currency={payout.currency}
            className="text-base font-semibold text-mono"
          />
          <Badge
            variant={STATUS_VARIANT[payout.status]}
            appearance="light"
            size="sm"
          >
            {t(`payouts.status.${payout.status}`)}
          </Badge>
        </div>

        <span className="text-xs text-muted-foreground">
          {payout.period_start !== null && payout.period_end !== null && (
            <>
              {t('payouts.forPeriod', {
                from: formatDate(payout.period_start),
                to: formatDate(payout.period_end),
              })}
              {' · '}
            </>
          )}
          {payout.transaction_count !== undefined &&
            t('payouts.purchaseCount', { count: payout.transaction_count })}
        </span>

        <span className="text-xs text-muted-foreground">
          {payout.bank !== null && `${payout.bank} `}
          {payout.account_masked}
          {payout.reference !== null && (
            <>
              {' · '}
              {t('payouts.reference', { reference: payout.reference })}
            </>
          )}
        </span>

        {payout.status === 'failed' && payout.failure_reason !== null && (
          <span className="text-xs text-destructive">
            {t('payouts.failedNote')}
          </span>
        )}
      </div>

      <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
    </Link>
  );
}

export default function PayoutsPage() {
  const { t } = useTranslation();
  const [page, setPage] = useState(1);
  const { data, isPending, error } = usePayouts(page);

  return (
    <div className="flex flex-col gap-5">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('payouts.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('payouts.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {isPending ? (
        <LoadingBlock lines={4} />
      ) : error ? (
        <ErrorBlock error={error} />
      ) : data.data.length === 0 ? (
        <EmptyBlock>
          <span className="flex flex-col items-center gap-2">
            <Landmark className="size-6 text-muted-foreground/60" />
            <span className="font-medium text-mono">
              {t('payouts.emptyTitle')}
            </span>
            <span className="max-w-sm">{t('payouts.emptyBody')}</span>
          </span>
        </EmptyBlock>
      ) : (
        <Card>
          <CardContent className="divide-y divide-border py-0">
            {data.data.map((payout) => (
              <PayoutRow key={payout.id} payout={payout} />
            ))}
          </CardContent>
          {data.meta.last_page > 1 && (
            <CardFooter>
              <ListPagination meta={data.meta} onPageChange={setPage} />
            </CardFooter>
          )}
        </Card>
      )}
    </div>
  );
}
