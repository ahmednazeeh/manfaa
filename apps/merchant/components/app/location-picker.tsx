'use client';

import { useEffect, useRef, useState } from 'react';
import { loadGoogleMaps, type GMap } from '@manfaa/ui';
import { Crosshair, LoaderCircle, MapPin, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Drops one coordinate on a map: a fixed pin at the centre, the map moving
 * under it, a Places search restricted to the Maldives, and a "my location"
 * button. Used by the setup wizard's location step and by the branch dialog
 * in settings — the customer's store map is a different component in a
 * different app, sharing only the loader (D9).
 *
 * The map is UNCONTROLLED after mount: `value` sets the opening centre and is
 * then only read back for display. Re-centring on every change would fight
 * the owner's own drag, since each drag is what produces the change.
 *
 * Nothing is reported until the owner moves something. The map's first idle
 * arrives with the centre we just set, and reporting that would turn "no pin
 * yet" into "pinned to the middle of Malé" without a finger being lifted —
 * which the branch dialog, where clearing a pin must stick, cannot survive.
 */

export interface LatLng {
  lat: number;
  lng: number;
}

/** Malé: where a Maldivian shop most likely is, and never a saved answer. */
const DEFAULT_CENTER: LatLng = { lat: 4.1755, lng: 73.5093 };

/** Wide enough to recognise the island; a saved pin opens at street level. */
const ISLAND_ZOOM = 13;
const PLACE_ZOOM = 17;

/**
 * Read at module scope so it is a plain build-time literal in this app's own
 * bundle. The shared loader deliberately takes the key as an argument and
 * reads no environment of its own (D8); an empty string here reaches it as
 * the same "unavailable" rejection a blocked script produces, and the picker
 * says so on screen rather than leaving a dead grey box.
 */
const MAPS_API_KEY = process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY ?? '';

type MapStatus = 'loading' | 'ready' | 'unavailable';

/**
 * merchant_branches.lat/.lng are decimal(10,7), so a longer value is rounded
 * on write and the stored pin would no longer be the one the map reported.
 * Rounding here instead means what the owner saw, what we sent and what comes
 * back on the next load are the same number.
 */
function round7(value: number): number {
  return Number(value.toFixed(7));
}

/**
 * The pair as one bidi-isolated run. Latitude and longitude are numbers
 * separated by a neutral comma: in a Dhivehi (RTL) sentence the bidi
 * algorithm resolves that comma to the paragraph direction and prints the
 * pair backwards, longitude first. The isolate (U+2068 … U+2069) pins it to
 * reading order wherever the sentence around it is going.
 */
export function formatPin({ lat, lng }: LatLng): string {
  return `\u2068${lat}, ${lng}\u2069`;
}

/**
 * True when a dismissal came from the Places suggestion list. Google appends
 * `.pac-container` to <body>, outside every React tree, so choosing a
 * suggestion reads to Radix as a click outside the dialog — and the dialog
 * would close on the very interaction the search box exists for.
 */
export function isPlacesDropdownEvent(event: Event): boolean {
  const target = event.target;
  return target instanceof Element && target.closest('.pac-container') !== null;
}

export function LocationPicker({
  value,
  onChange,
  onUnavailable,
  className,
}: {
  /** The pin on file, or null when there is none yet. */
  value: LatLng | null;
  /** Fired for owner-driven moves only — never for the map's own first idle. */
  onChange: (point: LatLng) => void;
  /** The map cannot be shown at all; a caller that requires a pin must relent. */
  onUnavailable?: () => void;
  className?: string;
}) {
  const { t } = useTranslation();
  const mapDivRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const mapRef = useRef<GMap | null>(null);
  const [status, setStatus] = useState<MapStatus>('loading');
  const [locating, setLocating] = useState(false);
  const [locateError, setLocateError] = useState<string | null>(null);

  // The map is built once. Callback identities and the opening centre are
  // read through refs so a re-render of the form around it cannot tear down
  // a map the owner is in the middle of dragging.
  const openingCenterRef = useRef(value);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const onUnavailableRef = useRef(onUnavailable);
  onUnavailableRef.current = onUnavailable;

  useEffect(() => {
    let cancelled = false;

    loadGoogleMaps(MAPS_API_KEY)
      .then((maps) => {
        if (cancelled || mapDivRef.current === null) return;

        const opening = openingCenterRef.current;
        const center =
          opening === null
            ? DEFAULT_CENTER
            : { lat: round7(opening.lat), lng: round7(opening.lng) };

        const map = new maps.Map(mapDivRef.current, {
          center,
          zoom: opening === null ? ISLAND_ZOOM : PLACE_ZOOM,
          disableDefaultUI: true,
          zoomControl: true,
          clickableIcons: false,
          gestureHandling: 'greedy',
        });
        mapRef.current = map;
        setStatus('ready');

        // Only real moves are reported: the opening idle carries the centre
        // we just set, and zooming in place fires another with the centre
        // unchanged. Either would announce a choice nobody made.
        let last = center;
        map.addListener('idle', () => {
          const centre = map.getCenter();
          if (centre === undefined) return;
          const point = {
            lat: round7(centre.lat()),
            lng: round7(centre.lng()),
          };
          if (point.lat === last.lat && point.lng === last.lng) return;
          last = point;
          onChangeRef.current(point);
        });

        // Places is a separate library and may be missing from the load; the
        // map is fully usable without it, so the search box is what degrades.
        if (maps.places !== undefined && searchRef.current !== null) {
          const autocomplete = new maps.places.Autocomplete(searchRef.current, {
            fields: ['geometry'],
            componentRestrictions: { country: 'mv' },
          });
          autocomplete.addListener('place_changed', () => {
            const location = autocomplete.getPlace().geometry?.location;
            if (location === undefined) return;
            map.panTo({ lat: location.lat(), lng: location.lng() });
            map.setZoom(PLACE_ZOOM);
          });
        }
      })
      .catch(() => {
        if (cancelled) return;
        setStatus('unavailable');
        onUnavailableRef.current?.();
      });

    return () => {
      cancelled = true;
    };
  }, []);

  /**
   * Only ever from this button. Asking the browser for a position on mount
   * fires the permission prompt at someone who came to fill in a form, and
   * a refusal there is remembered for the whole origin.
   */
  const locate = () => {
    setLocateError(null);

    if (navigator.geolocation === undefined) {
      setLocateError(t('location.locateUnsupported'));
      return;
    }

    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocating(false);
        mapRef.current?.panTo({
          lat: position.coords.latitude,
          lng: position.coords.longitude,
        });
        mapRef.current?.setZoom(PLACE_ZOOM);
      },
      () => {
        setLocating(false);
        setLocateError(t('location.locateFailed'));
      },
      { enableHighAccuracy: true, timeout: 10_000 },
    );
  };

  if (status === 'unavailable') {
    return (
      <div
        className={cn(
          'flex items-start gap-2 rounded-md border border-border bg-muted/40 p-4 text-sm text-secondary-foreground',
          className,
        )}
      >
        <TriangleAlert className="mt-0.5 size-4 shrink-0 text-yellow-500" />
        <div className="flex flex-col gap-1">
          <span>{t('location.unavailable')}</span>
          {value !== null && (
            <span className="text-xs text-muted-foreground">
              {t('location.pinned', { coordinates: formatPin(value) })}
            </span>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className={cn('flex flex-col gap-2.5', className)}>
      <Input
        ref={searchRef}
        type="text"
        placeholder={t('location.searchPlaceholder')}
        aria-label={t('location.searchLabel')}
      />

      <div className="relative h-56 overflow-hidden rounded-md border border-border">
        <div ref={mapDivRef} className="size-full bg-muted" />

        {status === 'ready' && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
            {/* Faded until a pin exists — the map has a centre either way, and
                a solid pin over an unset location would claim one. */}
            <MapPin
              aria-hidden
              className={cn(
                'size-9 -translate-y-4 fill-primary stroke-background drop-shadow-md',
                value === null && 'opacity-50',
              )}
            />
          </div>
        )}

        {status === 'loading' && (
          <div className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-muted-foreground">
            <LoaderCircle className="size-4 animate-spin" />
            {t('location.loading')}
          </div>
        )}

        {status === 'ready' && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="absolute bottom-2 end-2 shadow-md"
            disabled={locating}
            onClick={locate}
          >
            {locating ? (
              <LoaderCircle className="animate-spin" />
            ) : (
              <Crosshair />
            )}
            {locating ? t('location.locating') : t('location.locate')}
          </Button>
        )}
      </div>

      {locateError !== null ? (
        <p className="text-xs text-destructive">{locateError}</p>
      ) : (
        <p className="text-xs text-muted-foreground">
          {value === null
            ? t('location.dragHint')
            : t('location.pinned', { coordinates: formatPin(value) })}
        </p>
      )}
    </div>
  );
}
