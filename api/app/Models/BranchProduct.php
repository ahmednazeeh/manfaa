<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BranchProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shop's listing of one product — its price, its stock, its say on
 * whether it is available at all (PLAN-marketplace.md §2.2, §2.3).
 *
 * This is the row a shopper actually buys from.
 */
class BranchProduct extends Model
{
    /** @use HasFactory<BranchProductFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_laari' => 'integer',
            'compare_at_laari' => 'integer',
            'stock_qty' => 'integer',
            'low_stock_at' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Can a shopper add this to a cart right now?
     *
     * Untracked stock (`null`) is availability, not absence: a café does not
     * count cappuccinos. Zero is the opposite statement — counted, and there
     * is none — which is why the two cannot share a column meaning.
     */
    public function isBuyable(): bool
    {
        return $this->state === 'active'
            && ($this->stock_qty === null || $this->stock_qty > 0);
    }

    /** Counted, and running out. Drives the "Low stock" chip in products.png. */
    public function isLowStock(): bool
    {
        return $this->stock_qty !== null
            && $this->low_stock_at !== null
            && $this->stock_qty > 0
            && $this->stock_qty <= $this->low_stock_at;
    }
}
