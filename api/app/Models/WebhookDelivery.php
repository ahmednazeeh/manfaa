<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook delivery: one event bound for one endpoint. A single row per
 * delivery with an attempt counter — never a row per attempt; `last_error`
 * and `response_status` describe the most recent attempt only (see the
 * webhook_deliveries migration for the full rationale).
 */
class WebhookDelivery extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'webhook_endpoint_id' => 'integer',
            'payload' => 'array',
            'attempt' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
