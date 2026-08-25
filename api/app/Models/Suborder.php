<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tax\FeeTax;
use Database\Factories\SuborderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The unit of FULFILMENT — one shop's part of an order.
 *
 * Every rate on it was frozen at placement. That is what makes an order
 * re-computable after an amendment without a platform fee change reaching
 * backwards into it (§5.4c).
 */
class Suborder extends Model
{
    /** @use HasFactory<SuborderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items_laari' => 'integer',
            'delivery_laari' => 'integer',
            'subtotal_laari' => 'integer',
            'cashback_rate_bp' => 'integer',
            'cashback_min_laari' => 'integer',
            'cashback_laari' => 'integer',
            'order_fee_bp' => 'integer',
            'order_fee_laari' => 'integer',
            'order_fee_gst_laari' => 'integer',
            'order_fee_gst_bp' => 'integer',
            'payable_to_merchant_laari' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    /**
     * The GST terms this suborder's ORDER FEE was priced under, as stamped
     * on it at placement.
     *
     * The only correct source for re-deriving the tax on this order fee —
     * an amendment re-prices from here, never from the live tax_settings
     * row, which answers a different question (what the platform charges
     * NOW). A row written before the columns existed answers 0 bp / on_top,
     * which is the identity and reproduces its stored zero exactly.
     */
    public function feeTax(): FeeTax
    {
        return FeeTax::of((int) $this->order_fee_gst_bp, $this->order_fee_treatment);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SuborderItem::class);
    }
}
