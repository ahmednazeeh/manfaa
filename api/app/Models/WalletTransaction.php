<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'amount_laari' => 'integer',
            'balance_after_laari' => 'integer',
            'reference_id' => 'integer',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }
}
