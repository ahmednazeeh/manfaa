'use client';

import Link from 'next/link';
import { BrandMark } from '@manfaa/ui';
import { BadgeCheck, Percent, TrendingUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/app/language-switcher';
import { MerchantDevices, PitchPoints } from '@/components/marketing/pitch';

/**
 * The signed-out front door of merchant.manfaa.app (PLAN §WL): a lean
 * converter, not a marketing site — the SEO-weighted pitch stays on
 * manfaa.app's business section. One hero, the three-point story, the real
 * app in a phone, and two ways in. Signed-in visitors never see this
 * (app/page.tsx redirects on the session cookie).
 */
export function MerchantLanding() {
  const { t } = useTranslation();

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <header className="mx-auto flex w-full max-w-6xl items-center justify-between gap-3 px-5 py-4 sm:px-8">
        <div className="flex items-center gap-2">
          {/* The platform mark, superadmin-replaceable. "Merchant"
              stays as the product suffix it has always been. */}
          <BrandMark className="h-[22px] w-auto" alt="Manfaa" />
          <span className="text-lg font-semibold text-violet-600 dark:text-violet-400">
            {t('marketing.wordmark')}
          </span>
        </div>
        <div className="flex items-center gap-2">
          <LanguageSwitcher />
          <Button asChild variant="outline">
            <Link href="/login">{t('marketing.login')}</Link>
          </Button>
        </div>
      </header>

      <main className="mx-auto grid w-full max-w-6xl grow items-center gap-10 px-5 py-10 sm:px-8 lg:grid-cols-2 lg:gap-12 lg:py-14">
        <div className="flex max-w-xl flex-col gap-6">
          <h1 className="text-3xl font-semibold leading-[1.12] text-mono sm:text-4xl">
            {t('marketing.headline')}
          </h1>
          <p className="text-base text-muted-foreground">
            {t('marketing.subtitle')}
          </p>

          <PitchPoints className="mt-2" />

          <div className="mt-2 flex flex-wrap items-center gap-3">
            <Button asChild size="lg" className="px-6">
              <Link href="/signup">{t('marketing.register')}</Link>
            </Button>
            <Button asChild size="lg" variant="ghost">
              <Link href="/login">{t('marketing.haveAccount')}</Link>
            </Button>
          </div>

          {/* The WL follow-up that waited on MR6: the app is real now. */}
          <a
            href="https://manfaa.app/app/"
            rel="noopener"
            className="mt-1 inline-flex w-fit items-center gap-2 rounded-xl border border-border bg-background px-4 py-2.5 text-sm font-medium text-mono transition-colors hover:border-violet-600/40"
          >
            <span className="inline-flex size-7 items-center justify-center rounded-lg bg-violet-600/10 text-violet-600 dark:text-violet-400">
              ⇩
            </span>
            {t('marketing.getApp')}
          </a>
        </div>

        <MerchantDevices className="py-6" />
      </main>

      {/* The IsleBooks partner promo (owner, 2026-08-24): the one
          commercial detail the front door carries — WHEN the POS bill
          disappears. Same three-point voice as the pitch above. */}
      <section className="border-t border-border bg-violet-600/[0.04]">
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 px-5 py-10 sm:px-8">
          <div className="flex flex-col gap-1.5">
            <span className="text-xs font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-400">
              {t('marketing.posWaiverKicker')}
            </span>
            <h2 className="text-xl font-semibold text-mono sm:text-2xl">
              {t('marketing.posWaiverTitle')}
            </h2>
            <p className="max-w-2xl text-sm text-muted-foreground">
              {t('marketing.posWaiverBody')}
            </p>
          </div>
          <ul className="grid gap-4 sm:grid-cols-3">
            {(
              [
                [TrendingUp, 'marketing.posWaiverRule1'],
                [Percent, 'marketing.posWaiverRule2'],
                [BadgeCheck, 'marketing.posWaiverRule3'],
              ] as const
            ).map(([Icon, key]) => (
              <li key={key} className="flex items-start gap-3">
                <span className="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-600/10">
                  <Icon className="size-4.5 text-violet-600 dark:text-violet-400" />
                </span>
                <span className="text-sm text-muted-foreground">{t(key)}</span>
              </li>
            ))}
          </ul>
          <p className="text-xs text-muted-foreground">
            {t('marketing.posWaiverFoot')}
          </p>
        </div>
      </section>

      <footer className="border-t border-border">
        <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2 px-5 py-5 text-xs text-muted-foreground sm:px-8">
          <span>© {new Date().getFullYear()} Manfaa</span>
          <div className="flex items-center gap-4">
            {/* Legal pages are English-only documents — titles untranslated
                on purpose, matching the pages themselves. */}
            <Link href="/privacy" className="hover:text-foreground">
              Privacy
            </Link>
            <Link href="/terms" className="hover:text-foreground">
              Terms
            </Link>
            <a
              href="https://manfaa.app"
              rel="noopener"
              className="hover:text-foreground"
            >
              {t('marketing.forShoppers')}
            </a>
          </div>
        </div>
      </footer>
    </div>
  );
}
