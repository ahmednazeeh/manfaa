<?php

namespace App\Models;

use Database\Factories\MerchantUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['merchant_id', 'name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class MerchantUser extends Authenticatable
{
    /** @use HasFactory<MerchantUserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The three merchant panel tiers (PLAN §1), ASCENDING in authority —
     * the index in this list is the rank the role gate compares. Mirrors
     * the merchant_users_role_check constraint.
     *
     * @var list<string>
     */
    public const array ROLES = ['staff', 'manager', 'owner'];

    /**
     * True when this account's tier is at or above $minimum. Unknown roles
     * (a row written before a widening, say) rank below everything and are
     * refused by every gate.
     */
    public function hasRoleAtLeast(string $minimum): bool
    {
        $rank = array_search((string) $this->role, self::ROLES, true);
        $required = array_search($minimum, self::ROLES, true);

        return $rank !== false && $required !== false && $rank >= $required;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
