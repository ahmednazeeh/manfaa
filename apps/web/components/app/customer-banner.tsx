'use client';

import Link from 'next/link';
import { MoneyText } from '@manfaa/ui';
import { ArrowRight, Landmark, QrCode, ReceiptText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useBalance } from '@/lib/queries';
import { Button } from '@/components/ui/button';

/**
 * What a signed-in customer sees at the top of the storefront: what they
 * have earned, what is still conditional, and the three places they might
 * be heading.
 *
 * It replaces the acquisition hero, so it has to answer the same question
 * that hero answered for a visitor — "why am I here?" — with the version
 * that is true for someone who already joined: here is your money.
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

  return (
    <section className="border-b border-border bg-brand-soft">
      <div className="container flex flex-col gap-4 py-5 lg:flex-row lg:items-center lg:justify-between lg:py-6">
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

          <Button variant="outline" asChild>
            <Link href="/dashboard">
              <QrCode />
              {t('landing.bannerShowCode')}
            </Link>
          </Button>
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
    </section>
  );
}
