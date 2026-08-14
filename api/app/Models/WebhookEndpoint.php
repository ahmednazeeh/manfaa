<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL one POS vendor registered to receive §9.3 outbound webhooks at.
 * The signing secret is encrypted at rest and never exposed after the
 * issuing 201 — losing it means registering a new endpoint.
 */
class WebhookEndpoint extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_vendor_id' => 'integer',
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    public function posVendor(): BelongsTo
    {
        return $this->belongsTo(PosVendor::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
