<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_id' => 'integer',
            'account_id' => 'integer',
            'debit_laari' => 'integer',
            'credit_laari' => 'integer',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
