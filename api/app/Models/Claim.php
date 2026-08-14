<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Claim extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'customer_id' => 'integer',
            'claimed_date' => 'immutable_date',
            'claimed_amount_laari' => 'integer',
            'resolved_by' => 'integer',
            'resulting_transaction_id' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function resultingTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'resulting_transaction_id');
    }
}
