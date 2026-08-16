<?php

declare(strict_types=1);

namespace App\Domain\MerchantAccess;

use App\Models\Merchant;
use App\Models\MerchantRole;

/**
 * The three roles every merchant starts with — Owner, Manager, Staff —
 * carrying EXACTLY the powers the old ranked `staff < manager < owner`
 * tiers carried, so replacing the tier with a permission set changes who
 * may do what by nothing at all.
 *
 * Two of the presets are ordinary editable roles; the Owner is not (PLAN
 * §13b D9). A merchant grows past these by creating its own.
 */
final class RolePresetService
{
    public const string OWNER = 'owner';

    public const string MANAGER = 'manager';

    public const string STAFF = 'staff';

    /**
     * Every surface that carried NO gate before permissions existed — the
     * whole counter screen and the read models behind it. Staff must hold
     * all of them on day one: each one is a NARROWING, and a till that
     * loses `credits.create` loses its only reason to be logged in.
     *
     * @return list<Permission>
     */
    public static function staffPermissions(): array
    {
        return [
            Permission::CreditsCreate,
            Permission::CustomersLookup,
            Permission::TransactionsView,
            Permission::RateView,
            Permission::PromotionsView,
            Permission::ProductCategoriesView,
            Permission::SettlementsView,
            Permission::SettlementsPreview,
            Permission::WalletView,
        ];
    }

    /**
     * Staff, plus everything the old `merchant.role:manager` gate opened:
     * the shop's pricing and operations. Never the bank account, staff
     * accounts, preferences or API credentials — those were owner-only and
     * stay so, and neither is `bank_account.view`, which is new and belongs
     * with the account it names.
     *
     * @return list<Permission>
     */
    public static function managerPermissions(): array
    {
        return [
            ...self::staffPermissions(),

            // The per-sale override: the same pricing authority the rate
            // screen needs, which is why it was welded to the manager tier
            // in CreditController rather than left to the till.
            Permission::CreditsCustomRate,

            Permission::TransactionsAmend,
            Permission::TransactionsCancel,

            Permission::RateUpdate,

            Permission::PromotionsCreate,
            Permission::PromotionsPublish,
            Permission::PromotionsCancel,

            Permission::ProductCategoriesCreate,
            Permission::ProductCategoriesEdit,

            Permission::SettlementsCreate,
            Permission::SettlementsReceiptAdd,
            Permission::WalletSettle,

            Permission::ProfileView,

            Permission::BranchesView,
            Permission::BranchesCreate,
            Permission::BranchesEdit,
            Permission::BranchesDelete,
        ];
    }

    /**
     * The rows to seed, in the order a roles screen should list them.
     *
     * The Owner's stored permission list is EMPTY on purpose (PLAN §13b
     * §2.3): `is_owner` answers every check, including checks for
     * permissions that do not exist yet, so an enumerated list would lock
     * every owner out of the next feature until someone edited their role
     * by hand.
     *
     * @return list<array{slug: string, name: string, name_dv: string, is_owner: bool, permissions: list<string>}>
     */
    public static function presets(): array
    {
        return [
            [
                'slug' => self::OWNER,
                'name' => 'Owner',
                'name_dv' => 'ވެރިފަރާތް',
                'is_owner' => true,
                'permissions' => [],
            ],
            [
                'slug' => self::MANAGER,
                'name' => 'Manager',
                'name_dv' => 'މެނޭޖަރު',
                'is_owner' => false,
                'permissions' => array_map(
                    fn (Permission $permission) => $permission->value,
                    self::managerPermissions(),
                ),
            ],
            [
                'slug' => self::STAFF,
                'name' => 'Staff',
                'name_dv' => 'މުވައްޒަފު',
                'is_owner' => false,
                'permissions' => array_map(
                    fn (Permission $permission) => $permission->value,
                    self::staffPermissions(),
                ),
            ],
        ];
    }

    /**
     * Gives the merchant its preset roles, keyed by slug. Idempotent: a
     * merchant that already has them keeps them, edits included — a store
     * that widened its own Manager must not have that undone by the next
     * call.
     *
     * @return array<string, MerchantRole>
     */
    public function provision(Merchant|int $merchant): array
    {
        $merchantId = $merchant instanceof Merchant ? (int) $merchant->getKey() : $merchant;

        $roles = [];

        foreach (self::presets() as $preset) {
            $roles[$preset['slug']] = MerchantRole::query()->firstOrCreate(
                ['merchant_id' => $merchantId, 'slug' => $preset['slug']],
                [
                    'name' => $preset['name'],
                    'name_dv' => $preset['name_dv'],
                    'permissions' => $preset['permissions'],
                    'is_owner' => $preset['is_owner'],
                    'is_system' => true,
                ],
            );
        }

        return $roles;
    }

    /** One preset role, provisioning the whole set if the merchant has none. */
    public function ensure(Merchant|int $merchant, string $slug): MerchantRole
    {
        return $this->provision($merchant)[$slug];
    }
}
