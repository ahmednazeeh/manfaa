<?php

namespace App\Http\Resources;

use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in merchant account — the body of login, register and /me.
 *
 * @mixin MerchantUser
 */
class MerchantUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // The RESOLVED flat set, owner wildcard already expanded (D3).
            // Every gate in the panel reads this and nothing else: a set has
            // no order, so there is no tier left to compare against, and
            // shipping the owner's wildcard as a sentinel instead would make
            // `permissions.includes('bank_account.update')` false for the one
            // account the wildcard exists to protect.
            'permissions' => $this->resource->resolvedPermissions(),
            // The role travels too, but only to be PRINTED — "signed in as
            // Shift lead" — and so the roles screen can tell which row is
            // the reader's own. Never gate on it: custom names are the
            // store's own words and mean nothing to us.
            'role' => MerchantRoleResource::summary($this->role),
            'merchant' => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->name,
                // The onboarding lifecycle status — the panel routes draft /
                // rejected owners into the setup wizard and pending_review
                // ones onto the waiting screen.
                'status' => $this->merchant->status,
            ],
        ];
    }
}
