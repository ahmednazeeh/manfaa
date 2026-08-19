import type { MerchantPermission } from '@manfaa/api-client';
import {
  BadgeCheck,
  ClipboardList,
  Package,
  Truck,
  Banknote,
  HandCoins,
  Landmark,
  LayoutGrid,
  LucideIcon,
  MapPin,
  Megaphone,
  Percent,
  Plug,
  ReceiptText,
  ShieldCheck,
  Store,
  Users,
  Wallet,
} from 'lucide-react';
import { can, type PermissionHolder } from '@/lib/roles';

export interface AppMenuItem {
  title: string;
  path: string;
  icon: LucideIcon;
  /**
   * The permission that opens this screen — the one its READ route is gated
   * with server-side, so an entry is shown exactly when the screen it leads
   * to will render rather than 403. Required on every entry: there is no
   * section-wide floor to inherit any more, because a set has no floor, and
   * a screen nobody deliberately granted is a screen that silently opened.
   */
  permission: MerchantPermission;

  /**
   * ANY-of gate for a screen that merges several formerly separate screens
   * (Cashback settings). When set, holding any listed permission shows the
   * entry; `permission` stays the primary one for consumers that need a
   * single answer. The screen gates its own SECTIONS per permission, so
   * permissionForPath() deliberately skips such items (see below).
   */
  anyOf?: MerchantPermission[];
}

export interface AppMenuSection {
  /** Rendered as a group label when set. */
  label?: string;
  /**
   * Shown only when the platform has a marketplace AND this store is an
   * approved vendor. A greyed-out menu for a product a merchant cannot have
   * is worse than no menu at all (PLAN-marketplace.md §10).
   */
  marketplaceOnly?: boolean;
  items: AppMenuItem[];
}

/**
 * The merchant panel's whole navigation (§10 apps/merchant) and — via
 * permissionForPath — the single source of truth for which permission each
 * screen belongs to, so the sidebar and the settings gate can never
 * disagree. Cosmetic only: the API gates the same routes with
 * `merchant.can:…` and answers 403 `permission_required` regardless of what
 * the panel chooses to render.
 *
 * Grouped by WHEN a merchant uses each screen, not by what the API happens
 * to call it. The old menu was one undifferentiated run of six followed by
 * eight settings, which made the till's daily screen sit at the same weight
 * as the once-a-quarter ones.
 *
 *  - Till        what a cashier touches during a shift
 *  - Money       what the owner reconciles: what is owed, and getting paid
 *  - Marketing   what brings customers in
 *  - Store       the shop's own setup — how it prices and where it trades
 *  - Account     the things you set once: who may sign in, where money
 *                lands, how the tills authenticate
 */
export const APP_MENU: AppMenuSection[] = [
  {
    // No label, and first: the dashboard is not one screen among a group,
    // it is the answer to "how is the shop doing" that everything else
    // qualifies. Filing it under Money made it a peer of the wallet.
    items: [
      // It IS the outstanding read model — the ageing buckets, the total
      // payable — which is why it stands on the settlements permission
      // rather than one of its own.
      {
        title: 'Dashboard',
        path: '/dashboard',
        icon: LayoutGrid,
        permission: 'settlements.view',
      },
    ],
  },
  {
    label: 'Till',
    items: [
      {
        title: 'Credit customer',
        path: '/credit',
        icon: HandCoins,
        permission: 'credits.create',
      },
      {
        title: 'Settlements',
        path: '/settlements',
        icon: Landmark,
        permission: 'settlements.view',
      },
    ],
  },
  {
    label: 'Money',
    items: [
      {
        title: 'Transactions',
        path: '/transactions',
        icon: ReceiptText,
        permission: 'transactions.view',
      },
      {
        title: 'Wallet',
        path: '/wallet',
        icon: Wallet,
        permission: 'wallet.view',
      },
    ],
  },
  {
    // MARKETPLACE (PLAN-marketplace.md). Hidden entirely when the platform
    // switch is off, or when this store has not been approved to sell — a
    // greyed-out menu for a product a merchant cannot have is worse than no
    // menu at all.
    label: 'Marketplace',
    marketplaceOnly: true,
    items: [
      {
        title: 'Orders',
        path: '/marketplace/orders',
        icon: ClipboardList,
        permission: 'marketplace.manage',
      },
      {
        title: 'Products',
        path: '/marketplace/products',
        icon: Package,
        permission: 'marketplace.manage',
      },
      {
        title: 'Delivery',
        path: '/marketplace/delivery',
        icon: Truck,
        permission: 'marketplace.manage',
      },
      {
        title: 'Marketplace setup',
        path: '/marketplace/setup',
        icon: BadgeCheck,
        permission: 'marketplace.manage',
      },
    ],
  },
  {
    label: 'Marketing',
    items: [
      {
        title: 'Promotions',
        path: '/promotions',
        icon: Megaphone,
        permission: 'promotions.view',
      },
    ],
  },
  {
    label: 'Store',
    items: [
      {
        // Rate + product categories + preferences merged (owner,
        // 2026-08-17): one screen for everything that decides what a sale
        // earns.
        title: 'Cashback settings',
        path: '/settings/cashback',
        icon: Percent,
        permission: 'rate.view',
        anyOf: ['rate.view', 'product_categories.view', 'preferences.update'],
      },
      {
        title: 'Branches',
        path: '/settings/branches',
        icon: MapPin,
        permission: 'branches.view',
      },
      {
        title: 'Store profile',
        path: '/settings/profile',
        icon: Store,
        permission: 'profile.view',
      },
    ],
  },
  {
    label: 'Account',
    items: [
      {
        title: 'Bank account',
        path: '/settings/bank-account',
        icon: Banknote,
        permission: 'bank_account.view',
      },
      {
        title: 'Staff',
        path: '/settings/staff',
        icon: Users,
        permission: 'staff.view',
      },
      {
        title: 'Roles',
        path: '/settings/roles',
        icon: ShieldCheck,
        permission: 'roles.view',
      },
      {
        title: 'API access',
        path: '/settings/api-access',
        icon: Plug,
        permission: 'api_credentials.view',
      },
    ],
  },
];

const SETTINGS_PREFIX = '/settings/';

/**
 * The first Settings screen this account may open — /settings itself has no
 * content, so it forwards here. Null when they may open none of them, which
 * the caller renders as the notice.
 *
 * Matched by PATH rather than by section: the settings screens are split
 * across Store and Account, grouped by when a shopkeeper reaches for them,
 * and no section owns all of them.
 */
export function menuItemAllowed(
  me: PermissionHolder,
  item: AppMenuItem,
): boolean {
  return item.anyOf
    ? item.anyOf.some((permission) => can(me, permission))
    : can(me, item.permission);
}

export function firstSettingsPathFor(me: PermissionHolder): string | null {
  for (const section of APP_MENU) {
    for (const item of section.items) {
      if (item.path.startsWith(SETTINGS_PREFIX) && menuItemAllowed(me, item)) {
        return item.path;
      }
    }
  }

  return null;
}

/**
 * The permission a pathname needs, matched against the menu (longest path
 * wins, so /settings/product-categories beats a hypothetical /settings).
 * Null for a path the menu does not know — screens outside the menu carry
 * their own gates, and inventing a requirement for them would lock out a
 * screen nobody meant to gate.
 */
export function permissionForPath(pathname: string): MerchantPermission | null {
  let match: { path: string; permission: MerchantPermission } | null = null;

  for (const section of APP_MENU) {
    for (const item of section.items) {
      if (pathname !== item.path && !pathname.startsWith(`${item.path}/`)) {
        continue;
      }
      // A merged screen gates its own sections — the layout must leave it
      // open rather than demand one permission of several.
      if (item.anyOf) {
        continue;
      }
      if (match === null || item.path.length > match.path.length) {
        match = { path: item.path, permission: item.permission };
      }
    }
  }

  return match?.permission ?? null;
}
