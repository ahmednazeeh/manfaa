'use client';

import { useEffect, useRef, useState } from 'react';
import { type Zone, type ZonePoint } from '@manfaa/api-client';
import {
  loadGoogleMaps,
  type GMap,
  type GMapsApi,
  type GMarker,
  type GPolygon,
  type GPolyline,
} from '@manfaa/ui';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The zones canvas: every saved island polygon in its own colour, an optional
 * coral draft ring, and click-to-draw the page arms and disarms through the
 * `drawing` prop. The admin clicks dots around an island and closes the ring
 * by clicking the first dot again; the completed ring is plain {lat,lng}
 * points and the preview overlays are discarded — the draft the admin sees
 * afterwards is OUR polygon, redrawn from those points, so what is previewed
 * is exactly what would be submitted.
 *
 * The drawing is composed by hand (map clicks + vertex markers + a preview
 * polyline) because Google REMOVED the DrawingManager from Maps JS v3.65 —
 * its constructor now throws, which this page used to misreport as "the map
 * is unavailable".
 *
 * Like the merchant's location picker, the map is built once and every
 * callback is read through a ref, so re-renders of the page around it never
 * tear down a half-drawn ring.
 */

/** Malé, zoom wide enough to see the whole atoll ring around it. */
const DEFAULT_CENTER = { lat: 4.1755, lng: 73.5093 };
const DEFAULT_ZOOM = 12;

/**
 * Build-time literal in this app's own bundle — the shared loader takes the
 * key as an argument and reads no environment of its own (D8).
 */
const MAPS_API_KEY = process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY ?? '';

/** Draft/drawing colour — the brand coral, reserved so a ring being drawn
 * never blends into a saved neighbour. */
export const DRAFT_COLOR = '#F4626B';

/** Saved-zone palette, assigned by list index. Coral is deliberately absent. */
const ZONE_COLORS = [
  '#3B82F6',
  '#10B981',
  '#F59E0B',
  '#8B5CF6',
  '#06B6D4',
  '#EC4899',
  '#84CC16',
  '#F97316',
  '#14B8A6',
  '#6366F1',
];

/** The colour a zone renders with on the map — the side list mirrors it. */
export function zoneColor(index: number): string {
  return ZONE_COLORS[index % ZONE_COLORS.length];
}

/**
 * zones.polygon round-trips through JSON; 6 decimals (~10 cm) keeps the
 * payload clean of the float noise a projected map click carries.
 */
function round6(value: number): number {
  return Number(value.toFixed(6));
}

type MapStatus = 'loading' | 'ready' | 'unavailable';

export function ZonesMap({
  zones,
  draft,
  dimmedZoneId,
  drawing,
  onReady,
  onUnavailable,
  onPolygonComplete,
  className,
}: {
  /** Saved zones, in list order — index picks the colour. */
  zones: Zone[];
  /** A completed-but-unsaved ring, rendered dashed-bright in coral. */
  draft: ZonePoint[] | null;
  /** A zone being redrawn — its old ring fades so the new one reads on top. */
  dimmedZoneId: number | null;
  /** Arms the polygon DrawingManager. */
  drawing: boolean;
  /** Fired once, with the loaded API — the page geocodes with it on save. */
  onReady?: (maps: GMapsApi) => void;
  /** The map cannot be shown at all; drawing is off the table. */
  onUnavailable?: () => void;
  /** A ring of at least 3 points was closed. */
  onPolygonComplete: (points: ZonePoint[]) => void;
  className?: string;
}) {
  const mapDivRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const mapsRef = useRef<GMapsApi | null>(null);
  const mapRef = useRef<GMap | null>(null);
  const overlaysRef = useRef<GPolygon[]>([]);
  const [status, setStatus] = useState<MapStatus>('loading');

  const onReadyRef = useRef(onReady);
  onReadyRef.current = onReady;
  const onUnavailableRef = useRef(onUnavailable);
  onUnavailableRef.current = onUnavailable;
  const onPolygonCompleteRef = useRef(onPolygonComplete);
  onPolygonCompleteRef.current = onPolygonComplete;

  useEffect(() => {
    let cancelled = false;

    loadGoogleMaps(MAPS_API_KEY, ['places'])
      .then((maps) => {
        if (cancelled || mapDivRef.current === null) return;

        const map = new maps.Map(mapDivRef.current, {
          center: DEFAULT_CENTER,
          zoom: DEFAULT_ZOOM,
          disableDefaultUI: true,
          zoomControl: true,
          clickableIcons: false,
          gestureHandling: 'greedy',
        });
        mapsRef.current = maps;
        mapRef.current = map;

        setStatus('ready');
        onReadyRef.current?.(maps);
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

  // Island search: a Places box that flies the map to whatever island the
  // admin types, so drawing a far-flung zone does not start with a minute
  // of panning. Search only MOVES the camera — it never drops a dot.
  useEffect(() => {
    if (status !== 'ready') return;
    const maps = mapsRef.current;
    const map = mapRef.current;
    const input = searchRef.current;
    if (maps?.places === undefined || map === null || input === null) return;

    const autocomplete = new maps.places.Autocomplete(input, {
      fields: ['geometry'],
      // The zones are Maldivian islands; a search that offers Malé in
      // four other countries first is a search that wastes the admin.
      componentRestrictions: { country: 'mv' },
    });

    autocomplete.addListener('place_changed', () => {
      const location = autocomplete.getPlace().geometry?.location;

      if (location !== undefined) {
        map.panTo({ lat: location.lat(), lng: location.lng() });
        // Close enough to draw a ring around one island straight away.
        map.setZoom(14);
      }
    });
  }, [status]);

  // Arm/disarm follows the page's state rather than any internal one, so
  // cancelling from the form disarms the map without a second source of
  // truth. Every map click drops a dot; clicking the FIRST dot again closes
  // the ring. The cleanup runs on disarm, completion and unmount alike, so
  // no dot or line ever outlives the gesture.
  useEffect(() => {
    if (status !== 'ready' || !drawing) return;
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (maps === null || map === null) return;

    const points: ZonePoint[] = [];
    let dots: GMarker[] = [];
    let line: GPolyline | null = null;

    const redraw = () => {
      line?.setMap(null);
      for (const dot of dots) dot.setMap(null);
      dots = [];
      line =
        points.length >= 2
          ? new maps.Polyline({
              path: [...points],
              map,
              strokeColor: DRAFT_COLOR,
              strokeWeight: 2.5,
              clickable: false,
            })
          : null;
      points.forEach((point, index) => {
        const first = index === 0;
        const dot = new maps.Marker({
          position: point,
          map,
          // Only the first dot answers clicks — it is the close button.
          // The rest stay transparent so a click near them still lands on
          // the map and drops the next dot.
          clickable: first,
          cursor: first ? 'pointer' : undefined,
          icon: {
            path: maps.SymbolPath.CIRCLE,
            scale: first ? 7 : 5,
            fillColor: first ? '#FFFFFF' : DRAFT_COLOR,
            fillOpacity: 1,
            strokeColor: DRAFT_COLOR,
            strokeWeight: 2,
          },
        });
        if (first) {
          dot.addListener('click', () => {
            // A ring needs 3 dots; an earlier close-click does nothing,
            // exactly as the old DrawingManager ignored a degenerate ring —
            // drawing stays armed and the admin simply keeps clicking.
            if (points.length >= 3) {
              onPolygonCompleteRef.current([...points]);
            }
          });
        }
        dots.push(dot);
      });
    };

    map.setOptions({ draggableCursor: 'crosshair' });
    const listener = map.addListener('click', (event) => {
      const latLng = event.latLng;
      if (latLng === null) return;
      points.push({ lat: round6(latLng.lat()), lng: round6(latLng.lng()) });
      redraw();
    });

    return () => {
      listener.remove();
      line?.setMap(null);
      for (const dot of dots) dot.setMap(null);
      map.setOptions({ draggableCursor: null });
    };
  }, [drawing, status]);

  // Saved zones + draft, rebuilt whole on every change — a handful of rings,
  // nothing worth diffing.
  useEffect(() => {
    if (status !== 'ready') return;
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (maps === null || map === null) return;

    for (const overlay of overlaysRef.current) {
      overlay.setMap(null);
    }
    overlaysRef.current = [];

    zones.forEach((zone, index) => {
      const dimmed = zone.id === dimmedZoneId;
      const color = zoneColor(index);
      const overlay = new maps.Polygon({
        paths: zone.polygon,
        map,
        fillColor: color,
        fillOpacity: dimmed ? 0.04 : 0.18,
        strokeColor: color,
        strokeOpacity: dimmed ? 0.3 : 0.9,
        strokeWeight: 2,
        clickable: false,
      });
      overlaysRef.current.push(overlay);
    });

    if (draft !== null) {
      const overlay = new maps.Polygon({
        paths: draft,
        map,
        fillColor: DRAFT_COLOR,
        fillOpacity: 0.15,
        strokeColor: DRAFT_COLOR,
        strokeWeight: 2.5,
        clickable: false,
      });
      overlaysRef.current.push(overlay);
    }
  }, [zones, draft, dimmedZoneId, status]);

  if (status === 'unavailable') {
    return (
      <div
        className={cn(
          'flex items-start gap-2 rounded-md border border-border bg-muted/40 p-4 text-sm text-secondary-foreground',
          className,
        )}
      >
        <TriangleAlert className="mt-0.5 size-4 shrink-0 text-yellow-500" />
        <span>
          The map is unavailable right now, so zones cannot be drawn or redrawn.
          Existing zones can still be renamed or deleted from the list.
        </span>
      </div>
    );
  }

  return (
    <div
      className={cn(
        'relative overflow-hidden rounded-md border border-border',
        className,
      )}
    >
      <div
        ref={mapDivRef}
        className={cn('size-full bg-muted', drawing && 'cursor-crosshair')}
      />

      {status === 'ready' && (
        <input
          ref={searchRef}
          type="text"
          placeholder="Search island…"
          // Google clears the box on selection focus-out inconsistently;
          // leaving the typed text is the least surprising behaviour.
          className="absolute start-2.5 top-2.5 z-10 h-9 w-64 max-w-[calc(100%-5rem)] rounded-md border border-border bg-background px-3 text-sm text-foreground shadow-md outline-none placeholder:text-muted-foreground focus:border-ring"
        />
      )}

      {status === 'loading' && (
        <div className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-muted-foreground">
          <LoaderCircle className="size-4 animate-spin" />
          Loading the map…
        </div>
      )}

      {drawing && status === 'ready' && (
        <div className="pointer-events-none absolute inset-x-0 top-0 flex justify-center p-2.5">
          <span className="rounded-md bg-foreground/80 px-3 py-1.5 text-xs font-medium text-background shadow-md">
            Click dots around the island — click the first dot again to close
            the ring.
          </span>
        </div>
      )}
    </div>
  );
}
