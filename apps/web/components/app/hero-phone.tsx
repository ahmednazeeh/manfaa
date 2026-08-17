'use client';

import { BadgePercent, Wallet } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * The hero's phone: the REAL customer app's Home screen (rendered by the
 * app's own screenshot harness — marketing fixture, not a hand-drawn
 * imitation), inside a CSS device frame that gently floats, with two
 * cashback accents drifting on their own phases. Light and dark screenshots
 * swap with the site theme so the phone always matches its page.
 *
 * All motion sits behind `motion-safe`, so `prefers-reduced-motion` users
 * get a perfectly still hero.
 */
export function HeroPhone() {
  const { t } = useTranslation();

  return (
    <div className="relative mx-auto flex w-full max-w-sm items-center justify-center py-6 lg:py-10">
      {/* The single soft wash the hero is allowed. */}
      <div
        aria-hidden
        className="absolute inset-0 -z-10 rounded-full bg-brand-soft blur-3xl opacity-70 scale-90"
      />

      {/* The device — floats slowly. */}
      <div className="motion-safe:animate-hero-float">
        <div className="relative w-[264px] overflow-hidden rounded-[2.4rem] border-[7px] border-zinc-900 bg-zinc-900 shadow-2xl shadow-zinc-900/25 dark:border-zinc-700 sm:w-[290px]">
          {/* eslint-disable-next-line @next/next/no-img-element -- static marketing asset, exact pixels wanted */}
          <img
            src="/app-home-light.png"
            alt={t('landing.heroPhoneAlt')}
            width={780}
            height={1688}
            className="block h-auto w-full dark:hidden"
          />
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src="/app-home-dark.png"
            alt=""
            aria-hidden
            width={780}
            height={1688}
            className="hidden h-auto w-full dark:block"
          />
        </div>
      </div>

      {/* Cashback toast, drifting on its own phase. */}
      <div
        aria-hidden
        className="absolute -end-1 top-14 motion-safe:animate-hero-float-alt sm:end-0"
      >
        <div className="flex items-center gap-2.5 rounded-2xl border border-border bg-background px-3.5 py-2.5 shadow-lg shadow-zinc-900/10">
          <span className="inline-flex size-8 items-center justify-center rounded-full bg-brand-soft">
            <Wallet className="size-4 text-brand" />
          </span>
          <div className="flex flex-col">
            <span className="text-xs font-semibold text-mono" dir="ltr">
              + MVR 20.00
            </span>
            <span className="text-[10px] text-muted-foreground">
              {t('landing.heroToastLabel')}
            </span>
          </div>
        </div>
      </div>

      {/* Percent chip, low and slow. */}
      <div
        aria-hidden
        className="absolute -start-1 bottom-16 motion-safe:animate-hero-float-slow sm:start-2"
      >
        <span className="inline-flex size-11 items-center justify-center rounded-2xl border border-border bg-background shadow-lg shadow-zinc-900/10">
          <BadgePercent className="size-5 text-brand" />
        </span>
      </div>
    </div>
  );
}
