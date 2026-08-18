<?php

namespace App\Models;

use App\Domain\Zoning\ZoneAssigner;
use Database\Factories\MerchantBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantBranch extends Model
{
    /** @use HasFactory<MerchantBranchFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The pin decides the island: whenever the coordinates change, the
        // zone is recomputed HERE so every write path — the merchant's
        // branch dialog, the signup wizard, anything future — agrees.
        // ZoneAssigner::reassignAll saves quietly, so this cannot loop.
        static::saving(function (MerchantBranch $branch): void {
            if ($branch->isDirty(['lat', 'lng']) || ! $branch->exists) {
                $branch->zone_id = ZoneAssigner::zoneIdFor(
                    $branch->lat === null ? null : (float) $branch->lat,
                    $branch->lng === null ? null : (float) $branch->lng,
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
        ];
    }

    /**
     * What this branch will deliver, and where to — one row per island it
     * serves (PLAN-marketplace.md §2.4). No row means it does not go there.
     */
    public function deliveryRules(): HasMany
    {
        return $this->hasMany(BranchDeliveryRule::class, 'branch_id');
    }

    /** What this shop lists — its own prices and its own stock (§2.2). */
    public function products(): HasMany
    {
        return $this->hasMany(BranchProduct::class, 'branch_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'branch_id');
    }
}
