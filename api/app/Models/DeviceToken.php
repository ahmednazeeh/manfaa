<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One device's push registration.
 *
 * Lives and dies with the auth token that created it (FK, cascade delete),
 * so every existing revocation path — sign out, cut a device off from the
 * website, the token cap evicting the least recently used, a staff member
 * being deactivated — silently takes the push with it. Nothing has to
 * remember to.
 */
class DeviceToken extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_build' => 'integer',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function sendsDhivehi(): bool
    {
        return $this->locale === 'dv';
    }
}
