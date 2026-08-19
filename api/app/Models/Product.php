<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a thing IS — owned by the merchant, never by a shop
 * (PLAN-marketplace.md §2.2).
 *
 * Deliberately holds no price and no stock. Those belong to the branch that
 * sells it, because a chain's two shops genuinely differ on both.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Fields a shop may change WITHOUT review (owner decision, §11.1 Q3).
     *
     * These are operational: a shop that cannot reprice or mark something
     * out of stock without waiting a day will simply oversell. Everything
     * NOT on this list is a public claim and is gated — fail-closed, the
     * same stance as INSTANT_PROFILE_KEYS.
     *
     * They live on `branch_products`, not here, which is why this list names
     * no column of this table: every field of a product definition is a
     * claim.
     */
    public const array INSTANT_LISTING_KEYS = [
        'price_laari',
        'compare_at_laari',
        'stock_qty',
        'low_stock_at',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'cashback_rate_bp' => 'integer',
            'allow_substitutions' => 'boolean',
            'archived' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Where the product sits on the shelf — the SHOPPER'S vocabulary, shared
     * by every store so that browsing across shops is coherent.
     */
    public function marketplaceCategory(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCategory::class, 'marketplace_category_id');
    }

    /**
     * What the product EARNS, by the merchant's own pricing list — the same
     * one that prices their in-store sales.
     *
     * Optional by design (owner decision 2026-08-19): left unset, the
     * product falls into the default "everything else" bucket and earns the
     * standing rate, exactly as an unfiled product does in-store.
     */
    public function cashbackCategory(): BelongsTo
    {
        return $this->belongsTo(MerchantProductCategory::class, 'cashback_category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }
}
