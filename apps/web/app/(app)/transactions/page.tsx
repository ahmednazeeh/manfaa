'use client';

import { useState } from 'react';
import { type CustomerTransaction } from '@manfaa/api-client';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { statusReasonLabel } from '@/lib/labels';
import { useTransactions } from '@/lib/queries';
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
import { TransactionStatusChip } from '@/components/app/status-chip';

/**
 * The §6 customer mapping is already applied by the API (four internal
 * states arrive as `pending`); this screen renders the status chip and the
 * translatable reason line. Earning copy is future-conditional (§9.4 / §10):
 * "You'll earn … once X confirms" — never a past-tense promise.
 */
function TransactionRow({ transaction }: { transaction: CustomerTransaction }) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const merchant = transaction.merchant.name;

  const reasonLine = statusReasonLabel(t, transaction.status_reason, merchant);

  return (
    <div className="flex flex-wrap items-start justify-between gap-3 py-4">
      <div className="flex flex-col gap-1 min-w-0">
        <span className="text-sm font-medium text-mono">{merchant}</span>
        <span className="text-xs text-muted-foreground">
          {formatDate(transaction.occurred_at)}
          {' · '}
          {t('transactions.invoice', { invoiceNo: transaction.invoice_no })}
          {' · '}
          {t('transactions.spent', {
            amount: formatMoney(
              transaction.eligible_laari,
              transaction.currency,
            ),
          })}
        </span>
        {transaction.status === 'pending' && (
          <span className="text-xs text-secondary-foreground">
            {t('transactions.willEarn', {
              amount: formatMoney(
                transaction.cashback_laari,
                transaction.currency,
              ),
              merchant,
            })}
          </span>
        )}
        {reasonLine && (
          <span className="text-xs text-muted-foreground">{reasonLine}</span>
        )}
      </div>

      <div className="flex flex-col items-end gap-1 shrink-0">
        <MoneyText
          laari={transaction.cashback_laari}
          currency={transaction.currency}
          className="text-sm font-semibold text-mono"
        />
        <TransactionStatusChip status={transaction.status} />
      </div>
    </div>
  );
}

export default function TransactionsPage() {
  const [page, setPage] = useState(1);
  const { data, isPending, error } = useTransactions(page);
  const { t } = useTranslation();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('transactions.title')}</ToolbarPageTitle>
          <ToolbarDescription>
            {t('transactions.description')}
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <div className="pb-10">
        <Card>
          {isPending && <LoadingBlock lines={5} />}
          {!isPending && error && <ErrorBlock error={error} />}

          {data && data.data.length === 0 && (
            <EmptyBlock>{t('transactions.empty')}</EmptyBlock>
          )}

          {data && data.data.length > 0 && (
            <>
              <CardContent className="divide-y divide-border py-1">
                {data.data.map((transaction) => (
                  <TransactionRow
                    key={transaction.id}
                    transaction={transaction}
                  />
                ))}
              </CardContent>
              <CardFooter className="py-3">
                <ListPagination meta={data.meta} onPageChange={setPage} />
              </CardFooter>
            </>
          )}
        </Card>

        {/* Missing-cashback guidance: merchant-mediated by design — the store
            verifies the receipt and credits it manually (no self-serve claims). */}
        <p className="text-xs text-muted-foreground">
          {t('transactions.missingHint')}
        </p>
      </div>
    </div>
  );
}
