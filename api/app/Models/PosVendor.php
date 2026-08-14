<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosVendor extends Model
{
    protected $guarded = [];

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }
}
