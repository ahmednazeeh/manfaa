<?php

namespace App\Listeners;

use App\Models\ApiCredential;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Stamps api_credentials.last_used_at whenever a vendor token authenticates
 * a request. Wired through Sanctum's TokenAuthenticated event (auto-
 * discovered from app/Listeners) rather than route middleware, so it covers
 * every token-authenticated route — /v1 included — without touching any
 * shared route file.
 *
 * Cheap by construction: a single UPDATE, throttled so a busy till writes
 * at most once a minute, and skipped entirely for non-vendor tokens
 * (no matching credential row means the WHERE matches nothing).
 */
class TouchCredentialLastUsed
{
    /**
     * How stale last_used_at must be before we write again.
     */
    public const int THROTTLE_SECONDS = 60;

    public function handle(TokenAuthenticated $event): void
    {
        // Sanctum fires this for EVERY bearer token, and mobile app tokens
        // are now the busiest kind. They never have an api_credentials row —
        // that table is vendor credentials only — so the UPDATE below would
        // match nothing, once per mobile request, forever. Leave before
        // touching the database rather than paying a round trip to learn it.
        if (! $event->token->tokenable instanceof Merchant) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        ApiCredential::query()
            ->where('personal_access_token_id', $event->token->getKey())
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('last_used_at')
                    ->orWhere('last_used_at', '<', $now->subSeconds(self::THROTTLE_SECONDS));
            })
            ->toBase()
            ->update(['last_used_at' => $now]);
    }
}
