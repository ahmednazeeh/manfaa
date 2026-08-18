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
            'items_laari' => 'integer',
            'delivery_laari' => 'integer',
            'total_payable_laari' => 'integer',
            'cashback_total_laari' => 'integer',
            'placed_at' => 'immutable_datetime',
            'proof_submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
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
