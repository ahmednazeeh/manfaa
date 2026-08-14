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
            'abilities' => $this->abilities,
            'issued_by' => $this->issued_by,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoked_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
