<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement. Credits positive, withdrawals negative. */
class CustomerWalletEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_laari' => 'integer', 'balance_after_laari' => 'integer'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CustomerWallet::class, 'wallet_id');
    }
}
