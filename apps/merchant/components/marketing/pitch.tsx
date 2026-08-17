'use client';

import { HandCoins, Percent, Store } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toAbsoluteUrl } from '@/lib/helpers';
import { cn } from '@/lib/utils';

/**
 * The WL round's shared pitch pieces (PLAN §WL): the same three-point story
 * and phone mockup serve the signed-out landing AND the auth pages' side
 * panel, so the brand story is written once.
 *
 * The phone shows the REAL merchant app Dashboard — rendered by the app's
 * own screenshot harness (marketing shot, real shadows), never a drawn
 * imitation — swapping with the site theme like the manfaa.app hero.
 */
export function MerchantPhone({ className }: { className?: string }) {
  const { t } = useTranslation();

  return (
    <div className={cn('relative flex items-center justify-center', className)}>
      {/* The one soft wash the pitch is allowed. */}
      <div
        aria-hidden
        className="absolute inset-0 -z-10 scale-90 rounded-full bg-violet-600/15 opacity-70 blur-3xl"
      />
      <div className="motion-safe:animate-hero-float">
        <div className="relative w-[236px] overflow-hidden rounded-[2.2rem] border-[6px] border-zinc-900 bg-zinc-900 shadow-2xl shadow-zinc-900/25 dark:border-zinc-700 sm:w-[252px]">
          {/* eslint-disable-next-line @next/next/no-img-element -- static marketing asset, exact pixels wanted */}
          <img
            src={toAbsoluteUrl('/app-dashboard-light.png')}
            alt={t('marketing.phoneAlt')}
            width={780}
            height={1688}
            className="block h-auto w-full dark:hidden"
          />
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={toAbsoluteUrl('/app-dashboard-dark.png')}
            alt=""
            aria-hidden
            width={780}
            height={1688}
            className="hidden h-auto w-full dark:block"
          />
        </div>
      </div>
    </div>
  );
}

const POINTS = [
  { icon: Store, title: 'marketing.point1Title', body: 'marketing.point1Body' },
  {
    icon: Percent,
    title: 'marketing.point2Title',
    body: 'marketing.point2Body',
  },
  {
    icon: HandCoins,
    title: 'marketing.point3Title',
    body: 'marketing.point3Body',
  },
] as const;

export function PitchPoints({ className }: { className?: string }) {
  const { t } = useTranslation();

  return (
    <ul className={cn('flex flex-col gap-4', className)}>
      {POINTS.map(({ icon: Icon, title, body }) => (
        <li key={title} className="flex items-start gap-3.5">
          <span className="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-600/10">
            <Icon className="size-4.5 text-violet-600 dark:text-violet-400" />
          </span>
          <div className="flex flex-col gap-0.5">
            <span className="text-sm font-semibold text-mono">{t(title)}</span>
            <span className="text-sm text-muted-foreground">{t(body)}</span>
          </div>
        </li>
      ))}
    </ul>
  );
}

/**
 * The auth pages' brand half: wordmark, condensed pitch, phone. Rendered
 * only ≥lg — below that the form stands alone, exactly as before.
 */
export function PitchPanel() {
  const { t } = useTranslation();

  return (
    <div className="hidden min-h-screen flex-col justify-center gap-10 bg-violet-600/5 p-12 lg:flex xl:p-16">
      <div className="flex items-center gap-2">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={toAbsoluteUrl('/media/app/mini-logo.svg')}
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

      <div className="flex max-w-md flex-col gap-6">
        <h2 className="text-2xl font-semibold leading-snug text-mono">
          {t('marketing.panelTitle')}
        </h2>
        <PitchPoints />
      </div>

      <MerchantPhone className="hidden xl:flex" />
    </div>
  );
}
