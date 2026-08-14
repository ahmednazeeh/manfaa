<?php

namespace Database\Seeders;

use App\Domain\Credentials\VendorAbility;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PosVendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * §9.5 sandbox fixtures — the published data set POS vendors integrate
 * against so nobody ever debugs against production or mints real cashback
 * doing it. Everything here is public knowledge (the integration guide
 * prints the customer codes and the bearer token), which is exactly why
 * run() refuses outright in production.
 *
 * Idempotent: every row is keyed on its published identity (vendor name,
 * merchant slug, customer code, token hash), so re-running never
 * duplicates. Re-running DOES re-canonicalise the fixture state — in
 * particular the scheduled rate decrease is re-anchored to the NEXT
 * business-day midnight, so the sandbox always exhibits the documented
 * 200 bp current rate with a 150 bp pending_decrease.
 *
 * The bearer secret is deterministic (sandbox only!): the Sanctum row
 * stores sha256(TOKEN_SECRET) and the full bearer token is
 * "<token id>|<TOKEN_SECRET>" — recomposed by plainTextToken(), so the
 * manfaa:sandbox command can print it on every run, not just the first.
 */
class SandboxSeeder extends Seeder
{
    /** The published sandbox bearer secret. NEVER valid in production — run() refuses there. */
    public const string TOKEN_SECRET = 'sandbox-pos-vendor-token-do-not-use-in-prod';

    public const string VENDOR_NAME = 'Sandbox POS';

    public const string MERCHANT_SLUG = 'sandbox-store';

    /**
     * The published test customers (§9.5): three known codes. 333333 is
     * deliberately suspended so vendors can exercise the lookup
     * `valid: false` branch against a real row.
     */
    public const array CUSTOMERS = [
        ['customer_code' => '111111', 'name' => 'Aisha Mohamed', 'phone' => '+9607111111', 'status' => 'active'],
        ['customer_code' => '222222', 'name' => 'Hassan Ibrahim', 'phone' => '+9607222222', 'status' => 'active'],
        ['customer_code' => '333333', 'name' => 'Mariyam Saeed', 'phone' => '+9607333333', 'status' => 'suspended'],
    ];

    /** Current standing rate: 2.00% → 0.75% fee tier (§4). */
    public const int RATE_BP = 200;

    /** The perpetually scheduled decrease vendors see as pending_decrease. */
    public const int PENDING_RATE_BP = 150;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Refusing to seed sandbox fixtures in production — the token and customer codes are public knowledge.'
            );
        }

        // The chart of accounts, so POST /v1/transactions can post its
        // accrual journal in a freshly created sandbox database.
        $this->call(LedgerAccountSeeder::class);

        $vendor = PosVendor::query()->firstOrCreate(
            ['name' => self::VENDOR_NAME],
            ['contact' => 'integrations@manfaa.mv', 'integration_status' => 'sandbox'],
        );

        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => self::MERCHANT_SLUG],
            [
                'name' => 'Sandbox Store',
                'status' => 'active',
                'settlement_method' => 'bank',
                'validation_window_days' => 3,
                'min_eligible_laari' => 5000,
                'eligibility_basis' => 'Invoice total excluding GST and service charge.',
            ],
        );

        $merchant->branches()->firstOrCreate(
            ['name' => 'Sandbox Branch'],
            ['address' => 'Boduthakurufaanu Magu, Malé'],
        );

        $this->seedRates($merchant);

        foreach (self::CUSTOMERS as $customer) {
            Customer::query()->updateOrCreate(
                ['customer_code' => $customer['customer_code']],
                [...$customer, 'password' => 'password', 'phone_verified_at' => CarbonImmutable::now('UTC')],
            );
        }

        $this->seedCredential($merchant, $vendor);
    }

    /**
     * The full bearer token for the sandbox credential, or null when the
     * seeder has not run against this database yet.
     */
    public static function plainTextToken(): ?string
    {
        $token = PersonalAccessToken::query()
            ->where('token', hash('sha256', self::TOKEN_SECRET))
            ->first();

        return $token === null ? null : $token->getKey().'|'.self::TOKEN_SECRET;
    }

    /**
     * Append-only history exactly as the merchant rate-change path writes
     * it: the 200 bp row closes at the next business-day midnight, where
     * the scheduled 150 bp decrease opens (§9.2 — decreases take effect
     * only at 00:00 UTC+5). Keyed on rate_bp, so a re-run moves the
     * boundary forward instead of stacking rows.
     */
    private function seedRates(Merchant $merchant): void
    {
        $boundary = CarbonImmutable::now((string) config('app.business_timezone', 'Indian/Maldives'))
            ->addDay()
            ->startOfDay()
            ->utc();

        $merchant->rates()->updateOrCreate(
            ['rate_bp' => self::RATE_BP],
            [
                'effective_from' => (new CarbonImmutable('2026-01-01T00:00:00+05:00'))->utc(),
                'effective_to' => $boundary,
            ],
        );

        $merchant->rates()->updateOrCreate(
            ['rate_bp' => self::PENDING_RATE_BP],
            ['effective_from' => $boundary, 'effective_to' => null],
        );
    }

    /**
     * One credential, all four §9.1 abilities, linked to the Sandbox POS
     * vendor — the same shape CredentialService::issue writes, minus the
     * admin audit trail (no admin issues sandbox fixtures) and with the
     * deterministic token hash instead of a random plaintext.
     */
    private function seedCredential(Merchant $merchant, PosVendor $vendor): void
    {
        $abilities = VendorAbility::values();
        $tokenHash = hash('sha256', self::TOKEN_SECRET);

        $token = $merchant->tokens()->firstOrCreate(
            ['token' => $tokenHash],
            ['name' => self::MERCHANT_SLUG.' via '.self::VENDOR_NAME, 'abilities' => $abilities],
        );

        ApiCredential::query()->updateOrCreate(
            ['merchant_id' => $merchant->getKey(), 'token_hash' => $tokenHash],
            [
                'pos_vendor_id' => $vendor->getKey(),
                'personal_access_token_id' => $token->getKey(),
                'abilities' => $abilities,
                'revoked_at' => null,
            ],
        );
    }
}
