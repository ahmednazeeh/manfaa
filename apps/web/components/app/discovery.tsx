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
import { Card } from '@/components/ui/card';
import { EmptyBlock } from '@/components/app/async-states';
import { ScrollRow } from '@/components/app/scroll-row';
import { StoreAvatar } from '@/components/app/store-avatar';
import { ChannelChip, useCategoryLabel } from '@/components/app/store-labels';

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
function StoreLink({ slug, children }: { slug: string; children: ReactNode }) {
  return (
    <Link
      href={`/store/${slug}`}
      className="group block h-full rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      {children}
    </Link>
  );
}

/**
 * The one merchant card, shared by the landing shelves and the /discover
 * grid (§1 store-channel decision).
 *
 * Anatomy, top to bottom: the logo tile as the card's hero — no store has
 * uploaded a logo yet, so today that tile is the deterministic initials
 * mark and it carries the card on its own — then the name, the rate large
 * ("2% cashback"), the struck-through standing rate whenever a promotion is
 * beating it, and finally the channel chip with the muted meta.
 */
export function MerchantCard({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();
  const categoryLabel = useCategoryLabel();
  const boosted = isBoosted(entry);
  const category = categoryLabel(entry.category);

  return (
    <StoreLink slug={entry.slug}>
      <Card className="h-full transition-colors group-hover:border-primary/40">
        <div className="p-3 pb-0">
          <div className="aspect-[4/3] w-full overflow-hidden rounded-lg">
            <StoreAvatar
              name={entry.name}
              slug={entry.slug}
              logoUrl={entry.logo_url}
              size="tile"
            />
          </div>
        </div>

        <div className="flex grow flex-col gap-1 p-4 pt-3">
          <span className="truncate text-sm font-medium text-mono">
            {entry.name}
          </span>

          <div className="flex flex-wrap items-baseline gap-x-2">
            <span className="text-xl font-bold tracking-tight text-primary">
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

          <div className="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 pt-2 text-2xs text-muted-foreground">
            <ChannelChip channel={entry.channel} />
            {category !== null && <span>{category}</span>}
            {boosted && entry.promo_ends_at !== null && (
              <span>
                {t('discover.promoUntil', {
                  date: formatDate(entry.promo_ends_at),
                })}
              </span>
            )}
            {entry.distance_m !== null && (
              <DistanceLine meters={entry.distance_m} />
            )}
          </div>
        </div>
      </Card>
    </StoreLink>
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
            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
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
          <li key={entry.slug} className="w-44 shrink-0 snap-start sm:w-48">
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
 *  card is now a portrait tile rather than a row. */
export function MerchantGrid({ entries }: { entries: DiscoveryEntry[] }) {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
      {entries.map((entry) => (
        <MerchantCard key={entry.slug} entry={entry} />
      ))}
    </div>
  );
}
