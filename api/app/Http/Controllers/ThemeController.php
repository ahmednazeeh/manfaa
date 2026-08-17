<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Platform\BrandTheme;
use App\Http\Responses\CacheableJson;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public face of the admin-chosen storefront accent: one tiny ETagged
 * payload the customer web reads at boot. `brand` is null until an admin
 * picks a colour, and a null tells the web to keep its built-in stylesheet
 * untouched.
 */
class ThemeController extends Controller
{
    public function show(Request $request): Response
    {
        return CacheableJson::respond($request, response()->json([
            'data' => ['brand' => BrandTheme::current()],
        ]));
    }
}
