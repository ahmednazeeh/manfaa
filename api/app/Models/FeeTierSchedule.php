<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only effective-dated fee tier tables (§4). Rows are never updated
 * or deleted; the schedule active at instant T is the latest row with
 * effective_from <= T. The migration seeds the hardcoded §4 table with
 * effective_from in the far past so history reprices identically.
 */
#[Fillable(['effective_from', 'tiers', 'created_by', 'created_at'])]
class FeeTierSchedule extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'tiers' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
