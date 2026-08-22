<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Credentials\VendorAbility;
use App\Models\PosVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The one public client every WooCommerce store connects through
 * (owner decision 2026-08-22).
 *
 * Idempotent: running it again updates the abilities and copy and prints
 * the same client id, so the id the plugin ships with never changes. The
 * client has no secret and no callback list — see ConnectService on
 * public clients. A superadmin can still pause it from the admin registry
 * (`connect_enabled`), which stops NEW connections; existing grants are the
 * merchants' to revoke.
 */
final class RegisterWooCommerceClientCommand extends Command
{
    public const string NAME = 'Manfaa for WooCommerce';

    protected $signature = 'manfaa:register-woocommerce-client';

    protected $description = 'Create or update the public OAuth client the WooCommerce plugin connects through, and print its client id';

    public function handle(): int
    {
        $abilities = [
            VendorAbility::TransactionsWrite,
            VendorAbility::TransactionsReverse,
            VendorAbility::RatesRead,
            VendorAbility::CustomersLookup,
            VendorAbility::WebhooksManage,
        ];

        $vendor = PosVendor::query()->firstOrNew(['name' => self::NAME]);

        $vendor->fill([
            'display_name' => self::NAME,
            'description' => 'Pays Manfaa cashback on orders placed in your WooCommerce store.',
            'website' => 'https://manfaa.app',
            'integration_status' => 'active',
            'client_id' => $vendor->client_id ?? 'mfa_'.Str::lower(Str::random(24)),
            'client_secret_hash' => null,
            'redirect_uris' => null,
            'allowed_abilities' => array_map(fn (VendorAbility $a): string => $a->value, $abilities),
            'connect_enabled' => true,
            'public_client' => true,
        ])->save();

        $this->info(sprintf('%s — client_id %s (%s)', self::NAME, $vendor->client_id, $vendor->wasRecentlyCreated ? 'created' : 'updated'));

        return self::SUCCESS;
    }
}
