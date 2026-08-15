'use client';

import { useEffect, useState } from 'react';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import { BadgeCheck, Store, Wallet } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/**
 * The hero's right-hand panel: a phone showing the customer app while a
 * sale is credited. It answers the question the headline raises — "what
 * actually happens when I shop?" — as a short loop:
 *
 *   0  the 6-digit code, ready to show at the till
 *   1  the sale lands, still being confirmed by the store
 *   2  ka-ching — the cashback is credited and the balance ticks up
 *
 * It is a DEMONSTRATION, not data: the figures are fixed, the store is a
 * deliberately generic placeholder, and nothing here is fetched. The whole
 * panel is aria-hidden with one sentence of alternative text beside it,
 * because a looping animation read aloud is noise rather than information.
 *
 * Money renders through MoneyText, so the amounts follow the page language
 * — "MVR 9.60" in English, "9.60 ރުފިޔާ" in Dhivehi — rather than being
 * baked into the artwork.
 *
 * Under `prefers-reduced-motion` the loop never starts and the panel rests
 * on its final frame, which is the one that carries the point.
 */

const STEP_MS = 2600;
const STEPS = 3;

/** Fixed demo figures, in integer laari. */
const BALANCE_BEFORE = 124_000;
const SALE = 48_000;
const CASHBACK = 960;

export function CashbackDemo({ className }: { className?: string }) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const [step, setStep] = useState(0);

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      setStep(STEPS - 1);
      return;
    }

    const id = window.setInterval(
      () => setStep((current) => (current + 1) % STEPS),
      STEP_MS,
    );

    return () => window.clearInterval(id);
  }, []);

  const credited = step === 2;

  return (
    <div className={cn('relative flex justify-center', className)}>
      {/* One soft brand wash behind the phone — the hero's only large area
          of colour, kept well under the old three-stop gradient. */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-4 top-8 bottom-4 rounded-[3rem] bg-primary/10 blur-2xl"
      />

      <div
        aria-hidden="true"
        className="relative w-[19rem] rounded-[2.25rem] border border-border bg-card p-3.5 shadow-xl shadow-black/5 sm:w-[21rem]"
      >
        {/* Handset speaker */}
        <div className="mx-auto mb-3 h-1 w-12 rounded-full bg-muted-foreground/25" />

        {/* The screen is taller than its content on purpose: the ka-ching
            notification is absolutely positioned at the bottom of the
            handset, and without reserved space it lands on top of the sale
            row it is announcing. */}
        <div className="flex min-h-[19rem] flex-col gap-3 rounded-2xl bg-background p-3">
          {/* Wallet header: the balance is the number the whole loop moves */}
          <div className="flex items-center justify-between gap-2 rounded-xl bg-secondary/60 px-3 py-2.5">
            <span className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
              <Wallet className="size-3.5" />
              {t('landing.demoBalance')}
            </span>
            <MoneyText
              laari={credited ? BALANCE_BEFORE + CASHBACK : BALANCE_BEFORE}
              className={cn(
                'text-sm font-semibold transition-colors duration-500',
                credited ? 'text-primary' : 'text-mono',
              )}
            />
          </div>

          {/* The customer code, shown at the till */}
          <div
            className={cn(
              'flex flex-col items-center gap-1.5 rounded-xl border border-dashed border-border px-3 py-3 transition-opacity duration-500',
              step === 0 ? 'opacity-100' : 'opacity-40',
            )}
          >
            <span className="text-2xs tracking-wide text-muted-foreground uppercase">
              {t('landing.demoCodeLabel')}
            </span>
            <span
              dir="ltr"
              className="font-mono text-lg font-semibold tracking-[0.3em] text-mono"
            >
              482917
            </span>
          </div>

          {/* The sale line, revealed from step 1 */}
          <div
            className={cn(
              'flex items-center gap-2.5 rounded-xl border border-border px-3 py-2.5 transition-[opacity,transform] duration-500 ease-out',
              step === 0
                ? 'translate-y-1 opacity-0'
                : 'translate-y-0 opacity-100',
            )}
          >
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-secondary text-secondary-foreground">
              <Store className="size-4" />
            </span>
            <span className="flex min-w-0 flex-col">
              <span className="truncate text-xs font-medium text-mono">
                {t('landing.demoStore')}
              </span>
              <MoneyText
                laari={SALE}
                className="text-2xs text-muted-foreground"
              />
            </span>
            <span
              className={cn(
                'ms-auto shrink-0 text-2xs font-semibold transition-colors duration-500',
                credited ? 'text-primary' : 'text-muted-foreground',
              )}
            >
              {credited ? t('landing.demoEarned') : t('landing.demoPending')}
            </span>
          </div>
        </div>

        {/* Ka-ching. Slides up over the screen on the last step. */}
        <div
          className={cn(
            'pointer-events-none absolute inset-x-4 bottom-4 flex items-start gap-2.5 rounded-xl border border-border bg-card px-3 py-2.5 shadow-lg shadow-black/10 transition-[opacity,transform] duration-500 ease-out',
            credited
              ? 'translate-y-0 opacity-100'
              : 'translate-y-3 opacity-0',
          )}
        >
          <BadgeCheck className="mt-0.5 size-4 shrink-0 text-primary" />
          <span className="flex flex-col gap-0.5">
            <span className="text-xs font-semibold text-mono">
              {t('landing.demoToastTitle')}
            </span>
            <span className="text-2xs text-muted-foreground">
              {t('landing.demoToastBody', {
                amount: formatMoney(CASHBACK),
                store: t('landing.demoStore'),
              })}
            </span>
          </span>
        </div>
      </div>

      {/* What the animation says, once, for anyone not watching it. */}
      <p className="sr-only">
        {t('landing.demoAlt', {
          amount: formatMoney(CASHBACK),
          sale: formatMoney(SALE),
          store: t('landing.demoStore'),
        })}
      </p>
    </div>
  );
}
