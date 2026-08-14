<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasApiTokens, HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validation_window_days' => 'integer',
            'min_eligible_laari' => 'integer',
            'setup_state' => 'array',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(MerchantBranch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(MerchantRate::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(MerchantWallet::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }
}
