<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-store PRODUCT category (Task #25): either `excluded` (its lines
 * never earn, even during promotions) or a `rate` override in basis points.
 * The slug is generated from name_en at creation and is IMMUTABLE — it is
 * the public line key credits submit, and transaction_lines snapshot it.
 * Mode/rate changes affect FUTURE credits only; deactivation is soft.
 */
class MerchantProductCategory extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'rate_bp' => 'integer',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
