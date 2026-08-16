'use client';

import { useEffect, useRef, useState } from 'react';
import { type DiscoveryEntry } from '@manfaa/api-client';
import { loadGoogleMaps, type GMap, type GMapsApi } from '@manfaa/ui';
import { Crosshair, LoaderCircle, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { EmptyBlock } from '@/components/app/async-states';
import { MerchantCard, type GeoState } from '@/components/app/discovery';
import { useStoreName } from '@/components/app/store-labels';

/**
 * "Near you" drawn as a map instead of a list — adapted from avasprint's
 * delivery-pin picker, which WRITES one coordinate. This one only ever
 * READS: it drops a marker per located branch, frames them all, and opens
 * the store behind whichever one is tapped.
 *
 * Four deliberate departures from that reference:
 *
 *   - It located the visitor on mount. Here geolocation runs from a button
 *     press and nowhere else, the rule the rest of /discover already keeps
 *     — a permission prompt nobody asked for is the quickest route to a
 *     permanent browser-level block, and it costs the one gesture we get.
 *   - When the script failed it reported Malé through onChange so the
 *     checkout form stayed submittable. There is no form here, and calling
 *     Malé the visitor's location would be a lie the page then repeats as a
 *     distance on every card. A map that failed says so.
 *   - Its floating control was pinned with `right-2`. Every offset here is
 *     logical, so the control keeps to the end of the line in Dhivehi too.
 *   - Every string goes through t().
 *
 * `entries` is handed in by the caller as the union of the coordinate-
 * bearing shelves (D11), never `nearby` alone: that shelf is empty until
 * the visitor grants location, so sourcing pins from it would show an empty
 * map to exactly the person who opened it. Nothing here refetches
 * discovery either — the endpoint is throttled per IP on a query key built
 * from raw floats, so a pan-driven refetch would mint a request per
 * micro-drag and buy nothing: every located store is already on the map.
 */

/**
 * The browser key, read HERE and not inside @manfaa/ui: a NEXT_PUBLIC_*
 * literal is inlined at each app's own build, and this repo builds three of
 * them separately, so a read living in the shared package would resolve in
 * whichever app happened to have the variable set and silently blank in the
 * next (D8). Empty when unset — callers drop the map choice altogether
 * rather than offer a view that cannot paint.
 */
export const MAPS_API_KEY = process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY ?? '';

/** Malé — the sensible default centre for the Maldives. */
const MALE_CENTRE = { lat: 4.1755, lng: 73.5093 };

/** Greater Malé at a glance, for a map with nothing to frame. */
const DEFAULT_ZOOM = 12;

/** Street level: where a single pin, a search result and "my location" land. */
const CLOSE_ZOOM = 15;

/** Breathing room around the outermost pins, in pixels. */
const FIT_PADDING = 48;

export function NearbyMap({
  apiKey,
  entries,
  geo,
  coords,
  onRequestLocation,
}: {
  apiKey: string;
  /** Every located store, deduped by slug — see the note above. */
  entries: DiscoveryEntry[];
  geo: GeoState;
  coords: { lat: number; lng: number } | null;
  onRequestLocation: () => void;
}) {
  const { t } = useTranslation();
  const storeName = useStoreName();

  const mapDivRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const apiRef = useRef<GMapsApi | null>(null);
  const mapRef = useRef<GMap | null>(null);

  /**
   * The map is framed to the pins ONCE. Discovery refetches every minute and
   * rebuilds the markers below; re-framing on each of those would yank the
   * map out from under a reader who had panned somewhere of their own.
   */
  const framedRef = useRef(false);

  /**
   * Set by the "my location" press, cleared by the pan it asks for. The
   * coordinates arrive asynchronously and from a hook shared with the list,
   * so without this flag a grant made from the list's own button would pan
   * a map the reader never asked to move.
   */
  const panOnFixRef = useRef(false);

  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
    'loading',
  );
  const [selectedSlug, setSelectedSlug] = useState<string | null>(null);

  // Build the map itself, once. `status` flipping to 'ready' is what wakes
  // the marker effect below — the refs it needs are set here.
  useEffect(() => {
    let cancelled = false;

    loadGoogleMaps(apiKey)
      .then((maps) => {
        if (cancelled || mapDivRef.current === null) {
          return;
        }

        const map = new maps.Map(mapDivRef.current, {
          center: MALE_CENTRE,
          zoom: DEFAULT_ZOOM,
          disableDefaultUI: true,
          zoomControl: true,
          clickableIcons: false,
          // The reference used 'greedy' inside a short checkout form. This
          // map sits in the middle of a long scrolling page, where greedy
          // means a thumb aimed at the page scrolls the map instead and the
          // reader is stuck. Cooperative hands single-finger drags back to
          // the document.
          gestureHandling: 'cooperative',
        });

        apiRef.current = maps;
        mapRef.current = map;
        map.addListener('click', () => setSelectedSlug(null));
        setStatus('ready');

        // Places search — picking a result moves the map there. It changes
        // nothing about which stores are pinned; every located store is
        // already on the map, so panning to Hulhumalé simply reveals the
        // ones that were off screen.
        if (maps.places && searchRef.current !== null) {
          const autocomplete = new maps.places.Autocomplete(searchRef.current, {
            fields: ['geometry'],
            componentRestrictions: { country: 'mv' },
          });
          autocomplete.addListener('place_changed', () => {
            const location = autocomplete.getPlace().geometry?.location;
            if (location === undefined) {
              return;
            }
            map.panTo({ lat: location.lat(), lng: location.lng() });
            map.setZoom(CLOSE_ZOOM);
          });
        }
      })
      .catch(() => {
        if (!cancelled) {
          setStatus('error');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [apiKey]);

  // One marker per located branch, rebuilt whenever the payload changes. A
  // store with two branches is two pins that open the same card — which is
  // the honest picture, since either branch pays the same cashback.
  useEffect(() => {
    if (status !== 'ready') {
      return;
    }
    const maps = apiRef.current;
    const map = mapRef.current;
    if (maps === null || map === null) {
      return;
    }

    const pins = entries.flatMap((entry) =>
      entry.branches.map((branch) => ({ entry, branch })),
    );

    const markers = pins.map(({ entry, branch }) => {
      const marker = new maps.Marker({
        map,
        position: branch,
        title: storeName(entry),
      });
      marker.addListener('click', () => setSelectedSlug(entry.slug));
      return marker;
    });

    if (!framedRef.current && pins.length > 0) {
      framedRef.current = true;
      // fitBounds on a single point zooms to the deepest level the tiles go
      // to, which reads as a lost map rather than as one store. Centre that
      // one instead, and let the street grid say where it is.
      const only = pins.length === 1 ? pins[0] : undefined;
      if (only !== undefined) {
        map.setCenter(only.branch);
        map.setZoom(CLOSE_ZOOM);
      } else {
        const bounds = new maps.LatLngBounds();
        for (const pin of pins) {
          bounds.extend(pin.branch);
        }
        map.fitBounds(bounds, FIT_PADDING);
      }
    }

    // Markers belong to the map, not to React, so nothing removes them when
    // this effect re-runs or the view switches back to the list — they would
    // simply accumulate on top of each other.
    return () => {
      for (const marker of markers) {
        marker.setMap(null);
      }
    };
  }, [entries, status, storeName]);

  // The pan the locate button asked for, once the fix actually lands.
  useEffect(() => {
    if (!panOnFixRef.current) {
      return;
    }
    // A refusal answers the press just as a fix does. Left standing, the
    // flag would fire against a grant the reader later makes from the LIST
    // and move a map they never asked to move.
    if (geo.kind === 'denied' || geo.kind === 'unavailable') {
      panOnFixRef.current = false;
      return;
    }
    if (coords === null || mapRef.current === null) {
      return;
    }
    panOnFixRef.current = false;
    mapRef.current.panTo(coords);
    mapRef.current.setZoom(CLOSE_ZOOM);
  }, [coords, geo.kind]);

  const handleLocate = () => {
    // Already granted: this is a re-centre, not a second permission prompt.
    if (coords !== null) {
      mapRef.current?.panTo(coords);
      mapRef.current?.setZoom(CLOSE_ZOOM);
      return;
    }
    panOnFixRef.current = true;
    onRequestLocation();
  };

  if (status === 'error') {
    return (
      <Card>
        <EmptyBlock>{t('discover.mapUnavailable')}</EmptyBlock>
      </Card>
    );
  }

  const selected =
    selectedSlug === null
      ? undefined
      : entries.find((entry) => entry.slug === selectedSlug);

  return (
    <div className="flex flex-col gap-2">
      <Input
        ref={searchRef}
        type="text"
        // Google's own suggestion list replaces anything the browser would
        // offer, and the two stacked on each other is unreadable.
        autoComplete="off"
        placeholder={t('discover.mapSearchPlaceholder')}
        aria-label={t('discover.mapSearchPlaceholder')}
        className="max-w-md"
      />

      <div
        role="region"
        aria-label={t('discover.mapLabel')}
        className="relative h-96 overflow-hidden rounded-xl border border-border sm:h-[30rem]"
      >
        {/* The canvas is pinned LTR: Google lays its tiles, controls and
            attribution out left to right whatever the document says, and a
            mirrored container only moves the zoom control away from the
            corner it draws itself into. Everything around it — the card,
            the button, the caption — stays in the reader's direction. */}
        <div ref={mapDivRef} dir="ltr" className="size-full bg-muted" />

        {status === 'loading' && (
          <div className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
            {t('discover.mapLoading')}
          </div>
        )}

        {status === 'ready' && (
          <Button
            variant="outline"
            size="sm"
            onClick={handleLocate}
            disabled={geo.kind === 'locating'}
            className="absolute end-2 bottom-2 z-10 shadow-md"
          >
            {/* The spinner replaces the crosshair rather than the label:
                the section's own "Finding stores near you…" is a sentence,
                and a floating control that doubles in width mid-press
                shoves itself over the store card beside it. */}
            {geo.kind === 'locating' ? (
              <LoaderCircle className="animate-spin" />
            ) : (
              <Crosshair />
            )}
            {t('discover.myLocation')}
          </Button>
        )}

        {/* The tapped store, as the one card anatomy the rest of the app
            uses — so a pin and a grid tile say the same things in the same
            order, and the whole card is still the link to the store. */}
        {selected !== undefined && (
          <div className="absolute start-2 bottom-2 z-10 w-40 sm:w-44">
            <MerchantCard entry={selected} />
            <button
              type="button"
              onClick={() => setSelectedSlug(null)}
              aria-label={t('common.close')}
              className="absolute -top-2 -end-2 z-20 flex size-6 items-center justify-center rounded-full border border-border bg-background text-muted-foreground shadow-md hover:text-foreground"
            >
              <X className="size-3.5" />
            </button>
          </div>
        )}
      </div>

      <p className="text-xs text-muted-foreground">
        {entries.length === 0 ? t('discover.mapNoPins') : t('discover.mapHint')}
      </p>
    </div>
  );
}
