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
    /**
     * Can a shopper actually buy this, right now?
     *
     * Four conditions, and they are genuinely independent (security audit
     * 2026-08-19). The listing being active says nothing about the SHOP: a
     * store that closed, was suspended, paused itself, or had its
     * marketplace approval withdrawn kept selling through the cart, because
     * only the shelf was ever consulted.
     */
    public function isBuyable(): bool
    {
        if ($this->state !== 'active') {
            return false;
        }

        if ($this->stock_qty !== null && $this->stock_qty <= 0) {
            return false;
        }

        $merchant = $this->branch?->merchant;

        return $merchant !== null
            && $merchant->status === 'active'
            && $merchant->unpublished_at === null
            && $merchant->marketplace?->state === 'active';
    }

    /** As above, for a wanted quantity — the shelf must hold that many. */
    public function canSupply(int $qty): bool
    {
        return $this->isBuyable()
            && ($this->stock_qty === null || $this->stock_qty >= $qty);
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
