<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL registered to receive §9.3 outbound webhooks at.
 *
 * Owned by EITHER a POS vendor (one platform, one URL, every merchant it
 * serves) OR a single merchant (one shop, its own URL, its own events only —
 * owner, 2026-08-22). The database CHECK keeps it to exactly one. The
 * signing secret is encrypted at rest and never exposed after the issuing
 * 201 — losing it means registering a new endpoint.
 */
class WebhookEndpoint extends Model
{
    /** A merchant may register this many; a vendor is uncapped (admin-made). */
    public const int MAX_PER_MERCHANT = 5;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_vendor_id' => 'integer',
            'merchant_id' => 'integer',
            'api_credential_id' => 'integer',
            'created_by_merchant_user_id' => 'integer',
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    public function posVendor(): BelongsTo
    {
        return $this->belongsTo(PosVendor::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function apiCredential(): BelongsTo
    {
        return $this->belongsTo(ApiCredential::class);
    }

    public function isMerchantOwned(): bool
    {
        return $this->merchant_id !== null;
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
