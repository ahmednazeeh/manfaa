<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turn the pin a merchant just dropped into a written address.
 *
 * WHY this exists (owner round 2026-08-18). Branch addresses are required
 * now, but a shopkeeper should not have to type one that we can read off the
 * map they already tapped. Tested before building: OpenStreetMap does not
 * know either of our live merchants by name — a place SEARCH would return
 * nothing for the shops it was built for — but it does know Malé's streets,
 * in Thaana, with ward and postcode. So we do not search; we reverse the pin.
 *
 * The result is a SUGGESTION, never an authority. It lands in an editable
 * field and the merchant may overwrite every character of it: an address is
 * a claim about the shop, and the shop is the one making it.
 *
 * Same discipline as MapTileController — our identity in the User-Agent, our
 * cache in front, and a failure that degrades to "type it yourself" rather
 * than to an error the merchant cannot act on. Nominatim's usage policy caps
 * this at roughly one call a second; a per-branch-save lookup behind a cache
 * and a throttle is nowhere near it.
 */
final class ReverseGeocodeController extends Controller
{
    private const string AGENT = 'ManfaaMaps/1.0 (+https://manfaa.app; support@manfaa.app)';

    private const string ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    /** A pin does not move, and neither does the street it sits on. */
    private const int CACHE_DAYS = 30;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // ~11 m of precision. Two taps a few metres apart are the same
        // doorway and must not be two upstream calls.
        $lat = round((float) $validated['lat'], 4);
        $lng = round((float) $validated['lng'], 4);

        $address = Cache::remember(
            sprintf('geocode:reverse:v1:%s,%s', $lat, $lng),
            now()->addDays(self::CACHE_DAYS),
            fn (): ?string => $this->lookup($lat, $lng),
        );

        return new JsonResponse(['data' => ['address' => $address]]);
    }

    private function lookup(float $lat, float $lng): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::AGENT])
                ->timeout(8)
                ->get(self::ENDPOINT, [
                    'format' => 'jsonv2',
                    'lat' => $lat,
                    'lon' => $lng,
                    // House number → suburb. Anything wider is "Maldives",
                    // which tells a customer nothing they did not know.
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            if (! is_array($body)) {
                return null;
            }

            return $this->compose($body);
        } catch (Throwable $e) {
            // Never fatal: the merchant types the address instead.
            Log::warning('Reverse geocode failed', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the shortest line that still locates the shop.
     *
     * Nominatim's own `display_name` trails country and postcode through
     * every level; for a Maldivian branch that is a paragraph where a line
     * will do. Road, ward, city is what a person would say out loud.
     *
     * @param  array<string, mixed>  $body
     */
    private function compose(array $body): ?string
    {
        $address = is_array($body['address'] ?? null) ? $body['address'] : [];

        $parts = array_values(array_filter([
            $this->str($address, 'house_number'),
            $this->str($address, 'road'),
            $this->str($address, 'neighbourhood')
                ?? $this->str($address, 'suburb')
                ?? $this->str($address, 'quarter'),
            $this->str($address, 'city')
                ?? $this->str($address, 'town')
                ?? $this->str($address, 'village')
                ?? $this->str($address, 'island'),
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        if ($parts === []) {
            $display = $this->str($body, 'display_name');

            return $display === '' ? null : $display;
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function str(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
