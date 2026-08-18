<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerRefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money we owe a customer back (§5.4b).
 *
 * An OBLIGATION, not a payment. This platform has no customer wallet — a
 * payable balance is derived from confirmed cashback — so an amendment or a
 * rejection records the debt here, atomically with the change that created
 * it, and MP8 decides how it is settled. An order can never be reduced
 * without the debt existing.
 */
class CustomerRefund extends Model
{
    /** @use HasFactory<CustomerRefundFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_laari' => 'integer',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function suborder(): BelongsTo
    {
        return $this->belongsTo(Suborder::class);
    }
}
