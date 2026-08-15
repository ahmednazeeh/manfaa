<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A curated featured offer — the image banner at the top of Discover.
 *
 * Holds only what a merchant record cannot: artwork, copy, and a schedule.
 * Every merchant fact on the rendered banner (logo, cashback rate,
 * category, whether the shop is trading) is read live through the relation,
 * so a banner cannot outlive the thing it advertises.
 */
class StoreOffer extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'sort' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Active AND inside its window. An offer with no window runs until it is
     * switched off; `starts_at` in the future is scheduled, not live, which
     * is what lets a banner be prepared days ahead of the campaign.
     */
    public function scopeLive(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }
}
