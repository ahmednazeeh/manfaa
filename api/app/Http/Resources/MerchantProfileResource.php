<?php

namespace App\Http\Resources;

use App\Domain\Approvals\ChangeKind;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use App\Models\StoreCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner-editable merchant profile. `name` is read-only display —
 * renaming the business is an identity change and stays admin-only.
 * `eligibility_basis` is the §11 free-text mirror of the agreement,
 * displayed to customers, never used in computation.
 *
 * `category_retired` says the store still holds a curated category the
 * superadmin has since deactivated. The save path TOLERATES that value
 * (re-sending it unchanged is not an error — see ProfileController), so
 * without this flag the panel could not tell the owner why their category
 * is missing from the picker or that they should choose a new one.
 *
 * @mixin Merchant
 */
class MerchantProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_dv' => $this->name_dv,
            'slug' => $this->slug,
            'status' => $this->status,
            'category' => $this->category,
            'category_retired' => $this->categoryRetired(),
            'channel' => $this->channel,
            'eligibility_basis' => $this->eligibility_basis,
            'description' => $this->description,
            'description_dv' => $this->description_dv,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'support_phone' => $this->support_phone,
            'website_url' => $this->website_url,
            // MR9: the store's own public claims waiting on a reviewer, with
            // the proposed values. Null when nothing is queued. Both panels
            // render their "pending review" state from this — the form still
            // shows the LIVE values, because those are what a shopper sees
            // until the change is approved.
            'pending_change' => $this->pendingChange($request),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingChange(Request $request): ?array
    {
        $pending = MerchantChangeRequest::query()
            ->where('merchant_id', $this->id)
            ->where('kind', ChangeKind::Profile->value)
            ->where('status', MerchantChangeRequest::PENDING)
            ->latest('id')
            ->first();

        return $pending === null
            ? null
            : (new MerchantChangeRequestResource($pending))->resolve($request);
    }

    /**
     * One indexed lookup, and only when a category is actually set. This
     * resource serves a single merchant (profile show/update), never a
     * collection, so there is no N+1 to amortise.
     */
    private function categoryRetired(): bool
    {
        if ($this->category === null || $this->category === '') {
            return false;
        }

        return ! StoreCategory::query()
            ->where('slug', $this->category)
            ->where('active', true)
            ->exists();
    }
}
