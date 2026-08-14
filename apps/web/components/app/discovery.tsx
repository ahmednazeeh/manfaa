'use client';

import { useState, type ComponentType, type ReactNode } from 'react';
import Link from 'next/link';
import { type DiscoveryEntry } from '@manfaa/api-client';
import { ArrowRight, MapPin } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate, formatRateBp, splitDistance } from '@/lib/format';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyBlock } from '@/components/app/async-states';
import { StoreAvatar } from '@/components/app/store-avatar';

/**
 * Merchant discovery building blocks shared by the public landing page and
 * the authenticated /discover screen. Data comes from GET /api/discover
 * (public, no auth); rates are integer basis points end to end and only
 * formatRateBp turns them into display strings.
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
      className="group block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      {children}
    </Link>
  );
}

const clickableCard =
  'h-full transition-colors group-hover:border-primary/40 group-hover:bg-muted/40';

/** Standard merchant card: name, category, current rate, promo/distance. */
export function MerchantCard({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();
  const boosted = entry.rate_bp > entry.standing_rate_bp;

  return (
    <StoreLink slug={entry.slug}>
      <Card className={clickableCard}>
        <CardContent className="flex flex-col gap-2 p-5">
          <div className="flex items-start justify-between gap-2">
            <div className="flex min-w-0 items-center gap-3">
              <StoreAvatar
                name={entry.name}
                slug={entry.slug}
                logoUrl={entry.logo_url}
              />
              <span className="text-sm font-medium text-mono">
                {entry.name}
              </span>
            </div>
            {entry.category !== null && (
              <Badge variant="secondary" appearance="light" size="sm">
                {entry.category}
              </Badge>
            )}
          </div>

          <span className="text-lg font-semibold text-mono">
            {boosted
              ? t('discover.rateUsually', {
                  rate: formatRateBp(entry.rate_bp),
                  usual: formatRateBp(entry.standing_rate_bp),
                })
              : t('discover.rate', { rate: formatRateBp(entry.rate_bp) })}
          </span>

          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
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
        </CardContent>
      </Card>
    </StoreLink>
  );
}

/**
 * Promotion card for the "increased cashback" shelf: the boosted rate is the
 * headline, the standing rate is struck out beside it, and the promo end
 * date sits in a chip.
 */
export function PromoCard({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();

  return (
    <StoreLink slug={entry.slug}>
      <Card className={clickableCard}>
        <CardContent className="flex flex-col gap-3 p-5">
          <div className="flex items-start justify-between gap-2">
            <div className="flex min-w-0 items-center gap-3">
              <StoreAvatar
                name={entry.name}
                slug={entry.slug}
                logoUrl={entry.logo_url}
              />
              <span className="text-sm font-medium text-mono">
                {entry.name}
              </span>
            </div>
            {entry.promo_ends_at !== null && (
              <Badge variant="warning" appearance="light" size="sm">
                {t('discover.endsChip', {
                  date: formatDate(entry.promo_ends_at),
                })}
              </Badge>
            )}
          </div>

          <div className="flex items-baseline gap-2">
            <span className="text-3xl font-bold tracking-tight text-primary">
              {formatRateBp(entry.rate_bp)}
            </span>
            <span className="text-sm text-muted-foreground">
              {t('discover.cashbackLabel')}
            </span>
          </div>

          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            <span className="line-through">
              {t('discover.usuallyRate', {
                rate: formatRateBp(entry.standing_rate_bp),
              })}
            </span>
            {entry.category !== null && <span>{entry.category}</span>}
            {entry.distance_m !== null && (
              <DistanceLine meters={entry.distance_m} />
            )}
          </div>
        </CardContent>
      </Card>
    </StoreLink>
  );
}

/**
 * One titled shelf of merchant cards. When `entries` is empty: renders the
 * `emptyText` block if one is given, and disappears entirely otherwise —
 * the landing page hides empty shelves, the app shows a hint instead.
 */
export function DiscoverySection({
  icon: Icon,
  title,
  entries,
  emptyText,
  viewAllHref,
  card: CardComponent = MerchantCard,
  children,
}: {
  icon: LucideIcon;
  title: string;
  entries: DiscoveryEntry[];
  emptyText?: string;
  /** When set, a "View all" link renders beside the shelf title. */
  viewAllHref?: string;
  /** Card renderer; defaults to MerchantCard. */
  card?: ComponentType<{ entry: DiscoveryEntry }>;
  /** Header-side actions (e.g. the "Use my location" button). */
  children?: ReactNode;
}) {
  const { t } = useTranslation();

  if (entries.length === 0 && emptyText === undefined) {
    return null;
  }

  return (
    <section className="flex flex-col gap-3">
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
      {entries.length === 0 ? (
        <Card>
          <EmptyBlock>{emptyText}</EmptyBlock>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {entries.map((entry) => (
            <CardComponent key={entry.slug} entry={entry} />
          ))}
        </div>
      )}
    </section>
  );
}
