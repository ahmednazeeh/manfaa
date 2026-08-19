<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MerchantPayoutBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A run of what the platform owes shops for marketplace orders. */
class MerchantPayoutBatch extends Model
{
    /** @use HasFactory<MerchantPayoutBatchFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_laari' => 'integer',
            'merchant_count' => 'integer',
            'excluded_laari' => 'integer',
            'excluded_count' => 'integer',
            'cutoff_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'exported_at' => 'immutable_datetime',
            'api_sent_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MerchantPayoutItem::class, 'batch_id');
    }
}
