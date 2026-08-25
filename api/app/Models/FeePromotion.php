<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row governing PLATFORM FEE PROMOTIONS (owner, 2026-08-25) —
 * both kinds at once: the introductory offer every new merchant gets for
 * their first X days, and the platform-wide window that covers everybody.
 *
 * Its own table rather than PlatformConfig keys, following the
 * TransferSetting / TaxSetting precedent: that store holds INTEGERS, and the
 * banner copy here is marketing prose in two scripts.
 *
 * NOT App\Models\Promotion. That one is a CASHBACK promotion — a merchant
 * paying their customers more. This one lowers what Manfaa charges the
 * merchant. The two never meet, and confusing them is the single easiest
 * mistake to make in this area of the codebase.
 *
 * Ships with both switches OFF.
 */
class FeePromotion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'intro_enabled' => 'boolean',
            'intro_days' => 'integer',
            'intro_fee_bp' => 'integer',
            'wide_enabled' => 'boolean',
            'wide_from' => 'immutable_datetime',
            'wide_to' => 'immutable_datetime',
            'wide_fee_bp' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    /** There is exactly one, always. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'intro_enabled' => false,
            'intro_days' => 0,
            'wide_enabled' => false,
        ]);
    }
}
