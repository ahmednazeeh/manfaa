<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SuborderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of one shop's order.
 *
 * `qty` is what was ordered and never changes; `fulfilled_qty` is what the
 * shop will supply. The gap is the amendment, and keeping both is what lets
 * the customer's screen show the strike-through rather than a rewritten
 * history (§2.7).
 */
class SuborderItem extends Model
{
    /** @use HasFactory<SuborderItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'fulfilled_qty' => 'integer',
            'unit_price_laari' => 'integer',
            'line_total_laari' => 'integer',
            'cashback_laari' => 'integer',
        ];
    }

    public function suborder(): BelongsTo
    {
        return $this->belongsTo(Suborder::class);
    }

    /** True once the shop has reduced this line. */
    public function wasAmended(): bool
    {
        return $this->fulfilled_qty !== $this->qty;
    }

    /** What the customer gets back for the part not supplied. */
    public function refundLaari(): int
    {
        return ($this->qty - $this->fulfilled_qty) * $this->unit_price_laari;
    }
}
