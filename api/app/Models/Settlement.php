<?php

namespace App\Models;

use App\Domain\Settlement\SettlementState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'state' => SettlementState::class,
            'sale_total_laari' => 'integer',
            'cashback_total_laari' => 'integer',
            'fee_total_laari' => 'integer',
            'fee_gst_total_laari' => 'integer',
            // PLAN §1 prompt-payment discount: the relief granted (already
            // subtracted from amount_due_laari) and the rate it was priced
            // at. discount_rate_bp is null when nothing was granted.
            'discount_laari' => 'integer',
            'discount_posted_laari' => 'integer',
            'discount_rate_bp' => 'integer',
            'amount_due_laari' => 'integer',
            'amount_received_laari' => 'integer',
            'due_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SettlementPayment::class);
    }
}
