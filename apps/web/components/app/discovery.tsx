'use client';

import { useState, type ReactNode } from 'react';
import Link from 'next/link';
import {
  percentToBp,
  type DiscoveryEntry,
  type DiscoverySections,
} from '@manfaa/api-client';
import { ArrowRight, MapPin } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate, formatRate, splitDistance } from '@/lib/format';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { EmptyBlock } from '@/components/app/async-states';
import { ScrollRow } from '@/components/app/scroll-row';
import { StoreAvatar } from '@/components/app/store-avatar';
import {
  ChannelChip,
  useCategoryLabel,
  useStoreName,
} from '@/components/app/store-labels';

/**
 * Merchant discovery building blocks shared by the public landing page and
 * the /discover storefront. Data comes from GET /api/discover (public, no
 * auth); rates arrive as 2-decimal percent STRINGS (PLAN §1 wire format),
 * rendered verbatim by formatRate and compared — never rendered — through
 * percentToBp.
 *
 * ONE card anatomy serves every surface (MerchantCard): the logo tile is
 * the hero of the card, the rate sits large underneath it, a boosted store
 * strikes out its standing rate, and the channel is always a chip, never
 * the raw enum. Shelves (a scrolling row, landing) and sections (a grid,
 * /discover) differ only in how they lay that one card out.
 */

export type GeoState =
  | { kind: 'idle' }
  | { kind: 'locating' }
  | { kind: 'granted'; lat: number; lng: number }
  | { kind: 'denied' }
  | { kind: 'unavailable' };

/**
 * Browser geolocation behind an explicit user gesture — call requestLocation
 * from a button, never on mount, so the permission prompt is always the
 * user's own doing.
 */
export function useLocationRequest() {
  const [geo, setGeo] = useState<GeoState>({ kind: 'idle' });
  const coords = geo.kind === 'granted' ? { lat: geo.lat, lng: geo.lng } : null;

  const requestLocation = () => {
    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
      setGeo({ kind: 'unavailable' });
      return;
    }
    setGeo({ kind: 'locating' });
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setGeo({
          kind: 'granted',
          lat: position.coords.latitude,
          lng: position.coords.longitude,
        });
      },
      (positionError) => {
        setGeo({
          kind:
            positionError.code === positionError.PERMISSION_DENIED
              ? 'denied'
              : 'unavailable',
        });
      },
      { maximumAge: 5 * 60 * 1000, timeout: 15_000 },
    );
  };

  return { geo, coords, requestLocation };
}

/**
 * A live promotion is exactly "the rate now differs from the usual rate" —
 * compared in basis points, the unit rate arithmetic belongs in, never by
 * comparing the display strings.
 */
export function isBoosted(entry: {
  cashback_rate_percent: string;
  standing_cashback_rate_percent: string;
}): boolean {
  return (
    percentToBp(entry.cashback_rate_percent) >
    percentToBp(entry.standing_cashback_rate_percent)
  );
}

/**
 * The in-store facet of the read model: the API's own `in_store` shelf, the
 * exact mirror of `online` (both filter the complete listed set, then cap).
 *
 * This used to be derived here by filtering `recently_added`, which was
 * wrong: that shelf is itself capped at the per-section ceiling, so once the
 * newest capped-many listings were all online, the "In store" rail chip and
 * shelf vanished while the directory grid on the same screen still listed
 * every in-store store. Never re-derive one facet from another shelf — ask
 * the server for the facet. Like every shelf it is a teaser: the
 * authoritative, unbounded list is the paginated directory behind
 * "All stores".
 */
export function inStoreEntries(sections: DiscoverySections): DiscoveryEntry[] {
  return sections.in_store;
}

/**
 * Whether the platform has anything to show at all.
 *
 * `recently_added` alone would answer this, being the complete listed set —
 * but it carries a `.catch([])` for the deploy window in which an older API
 * build has not started sending it, and an empty shelf must never be read
 * as an empty PLATFORM. Any populated shelf is proof of life.
 */
export function hasListedStores(sections: DiscoverySections): boolean {
  return (
    sections.recently_added.length > 0 ||
    sections.featured.length > 0 ||
    sections.increased.length > 0 ||
    sections.in_store.length > 0 ||
    sections.online.length > 0
  );
}

function DistanceLine({ meters }: { meters: number }) {
  const { t } = useTranslation();
  const distance = splitDistance(meters);
  return (
    <span className="inline-flex items-center gap-1">
      <MapPin className="size-3" />
      {distance.unit === 'm'
        ? t('discover.distanceMeters', { meters: distance.value })
        : t('discover.distanceKm', { km: distance.value })}
    </span>
  );
}

/**
 * Wraps a merchant card in its store-page link. Every card anywhere in the
 * app is clickable through this — the slug is the only identifier that ever
 * travels in a URL.
 */
/**
 * The one merchant card, shared by the landing shelves and the /discover
 * grid (§1 store-channel decision).
 *
 * Anatomy: the logo tile as the card's hero, then the NAME, then the
 * cashback rate as the largest thing on the card after it — that number is
 * why anyone is looking — then the facts a shopper decides on: what kind of
 * shop, whether they can walk in or must go online, how far away it is, and
 * a way to read the terms before they go.
 *
 * The whole card is one click target via the stretched-link pattern: the
 * store link is an absolutely positioned overlay, so the card body stays
 * plain markup and "Terms" can be its own real link on top of it. Nesting a
 * second anchor inside the first would be invalid HTML and would swallow
 * one of the two destinations.
 */
export function MerchantCard({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();
  const categoryLabel = useCategoryLabel();
  const storeName = useStoreName();
  const boosted = isBoosted(entry);
  const category = categoryLabel(entry.category);
  const name = storeName(entry);

  return (
    <Card className="group relative h-full transition-colors focus-within:border-brand/40 hover:border-brand/40">
      {/* The stretched link: covers the card, sits under the Terms link. */}
      <Link
        href={`/store/${entry.slug}`}
        aria-label={name}
        className="absolute inset-0 z-0 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      />

      <div className="p-2 pb-0">
        {/* Square, so an uploaded square logo fills the slot instead of
            being letterboxed inside a 4:3 box — edge to edge, with the
            card's own padding as the only frame it needs. */}
        <div className="aspect-square w-full overflow-hidden rounded-lg">
          <StoreAvatar
            name={name}
            slug={entry.slug}
            logoUrl={entry.logo_url}
            size="tile"
          />
        </div>
      </div>

      <div className="flex grow flex-col gap-1 p-3 pt-2.5">
        <span className="line-clamp-2 text-sm font-semibold text-mono">
          {name}
        </span>

        <div className="flex flex-wrap items-baseline gap-x-2">
          {/* The rate leads the card, but a shelf is a row of these: at
              display size it shouted over the name it belongs to. */}
          <span className="text-lg font-bold tracking-tight text-brand">
            {t('discover.rate', {
              rate: formatRate(entry.cashback_rate_percent),
            })}
          </span>
          {boosted && (
            <span className="text-xs text-muted-foreground line-through">
              {t('discover.usuallyRate', {
                rate: formatRate(entry.standing_cashback_rate_percent),
              })}
            </span>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-1.5">
          <ChannelChip channel={entry.channel} />
          {category !== null && (
            <Badge variant="secondary" appearance="light" size="sm">
              {category}
            </Badge>
          )}
        </div>

        <div className="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 pt-1.5 text-2xs text-muted-foreground">
          {entry.distance_m !== null && (
            <DistanceLine meters={entry.distance_m} />
          )}
          {boosted && entry.promo_ends_at !== null && (
            <span>
              {t('discover.promoUntil', {
                date: formatDate(entry.promo_ends_at),
              })}
            </span>
          )}
          {/* Above the stretched link, so it is a genuine second
              destination: what this store counts as eligible, before the
              shopper walks in. */}
          <Link
            href={`/store/${entry.slug}#terms`}
            className="relative z-10 ms-auto underline underline-offset-2 hover:text-foreground"
          >
            {t('discover.termsLink')}
          </Link>
        </div>
      </div>
    </Card>
  );
}

/** Shelf/section heading with its optional "see all" link and actions. */
function ShelfHeader({
  icon: Icon,
  title,
  viewAllHref,
  children,
}: {
  icon: LucideIcon;
  title: string;
  viewAllHref?: string;
  children?: ReactNode;
}) {
  const { t } = useTranslation();

  return (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h2 className="flex items-center gap-2 text-base font-semibold text-mono">
        <Icon className="size-4 text-muted-foreground" />
        {title}
      </h2>
      <div className="flex items-center gap-3">
        {children}
        {viewAllHref !== undefined && (
          <Link
            href={viewAllHref}
            className="inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline"
          >
            {t('discover.viewAll')}
            <ArrowRight className="size-3.5 rtl:rotate-180" />
          </Link>
        )}
      </div>
    </div>
  );
}

/**
 * A landing SHELF: heading, "see all", and one horizontally scrolling row
 * of cards. It renders nothing at all when empty — the landing page never
 * shows a shelf explaining that it has nothing in it.
 */
export function StoreShelf({
  icon,
  title,
  entries,
  viewAllHref,
}: {
  icon: LucideIcon;
  title: string;
  entries: DiscoveryEntry[];
  viewAllHref?: string;
}) {
  if (entries.length === 0) {
    return null;
  }

  return (
    <section className="flex flex-col gap-3">
      <ShelfHeader icon={icon} title={title} viewAllHref={viewAllHref} />
      <ScrollRow label={title}>
        {entries.map((entry) => (
          <li key={entry.slug} className="w-36 shrink-0 snap-start sm:w-40">
            <MerchantCard entry={entry} />
          </li>
        ))}
      </ScrollRow>
    </section>
  );
}

/**
 * A /discover SECTION: the same heading, but the cards lay out as a grid
 * and an empty section explains itself instead of vanishing — inside the
 * storefront, a shelf that silently disappears is a missing answer.
 */
export function DiscoverySection({
  icon,
  title,
  entries,
  emptyText,
  viewAllHref,
  children,
}: {
  icon: LucideIcon;
  title: string;
  entries: DiscoveryEntry[];
  emptyText?: string;
  /** When set, a "View all" link renders beside the section title. */
  viewAllHref?: string;
  /** Header-side actions (e.g. the "Use my location" button). */
  children?: ReactNode;
}) {
  if (entries.length === 0 && emptyText === undefined) {
    return null;
  }

  return (
    <section className="flex flex-col gap-3">
      <ShelfHeader icon={icon} title={title} viewAllHref={viewAllHref}>
        {children}
      </ShelfHeader>
      {entries.length === 0 ? (
        <Card>
          <EmptyBlock>{emptyText}</EmptyBlock>
        </Card>
      ) : (
        <MerchantGrid entries={entries} />
      )}
    </section>
  );
}

/** The shared card grid — narrower columns than a text list, because the
 *  card is a compact portrait tile rather than a row. The column count
 *  keeps climbing on wide screens: a directory reads as a directory, not
 *  as a handful of oversized posters. */
export function MerchantGrid({ entries }: { entries: DiscoveryEntry[] }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7">
      {entries.map((entry) => (
        <MerchantCard key={entry.slug} entry={entry} />
      ))}
    </div>
  );
}
