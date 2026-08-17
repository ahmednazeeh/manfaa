<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An island zone: an admin-drawn polygon with a name. Branches are assigned
 * by point-in-polygon at write time (ZoneAssigner) — the zone is the unit
 * the customer's location picker offers.
 */
class Zone extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'polygon' => 'array',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(MerchantBranch::class);
    }
}
