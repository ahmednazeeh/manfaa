<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'customer_code',
    'phone',
    'phone_verified_at',
    'name',
    'email',
    'password',
    'status',
    'payout_bank',
    'payout_account',
    'payout_account_name',
    'kyc_status',
])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Generate a unique random 6-digit customer code.
     */
    public static function generateCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (static::query()->where('customer_code', $code)->exists());

        return $code;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function payoutItems(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }
}
