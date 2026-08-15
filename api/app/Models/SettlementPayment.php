<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementPayment extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settlement_id' => 'integer',
            'merchant_id' => 'integer',
            'amount_laari' => 'integer',
            'slip_size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'matched_by' => 'integer',
            'matched_at' => 'immutable_datetime',
            'rejected_by' => 'integer',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
