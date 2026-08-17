import { type ZonePoint } from '@manfaa/api-client';
import { type GGeocoderResult } from '@manfaa/ui';

/**
 * Naming a zone the admin didn't name: the polygon's centroid is
 * reverse-geocoded and the most specific locality-ish component wins. Pure
 * functions — the page owns the Geocoder call so these stay testable and
 * map-free.
 */

/**
 * Vertex average — not the true area centroid, but an island ring is convex
 * enough that the average always lands on (or over) the island, which is all
 * the reverse geocoder needs.
 */
export function centroidOf(polygon: ZonePoint[]): ZonePoint {
  let lat = 0;
  let lng = 0;
  for (const point of polygon) {
    lat += point.lat;
    lng += point.lng;
  }
  return { lat: lat / polygon.length, lng: lng / polygon.length };
}

/**
 * Most-specific-first: an inhabited island geocodes as a locality, a ward of
 * Malé as a sublocality, an atoll as administrative_area_level_2, and an
 * uninhabited islet often only as a natural_feature. The first type with a
 * hit anywhere in the result set wins.
 */
const LOCALITY_TYPES = [
  'locality',
  'sublocality',
  'administrative_area_level_2',
  'natural_feature',
] as const;

/**
 * The island/city/village name in a reverse-geocode result set, or null when
 * nothing usable came back. Falls back to the first comma-part of the top
 * result's formatted address. Clamped to the API's 100-char name limit.
 */
export function islandNameFrom(results: GGeocoderResult[]): string | null {
  for (const type of LOCALITY_TYPES) {
    for (const result of results) {
      for (const component of result.address_components) {
        if (component.types.includes(type)) {
          return component.long_name.slice(0, 100);
        }
      }
    }
  }

  const firstPart = results[0]?.formatted_address.split(',')[0]?.trim();
  return firstPart ? firstPart.slice(0, 100) : null;
}
