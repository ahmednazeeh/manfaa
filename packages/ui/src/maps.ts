/**
 * Google Maps JS loader, shared by the merchant's pin-drop and the
 * customer's store map (§maps D9: two components, one script tag).
 *
 * Types only and a script tag — deliberately no markup. Tailwind v4 carries
 * no `@source` directive here and does not scan this package through its
 * pnpm symlink, so a styled component living in @manfaa/ui would ship to the
 * apps with its classes silently missing (D8). The two map components stay
 * in their own apps, where the scanner and that app's i18n both reach them.
 *
 * The surface below is typed loosely and only as wide as the two callers
 * need — the maps typings package is a megabyte of definitions for an API
 * we touch a dozen members of.
 */

export interface GLatLng {
  lat(): number;
  lng(): number;
}

export interface GLatLngLiteral {
  lat: number;
  lng: number;
}

export interface GLatLngBounds {
  extend(point: GLatLngLiteral): GLatLngBounds;
  isEmpty(): boolean;
}

/** Handle a listener registration returns; remove() detaches it. */
export interface GListener {
  remove(): void;
}

/** The event a map click hands its listener. */
export interface GMapMouseEvent {
  latLng: GLatLng | null;
}

export interface GMap {
  getCenter(): GLatLng | undefined;
  setCenter(c: GLatLngLiteral): void;
  panTo(c: GLatLngLiteral): void;
  getZoom(): number | undefined;
  setZoom(z: number): void;
  fitBounds(bounds: GLatLngBounds, padding?: number): void;
  setOptions(options: Record<string, unknown>): void;
  addListener(
    event: string,
    handler: (event: GMapMouseEvent) => void,
  ): GListener;
}

/**
 * The classic marker, not AdvancedMarkerElement: the newer one needs a
 * cloud-configured `mapId` and the `marker` library, and the key we borrow
 * belongs to another project whose console we do not own.
 */
export interface GMarker {
  setMap(map: GMap | null): void;
  getPosition(): GLatLng | undefined;
  addListener(event: string, handler: () => void): void;
}

export interface GInfoWindow {
  setContent(content: string | HTMLElement): void;
  open(options: { map: GMap; anchor?: GMarker }): void;
  close(): void;
}

interface GAutocomplete {
  addListener(event: string, handler: () => void): void;
  getPlace(): { geometry?: { location?: GLatLng } };
}

export interface GPolygon {
  setMap(map: GMap | null): void;
  getPath(): { getArray(): GLatLng[] };
  setOptions(options: Record<string, unknown>): void;
}

/**
 * An open line — the zones page's in-progress ring. (Google REMOVED the
 * DrawingManager in Maps JS v3.65, so polygon drawing is composed from map
 * clicks, vertex markers and this polyline instead.)
 */
export interface GPolyline {
  setMap(map: GMap | null): void;
}

export interface GGeocoderAddressComponent {
  long_name: string;
  short_name: string;
  types: string[];
}

export interface GGeocoderResult {
  formatted_address: string;
  types: string[];
  address_components: GGeocoderAddressComponent[];
}

/** Promise form only — the callback signature is legacy surface we skip. */
export interface GGeocoder {
  geocode(request: {
    location: GLatLngLiteral;
  }): Promise<{ results: GGeocoderResult[] }>;
}

export interface GMapsApi {
  Map: new (el: HTMLElement, opts: Record<string, unknown>) => GMap;
  Marker: new (opts: Record<string, unknown>) => GMarker;
  InfoWindow: new (opts?: Record<string, unknown>) => GInfoWindow;
  LatLngBounds: new () => GLatLngBounds;
  Polygon: new (opts: Record<string, unknown>) => GPolygon;
  Polyline: new (opts: Record<string, unknown>) => GPolyline;
  Geocoder: new () => GGeocoder;
  /** Symbol paths for classic Marker icons (the drawing dots). */
  SymbolPath: { CIRCLE: number };
  /**
   * Optional because Places is a separate library: the map itself is usable
   * even when the search box cannot be built, so callers guard rather than
   * abandon the map.
   */
  places?: {
    Autocomplete: new (
      input: HTMLInputElement,
      opts?: Record<string, unknown>,
    ) => GAutocomplete;
  };
}

/**
 * Global the Maps script calls back into. Namespaced to Manfaa so a page
 * that ever loads another vendor's Maps snippet cannot resolve our promise
 * with its script's `google.maps`.
 */
const READY_CALLBACK = '__manfaaGmapsReady';

let mapsPromise: Promise<GMapsApi> | null = null;

/**
 * Loads the Maps JS API (plus Places for the search box) exactly once per
 * page. Two maps mounted together — the branch dialog behind the wizard, say
 * — share this promise rather than racing two script tags for the same
 * global.
 *
 * The key is an ARGUMENT, never a `process.env` read in here: this package
 * is consumed by three separately-built Next apps, and a `NEXT_PUBLIC_*`
 * literal is inlined at each app's own build, so a key present in one app's
 * environment and absent from another's would produce a package that works
 * in one and fails in the next with nothing in the diff to explain it (D8).
 * The first caller's key wins for the lifetime of the page; the API can only
 * be loaded once, so a second key could not take effect anyway.
 *
 * `libraries` works the same way: the script tag is built once, from the
 * FIRST caller's list, so a page whose components need different libraries
 * must ask for the union up front. The default stays `['places']` — the
 * merchant pin-drop and customer store map predate the parameter and keep
 * loading exactly what they always did.
 */
export function loadGoogleMaps(
  apiKey: string,
  libraries: readonly string[] = ['places'],
): Promise<GMapsApi> {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('Maps can only load in the browser.'));
  }

  const w = window as unknown as { google?: { maps?: GMapsApi } };
  if (w.google?.maps) return Promise.resolve(w.google.maps);
  if (mapsPromise) return mapsPromise;

  if (!apiKey) {
    return Promise.reject(new Error('The map is unavailable right now.'));
  }

  mapsPromise = new Promise<GMapsApi>((resolve, reject) => {
    (window as unknown as Record<string, () => void>)[READY_CALLBACK] = () => {
      resolve((window as unknown as { google: { maps: GMapsApi } }).google.maps);
    };

    const script = document.createElement('script');
    script.src =
      'https://maps.googleapis.com/maps/api/js?key=' +
      encodeURIComponent(apiKey) +
      '&libraries=' +
      encodeURIComponent(libraries.join(',')) +
      '&callback=' +
      READY_CALLBACK +
      '&loading=async';
    script.async = true;
    script.onerror = () => {
      // Cleared so the next mount retries: a blocked script or a dropped
      // connection is transient, and a cached rejected promise would leave
      // the map dead until a full page reload.
      mapsPromise = null;
      reject(new Error("Couldn't load Google Maps."));
    };
    document.head.appendChild(script);
  });

  return mapsPromise;
}
