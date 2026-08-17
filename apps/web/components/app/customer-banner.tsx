'use client';

import { useMemo } from 'react';
import Link from 'next/link';
import { MoneyText } from '@manfaa/ui';
import { ArrowRight, Landmark, ReceiptText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { encodeQr } from '@/lib/qr';
import { useBalance, useMe } from '@/lib/queries';
import { Button } from '@/components/ui/button';
import { QrCode } from '@/components/app/qr-code';

/**
 * What a signed-in customer sees at the top of the storefront: who they
 * are, what they have earned, the code they will show at the till, and the
 * places they might be heading.
 *
 * It replaces the acquisition hero, so it has to answer the same question
 * that hero answered for a visitor — "why am I here?" — with the version
 * that is true for someone who already joined: here is your money.
 *
 * A personal band, NOT the dashboard relocated: greeting, the two balance
 * figures, and the code as a compact chip. Everything deeper — the big QR,
 * payout windows, history — stays behind the "Open dashboard" link.
 *
 * Confirmed and pending are shown SEPARATELY and never summed (§9.4):
 * pending cashback is conditional on the store confirming the purchase, and
 * one combined figure would promise money the platform does not yet hold.
 * A missing payout account is called out here rather than left for the
 * customer to discover on payout day.
 */
export function CustomerBanner() {
  const { t } = useTranslation();
  const { data: balance } = useBalance();
  // The same probe the header runs; react-query dedupes the request.
  const { data: me } = useMe();

  // The chip only shows a QR the encoder can actually draw; when it can't,
  // the code text alone still does the job at the till.
  const qrAvailable = useMemo(
    () => me !== undefined && encodeQr(me.customer_code) !== null,
    [me],
  );

  return (
    <section className="border-b border-border bg-brand-soft">
      <div className="container flex flex-col gap-3 py-5 lg:py-6">
        {/* The greeting row. `me` can lag one probe behind the signed-in
            hint (see useSignedIn), so the name renders only once the probe
            has an answer; ms-auto keeps the dashboard link parked at the
            inline end either way. */}
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          {me !== undefined && (
            <h1 className="text-base font-semibold text-mono">
              {t('landing.bannerGreeting', { name: me.name })}
            </h1>
          )}
          <Button variant="ghost" size="sm" asChild className="-me-2.5 ms-auto">
            <Link href="/dashboard">
              {t('landing.openDashboard')}
              <ArrowRight className="rtl:rotate-180" />
            </Link>
          </Button>
        </div>

        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex flex-wrap items-end gap-x-8 gap-y-3">
            <div className="flex flex-col">
              <span className="text-xs font-medium text-muted-foreground">
                {t('landing.bannerConfirmed')}
              </span>
              {/* No skeleton: the label carries the meaning and a dash is
                  honest for the moment before the figure lands. */}
              <span className="text-2xl font-bold tracking-tight text-brand sm:text-3xl">
                {balance === undefined ? (
                  '—'
                ) : (
                  <MoneyText
                    laari={balance.confirmed_laari}
                    currency={balance.currency}
                  />
                )}
              </span>
            </div>

            <div className="flex flex-col">
              <span className="text-xs font-medium text-muted-foreground">
                {t('landing.bannerPending')}
              </span>
              <span className="text-base font-semibold text-mono">
                {balance === undefined ? (
                  '—'
                ) : (
                  <MoneyText
                    laari={balance.pending_laari}
                    currency={balance.currency}
                  />
                )}
              </span>
              <span className="text-2xs text-muted-foreground">
                {t('landing.bannerPendingNote')}
              </span>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* The bank account is the one thing that can silently stop a
                payout, so it outranks the ordinary links when missing. */}
            {balance !== undefined && !balance.has_payout_account && (
              <Button
                asChild
                className="bg-brand text-brand-foreground hover:bg-brand/90"
              >
                <Link href="/payout-account">
                  <Landmark />
                  {t('landing.bannerAddAccount')}
                </Link>
              </Button>
            )}

            {/* The code itself, as a chip: what the cashier scans or types,
                without a trip to the dashboard. The full-size QR is one tap
                away behind it. */}
            {me !== undefined && (
              <Link
                href="/dashboard"
                className="flex items-center gap-2.5 rounded-lg border border-border bg-background py-1.5 ps-1.5 pe-3 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              >
                {qrAvailable && (
                  <span className="shrink-0 overflow-hidden rounded-md border border-border">
                    <QrCode
                      value={me.customer_code}
                      label={t('dashboard.qrAlt', { code: me.customer_code })}
                      className="size-9"
                    />
                  </span>
                )}
                <span className="flex flex-col">
                  <span className="text-2xs font-medium text-muted-foreground">
                    {t('dashboard.yourCode')}
                  </span>
                  <span
                    dir="ltr"
                    className="text-sm font-semibold tracking-[0.15em] text-mono tabular-nums"
                  >
                    {me.customer_code}
                  </span>
                </span>
              </Link>
            )}

            <Button variant="outline" asChild>
              <Link href="/transactions">
                <ReceiptText />
                {t('landing.bannerActivity')}
              </Link>
            </Button>
            <Button variant="ghost" asChild>
              <Link href="/payouts">
                {t('landing.bannerPayouts')}
                <ArrowRight className="rtl:rotate-180" />
              </Link>
            </Button>
          </div>
        </div>
      </div>
    </section>
  );
}
