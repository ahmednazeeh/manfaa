<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Percent;
use App\Domain\Platform\InvalidSettingException;
use App\Domain\Platform\PlatformConfig;
use App\Http\Controllers\Controller;
use App\Rules\PercentRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typed platform settings: GET returns every key with its effective value,
 * default and allowed range; PATCH writes one key with per-key range
 * validation. Unset keys read as their defaults, so nothing changes
 * behaviour until an admin actually writes.
 *
 * Wire grammar (PLAN §1 "API wire format"): a key holding a RATE is named
 * `_percent` and carries a 2-decimal percent — "5", "5.0", "5.00" or the
 * JSON number 5 — converted to the integer basis points the engine stores
 * by exact integer math, never a float. Its `_bp` name is off the wire
 * entirely and 404s like any other unknown key. Keys in laari or days keep
 * their plain integer, which is what they are.
 */
class PlatformSettingsController extends Controller
{
    public function index(PlatformConfig $config): JsonResponse
    {
        return response()->json(['data' => $config->all()]);
    }

    public function update(Request $request, string $key, PlatformConfig $config): JsonResponse
    {
        // {key} is the WIRE name; the row it writes may be named otherwise.
        $storageKey = PlatformConfig::storageKey($key);

        if ($storageKey === null) {
            abort(404, sprintf('Unknown platform setting "%s".', $key));
        }

        $spec = PlatformConfig::KEYS[$storageKey];
        $percent = isset(PlatformConfig::PERCENT_KEYS[$storageKey]);

        $validated = $request->validate([
            'value' => $percent
                ? ['required', PercentRate::between($spec['min'], $spec['max'])]
                : ['required', 'integer'],
        ]);

        $value = $percent
            ? Percent::toBasisPointsWithin($validated['value'], $spec['min'], $spec['max'])
            : (int) $validated['value'];

        try {
            $config->set($storageKey, $value, (int) $request->user('admin')->getKey());
        } catch (InvalidSettingException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => [$key => $config->all()[$key]]]);
    }
}
