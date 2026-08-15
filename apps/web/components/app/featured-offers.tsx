'use client';

import Link from 'next/link';
import type { DiscoveryOffer } from '@manfaa/api-client';
import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatRate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { StoreAvatar } from '@/components/app/store-avatar';
import { useCategoryLabel, useStoreName } from '@/components/app/store-labels';

/**
 * Featured offers — the curated image banners above the directory.
 *
 * Each banner is a wide card: badge, store mark, headline, blurb, the live
 * cashback figure, and the artwork itself. The copy sits over a tint rather
 * than over the picture — text on an arbitrary uploaded image is a contrast
 * bet the platform cannot win — and the artwork takes a THIRD of the card,
 * not a half: at half it stopped reading as the illustration to an offer
 * and started reading as two panels arguing.
 *
 * The cards lay out as a grid that fills its row, so one offer is a full
 * banner rather than a stub floating in white space. No auto-advancing
 * carousel: a banner that moves on its own takes the decision away from the
 * reader mid-sentence and needs a pause control to be usable at all.
 *
 * Every merchant fact rendered here is served live by the API — the rate
 * shown is what the store pays right now, and an offer whose store has
 * stopped trading never arrives.
 */
export function FeaturedOffers({ offers }: { offers: DiscoveryOffer[] }) {
  const { t, i18n } = useTranslation();
  const storeName = useStoreName();
  const categoryLabel = useCategoryLabel();
  const dhivehi = i18n.language.startsWith('dv');

  if (offers.length === 0) {
    return null;
  }

  /** The Dhivehi string when the page is Dhivehi and one was written. */
  const localised = (en: string | null, dv: string | null): string | null =>
    (dhivehi && dv !== null && dv.trim() !== '' ? dv : en);

  return (
    <section className="container flex flex-col gap-3 pt-5">
      {/* No "see all" here, unlike the store shelves: every live offer is
          already in this row, and the only page it could lead to is the one
          the reader is standing on. */}
      <h2 className="flex items-center gap-2 text-base font-semibold text-mono">
        <Sparkles className="size-4 text-brand" />
        {t('discover.featuredOffers')}
      </h2>

      {/* One offer spans the row; from two on they pair up. A curated
          section holds a handful, so this never becomes a wall. */}
      <ul
        className={cn(
          'grid gap-4',
          offers.length > 1 && 'md:grid-cols-2',
        )}
      >
        {offers.map((offer) => {
          const category = categoryLabel(offer.merchant.category);
          const badge = localised(offer.badge, offer.badge_dv);
          const blurb = localised(offer.blurb, offer.blurb_dv);
          const title = localised(offer.title, offer.title_dv) ?? offer.title;

          return (
            <li key={offer.id}>
              <Link
                href={`/store/${offer.merchant.slug}`}
                className="group flex h-full min-h-36 overflow-hidden rounded-2xl border border-border bg-brand-soft transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              >
                <div className="flex min-w-0 flex-1 flex-col gap-2 p-4">
                  {badge !== null && (
                    <Badge
                      variant="primary"
                      size="sm"
                      className="w-fit bg-brand text-brand-foreground"
                    >
                      {badge}
                    </Badge>
                  )}

                  <div className="flex items-center gap-2">
                    <StoreAvatar
                      name={storeName(offer.merchant)}
                      slug={offer.merchant.slug}
                      logoUrl={offer.merchant.logo_url}
                      size="sm"
                    />
                    <span className="truncate text-sm font-semibold text-mono">
                      {title}
                    </span>
                  </div>

                  {blurb !== null && (
                    <p className="line-clamp-2 text-xs/relaxed text-muted-foreground">
                      {blurb}
                    </p>
                  )}

                  <div className="mt-auto flex flex-wrap items-baseline gap-x-2 pt-1">
                    <span className="text-xl font-bold tracking-tight text-brand">
                      {formatRate(offer.merchant.cashback_rate_percent)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {t('discover.cashbackLabel')}
                    </span>
                  </div>

                  {category !== null && (
                    <span className="text-2xs text-muted-foreground">
                      {category}
                    </span>
                  )}
                </div>

                {/* The artwork takes a third of whatever width the card
                    gets, floored so it survives a narrow phone and capped
                    so a full-width banner does not turn into a mural. Its
                    height is governed by the copy, never by whatever the
                    admin uploaded, so a row of banners stays level. */}
                <div className="w-1/3 min-w-24 max-w-72 shrink-0 self-stretch overflow-hidden">
                  <img
                    src={offer.image_url}
                    alt=""
                    loading="lazy"
                    className="size-full object-cover"
                  />
                </div>
              </Link>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
