<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\InvalidSettingException;
use App\Domain\Platform\PlatformConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typed platform settings: GET returns every key with its effective value,
 * default and allowed range; PATCH writes one key with per-key integer
 * range validation. Unset keys read as their defaults, so nothing changes
 * behaviour until an admin actually writes.
 */
class PlatformSettingsController extends Controller
{
    public function index(PlatformConfig $config): JsonResponse
    {
        return response()->json(['data' => $config->all()]);
    }

    public function update(Request $request, string $key, PlatformConfig $config): JsonResponse
    {
        if (! array_key_exists($key, PlatformConfig::KEYS)) {
            abort(404, sprintf('Unknown platform setting "%s".', $key));
        }

        $validated = $request->validate([
            'value' => ['required', 'integer'],
        ]);

        try {
            $config->set($key, (int) $validated['value'], (int) $request->user('admin')->getKey());
        } catch (InvalidSettingException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => [$key => $config->all()[$key]]]);
    }
}
