'use client';

import { useState } from 'react';
import Link from 'next/link';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { MerchantSettlement } from '@/lib/api';
import { useSettlements } from '@/lib/queries';
import { can } from '@/lib/roles';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardFooter,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarActions,
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
import { SettlementStateBadge } from '@/components/app/state-badge';

/**
 * Settlement history. Under the receipt-first flow (PLAN §1) the state chip
 * alone does not answer the merchant's question, so each row also carries
 * the human status: "Manfaa is verifying your transfer" while a slip is in
 * review, and the admin's REASON on a rejected one — with a route to a fresh
 * settlement, which is the whole remedy.
 */

function StatusCell({ settlement }: { settlement: MerchantSettlement }) {
  const { t } = useTranslation();
  const status = settlement.merchant_status;

  return (
    <div className="flex flex-col gap-1">
      <SettlementStateBadge state={settlement.state} />
      {status.code === 'verifying' && (
        <span className="text-xs text-muted-foreground">
          {t('settlement.statusVerifying')}
        </span>
      )}
      {status.code === 'rejected' && (
        <span className="flex flex-col gap-0.5">
          <span className="text-xs font-medium text-destructive">
            {t('settlement.statusRejected')}
          </span>
          <span className="text-xs text-muted-foreground">
            {status.rejection?.reason ?? t('settlement.statusRejectedNoReason')}
          </span>
        </span>
      )}
    </div>
  );
}

export default function SettlementsPage() {
  const { t } = useTranslation();
  const { me } = useLayout();
  const canSettle = can(me, 'settlements.create');
  const [page, setPage] = useState(1);
  const settlements = useSettlements(page);

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('settlement.listTitle')}</ToolbarPageTitle>
          <ToolbarDescription>
            {t('settlement.listSubtitle')}
          </ToolbarDescription>
        </ToolbarHeading>
        {/* Reading the list and building a batch are separate grants: this
            button leads to the wizard whose last step is the POST, so it
            stands on that POST's permission. The API gates it identically. */}
        {canSettle && (
          <ToolbarActions>
            <Button asChild>
              <Link href="/settlements/new">
                <Plus />
                {t('settlement.title')}
              </Link>
            </Button>
          </ToolbarActions>
        )}
      </Toolbar>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {settlements.data
              ? t('settlement.listCount', {
                  count: settlements.data.meta.total,
                })
              : t('settlement.listTitle')}
          </CardTitle>
        </CardHeader>

        {settlements.error ? (
          <ErrorBlock error={settlements.error} />
        ) : !settlements.data ? (
          <LoadingBlock lines={5} />
        ) : settlements.data.data.length === 0 ? (
          <EmptyBlock>{t('settlement.emptyList')}</EmptyBlock>
        ) : (
          <>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('settlement.colReference')}</TableHead>
                      <TableHead>{t('settlement.colCreated')}</TableHead>
                      <TableHead>{t('settlement.colStatus')}</TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colCashback')}
                      </TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colFees')}
                      </TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colAmountDue')}
                      </TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colReceived')}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {settlements.data.data.map((settlement) => (
                      <TableRow key={settlement.id}>
                        <TableCell>
                          <Link
                            href={`/settlements/${settlement.id}`}
                            className="font-medium text-mono hover:text-primary"
                            dir="ltr"
                          >
                            {settlement.reference}
                          </Link>
                        </TableCell>
                        <TableCell className="whitespace-nowrap text-secondary-foreground">
                          {format(
                            new Date(settlement.created_at),
                            'dd MMM yyyy',
                          )}
                        </TableCell>
                        <TableCell>
                          <StatusCell settlement={settlement} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={settlement.cashback_total_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText
                            laari={
                              settlement.fee_total_laari +
                              settlement.fee_gst_total_laari
                            }
                          />
                        </TableCell>
                        <TableCell className="text-end font-medium">
                          <MoneyText laari={settlement.amount_due_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={settlement.amount_received_laari} />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardTable>
            <CardFooter>
              <ListPagination
                meta={settlements.data.meta}
                onPageChange={setPage}
              />
            </CardFooter>
          </>
        )}
      </Card>
    </div>
  );
}
