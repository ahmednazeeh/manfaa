<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MerchantPayoutItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One transfer to one shop, and the orders it settles. */
class MerchantPayoutItem extends Model
{
    /** @use HasFactory<MerchantPayoutItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /** States from which a transfer may still be attempted. */
    public const array SENDABLE = ['pending', 'failed'];

    protected function casts(): array
    {
        return [
            'amount_laari' => 'integer',
            'attempts' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MerchantPayoutBatch::class, 'batch_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function suborders(): HasMany
    {
        return $this->hasMany(Suborder::class, 'payout_item_id');
    }

    /** Parked awaiting a second approver — alive, and never re-sent. */
    public function isParked(): bool
    {
        return $this->state === 'pending_approval';
    }
}
