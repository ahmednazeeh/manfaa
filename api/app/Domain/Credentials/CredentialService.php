<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\PosVendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 */
class CredentialService
{
    public function issue(Merchant $merchant, PosVendor $vendor, array $abilities, AdminUser $issuedBy): IssuedCredential
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

        return DB::transaction(function () use ($merchant, $vendor, $abilities, $issuedBy) {
            $token = $merchant->createToken(
                sprintf('%s via %s', $merchant->slug, $vendor->name),
                $abilities,
            );

            $credential = ApiCredential::query()->create([
                'merchant_id' => $merchant->getKey(),
                'pos_vendor_id' => $vendor->getKey(),
                'personal_access_token_id' => $token->accessToken->getKey(),
                // Same SHA-256 digest Sanctum stores — never the plaintext.
                'token_hash' => $token->accessToken->token,
                'abilities' => $abilities,
                'issued_by' => $issuedBy->getKey(),
            ]);

            return new IssuedCredential($credential, $token->plainTextToken);
        });
    }

    /**
     * Deletes the Sanctum token (auth dies immediately) and stamps the
     * credential row instead of deleting it — the issuance and revocation
     * audit trail is append-only history.
     */
    public function revoke(ApiCredential $credential, AdminUser $revokedBy): ApiCredential
    {
        if ($credential->revoked_at !== null) {
            throw CredentialAlreadyRevokedException::for($credential);
        }

        return DB::transaction(function () use ($credential, $revokedBy) {
            if ($credential->personal_access_token_id !== null) {
                PersonalAccessToken::query()
                    ->whereKey($credential->personal_access_token_id)
                    ->delete();
            }

            $credential->forceFill([
                'revoked_at' => CarbonImmutable::now('UTC'),
                'revoked_by' => $revokedBy->getKey(),
            ])->save();

            return $credential;
        });
    }
}
