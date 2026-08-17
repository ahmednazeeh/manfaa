'use client';

import { useMemo } from 'react';
import Link from 'next/link';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import { CalendarClock, HandCoins, Landmark } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { encodeQr } from '@/lib/qr';
import { useBalance } from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { AvatarEditor } from '@/components/app/avatar-editor';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { QrCode } from '@/components/app/qr-code';

/**
 * The §10 balance rules, non-negotiable: Confirmed is the HEADLINE figure.
 * Pending is never summed into it and always reads as conditional money.
 */
export default function DashboardPage() {
  const { me } = useLayout();
  const { data: balance, isPending, error } = useBalance();
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();

  const qrAvailable = useMemo(
    () => encodeQr(me.customer_code) !== null,
    [me.customer_code],
  );

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('dashboard.title')}</ToolbarPageTitle>
          <ToolbarDescription>
            {t('dashboard.greeting', { name: me.name })}
          </ToolbarDescription>
        </ToolbarHeading>
        <AvatarEditor me={me} />
      </Toolbar>

      {isPending && <LoadingBlock lines={4} />}
      {!isPending && error && <ErrorBlock error={error} />}

      {balance && (
        <div className="flex flex-col gap-5 pb-10">
          {!balance.has_payout_account && (
            <Alert variant="info" appearance="light">
              <AlertIcon>
                <Landmark />
              </AlertIcon>
              <AlertContent>
                <AlertTitle>{t('dashboard.addPayoutAccountTitle')}</AlertTitle>
                <AlertDescription>
                  {t('dashboard.addPayoutAccountBody')}
                </AlertDescription>
                <div className="pt-1">
                  <Button asChild size="sm" variant="outline">
                    <Link href="/payout-account">
                      {t('dashboard.addPayoutAccountAction')}
                    </Link>
                  </Button>
                </div>
              </AlertContent>
            </Alert>
          )}

          <div className="grid gap-5 lg:grid-cols-2">
            {/* HEADLINE: Confirmed balance. Pending never joins this figure. */}
            <Card>
              <CardContent className="flex flex-col gap-2 p-7">
                <span className="text-sm font-medium text-muted-foreground">
                  {t('dashboard.confirmedBalance')}
                </span>
                <MoneyText
                  laari={balance.confirmed_laari}
                  currency={balance.currency}
                  className="text-4xl font-semibold text-mono"
                />
                <span className="text-xs text-muted-foreground">
                  {t('dashboard.confirmedHint')}
                </span>

                {balance.pending_laari > 0 && (
                  <div className="mt-3 border-t border-border pt-3">
                    <span className="text-sm text-muted-foreground">
                      {t('dashboard.pendingLine', {
                        amount: formatMoney(
                          balance.pending_laari,
                          balance.currency,
                        ),
                      })}
                    </span>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Customer code + QR — what the cashier scans or types. */}
            <Card>
              <CardContent className="flex items-center gap-6 p-7">
                <div className="flex flex-col gap-2 grow">
                  <span className="text-sm font-medium text-muted-foreground">
                    {t('dashboard.yourCode')}
                  </span>
                  <span
                    // -me cancels the trailing letter-space, which would
                    // otherwise widen the box past the glyphs and leave the
                    // centred code sitting off to one side.
                    className="-me-[0.2em] text-3xl font-semibold tracking-[0.2em] text-mono tabular-nums"
                    dir="ltr"
                  >
                    {me.customer_code}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {t('dashboard.codeHint')}
                  </span>
                  {!qrAvailable && (
                    <span className="text-xs text-muted-foreground">
                      {t('dashboard.qrUnavailable')}
                    </span>
                  )}
                </div>
                {qrAvailable && (
                  <div className="shrink-0 rounded-lg border border-border overflow-hidden">
                    <QrCode
                      value={me.customer_code}
                      label={t('dashboard.qrAlt', { code: me.customer_code })}
                      className="size-28"
                    />
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-5 sm:grid-cols-2">
            <Card>
              <CardContent className="flex flex-col gap-1.5 p-5">
                <span className="flex items-center gap-1.5 text-xs font-medium uppercase text-muted-foreground">
                  <HandCoins className="size-3.5" />
                  {t('dashboard.paidThisMonth')}
                </span>
                <MoneyText
                  laari={balance.paid_this_month_laari}
                  currency={balance.currency}
                  className="text-xl font-semibold text-mono"
                />
              </CardContent>
            </Card>

            <Card>
              <CardContent className="flex flex-col gap-1.5 p-5">
                <span className="flex items-center gap-1.5 text-xs font-medium uppercase text-muted-foreground">
                  <CalendarClock className="size-3.5" />
                  {t('dashboard.nextPayout')}
                </span>
                <span className="text-xl font-semibold text-mono">
                  {t('dashboard.payoutWindow', {
                    start: formatDate(balance.next_payout_window.starts_at),
                    end: formatDate(balance.next_payout_window.ends_at),
                  })}
                </span>
                <span className="text-xs text-muted-foreground">
                  {t('dashboard.minimumPayoutNote', {
                    minimum: formatMoney(
                      balance.minimum_payout_laari,
                      balance.currency,
                    ),
                  })}
                </span>
              </CardContent>
            </Card>
          </div>

          <div>
            <Button asChild variant="outline">
              <Link href="/transactions">
                {t('dashboard.viewTransactions')}
              </Link>
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
