'use client';

import Link from 'next/link';
import type { DiscoveryOffer } from '@manfaa/api-client';
import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatRate } from '@/lib/format';
import { Badge } from '@/components/ui/badge';
import { ScrollRow } from '@/components/app/scroll-row';
import { StoreAvatar } from '@/components/app/store-avatar';
import { useCategoryLabel, useStoreName } from '@/components/app/store-labels';

/**
 * Featured offers — the curated banners above the directory.
 *
 * A banner is one of two KINDS and never a blend of the two:
 *
 *  - an IMAGE banner is the picture, edge to edge, with nothing printed
 *    over it. The artwork is uploaded at exactly the ratio rendered here,
 *    so it is shown whole;
 *  - a TEXT banner has no artwork and is laid out here from the words and
 *    the live rate — a designed card in the platform's own colours.
 *
 * The blend failed on both counts: copy squeezed into the margin beside a
 * picture cut into the artwork, and the artwork cropped away the half of
 * itself that carried the message.
 *
 * Fixed-width cards in a scroller, not a grid that stretches: two banners
 * filling a row put their artwork edge to edge and one reads as bleeding
 * into the next. Not an auto-advancing carousel either — a banner that
 * moves on its own takes the decision away from the reader mid-sentence
 * and needs a pause control to be usable at all. Swiping is the gesture
 * people already use here.
 *
 * On a TEXT banner every merchant fact is served live by the API: the rate
 * shown is what the store pays right now. An IMAGE banner says whatever
 * its artwork says — the admin owns those words, which is stated plainly
 * where the artwork is uploaded. Either way an offer whose store has
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

      <ScrollRow label={t('discover.featuredOffers')} className="gap-4">
        {offers.map((offer) => {
          const title = localised(offer.title, offer.title_dv) ?? offer.title;

          return (
            <li
              key={offer.id}
              className="w-[19rem] shrink-0 snap-start sm:w-[26rem]"
            >
              <Link
                href={`/store/${offer.merchant.slug}`}
                // 16:9 for both kinds, so a row of banners is level whatever
                // the mix — and so uploaded artwork lands in a slot its own
                // shape and is never cropped.
                className="group block aspect-video overflow-hidden rounded-2xl border border-border transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              >
                {offer.kind === 'image' && offer.image_url !== null ? (
                  <img
                    src={offer.image_url}
                    // The artwork carries the whole message, so it carries
                    // the accessible name too — never alt="".
                    alt={title}
                    loading="lazy"
                    className="size-full object-cover"
                  />
                ) : (
                  <TextBanner
                    badge={localised(offer.badge, offer.badge_dv)}
                    blurb={localised(offer.blurb, offer.blurb_dv)}
                    category={categoryLabel(offer.merchant.category)}
                    logoUrl={offer.merchant.logo_url}
                    rate={formatRate(offer.merchant.cashback_rate_percent)}
                    slug={offer.merchant.slug}
                    storeName={storeName(offer.merchant)}
                    title={title}
                  />
                )}
              </Link>
            </li>
          );
        })}
      </ScrollRow>
    </section>
  );
}

/**
 * The designed text banner. It has the whole card, so it is composed as a
 * card rather than as the leftover column beside a picture: the badge and
 * the store mark ride the top, the headline takes the middle at display
 * size, and the rate anchors the foot as the largest thing on it.
 */
function TextBanner({
  badge,
  blurb,
  category,
  logoUrl,
  rate,
  slug,
  storeName,
  title,
}: {
  badge: string | null;
  blurb: string | null;
  category: string | null;
  logoUrl: string | null;
  rate: string;
  slug: string;
  storeName: string;
  title: string;
}) {
  const { t } = useTranslation();

  return (
    <div className="relative flex size-full flex-col justify-between overflow-hidden bg-brand-soft p-4 sm:p-5">
      {/* One soft shape off the trailing corner so the ground is not a flat
          rectangle. Mostly outside the card and faint enough to read as
          depth rather than as a circle someone left behind. */}
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-24 -end-16 size-52 rounded-full bg-brand/[0.07]"
      />

      <div className="relative flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-2">
          <StoreAvatar
            name={storeName}
            slug={slug}
            logoUrl={logoUrl}
            size="sm"
          />
          <span className="truncate text-xs font-medium text-muted-foreground">
            {storeName}
          </span>
        </div>
        {badge !== null && (
          <Badge
            variant="primary"
            size="sm"
            className="shrink-0 bg-brand text-brand-foreground"
          >
            {badge}
          </Badge>
        )}
      </div>

      <div className="relative flex min-w-0 flex-col gap-1">
        <span className="font-display line-clamp-2 text-lg/tight font-semibold text-mono sm:text-xl/tight">
          {title}
        </span>
        {blurb !== null && (
          <p className="line-clamp-2 text-xs/relaxed text-muted-foreground">
            {blurb}
          </p>
        )}
      </div>

      <div className="relative flex flex-wrap items-baseline gap-x-2">
        <span className="text-2xl font-bold tracking-tight text-brand sm:text-3xl">
          {rate}
        </span>
        <span className="text-xs text-muted-foreground">
          {t('discover.cashbackLabel')}
        </span>
        {category !== null && (
          <span className="ms-auto self-end text-2xs text-muted-foreground">
            {category}
          </span>
        )}
      </div>
    </div>
  );
}
