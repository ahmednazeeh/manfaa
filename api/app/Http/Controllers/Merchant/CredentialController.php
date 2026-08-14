<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CredentialResource;
use App\Models\ApiCredential;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Merchant panel read model for the merchant's own vendor credentials
 * (§9.1): which POS vendors hold a token, with which abilities, when each
 * was last seen, and whether it is revoked. Strictly read-only and never
 * a token value — issuance and revocation are admin actions.
 */
class CredentialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $rows = ApiCredential::query()
            ->where('merchant_id', $user->merchant_id)
            ->with('posVendor')
            ->orderByDesc('id')
            ->get();

        return CredentialResource::collection($rows);
    }
}
