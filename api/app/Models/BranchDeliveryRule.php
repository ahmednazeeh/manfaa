<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BranchDeliveryRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one branch will do for one destination island
 * (PLAN-marketplace.md §2.4).
 *
 * The existence of the row is the promise: no row means this branch does not
 * deliver there.
 */
class BranchDeliveryRule extends Model
{
    /** @use HasFactory<BranchDeliveryRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'free_delivery_over_laari' => 'integer',
            'delivery_fee_laari' => 'integer',
            'order_minimum_laari' => 'integer',
            'eta_min' => 'integer',
            'eta_max' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
