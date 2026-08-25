<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only pricing split of a lined transaction — rows are written once
 * at credit time and never updated or deleted (same guard as ledger
 * entries). The parent transaction's totals are the SUM of these stored
 * integers; reversals reverse the STORED transaction totals and leave the
 * line snapshots untouched.
 */
class TransactionLine extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('transaction_lines is append-only — lines are never updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('transaction_lines is append-only — lines are never deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'product_category_id' => 'integer',
            'amount_laari' => 'integer',
            'effective_rate_bp' => 'integer',
            'fee_bp' => 'integer',
            'cashback_laari' => 'integer',
            'fee_laari' => 'integer',
            'fee_gst_bp' => 'integer',
            'fee_gst_laari' => 'integer',
            'sort' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(MerchantProductCategory::class, 'product_category_id');
    }
}
