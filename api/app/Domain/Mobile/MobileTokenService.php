<?php

declare(strict_types=1);

namespace App\Domain\Mobile;

use App\Models\Customer;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The one place a mobile token is created, listed or destroyed.
 *
 * Mirrors CredentialService's stance for vendor tokens: minting is funnelled
 * through a single method so the ability set is not a thing a caller may
 * choose. A token carries exactly its audience's ability and nothing else —
 * never the '*' wildcard, which would make EnsureMobileToken's ability check
 * vacuous and leave `instanceof` as the only wall standing.
 */
final class MobileTokenService
{
    /**
     * Live tokens kept per user per audience; minting past this revokes the
     * least recently used.
     *
     * Reinstalling an app mints a new token and cannot revoke the old one —
     * the old plaintext is gone from the device. Without a cap, a user who
     * reinstalls monthly accumulates live credentials forever, every one of
     * them a working key. Five covers a phone, a tablet and a replacement
     * mid-cycle; a merchant running more tills than that should be minting
     * per-device staff accounts, which is what the permission catalogue is
     * for.
     */
    public const int MAX_TOKENS_PER_AUDIENCE = 5;

    /**
     * Mint a token for a device.
     *
     * The audience is asserted against the model rather than inferred from
     * it: an argument mismatch here would produce a token that authenticates
     * the wrong app, and that must be a loud programming error rather than a
     * quiet privilege grant.
     */
    public function issue(
        Customer|MerchantUser $user,
        MobileAudience $audience,
        string $deviceName,
    ): IssuedMobileToken {
        $expected = $audience->model();

        if (! $user instanceof $expected) {
            throw new InvalidArgumentException(sprintf(
                'Cannot mint a %s token for %s.',
                $audience->value,
                $user::class,
            ));
        }

        $deviceName = self::cleanDeviceName($deviceName);
        $expiresAt = CarbonImmutable::now('UTC')->addDays($audience->tokenLifetimeDays());

        return DB::transaction(function () use ($user, $audience, $deviceName, $expiresAt) {
            // Serialise concurrent mints for ONE account, exactly as
            // CredentialService does before its own cap check. Without this,
            // two sign-ins racing under READ COMMITTED both read the same
            // pre-prune set, both delete the same victim (the loser's DELETE
            // matches zero rows, which Model::delete does not report) and
            // both insert — leaving more live credentials than the cap
            // promises. An app retrying a timed-out sign-in reproduces it
            // with no attacker involved.
            $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            $this->pruneTo(self::MAX_TOKENS_PER_AUDIENCE - 1, $user, $audience);

            $token = $user->createToken(
                sprintf('%s: %s', $audience->value, $deviceName),
                [$audience->ability()],
                $expiresAt,
            );

            return new IssuedMobileToken(
                $token->plainTextToken,
                $expiresAt,
                $audience,
                $deviceName,
            );
        });
    }

    /**
     * The devices currently signed in — what the customer web app and the
     * app itself both render so a lost phone can be cut off.
     *
     * Expired rows are excluded: they authenticate nothing, and listing them
     * as "signed in" would invite someone to revoke a dead row and believe
     * they had secured something.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function devices(Customer|MerchantUser $user, MobileAudience $audience): Collection
    {
        return $this->liveTokensFor($user, $audience)
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Revoke ONE device by its token id.
     *
     * Scoped through the user's own relation, so an id belonging to somebody
     * else matches nothing and answers false — the authorisation IS the
     * query, not a check that a later edit could forget to make.
     */
    public function revokeDevice(Customer|MerchantUser $user, MobileAudience $audience, int $tokenId): bool
    {
        return $this->tokensFor($user, $audience)->whereKey($tokenId)->delete() > 0;
    }

    /**
     * Revoke the token the current request authenticated with — sign out
     * THIS device, leaving the user's other devices alone.
     */
    public function revokeCurrent(Customer|MerchantUser $user): void
    {
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }
    }

    /**
     * Revoke every token this user holds for one audience — "sign out
     * everywhere", and the remedy for a lost or stolen phone.
     *
     * Deliberately NOT limited to live rows: an expired row is worthless but
     * still a row, and "sign out everywhere" should leave nothing behind.
     */
    public function revokeAll(Customer|MerchantUser $user, MobileAudience $audience): int
    {
        return (int) $this->tokensFor($user, $audience)->delete();
    }

    /**
     * Revoke every mobile token this user holds, whatever the audience.
     *
     * The hook for account-state changes: deactivating a staff member or
     * suspending a customer must not merely make their tokens *refuse* —
     * reactivating them later would otherwise bring a token on a device they
     * no longer hold straight back to life.
     */
    public function revokeEverything(Customer|MerchantUser $user): int
    {
        return (int) $user->tokens()->delete();
    }

    /**
     * Drop the least recently used tokens until at most $keep remain.
     */
    private function pruneTo(int $keep, Customer|MerchantUser $user, MobileAudience $audience): void
    {
        // Reap dead rows BEFORE anything competes for a slot. An expired
        // token authenticates nothing, so it must not hold a place against
        // the cap — and it must certainly not win the eviction contest.
        // It would: Sanctum refuses an expired token before stamping
        // last_used_at, so its timestamp freezes at its final valid use,
        // which is often MORE recent than a live-but-idle token's. Left in,
        // four dead rows would keep their slots while the account's only
        // working credential was deleted.
        $this->tokensFor($user, $audience)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', CarbonImmutable::now('UTC'))
            ->delete();

        $tokens = $this->tokensFor($user, $audience)
            // COALESCE, not NULLS FIRST. last_used_at is NULL from mint until
            // the token's first authenticated request, so NULLS FIRST made a
            // brand-new token the FIRST thing the next sign-in evicted —
            // sign in on till 6, sign in anywhere a minute later, till 6 is
            // dead. The inverse was worse: while any unused token existed, no
            // used token was evictable, so a dormant token on a retired
            // device was immune to the very control meant to clear it.
            // Ranking an unused token by its age fixes both directions.
            ->orderByRaw('COALESCE(last_used_at, created_at) ASC')
            ->orderBy('id')
            ->get();

        $surplus = $tokens->count() - $keep;

        if ($surplus <= 0) {
            return;
        }

        foreach ($tokens->take($surplus) as $token) {
            $token->delete();
        }
    }

    /**
     * This user's tokens for one audience, live or expired.
     *
     * Scoped by the ability, not by name: the name is display-only and a
     * device is free to call itself anything, while the ability is the thing
     * the security check reads.
     *
     * @return MorphMany<PersonalAccessToken, Customer|MerchantUser>
     */
    private function tokensFor(Customer|MerchantUser $user, MobileAudience $audience): MorphMany
    {
        return $user->tokens()
            ->whereJsonContains('abilities', $audience->ability());
    }

    /**
     * The subset that can still authenticate anything.
     *
     * @return MorphMany<PersonalAccessToken, Customer|MerchantUser>
     */
    private function liveTokensFor(Customer|MerchantUser $user, MobileAudience $audience): MorphMany
    {
        return $this->tokensFor($user, $audience)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', CarbonImmutable::now('UTC'));
            });
    }

    /**
     * "Ahmed's iPhone" survives; control characters and 120 columns of paste
     * do not. Display-only, so this is hygiene rather than a security
     * boundary — but it lands in the user's own device list and in logs.
     */
    private static function cleanDeviceName(string $name): string
    {
        $name = trim(preg_replace('/[[:cntrl:]]+/u', ' ', $name) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            return 'Unnamed device';
        }

        return mb_substr($name, 0, 60);
    }
}
