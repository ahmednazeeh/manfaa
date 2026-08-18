<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money leaving the platform to a customer's bank.
 *
 * `internal_ref` is the idempotency key the upstream deduplicates on, which
 * is why it is unique here too: the same payout always produces the same
 * string, so a crashed worker resuming cannot pay twice.
 */
class CustomerPayout extends Model
{
    /** @use HasFactory<CustomerPayoutFactory> */
    use HasFactory;

    protected $guarded = [];

    /** States from which a transfer may still be attempted. */
    public const array SENDABLE = ['pending', 'failed'];

    protected function casts(): array
    {
        return [
            'amount_laari' => 'integer',
            'attempts' => 'integer',
            'requested_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Parked awaiting a second approver. Alive, not failed — and the one
     * state nothing may ever re-send from.
     */
    public function isParked(): bool
    {
        return $this->state === 'pending_approval';
    }
}
