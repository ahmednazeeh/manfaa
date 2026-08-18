<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MerchantMarketplaceProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's marketplace enrolment (PLAN-marketplace.md §2.1, §9).
 *
 * The ABSENCE of a row is the ordinary state: most merchants sell in-store
 * for cashback and never open a shop online. A row exists once they have
 * asked to, and its `state` says how far that has got.
 */
class MerchantMarketplaceProfile extends Model
{
    /** @use HasFactory<MerchantMarketplaceProfileFactory> */
    use HasFactory;

    protected $guarded = [];

    /** Selling online right now. Everything public checks THIS, not `state`. */
    public function isLive(): bool
    {
        return $this->state === 'active';
    }

    protected function casts(): array
    {
        return [
            'prep_time_min' => 'integer',
            'prep_time_max' => 'integer',
            'order_fee_bp' => 'integer',
            'rating_count' => 'integer',
            'rating_sum' => 'integer',
            'enrolled_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * The average rating, or null when nobody has rated yet.
     *
     * Null rather than 0.0 on purpose (§11.2): a new shop has no rating, and
     * showing it zero stars would libel it on its first day.
     */
    public function ratingAverage(): ?float
    {
        return $this->rating_count > 0
            ? round($this->rating_sum / $this->rating_count, 1)
            : null;
    }
}
