<?php

namespace App\Models;

use App\Domain\Payout\PayoutBatchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'cutoff_at' => 'immutable_datetime',
            'state' => PayoutBatchState::class,
            'total_laari' => 'integer',
            'customer_count' => 'integer',
            'approved_by_first' => 'integer',
            'approved_by_second' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class, 'batch_id');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_first');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_second');
    }
}
