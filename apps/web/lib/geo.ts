/**
 * Client-side haversine for the store page's branch distances — display-only
 * float maths (never money). Mirrors the API's Haversine domain helper so a
 * distance shown here matches what the discovery endpoint would say.
 */

const EARTH_RADIUS_M = 6_371_000;

/** Great-circle distance in whole metres between two WGS84 points. */
export function haversineMeters(
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number,
): number {
  const toRad = (deg: number) => (deg * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return Math.round(2 * EARTH_RADIUS_M * Math.asin(Math.sqrt(a)));
}
