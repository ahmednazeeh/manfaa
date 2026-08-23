'use client';

import Link from 'next/link';
import { displayName } from '@/lib/display-name';
import { type CustomerBalance } from '@manfaa/api-client';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import { BadgeCheck, CalendarClock, HandCoins, Landmark, Gift, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { useBalance } from '@/lib/queries';
import { cn } from '@/lib/utils';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useQuery } from '@tanstack/react-query';
import { getReferrals } from '@manfaa/api-client';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { AvatarEditor } from '@/components/app/avatar-editor';
import { CodeCard } from '@/components/app/code-card';
import { RecentActivity } from '@/components/app/recent-activity';

/**
 * The §10 balance rules, non-negotiable: Confirmed is the HEADLINE figure.
 * Pending is never summed into it and always reads as conditional money.
 *
 * Visual weight follows importance (owner, 2026-08-17): the available
 * balance is the hero, the code card is what gets used at a till (first
 * after the hero on phones), paid-this-month and next-payout share one
 * quieter summary card, and the page ends with recent activity.
 */

/** One label voice across every figure on this page; values dominate. */
const LABEL =
  'text-2xs font-semibold uppercase tracking-wider text-muted-foreground';

/**
 * The payout-minimum progress inside the hero. CONFIRMED laari only —
 * pending money never advances this bar (§10). The bar is width-driven,
 * so it fills from the inline start and mirrors under Dhivehi for free.
 */
function PayoutProgress({ balance }: { balance: CustomerBalance }) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();

  const minimum = balance.minimum_payout_laari;
  if (minimum <= 0) {
    return null;
  }

  const ready = balance.confirmed_laari >= minimum;
  const percent = ready
    ? 100
    : Math.floor((balance.confirmed_laari * 100) / minimum);

  return (
    <div className="flex flex-col gap-1.5">
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
        aria-label={t('dashboard.nextPayout')}
        className="h-2 w-full overflow-hidden rounded-full bg-brand/15"
      >
        <div
          className="h-full rounded-full bg-brand transition-[width] duration-500"
          style={{ width: `${percent}%` }}
        />
      </div>
      {ready ? (
        <span className="flex items-center gap-1.5 text-xs font-medium text-brand">
          <BadgeCheck className="size-3.5 shrink-0" />
          {t('dashboard.progressReady')}
        </span>
      ) : (
        <span className="text-xs text-muted-foreground">
          {t('dashboard.progressLine', {
            amount: formatMoney(balance.confirmed_laari, balance.currency),
            minimum: formatMoney(minimum, balance.currency),
          })}
        </span>
      )}
    </div>
  );
}

/** HERO: the confirmed balance. Pending never joins this figure. */
function BalanceHero({
  balance,
  className,
}: {
  balance: CustomerBalance;
  className?: string;
}) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();

  return (
    <Card
      className={cn(
        // A whisper of the brand, not a fill: tinted wash + tinted border.
        'border-brand/20 bg-gradient-to-br from-brand-soft/70 via-card to-card',
        className,
      )}
    >
      <CardContent className="flex flex-col gap-4 p-6 sm:p-7">
        <div className="flex flex-col gap-1.5">
          <span className={LABEL}>{t('dashboard.confirmedBalance')}</span>
          <MoneyText
            laari={balance.confirmed_laari}
            currency={balance.currency}
            className="text-4xl font-semibold tracking-tight text-brand"
          />
          <span className="text-xs text-muted-foreground">
            {t('dashboard.confirmedHint')}
          </span>
        </div>

        <PayoutProgress balance={balance} />

        {balance.pending_laari > 0 && (
          <span className="border-t border-brand/10 pt-3 text-sm text-muted-foreground">
            {t('dashboard.pendingLine', {
              amount: formatMoney(balance.pending_laari, balance.currency),
            })}
          </span>
        )}

        {/* The card's ONE action — no floating buttons below the fold. */}
        <div className="pt-1">
          <Button
            asChild
            className="bg-brand text-brand-foreground hover:bg-brand/90"
          >
            <Link href="/transactions">{t('dashboard.viewTransactions')}</Link>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

/** Paid-this-month and the next payout window, in ONE quiet card. */
function PayoutSummary({
  balance,
  className,
}: {
  balance: CustomerBalance;
  className?: string;
}) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();

  return (
    <Card className={className}>
      <CardContent className="grid p-0 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5 p-5">
          <span className={cn('flex items-center gap-1.5', LABEL)}>
            <HandCoins className="size-3.5 text-brand" />
            {t('dashboard.paidThisMonth')}
          </span>
          <MoneyText
            laari={balance.paid_this_month_laari}
            currency={balance.currency}
            className="text-xl font-semibold text-mono"
          />
        </div>

        {/* Logical start border, so the divider mirrors under Dhivehi. */}
        <div className="flex flex-col gap-1.5 border-t border-border p-5 sm:border-t-0 sm:border-s">
          <span className={cn('flex items-center gap-1.5', LABEL)}>
            <CalendarClock className="size-3.5 text-brand" />
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
        </div>
      </CardContent>
    </Card>
  );
}

export default function DashboardPage() {
  const { me } = useLayout();
  const { data: balance, isPending, error } = useBalance();
  const { t, i18n } = useTranslation();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('dashboard.title')}</ToolbarPageTitle>
          <ToolbarDescription>
            {t('dashboard.greeting', {
                name: displayName(me.name, me.name_dv, i18n.language),
              })}
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

          {/* One grid, two widths. DOM order IS the phone order — hero,
              then the code (what gets shown at a till), then the payout
              summary, then activity. At xl the code card moves to a side
              column beside the money column. */}
          <ReferralPromo />

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,24rem)]">
            <BalanceHero balance={balance} className="xl:col-start-1" />
            <CodeCard
              me={me}
              labelClassName={LABEL}
              className="xl:col-start-2 xl:row-start-1 xl:row-span-2"
            />
            <PayoutSummary balance={balance} className="xl:col-start-1" />
            <RecentActivity className="xl:col-span-2" />
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * One line, one link (owner, 2026-08-23: "don't add too much text"). Renders
 * nothing until the programme config has loaded, and nothing at all while
 * the programme is off.
 */
function ReferralPromo() {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const referrals = useQuery({
    queryKey: ['referrals'],
    queryFn: ({ signal }) => getReferrals({ signal }),
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const data = referrals.data?.data;
  if (!data?.enabled) return null;

  return (
    <Link
      href="/referrals"
      className="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-accent"
    >
      <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400">
        <Gift className="size-4.5" />
      </span>
      <span className="min-w-0 flex-1 truncate text-sm font-medium">
        {t('referrals.promo', { amount: formatMoney(data.reward_laari) })}
      </span>
      <ChevronRight className="size-4 shrink-0 text-muted-foreground rtl:rotate-180" />
    </Link>
  );
}
