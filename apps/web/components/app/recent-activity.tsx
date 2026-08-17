'use client';

import Link from 'next/link';
import { type CustomerTransaction } from '@manfaa/api-client';
import { useFormatMoney } from '@manfaa/ui';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { useTransactions } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { TransactionStatusChip } from '@/components/app/status-chip';
import { StoreAvatar } from '@/components/app/store-avatar';

/**
 * The dashboard's last-few-purchases strip: page 1 of the same endpoint the
 * Transactions screen reads, trimmed to five compact rows. Money that is
 * still conditional stays visually conditional — the cashback figure only
 * takes the brand accent once it is confirmed or paid (§10: pending is
 * never presented as owned money).
 */

const VISIBLE_ROWS = 5;

/** Cashback that reads as WON: confirmed or already paid out. */
const ACCENT_STATUSES: ReadonlyArray<CustomerTransaction['status']> = [
  'confirmed',
  'paid',
];

function ActivityRow({ transaction }: { transaction: CustomerTransaction }) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const accent = ACCENT_STATUSES.includes(transaction.status);
  // A reversed or unpaid row never earned its cashback; a "+" would lie.
  const cancelled =
    transaction.status === 'reversed' || transaction.status === 'unpaid';

  return (
    <Link
      href="/transactions"
      className="-mx-5 flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/60"
    >
      {/* No logo in this payload — the deterministic monogram tile is the
          merchant's mark here, same as on the storefront. */}
      <StoreAvatar
        name={transaction.merchant.name}
        slug={transaction.merchant.slug}
        logoUrl={null}
        size="sm"
      />

      <div className="flex min-w-0 grow flex-col gap-0.5">
        <span className="truncate text-sm font-medium text-mono">
          {transaction.merchant.name}
        </span>
        <span className="truncate text-xs text-muted-foreground">
          {formatDate(transaction.occurred_at)}
          {' · '}
          {t('transactions.spent', {
            amount: formatMoney(
              transaction.eligible_laari,
              transaction.currency,
            ),
          })}
        </span>
      </div>

      <div className="flex shrink-0 flex-col items-end gap-1">
        <span
          className={cn(
            'text-sm font-semibold tabular-nums',
            accent ? 'text-brand' : 'text-muted-foreground',
            cancelled && 'line-through decoration-muted-foreground/50',
          )}
        >
          {cancelled
            ? formatMoney(transaction.cashback_laari, transaction.currency)
            : t('dashboard.recentCashback', {
                amount: formatMoney(
                  transaction.cashback_laari,
                  transaction.currency,
                ),
              })}
        </span>
        <TransactionStatusChip status={transaction.status} />
      </div>
    </Link>
  );
}

export function RecentActivity({ className }: { className?: string }) {
  const { data, isPending, error } = useTransactions(1);
  const { t } = useTranslation();

  const rows = data?.data.slice(0, VISIBLE_ROWS) ?? [];

  return (
    <section className={className} aria-label={t('dashboard.recentActivity')}>
      <Card>
        <CardHeader className="min-h-12 py-2">
          <CardTitle className="text-sm">
            {t('dashboard.recentActivity')}
          </CardTitle>
          <Link
            href="/transactions"
            className="text-sm font-medium text-brand hover:underline"
          >
            {t('dashboard.viewAll')}
          </Link>
        </CardHeader>

        {isPending && <LoadingBlock lines={3} />}
        {!isPending && error != null && <ErrorBlock error={error} />}

        {data && rows.length === 0 && (
          <EmptyBlock>{t('dashboard.recentEmpty')}</EmptyBlock>
        )}

        {rows.length > 0 && (
          <CardContent className="divide-y divide-border py-1">
            {rows.map((transaction) => (
              <ActivityRow key={transaction.id} transaction={transaction} />
            ))}
          </CardContent>
        )}
      </Card>
    </section>
  );
}
