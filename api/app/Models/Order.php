<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The PAYMENT. One per checkout, however many shops it spans. */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'address_snapshot' => 'array',
            // Every identifier the matched bank credit answered to.
            'matched_trx_refs' => 'array',
            'items_laari' => 'integer',
            'delivery_laari' => 'integer',
            'total_payable_laari' => 'integer',
            'cashback_total_laari' => 'integer',
            'placed_at' => 'immutable_datetime',
            'proof_submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            // The bank-watching window. Cast, so the poll job compares two
            // instants rather than an instant against a string.
            'poll_started_at' => 'immutable_datetime',
            'poll_until' => 'immutable_datetime',
            'poll_attempts' => 'integer',
            'matched_score' => 'integer',
            'auto_verified' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function suborders(): HasMany
    {
        return $this->hasMany(Suborder::class);
    }
}
