<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\BrandTheme;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront accent colour, as a superadmin lever. Read by any admin
 * (the appearance screen shows the current state); the write sits behind
 * EnsureSuperadmin in the routes. The customer web reads the public
 * /api/theme mirror.
 */
class BrandThemeController extends Controller
{
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => ['color' => BrandTheme::current()]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Null clears the override; otherwise exactly #rrggbb.
            'color' => ['present', 'nullable', 'string', 'regex:'.BrandTheme::HEX_PATTERN],
        ]);

        BrandTheme::set($validated['color'] === null ? null : strtolower($validated['color']));

        return new JsonResponse(['data' => ['color' => BrandTheme::current()]]);
    }
}
