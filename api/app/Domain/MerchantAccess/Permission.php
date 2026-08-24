<?php

declare(strict_types=1);

namespace App\Domain\MerchantAccess;

/**
 * The closed set of things a merchant panel account can be allowed to do
 * (PLAN §13b staff permissions). A permission nothing checks is not a
 * permission, so the CATALOGUE is code and only the ASSIGNMENT is data: a
 * merchant_roles row stores which of these slugs a role holds, and adding a
 * permission is a deploy rather than a data migration.
 *
 * Slugs are `group.action` with a DOT. Vendor token abilities
 * (App\Domain\Credentials\VendorAbility) keep `group:action` with a COLON,
 * and the separation is deliberate: the two are different axes — a
 * session-authenticated MerchantUser carries a Sanctum TransientToken whose
 * ability check answers true for everything — and a slug that reads wrong
 * at a glance is the cheapest way to catch one being passed to the other.
 */
enum Permission: string
{
    case CreditsCreate = 'credits.create';
    case CreditsCustomRate = 'credits.custom_rate';
    case CustomersLookup = 'customers.lookup';

    case TransactionsView = 'transactions.view';
    case TransactionsAmend = 'transactions.amend';
    case TransactionsCancel = 'transactions.cancel';

    case RateView = 'rate.view';
    case RateUpdate = 'rate.update';

    case PromotionsView = 'promotions.view';
    case PromotionsCreate = 'promotions.create';
    case PromotionsPublish = 'promotions.publish';
    case PromotionsCancel = 'promotions.cancel';

    case ProductCategoriesView = 'product_categories.view';
    case ProductCategoriesCreate = 'product_categories.create';
    case ProductCategoriesEdit = 'product_categories.edit';

    case SettlementsView = 'settlements.view';
    case SettlementsPreview = 'settlements.preview';
    case SettlementsCreate = 'settlements.create';
    case SettlementsReceiptAdd = 'settlements.receipt_add';

    case WalletView = 'wallet.view';
    case WalletSettle = 'wallet.settle';

    // Funding the wallet by bank transfer (owner, 2026-08-24). Its own slug
    // rather than riding on wallet.settle: claiming a real transfer and
    // uploading the slip is the same weight as submitting a settlement, and
    // a role that may spend the balance is not automatically one that may
    // assert money arrived.
    case WalletTopUp = 'wallet.top_up';

    case ProfileView = 'profile.view';
    case ProfileEdit = 'profile.edit';

    // Taking the whole store off the app, and putting it back. Separate from
    // profile.edit on purpose: editing a phone number and going dark to
    // every customer in the country are not the same authority, and the
    // second one belongs with whoever answers for the shop.
    case StorePublication = 'store.publication';

    // Working the marketplace: the order queue and the shelf. COUNTER work,
    // so an ordinary cashier holds it — they are the people who pick and
    // hand over an order.
    case MarketplaceManage = 'marketplace.manage';

    // Committing the BUSINESS to the marketplace: applying, uploading
    // identity papers, submitting for review. Deliberately a different
    // permission from the one above (security audit 2026-08-19). Granting
    // staff the order queue had handed every cashier the authority to enrol
    // the company and send its registration documents to us — the same
    // permission was doing two jobs of wildly different weight.
    case MarketplaceEnrol = 'marketplace.enrol';
    case BrandingUpdate = 'branding.update';

    case BranchesView = 'branches.view';
    case BranchesCreate = 'branches.create';
    case BranchesEdit = 'branches.edit';
    case BranchesDelete = 'branches.delete';

    case BankAccountView = 'bank_account.view';
    case BankAccountUpdate = 'bank_account.update';

    case PreferencesUpdate = 'preferences.update';

    case StaffView = 'staff.view';
    case StaffInvite = 'staff.invite';
    case StaffEdit = 'staff.edit';

    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    case ApiCredentialsView = 'api_credentials.view';
    case ApiCredentialsCreate = 'api_credentials.create';
    case ApiCredentialsRevoke = 'api_credentials.revoke';

    case SetupView = 'setup.view';
    case SetupEdit = 'setup.edit';
    case SetupSubmit = 'setup.submit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }

    /**
     * What the checkbox on the roles screen says. Written as the ACT, not
     * the screen — a role is a list of things a person may do, and "View
     * settlements" tells a shopkeeper what they are handing over in a way
     * that `settlements.view` never will.
     */
    public function label(): string
    {
        return match ($this) {
            self::CreditsCreate => 'Credit a customer',
            self::CreditsCustomRate => 'Set a custom cashback rate on a sale',
            self::CustomersLookup => 'Look up a customer',

            self::TransactionsView => 'View transactions',
            self::TransactionsAmend => 'Correct a transaction',
            self::TransactionsCancel => 'Cancel a transaction',

            self::RateView => 'View the cashback rate',
            self::RateUpdate => 'Change the cashback rate',

            self::PromotionsView => 'View promotions',
            self::PromotionsCreate => 'Create a promotion',
            self::PromotionsPublish => 'Publish a promotion',
            self::PromotionsCancel => 'Cancel a promotion',

            self::ProductCategoriesView => 'View product categories',
            self::ProductCategoriesCreate => 'Add a product category',
            self::ProductCategoriesEdit => 'Edit a product category',

            self::SettlementsView => 'View settlements',
            self::SettlementsPreview => 'Preview a settlement',
            self::SettlementsCreate => 'Submit a settlement',
            self::SettlementsReceiptAdd => 'Add a payment receipt to a settlement',

            self::WalletView => 'View the wallet',
            self::WalletSettle => 'Settle from the wallet',
            self::WalletTopUp => 'Top up the wallet',

            self::ProfileView => 'View the store profile',
            self::ProfileEdit => 'Edit the store profile',
            self::StorePublication => 'Pause and resume the store on the app',
            self::MarketplaceManage => 'Work marketplace orders and products',
            self::MarketplaceEnrol => 'Apply to sell on the marketplace',
            self::BrandingUpdate => 'Change the store logo',

            self::BranchesView => 'View branches',
            self::BranchesCreate => 'Add a branch',
            self::BranchesEdit => 'Edit a branch',
            self::BranchesDelete => 'Remove a branch',

            self::BankAccountView => 'View the bank account',
            self::BankAccountUpdate => 'Change the bank account',

            self::PreferencesUpdate => 'Change store preferences',

            self::StaffView => 'View staff accounts',
            self::StaffInvite => 'Invite a staff member',
            self::StaffEdit => 'Edit a staff account',

            self::RolesView => 'View roles',
            self::RolesManage => 'Create and edit roles',

            self::ApiCredentialsView => 'View API credentials',
            self::ApiCredentialsCreate => 'Issue an API credential',
            self::ApiCredentialsRevoke => 'Revoke an API credential',

            self::SetupView => 'View the setup wizard',
            self::SetupEdit => 'Fill in the setup wizard',
            self::SetupSubmit => 'Submit the store for review',
        };
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::CreditsCreate,
            self::CreditsCustomRate,
            self::CustomersLookup,
            self::TransactionsView,
            self::TransactionsAmend,
            self::TransactionsCancel => PermissionGroup::Till,

            self::SettlementsView,
            self::SettlementsPreview,
            self::SettlementsCreate,
            self::SettlementsReceiptAdd,
            self::WalletView,
            self::WalletSettle,
            self::WalletTopUp => PermissionGroup::Money,

            self::PromotionsView,
            self::PromotionsCreate,
            self::PromotionsPublish,
            self::PromotionsCancel => PermissionGroup::Marketing,

            self::RateView,
            self::RateUpdate,
            self::ProductCategoriesView,
            self::ProductCategoriesCreate,
            self::ProductCategoriesEdit,
            self::BranchesView,
            self::BranchesCreate,
            self::BranchesEdit,
            self::BranchesDelete,
            self::ProfileView,
            self::ProfileEdit,
            self::StorePublication,
            self::MarketplaceManage,
            self::MarketplaceEnrol,
            self::BrandingUpdate,
            self::SetupView,
            self::SetupEdit,
            self::SetupSubmit => PermissionGroup::Store,

            self::BankAccountView,
            self::BankAccountUpdate,
            self::PreferencesUpdate,
            self::StaffView,
            self::StaffInvite,
            self::StaffEdit,
            self::RolesView,
            self::RolesManage,
            self::ApiCredentialsView,
            self::ApiCredentialsCreate,
            self::ApiCredentialsRevoke => PermissionGroup::Account,
        };
    }
}
