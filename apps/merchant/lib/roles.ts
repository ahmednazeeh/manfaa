import {
  isMerchantPermission,
  type MerchantPermission,
} from '@manfaa/api-client';
import type { TFunction } from 'i18next';

/**
 * Anyone whose authority can be asked about: the signed-in account from
 * `/me`, but stated structurally so a caller holding only the permission
 * set — a test, or a screen that carries the actor rather than the whole
 * session — needs no session to ask.
 */
export interface PermissionHolder {
  permissions: readonly string[];
}

/**
 * The panel-side half of the permission check (PLAN §13b): does the
 * signed-in account hold this permission?
 *
 * There is nothing to compare and no order to compare it in — authority is
 * a SET, and a store's own roles ("Shift lead", "Accounts") are neither
 * ranked against each other nor comparable by name. `me.permissions` is the
 * resolved set the server sends, with the owner's wildcard already expanded
 * (D3), so the owner needs no special case here.
 *
 * The argument is typed against the catalogue rather than `string` so a
 * mistyped slug is a build failure instead of a control that quietly never
 * renders. The wire is deliberately looser — `permissions` is `string[]`, so
 * a server that already enforces a permission this build predates still
 * parses.
 *
 * Cosmetics only: every gate has a server-side check behind it
 * (`merchant.can:…`, 403 `permission_required`). Hiding a control is a
 * courtesy to the reader, never the boundary.
 */
export function can(
  me: PermissionHolder,
  permission: MerchantPermission,
): boolean {
  return me.permissions.includes(permission);
}

/**
 * Every permission in the catalogue as the ACT it allows, in the reader's
 * language: what a refusal has to name if it is to be any use — "you need
 * permission to view settlements" tells a cashier who to ask, where
 * `settlements.view` tells them nothing and is not even English.
 *
 * The server publishes its own labels (GET /merchant/permissions) and the
 * roles screen renders those, but nothing else can: that endpoint needs
 * `roles.view`, which is precisely the permission a refused reader is least
 * likely to hold, and its wording is English only. So a gate translates
 * here instead. Keep the wording in step with Permission::label() on the
 * API — one act, two audiences.
 */
const MERCHANT_PERMISSION_KEYS: Record<MerchantPermission, string> = {
  'credits.create': 'roles.permissions.creditsCreate',
  'credits.custom_rate': 'roles.permissions.creditsCustomRate',
  'customers.lookup': 'roles.permissions.customersLookup',
  'transactions.view': 'roles.permissions.transactionsView',
  'transactions.amend': 'roles.permissions.transactionsAmend',
  'transactions.cancel': 'roles.permissions.transactionsCancel',
  'rate.view': 'roles.permissions.rateView',
  'rate.update': 'roles.permissions.rateUpdate',
  'promotions.view': 'roles.permissions.promotionsView',
  'promotions.create': 'roles.permissions.promotionsCreate',
  'promotions.publish': 'roles.permissions.promotionsPublish',
  'promotions.cancel': 'roles.permissions.promotionsCancel',
  'product_categories.view': 'roles.permissions.productCategoriesView',
  'product_categories.create': 'roles.permissions.productCategoriesCreate',
  'product_categories.edit': 'roles.permissions.productCategoriesEdit',
  'settlements.view': 'roles.permissions.settlementsView',
  'settlements.preview': 'roles.permissions.settlementsPreview',
  'settlements.create': 'roles.permissions.settlementsCreate',
  'settlements.receipt_add': 'roles.permissions.settlementsReceiptAdd',
  'wallet.view': 'roles.permissions.walletView',
  'wallet.settle': 'roles.permissions.walletSettle',
  'profile.view': 'roles.permissions.profileView',
  'profile.edit': 'roles.permissions.profileEdit',
  'branding.update': 'roles.permissions.brandingUpdate',
  'branches.view': 'roles.permissions.branchesView',
  'branches.create': 'roles.permissions.branchesCreate',
  'branches.edit': 'roles.permissions.branchesEdit',
  'branches.delete': 'roles.permissions.branchesDelete',
  'bank_account.view': 'roles.permissions.bankAccountView',
  'bank_account.update': 'roles.permissions.bankAccountUpdate',
  'preferences.update': 'roles.permissions.preferencesUpdate',
  'staff.view': 'roles.permissions.staffView',
  'staff.invite': 'roles.permissions.staffInvite',
  'staff.edit': 'roles.permissions.staffEdit',
  'roles.view': 'roles.permissions.rolesView',
  'roles.manage': 'roles.permissions.rolesManage',
  'api_credentials.view': 'roles.permissions.apiCredentialsView',
  'api_credentials.create': 'roles.permissions.apiCredentialsCreate',
  'api_credentials.revoke': 'roles.permissions.apiCredentialsRevoke',
  'setup.view': 'roles.permissions.setupView',
  'setup.edit': 'roles.permissions.setupEdit',
  'setup.submit': 'roles.permissions.setupSubmit',
};

/**
 * One permission in prose. Takes a plain string because slugs also arrive
 * off the wire — a 403 names the one it refused on, and a server ahead of
 * this build can name a permission it does not know — so an unrecognised
 * value degrades to neutral wording rather than printing itself.
 */
export function permissionLabel(t: TFunction, permission: string): string {
  return isMerchantPermission(permission)
    ? t(MERCHANT_PERMISSION_KEYS[permission])
    : t('roles.permissions.unknown');
}
