/**
 * Bounds of the public directory's `q` parameter — mirrors the API's
 * validation (DiscoveryController: min:2 / max:40). Every search input
 * clamps to this window client-side so neither a keystroke, a paste nor a
 * shared /discover?q=… URL ever turns into a 422 from the API.
 */
export const SEARCH_MIN_CHARS = 2;
export const SEARCH_MAX_CHARS = 40;
