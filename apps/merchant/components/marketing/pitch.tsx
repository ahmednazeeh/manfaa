'use client';

import { BrandMark } from '@manfaa/ui';
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
      <PhoneFrame className="w-[236px] motion-safe:animate-hero-float sm:w-[252px]" />
    </div>
  );
}

/** The phone bezel — the app dashboard on a handset. */
function PhoneFrame({ className }: { className?: string }) {
  const { t } = useTranslation();
  return (
    <div
      className={cn(
        'overflow-hidden rounded-[2rem] border-[6px] border-zinc-900 bg-zinc-900 shadow-2xl shadow-zinc-900/30 dark:border-zinc-700',
        className,
      )}
    >
      {/* eslint-disable-next-line @next/next/no-img-element -- static marketing asset */}
      <img
        src={toAbsoluteUrl('/app-dashboard-light.png')}
        alt={t('marketing.phoneAlt')}
        width={1170}
        height={2532}
        className="block h-auto w-full dark:hidden"
      />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={toAbsoluteUrl('/app-dashboard-dark.png')}
        alt=""
        aria-hidden
        width={1170}
        height={2532}
        className="hidden h-auto w-full dark:block"
      />
    </div>
  );
}

/** The tablet bezel — the app dashboard on a 10" slate (landscape). */
function TabletFrame({ className }: { className?: string }) {
  const { t } = useTranslation();
  return (
    <div
      className={cn(
        'overflow-hidden rounded-[1.4rem] border-[10px] border-zinc-900 bg-zinc-900 shadow-2xl shadow-zinc-900/25 dark:border-zinc-700',
        className,
      )}
    >
      {/* eslint-disable-next-line @next/next/no-img-element -- static marketing asset */}
      <img
        src={toAbsoluteUrl('/app-tablet-light.png')}
        alt={t('marketing.tabletAlt')}
        width={2560}
        height={1600}
        className="block h-auto w-full dark:hidden"
      />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={toAbsoluteUrl('/app-tablet-dark.png')}
        alt=""
        aria-hidden
        width={2560}
        height={1600}
        className="hidden h-auto w-full dark:block"
      />
    </div>
  );
}

/**
 * The landing hero device shot: on a wide screen the tablet sits behind
 * with the phone standing in front of its lower-right corner (owner
 * request, 2026-08-23). Below `sm` there is no room to stage both, so the
 * phone stands alone — the tablet says nothing the phone does not.
 */
export function MerchantDevices({ className }: { className?: string }) {
  return (
    <div className={cn('relative flex justify-center', className)}>
      <div
        aria-hidden
        className="absolute inset-0 -z-10 scale-90 rounded-full bg-violet-600/15 opacity-70 blur-3xl"
      />

      {/* ≥ sm: tablet behind, phone in front to the right. */}
      <div className="relative hidden w-full max-w-[540px] motion-safe:animate-hero-float sm:block">
        <TabletFrame />
        <PhoneFrame className="absolute -bottom-8 -right-2 w-[30%] max-w-[168px] sm:-right-4 lg:-right-6" />
      </div>

      {/* < sm: the phone alone, centred. */}
      <PhoneFrame className="w-[230px] motion-safe:animate-hero-float sm:hidden" />
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
    // The panel needs ~1075px to draw everything, which is taller than most
    // laptop viewports. `justify-center` overflowed symmetrically with no
    // way to scroll, so a short monitor lost the wordmark off the top AND
    // the phone off the bottom (owner report 2026-08-18). Now the column
    // scrolls, and `m-auto` on the content centres it only when there is
    // room to centre — below that it simply starts at the top.
    <div className="hidden h-screen flex-col overflow-y-auto bg-violet-600/5 p-12 lg:flex xl:p-16">
      <div className="m-auto flex w-full flex-col gap-10">
        <div className="flex items-center gap-2">
          {/* The platform mark, superadmin-replaceable. "Merchant"
            stays as the product suffix it has always been. */}
          <BrandMark className="h-[22px] w-auto" alt="Manfaa" />
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

        {/* The phone is the first thing worth dropping on a short screen:
          it costs 531px and says nothing the words above do not. */}
        <MerchantPhone className="hidden xl:[@media(min-height:900px)]:flex" />
      </div>
    </div>
  );
}
