'use client';

import { useState } from 'react';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import { format } from 'date-fns';
import { Plus, Wallet as WalletIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { walletMovementLabel } from '@/lib/labels';
import { useWallet } from '@/lib/queries';
import { can } from '@/lib/roles';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
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
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { AutoSettleCard } from '@/components/wallet/auto-settle-card';
import { PendingTopUps } from '@/components/wallet/pending-top-ups';
import { TopUpDialog } from '@/components/wallet/top-up-dialog';

/**
 * The store's Manfaa wallet (owner, 2026-08-24 — the wallet IS pre-funding
 * now). Three things on one screen, each behind its own permission:
 *
 *  - the balance, and topping it up by bank transfer (`wallet.top_up`) —
 *    a receipt-first claim the bank's own history confirms;
 *  - the auto-settle switch (`preferences.update` to move it) — whether
 *    the hourly run settles validated cashback from this balance;
 *  - the claims still being verified, and the movements ledger.
 *
 * Everything shown is the server's: no balance is projected, no claim is
 * counted as money before it is matched.
 */
export default function WalletPage() {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const { me } = useLayout();
  const wallet = useWallet();
  const [topUpOpen, setTopUpOpen] = useState(false);

  const canTopUp = can(me, 'wallet.top_up');
  const canToggleAutoSettle = can(me, 'preferences.update');

  const pending = wallet.data?.pending_top_ups ?? [];
  // The hero line says "{amount} in {count} top-ups being verified", so it
  // must count only the claims that ARE being verified — the list also
  // carries claims refused in the last week, which are neither waiting nor
  // money. Each still-waiting claim contributes what the merchant typed,
  // because no bank figure exists for it yet; once one does the claim is
  // decided and leaves this sum entirely.
  const awaiting = pending.filter((topUp) => topUp.state === 'pending');
  const pendingLaari = awaiting.reduce(
    (total, topUp) => total + (topUp.received_laari ?? topUp.amount_laari),
    0,
  );

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('wallet.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('wallet.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {wallet.error ? (
        <ErrorBlock error={wallet.error} />
      ) : (
        <div className="flex flex-col gap-5 pb-7.5">
          <div className="grid grid-cols-1 items-stretch gap-5 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardContent className="flex flex-wrap items-center gap-4 p-5">
                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary/10">
                  <WalletIcon className="size-6 text-primary" />
                </span>
                <div className="flex min-w-0 grow flex-col gap-1">
                  <span className="text-xs font-medium uppercase text-muted-foreground">
                    {t('wallet.balance')}
                  </span>
                  {wallet.data ? (
                    <MoneyText
                      laari={wallet.data.balance_laari}
                      currency={wallet.data.currency}
                      className="text-2xl font-semibold text-mono"
                    />
                  ) : (
                    <Skeleton className="h-8 w-40 rounded-md" />
                  )}
                  <span className="text-sm text-muted-foreground">
                    {awaiting.length > 0
                      ? t('wallet.pendingSummary', {
                          count: awaiting.length,
                          amount: formatMoney(pendingLaari),
                        })
                      : t('wallet.balanceHint')}
                  </span>
                </div>
                {canTopUp && (
                  <Button
                    onClick={() => setTopUpOpen(true)}
                    disabled={!wallet.data}
                  >
                    <Plus />
                    {t('wallet.topUp')}
                  </Button>
                )}
              </CardContent>
            </Card>

            <AutoSettleCard
              enabled={wallet.data?.auto_settle_from_wallet}
              canToggle={canToggleAutoSettle}
            />
          </div>

          <PendingTopUps topUps={pending} />

          <Card>
            <CardHeader>
              <CardTitle>{t('wallet.movements')}</CardTitle>
            </CardHeader>
            {!wallet.data ? (
              <LoadingBlock lines={4} />
            ) : (wallet.data.transactions?.length ?? 0) === 0 ? (
              <EmptyBlock>{t('wallet.emptyMovements')}</EmptyBlock>
            ) : (
              <CardTable>
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{t('wallet.colDate')}</TableHead>
                        <TableHead>{t('wallet.colType')}</TableHead>
                        <TableHead>{t('wallet.colDescription')}</TableHead>
                        <TableHead className="text-end">
                          {t('wallet.colAmount')}
                        </TableHead>
                        <TableHead className="text-end">
                          {t('wallet.colBalanceAfter')}
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {(wallet.data.transactions ?? []).map((movement) => (
                        <TableRow key={movement.id}>
                          <TableCell className="text-secondary-foreground whitespace-nowrap">
                            {format(
                              new Date(movement.created_at),
                              'dd MMM yyyy, HH:mm',
                            )}
                          </TableCell>
                          <TableCell>
                            {walletMovementLabel(t, movement.type)}
                          </TableCell>
                          <TableCell className="text-secondary-foreground">
                            {movement.description ?? '—'}
                          </TableCell>
                          {/* The ledger's own figure: what actually moved.
                              A top-up movement is written from the BANK's
                              credit, never from the amount the merchant
                              typed on the claim, so this column already
                              answers "what really went in" and needs no
                              claim beside it. */}
                          <TableCell
                            className={cn(
                              'text-end font-medium',
                              movement.amount_laari < 0
                                ? 'text-destructive'
                                : 'text-green-600 dark:text-green-500',
                            )}
                          >
                            <MoneyText laari={movement.amount_laari} />
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText laari={movement.balance_after_laari} />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardTable>
            )}
          </Card>
        </div>
      )}

      {canTopUp && wallet.data && (
        <TopUpDialog
          open={topUpOpen}
          onOpenChange={setTopUpOpen}
          minLaari={wallet.data.top_up_min_laari}
          accounts={wallet.data.bank_accounts}
        />
      )}
    </div>
  );
}
