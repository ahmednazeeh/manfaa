'use client';

import { useState } from 'react';
import { type DiscoveryEntry } from '@manfaa/api-client';
import {
  Globe,
  LoaderCircle,
  MapPin,
  Sparkles,
  Store,
  TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate, formatRateBp, splitDistance } from '@/lib/format';
import { useDiscovery } from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';

type GeoState =
  | { kind: 'idle' }
  | { kind: 'locating' }
  | { kind: 'granted'; lat: number; lng: number }
  | { kind: 'denied' }
  | { kind: 'unavailable' };

function MerchantCard({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();
  const boosted = entry.rate_bp > entry.standing_rate_bp;

  return (
    <Card>
      <CardContent className="flex flex-col gap-2 p-5">
        <div className="flex items-start justify-between gap-2">
          <span className="text-sm font-medium text-mono">{entry.name}</span>
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
          {entry.distance_m !== null &&
            (() => {
              const distance = splitDistance(entry.distance_m);
              return (
                <span className="inline-flex items-center gap-1">
                  <MapPin className="size-3" />
                  {distance.unit === 'm'
                    ? t('discover.distanceMeters', { meters: distance.value })
                    : t('discover.distanceKm', { km: distance.value })}
                </span>
              );
            })()}
        </div>
      </CardContent>
    </Card>
  );
}

function Section({
  icon: Icon,
  title,
  entries,
  emptyText,
  children,
}: {
  icon: LucideIcon;
  title: string;
  entries: DiscoveryEntry[];
  emptyText: string;
  children?: React.ReactNode;
}) {
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-base font-semibold text-mono">
          <Icon className="size-4 text-muted-foreground" />
          {title}
        </h2>
        {children}
      </div>
      {entries.length === 0 ? (
        <Card>
          <EmptyBlock>{emptyText}</EmptyBlock>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {entries.map((entry) => (
            <MerchantCard key={entry.slug} entry={entry} />
          ))}
        </div>
      )}
    </section>
  );
}

export default function DiscoverPage() {
  const [geo, setGeo] = useState<GeoState>({ kind: 'idle' });
  const coords = geo.kind === 'granted' ? { lat: geo.lat, lng: geo.lng } : null;
  const { data, isPending, error } = useDiscovery(coords);
  const { t } = useTranslation();

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

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('discover.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('discover.description')}</ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {isPending && <LoadingBlock lines={5} />}
      {!isPending && error && <ErrorBlock error={error} />}

      {data && (
        <div className="flex flex-col gap-8 pb-10">
          <Section
            icon={Sparkles}
            title={t('discover.featured')}
            entries={data.featured}
            emptyText={t('discover.sectionEmpty')}
          />

          <Section
            icon={TrendingUp}
            title={t('discover.increased')}
            entries={data.increased}
            emptyText={t('discover.sectionEmpty')}
          />

          <Section
            icon={Store}
            title={t('discover.nearby')}
            entries={data.nearby}
            emptyText={
              geo.kind === 'granted'
                ? t('discover.nearbyEmpty')
                : geo.kind === 'denied'
                  ? t('discover.locationDenied')
                  : geo.kind === 'unavailable'
                    ? t('discover.locationUnavailable')
                    : t('discover.nearbyAskLocation')
            }
          >
            {geo.kind !== 'granted' && (
              <Button
                variant="outline"
                size="sm"
                onClick={requestLocation}
                disabled={geo.kind === 'locating'}
              >
                {geo.kind === 'locating' ? (
                  <>
                    <LoaderCircle className="animate-spin" />
                    {t('discover.locating')}
                  </>
                ) : (
                  <>
                    <MapPin />
                    {t('discover.useMyLocation')}
                  </>
                )}
              </Button>
            )}
          </Section>

          <Section
            icon={Globe}
            title={t('discover.online')}
            entries={data.online}
            emptyText={t('discover.sectionEmpty')}
          />
        </div>
      )}
    </div>
  );
}
