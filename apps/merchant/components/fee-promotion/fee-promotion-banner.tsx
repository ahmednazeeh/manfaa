'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import {
  feePromotionBanner,
  isActiveFeePromotion,
  type ActiveMerchantFeePromotion,
  type PublicFeePromotionOffer,
} from '@manfaa/api-client';
import { BadgePercent } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatBusinessDate } from '@/lib/dates';
import { formatRate } from '@/lib/estimate';
import { feePromotionKindLabel } from '@/lib/labels';
import { useFeePromotion, usePublicFeePromotion } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/providers/i18n-provider';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/**
 * PLATFORM FEE PROMOTIONS, as a merchant sees them (owner, 2026-08-25: "Show
 * promos when enabled on merchant panel and app, and on the merchant landing
 * as banners when active. I intend to use this feature during initial
 * merchant acquisition.").
 *
 * TWO AUDIENCES, ONE FILE, because they are the same offer said twice and the
 * two must never drift:
 *
 *   MerchantFeePromotionBanner   the signed-in store. What THEY are being
 *                                charged and when THEIR window closes, from
 *                                the authenticated endpoint that resolves the
 *                                one winning offer for them.
 *   PublicFeePromotionOffers     the landing page. What is on offer to
 *                                whoever signs up next, from the
 *                                unauthenticated endpoint — the acquisition
 *                                surface, so it is written as an offer with a
 *                                way to take it, not as a status notice.
 *
 * WHERE THE WORDS COME FROM. The sentence in every banner is the
 * superadmin's, in the reader's own language (`banner_en` / `banner_dv`),
 * fetched per request — a campaign's wording changes without a deploy, and
 * this panel hardcodes no marketing sentence anywhere. What IS in the locale
 * files is the frame around it: which offer this is, the fee as a percent,
 * and when it runs out. Those are facts about the merchant's account rather
 * than copy, they have to be right in both languages, and none of them is a
 * sentence a superadmin would ever want to edit.
 *
 * NOTHING RENDERS WHEN NOTHING IS RUNNING. No skeleton, no empty card, no
 * "no promotions" row: the load is quiet and a failure is silent, because a
 * banner is not something a merchant can be left waiting for and an outage
 * on this endpoint must not stop anyone recording a sale.
 *
 * IT VANISHES THE MOMENT IT EXPIRES — on the clock, not on the next refetch.
 * `ends_at` is an instant, and a panel left open across it would otherwise
 * keep promising a fee the till has already stopped charging. Every window
 * end is watched with a timer that fires exactly at the boundary and asks the
 * server again, so the banner disappears on time — and, when one offer ending
 * hands over to another that is still running, the answer that replaces it is
 * the server's rather than this component's guess.
 */

/** setTimeout's 32-bit ceiling: a longer delay fires immediately instead. */
const MAX_TIMEOUT_MS = 2_147_483_647;

/** Is this instant already behind us? A null end never expires. */
function hasPassed(instant: string | null): boolean {
  if (instant === null) {
    return false;
  }
  const at = Date.parse(instant);
  return Number.isFinite(at) && at <= Date.now();
}

/** An ISO instant as epoch ms, or null when absent or unparseable. */
function boundaryOf(instant: string | null | undefined): number | null {
  if (instant === undefined || instant === null) {
    return null;
  }
  const at = Date.parse(instant);
  return Number.isFinite(at) ? at : null;
}

/**
 * Calls `onExpire` when the clock reaches `at`, re-arming in chunks because
 * setTimeout cannot hold a delay longer than ~24.8 days and a 90-day
 * introductory window is longer than that. Already past means immediately.
 */
function useExpiryWatch(at: number | null, onExpire: () => void): void {
  const latest = useRef(onExpire);

  useEffect(() => {
    latest.current = onExpire;
  }, [onExpire]);

  useEffect(() => {
    if (at === null) {
      return;
    }

    let timer: ReturnType<typeof setTimeout> | undefined;

    const arm = (): void => {
      const remaining = at - Date.now();
      if (remaining <= 0) {
        latest.current();
        return;
      }
      timer = setTimeout(arm, Math.min(remaining, MAX_TIMEOUT_MS));
    };

    arm();

    return () => {
      if (timer !== undefined) {
        clearTimeout(timer);
      }
    };
  }, [at]);
}

/**
 * The promotion pricing THIS store's new sales right now, or null.
 *
 * The single honest answer for the whole panel: the API's own resolution
 * (lower fee wins, never above the store's tier), narrowed so the fee and the
 * kind stop being nullable, and re-checked against the clock so a window that
 * closed while the tab sat open is not still on screen.
 *
 * Exported because it is not only a banner: the credit screen's cost preview
 * prices its estimate from the same figure, and a preview quoting a fee the
 * server is no longer going to charge would be wrong on the one screen where
 * a merchant is deciding what a sale costs them.
 */
export function useActiveFeePromotion(): ActiveMerchantFeePromotion | null {
  const query = useFeePromotion();
  // A counter whose only job is to re-render at the boundary; the expiry
  // itself is read off the clock, below.
  const [, retick] = useState(0);
  const { data: promotion, refetch } = query;

  const expire = useCallback((): void => {
    // Re-render (the check below now reads expired) and ask the server what
    // replaces it — another offer may still be running.
    retick((count) => count + 1);
    void refetch();
  }, [refetch]);

  useExpiryWatch(boundaryOf(promotion?.ends_at), expire);

  if (promotion === undefined || !isActiveFeePromotion(promotion)) {
    return null;
  }

  return hasPassed(promotion.ends_at) ? null : promotion;
}

/**
 * THE LAST DAY THE PROMOTIONAL FEE APPLIES, as "12 Sep 2026", in the BUSINESS
 * timezone like every other rule boundary in this panel (lib/dates.ts): the
 * server decides the window in UTC+5, and a merchant travelling would
 * otherwise read a day either side of the day their fee actually changes.
 *
 * `ends_at` IS EXCLUSIVE — it is the first instant the offer stops pricing a
 * sale, and for an introductory offer it is always exactly midnight Malé, so
 * printing it as a bare date names the first day the merchant pays FULL price
 * again. One day later than the truth, on the one surface a merchant plans
 * against, and flatly at odds with the `days_remaining` count printed beside
 * it: on the final day the panel would have read "24 Sep · 1 day left". So
 * one millisecond comes off first, which lands inside the last chargeable day
 * whatever time of day the boundary sits at.
 *
 * (The till app sidesteps this by printing only the day count, and the admin
 * preview by printing the instant WITH its time, where "00:00" disambiguates
 * the boundary. This panel is the surface that prints a bare date.)
 *
 * Null rather than "Invalid Date" for anything unparseable. The API sends
 * ISO-8601 and zod has already had it, so this cannot happen — but a banner
 * is decoration on somebody's dashboard and must not be the thing that takes
 * the screen down if it ever does.
 */
function formatLastDay(instant: string): string | null {
  const at = boundaryOf(instant);
  return at === null ? null : formatBusinessDate(new Date(at - 1));
}

/**
 * "Last day 12 Sep 2026 · 9 days left", or just the date when the API sent no
 * count, or nothing at all for an offer with no end.
 *
 * Both halves count the same days: the date is the LAST day the promotional
 * fee applies, and the count is how many such days are left including today.
 * On the final day they agree — "Last day 12 Sep 2026 · 1 day left". The
 * wording is "Last day" rather than "Ends" for the same reason the date moved:
 * "Ends the 12th" does not say whether the 12th counts, and a merchant
 * scheduling a large sale around it needs it to.
 *
 * A count of ZERO is dropped rather than printed: it means the window closes
 * today, which the date beside it already says, and "0 days left" beside a
 * promotion that is still pricing sales reads like it has already gone.
 */
function useWindowEnd(
  endsAt: string | null,
  daysRemaining: number | null,
): string | null {
  const { t } = useTranslation();

  const date = endsAt === null ? null : formatLastDay(endsAt);

  if (date === null) {
    return null;
  }

  const ends = t('feePromotion.endsOn', { date });

  return daysRemaining === null || daysRemaining <= 0
    ? ends
    : `${ends} · ${t('feePromotion.daysLeft', { count: daysRemaining })}`;
}

/**
 * How much room the host has. `alert` is the standing banner a screen can
 * give a block to (the dashboard, the settle flow); `inline` is one tinted
 * line for a card that is already a column of figures (the cost preview, the
 * standing-rate card) — same facts, same words, no box inside a box.
 */
export type FeePromotionBannerVariant = 'alert' | 'inline';

export interface MerchantFeePromotionBannerProps {
  variant?: FeePromotionBannerVariant;
  className?: string;
}

/**
 * THE SIGNED-IN BANNER: what this store is being charged, and until when.
 *
 * Rendered wherever a merchant meets the platform fee — the dashboard, the
 * settle flow and the credit cost preview — and beside the standing-rate
 * figure, which quotes the §4 TIER fee and is therefore not what they are
 * paying today. That figure is deliberately left alone (the API's containment
 * rule): this banner is the mechanism that tells the truth beside it.
 */
export function MerchantFeePromotionBanner({
  variant = 'alert',
  className,
}: MerchantFeePromotionBannerProps) {
  const { t } = useTranslation();
  const { language } = useLanguage();
  const promotion = useActiveFeePromotion();
  // Hooks before the early return: the window-end sentence is a translation,
  // not a value, so it costs nothing when there is no promotion.
  const windowEnd = useWindowEnd(
    promotion?.ends_at ?? null,
    promotion?.days_remaining ?? null,
  );

  if (promotion === null) {
    return null;
  }

  const fee = formatRate(promotion.platform_fee_percent);
  const kind = feePromotionKindLabel(t, promotion.kind);
  // The superadmin's own sentence. Null only if a campaign was somehow
  // enabled without wording (the API refuses to), in which case the frame
  // below still tells the merchant the fee — the part they are owed.
  const sentence = feePromotionBanner(promotion, language);

  if (variant === 'inline') {
    return (
      <div
        className={cn(
          'flex flex-col gap-1 rounded-md bg-violet-600/[0.07] px-3 py-2',
          className,
        )}
      >
        <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs font-medium text-violet-600 dark:text-violet-400">
          <BadgePercent className="size-3.5 shrink-0" aria-hidden />
          {t('feePromotion.merchantTitle', { fee })}
          <span className="font-normal text-muted-foreground">{kind}</span>
        </span>
        {sentence !== null && (
          <span className="text-xs text-muted-foreground">{sentence}</span>
        )}
        {windowEnd !== null && (
          <span className="text-xs text-muted-foreground">{windowEnd}</span>
        )}
      </div>
    );
  }

  return (
    <Alert variant="info" appearance="light" className={className}>
      <AlertIcon>
        <BadgePercent />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          <span className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            {t('feePromotion.merchantTitle', { fee })}
            <span className="text-xs font-normal text-muted-foreground">
              {kind}
            </span>
          </span>
        </AlertTitle>
        <AlertDescription>
          <span className="flex flex-col gap-1">
            {sentence !== null && <span>{sentence}</span>}
            {windowEnd !== null && (
              <span className="text-xs text-muted-foreground">{windowEnd}</span>
            )}
            {/* The one thing a promotional fee must not be allowed to imply:
                a settlement already on the board does not get cheaper. Every
                recorded sale keeps the fee it was rung up under (the server
                stamps it), so this banner is a statement about the NEXT sale
                — which is exactly what it says. */}
            <span className="text-xs text-muted-foreground">
              {t('feePromotion.newSalesNote')}
            </span>
          </span>
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

/**
 * THE PUBLIC BAND: every offer currently on the table, on the front door.
 *
 * Both kinds can be live at once and neither wins here — the merchant
 * endpoint picks one because only one can price a sale, but a landing page is
 * advertising, so each running offer gets its own banner.
 *
 * An introductory offer is published as "your first X days", never as a date:
 * a visitor has no approval stamp, and any date printed here would be a
 * promise about a merchant they are not yet. A platform-wide offer's end IS
 * printed — that is the platform's own deadline, and a deadline is the part
 * of an offer that makes somebody act.
 *
 * One call to action for the whole band rather than one per offer: whichever
 * offer moved them, the next step is the same door.
 */
export function PublicFeePromotionOffers({
  className,
}: {
  className?: string;
}) {
  const query = usePublicFeePromotion();
  const [, retick] = useState(0);
  const { t } = useTranslation();
  const { refetch } = query;

  const offers = query.data?.offers ?? [];
  const live = offers.filter((offer) => !hasPassed(offer.ends_at));

  // The first window to close, so one timer covers the band. Whatever it is
  // that ends, the server is asked again and the band re-renders with what is
  // left — possibly nothing.
  const nextEnd = live.reduce<number | null>((earliest, offer) => {
    const at = boundaryOf(offer.ends_at);
    if (at === null) {
      return earliest;
    }
    return earliest === null || at < earliest ? at : earliest;
  }, null);

  const expire = useCallback((): void => {
    retick((count) => count + 1);
    void refetch();
  }, [refetch]);

  useExpiryWatch(nextEnd, expire);

  if (live.length === 0) {
    return null;
  }

  return (
    <section
      className={cn(
        'border-b border-border bg-violet-600/[0.06]',
        className,
      )}
    >
      <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 px-5 py-7 sm:px-8 lg:flex-row lg:items-center lg:justify-between lg:gap-8">
        <div
          className={cn(
            'grid grow gap-5',
            live.length > 1 && 'sm:grid-cols-2',
          )}
        >
          {live.map((offer) => (
            <PublicOfferBanner key={offer.kind} offer={offer} />
          ))}
        </div>
        <Button asChild size="lg" className="w-fit shrink-0 px-6">
          <Link href="/signup">{t('marketing.register')}</Link>
        </Button>
      </div>
    </section>
  );
}

/** One offer on the front door: the terms, then the superadmin's pitch. */
function PublicOfferBanner({ offer }: { offer: PublicFeePromotionOffer }) {
  const { t } = useTranslation();
  const { language } = useLanguage();

  const sentence = feePromotionBanner(offer, language);
  // Whichever of the two an offer of this kind carries — never both, and
  // never a date for the introductory one: a visitor has no approval stamp,
  // so the only true thing to say about its length is how long it runs.
  //
  // The LAST day, not the exclusive boundary. A superadmin setting a campaign
  // to run "through the 30th" naturally types midnight on the 1st, and a
  // marketing claim that names the 1st is a claim the platform will not
  // honour — this is the front door, where the date is the thing that makes
  // somebody act.
  const endsOn = offer.ends_at === null ? null : formatLastDay(offer.ends_at);
  const terms =
    offer.intro_days !== null
      ? t('feePromotion.offerIntroDays', { count: offer.intro_days })
      : endsOn !== null
        ? t('feePromotion.offerEnds', { date: endsOn })
        : null;

  return (
    <div className="flex items-start gap-3">
      <span className="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-600/15">
        <BadgePercent className="size-4.5 text-violet-600 dark:text-violet-400" />
      </span>
      <div className="flex flex-col gap-1">
        <span className="text-xs font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-400">
          {t('feePromotion.offerKicker')}
        </span>
        <span className="text-lg font-semibold text-mono">
          {t('feePromotion.offerFee', {
            fee: formatRate(offer.platform_fee_percent),
          })}
        </span>
        {sentence !== null && (
          <span className="text-sm text-muted-foreground">{sentence}</span>
        )}
        {terms !== null && (
          <span className="text-xs text-muted-foreground">{terms}</span>
        )}
      </div>
    </div>
  );
}
