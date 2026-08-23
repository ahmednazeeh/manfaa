<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device a customer has been seen on (self-referral defence, owner
 * 2026-08-24).
 *
 * `device_hash` is HMAC-SHA256(app key, raw id) — the raw Android SSAID,
 * iOS identifierForVendor/Keychain UUID, or web browser-ref cookie NEVER
 * reaches the database. Equality is the only question the defence asks, and
 * a hash answers it without the table ever naming a real device.
 *
 * Written exclusively by DeviceIdentity::record(); read exclusively by
 * DeviceIdentity::sharesDevice(). No API ever lists these rows.
 */
class CustomerDevice extends Model
{
    /** first_seen_at / last_seen_at are the record — no created/updated pair. */
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
