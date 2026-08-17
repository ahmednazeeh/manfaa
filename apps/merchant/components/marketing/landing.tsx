'use client';

import Link from 'next/link';
import { useTranslation } from 'react-i18next';
import { toAbsoluteUrl } from '@/lib/helpers';
import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/app/language-switcher';
import { MerchantPhone, PitchPoints } from '@/components/marketing/pitch';

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
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={toAbsoluteUrl('/media/app/mini-logo.svg?v=mf2')}
            className="h-5"
            alt=""
            aria-hidden
          />
          <span className="text-lg font-semibold tracking-tight text-mono">
            {t('common.appName')}
          </span>
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
        </div>

        <MerchantPhone className="py-6" />
      </main>

      <footer className="border-t border-border">
        <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2 px-5 py-5 text-xs text-muted-foreground sm:px-8">
          <span>© {new Date().getFullYear()} Manfaa</span>
          <a
            href="https://manfaa.app"
            rel="noopener"
            className="hover:text-foreground"
          >
            {t('marketing.forShoppers')}
          </a>
        </div>
      </footer>
    </div>
  );
}
