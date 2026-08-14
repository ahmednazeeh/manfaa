<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only state-change log — rows are written once, never updated.
 */
class TransactionEvent extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'actor_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
