<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\CustomerAvatar;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Set or clear the customer's own profile picture.
 *
 * Mounted on BOTH auth surfaces — the website's session routes
 * (routes/api/customer.php) and the app's token routes
 * (routes/api/mobile.php) — exactly like the device list: one controller,
 * two doors, and `$request->user('customer')` resolves the caller behind
 * either. On the mobile tree NormalisesMobileErrors reshapes the 422s.
 *
 * Validation mirrors the merchant logo upload (Merchant\SetupController):
 * strictly raster images — SVG is scriptable content served from our origin
 * and is refused outright — with the same dimension sanity bounds. The size
 * cap is 4 MB (phone camera portraits run larger than shop logos).
 */
final class AvatarController extends Controller
{
    /** Upload or replace. Returns the new content-addressed URL. */
    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $request->validate([
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096', // KB
                Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(4096)->maxHeight(4096),
            ],
        ]);

        $url = CustomerAvatar::store($customer, $request->file('avatar'));

        return response()->json(['data' => ['avatar_url' => $url]]);
    }

    /** Remove: delete the file, null the column. Idempotent. */
    public function destroy(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        CustomerAvatar::remove($customer);

        return response()->json(['data' => ['avatar_url' => null]]);
    }
}
