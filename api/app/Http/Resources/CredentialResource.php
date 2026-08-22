<?php

namespace App\Http\Resources;

use App\Models\ApiCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The safe view of an api_credentials row for both the admin and merchant
 * panels. Deliberately never exposes `token_hash` or the personal access
 * token linkage — the plaintext token exists only in the 201 body of the
 * issuing request, and nothing recoverable ever appears in a listing.
 *
 * @mixin ApiCredential
 */
class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'pos_vendor' => $this->posVendor === null ? null : [
                'id' => $this->posVendor->id,
                'name' => $this->posVendor->name,
            ],
            // Free-text partner name from the merchant self-serve path;
            // null on admin-issued rows, which carry a pos_vendor instead.
            'label' => $this->label,
            // Which store a public-client grant ("Connect with Manfaa" from
            // a plugin) came from — `https://shop.example.mv`; null on
            // everything else. Shown so a merchant with two stores can tell
            // the two connections apart before revoking one.
            'connected_from' => $this->connected_from,
            // What a panel should print for this credential, resolved once
            // here so admin and merchant never disagree about the fallback.
            'display_name' => $this->posVendor?->name ?? $this->label ?? 'API credential',
            'abilities' => $this->abilities,
            'issued_by' => $this->issued_by,
            // WHO minted a token that can write cashback: Manfaa, or the
            // store itself. Admin names are deliberately not exposed — the
            // merchant panel renders the type, not our staff directory.
            'issuer' => [
                'type' => $this->issued_by_merchant_user !== null
                    ? 'merchant_user'
                    : ($this->issued_by !== null ? 'admin' : 'unknown'),
                'name' => $this->issuedByMerchantUser?->name,
            ],
            'revoked_by_type' => $this->revoked_at === null
                ? null
                : ($this->revoked_by_merchant_user !== null
                    ? 'merchant_user'
                    : ($this->revoked_by !== null ? 'admin' : 'unknown')),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoked_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
