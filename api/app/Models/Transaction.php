<?php

namespace App\Models;

use App\Domain\Cashback\TransactionState;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'branch_id' => 'integer',
            'customer_id' => 'integer',
            'promotion_id' => 'integer',
            'eligible_laari' => 'integer',
            'sale_laari' => 'integer',
            'rate_bp' => 'integer',
            'fee_bp' => 'integer',
            'cashback_laari' => 'integer',
            'fee_laari' => 'integer',
            'fee_gst_laari' => 'integer',
            'state' => TransactionState::class,
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'clock_start_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function settlementLines(): HasMany
    {
        return $this->hasMany(SettlementLine::class);
    }
}
