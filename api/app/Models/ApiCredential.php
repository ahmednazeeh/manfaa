<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'abilities' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function posVendor(): BelongsTo
    {
        return $this->belongsTo(PosVendor::class);
    }

    /**
     * The merchant OWNER who self-issued this credential from the panel
     * (§13b task #21), null for the admin-issued path — where `issued_by`
     * names an admin_users row instead. The two are mutually exclusive by
     * check constraint.
     */
    public function issuedByMerchantUser(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'issued_by_merchant_user');
    }

    public function revokedByMerchantUser(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'revoked_by_merchant_user');
    }
}
