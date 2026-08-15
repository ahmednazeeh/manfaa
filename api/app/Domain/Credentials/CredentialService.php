<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Vendor credential lifecycle (§9.1): per-merchant Sanctum tokens with
 * abilities, one per merchant per POS vendor, independently revocable.
 *
 * The token belongs to the MERCHANT (the vendor acts *as* the merchant);
 * the vendor linkage, issuance audit and revocation stamp live on the
 * api_credentials row. Revoking one credential deletes exactly one
 * personal access token — a merchant switching POS vendor never forces
 * rotation across other merchants, or across their own other credentials.
 *
 * Two issuance paths, one mint:
 *
 *  - `issue()` — an ADMIN issuing against a curated `pos_vendors` row, the
 *    path our own integration team uses at onboarding;
 *  - `issueForMerchantUser()` — the merchant OWNER self-serving from the
 *    panel (§13b task #21), naming their integration partner as free text
 *    and bounded by MAX_ACTIVE_PER_MERCHANT live credentials.
 *
 * Both audit their actor into a column of its own, because "Manfaa issued
 * this write token" and "the shopkeeper issued this write token" are
 * different facts.
 */
class CredentialService
{
    /**
     * The most unrevoked credentials one merchant may hold. Self-serve
     * issuance is unattended, so the number of live tokens that can write
     * cashback for a store is bounded here rather than by our review.
     */
    public const int MAX_ACTIVE_PER_MERCHANT = 10;

    public function issue(Merchant $merchant, PosVendor $vendor, array $abilities, AdminUser $issuedBy): IssuedCredential
    {
        return $this->mint($merchant, $abilities, $vendor->name, [
            'pos_vendor_id' => $vendor->getKey(),
            'issued_by' => $issuedBy->getKey(),
        ]);
    }

    /**
     * Merchant self-serve issuance. `$label` is the integration partner as
     * the owner named it (free text — the merchant cannot write to the
     * curated vendor registry), and `$issuedBy` must belong to `$merchant`:
     * a merchant user can only ever mint a token against their own store.
     *
     * The cap is counted inside the transaction behind a row lock on the
     * merchant, so two simultaneous submissions cannot both see room for
     * the last credential.
     *
     * @param  list<string>  $abilities
     */
    public function issueForMerchantUser(Merchant $merchant, string $label, array $abilities, MerchantUser $issuedBy): IssuedCredential
    {
        if ((int) $issuedBy->merchant_id !== (int) $merchant->getKey()) {
            throw new InvalidArgumentException('A merchant user may only issue credentials for their own merchant.');
        }

        $label = trim($label);

        if ($label === '') {
            throw new InvalidArgumentException('A self-issued credential must name the integration partner.');
        }

        return DB::transaction(function () use ($merchant, $label, $abilities, $issuedBy) {
            Merchant::query()->whereKey($merchant->getKey())->lockForUpdate()->first();

            $active = ApiCredential::query()
                ->where('merchant_id', $merchant->getKey())
                ->whereNull('revoked_at')
                ->count();

            if ($active >= self::MAX_ACTIVE_PER_MERCHANT) {
                throw CredentialCapReachedException::atCap(self::MAX_ACTIVE_PER_MERCHANT);
            }

            return $this->mint($merchant, $abilities, $label, [
                'label' => $label,
                'issued_by_merchant_user' => $issuedBy->getKey(),
            ]);
        });
    }

    /**
     * Deletes the Sanctum token (auth dies immediately) and stamps the
     * credential row instead of deleting it — the issuance and revocation
     * audit trail is append-only history.
     *
     * A merchant user may only revoke their own store's credential; callers
     * scope the lookup so a foreign id is a 404 long before this guard, but
     * the domain refuses it regardless.
     */
    public function revoke(ApiCredential $credential, AdminUser|MerchantUser $revokedBy): ApiCredential
    {
        if ($credential->revoked_at !== null) {
            throw CredentialAlreadyRevokedException::for($credential);
        }

        if ($revokedBy instanceof MerchantUser && (int) $revokedBy->merchant_id !== (int) $credential->merchant_id) {
            throw new InvalidArgumentException('A merchant user may only revoke their own merchant\'s credentials.');
        }

        return DB::transaction(function () use ($credential, $revokedBy) {
            if ($credential->personal_access_token_id !== null) {
                PersonalAccessToken::query()
                    ->whereKey($credential->personal_access_token_id)
                    ->delete();
            }

            $credential->forceFill([
                'revoked_at' => CarbonImmutable::now('UTC'),
                $revokedBy instanceof AdminUser ? 'revoked_by' : 'revoked_by_merchant_user' => $revokedBy->getKey(),
            ])->save();

            return $credential;
        });
    }

    /**
     * The one place a vendor token is created. `$tokenName` is display-only
     * (Sanctum's `name` column); the abilities are the security boundary and
     * are validated against the closed VendorAbility set first — a token can
     * never carry an ability outside it, whoever asked for it.
     *
     * @param  list<string>  $abilities
     * @param  array<string, mixed>  $audit  columns identifying the issuer
     */
    private function mint(Merchant $merchant, array $abilities, string $tokenName, array $audit): IssuedCredential
    {
        $abilities = array_values(array_unique($abilities));

        if ($abilities === []) {
            throw InvalidAbilityException::empty();
        }

        foreach ($abilities as $ability) {
            if (! is_string($ability) || ! in_array($ability, VendorAbility::values(), true)) {
                throw InvalidAbilityException::unknown(is_string($ability) ? $ability : gettype($ability));
            }
        }

        return DB::transaction(function () use ($merchant, $abilities, $tokenName, $audit) {
            $token = $merchant->createToken(
                sprintf('%s via %s', $merchant->slug, $tokenName),
                $abilities,
            );

            $credential = ApiCredential::query()->create([
                'merchant_id' => $merchant->getKey(),
                'personal_access_token_id' => $token->accessToken->getKey(),
                // Same SHA-256 digest Sanctum stores — never the plaintext.
                'token_hash' => $token->accessToken->token,
                'abilities' => $abilities,
                ...$audit,
            ]);

            return new IssuedCredential($credential, $token->plainTextToken);
        });
    }
}
