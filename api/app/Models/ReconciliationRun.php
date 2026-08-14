<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of one daily reconciliation run. ran_at is the record
 * timestamp; rows are never updated after being written.
 */
class ReconciliationRun extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journals_checked' => 'integer',
            'issues' => 'array',
            'totals' => 'array',
            'ran_at' => 'immutable_datetime',
        ];
    }
}
