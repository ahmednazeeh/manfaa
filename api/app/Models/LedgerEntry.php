<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only journal line — rows are written once, never updated.
 */
class LedgerEntry extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('ledger_entries is append-only — entries are never updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('ledger_entries is append-only — entries are never deleted.');
        });
    }

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
