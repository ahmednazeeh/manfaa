<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Customers\CustomerAvatar;
use App\Http\Controllers\Controller;
use App\Http\Responses\CacheableJson;
use App\Models\Customer;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who am I, right now.
 *
 * THE REASON THIS EXISTS: the merchant sign-in response carries the staff
 * member's resolved permissions, and the till builds its navigation from
 * them — the app must not draw a screen the account may not reach. Without a
 * refresh, that array was captured at sign-in and then frozen for the whole
 * 90-day life of the token: an owner granting `settlements.create` at 09:00
 * could not make it appear on the manager's till, and — the case that
 * matters — a demoted cashier kept rendering the settlement builder until
 * they happened to sign in again. The only way to refresh it was a full
 * re-sign-in, which also evicts another device under the token cap.
 *
 * So the apps call this on launch and on resume. It is deliberately cheap
 * and conditional: unchanged permissions cost a 304, not a payload.
 */
final class SessionController extends Controller
{
    public function customer(Request $request): Response
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return CacheableJson::respond($request, response()->json([
            'data' => [
                'id' => $customer->getKey(),
                'name' => $customer->name,
                // Their name in Thaana. Null until the queued writer fills it
                // in, and null forever if the model could not — the clients
                // fall back to `name`, never to an empty header.
                'name_dv' => $customer->name_dv,
                'customer_code' => $customer->customer_code,
                'phone' => $customer->phone,
                // Null until a picture is set. Content-addressed (a new
                // uuid URL per upload), so the app caches it hard.
                'avatar_url' => CustomerAvatar::url($customer),
                // What the app needs to know it must nag about: nobody gets
                // paid without one.
                'has_payout_account' => filled($customer->payout_bank)
                    && filled($customer->payout_account)
                    && filled($customer->payout_account_name),
            ],
        ]));
    }

    public function merchant(Request $request): Response
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $user->loadMissing('merchant');

        return CacheableJson::respond($request, response()->json([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'merchant' => [
                    'id' => $user->merchant?->getKey(),
                    'name' => $user->merchant?->name,
                    'slug' => $user->merchant?->slug,
                    // The till should say "we are not trading yet" rather
                    // than let a cashier discover it on a refused credit.
                    'status' => $user->merchant?->status,
                ],
                // Resolved FRESH on every call — the whole point.
                'permissions' => $user->resolvedPermissions(),
            ],
        ]));
    }
}
