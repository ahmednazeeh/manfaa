import type { MerchantStaffRole } from '@manfaa/api-client';
import { hasRoleAtLeast } from '@/lib/roles';
import {
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
  SlidersHorizontal,
  Store,
  Tags,
  Users,
  Wallet,
} from 'lucide-react';

export interface AppMenuItem {
  title: string;
  path: string;
  icon: LucideIcon;
  /**
   * Lowest tier this screen is shown to. Defaults to the section's
   * `minRole`, which itself defaults to `staff` (everyone).
   */
  minRole?: MerchantStaffRole;
}

export interface AppMenuSection {
  /** Rendered as a group label when set. */
  label?: string;
  /** Section-wide floor; individual items may raise it, never lower it. */
  minRole?: MerchantStaffRole;
  items: AppMenuItem[];
}

/**
 * The merchant panel's whole navigation (§10 apps/merchant) and — via
 * minRoleForPath — the single source of truth for which tier each screen
 * belongs to (PLAN §1):
 *
 *  - staff    the counter: credit entry, plus every read screen;
 *  - manager  + cashback rate, product categories, promotions writes,
 *             settlement builder, branches;
 *  - owner    + store profile, bank account, staff accounts, API access,
 *             preferences.
 *
 * Cosmetic only — the API enforces the same split server-side (403
 * `owner_required` / `manager_required`) on every route.
 */
/**
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
    label: 'Till',
    items: [
      { title: 'Credit customer', path: '/credit', icon: HandCoins },
      { title: 'Transactions', path: '/transactions', icon: ReceiptText },
    ],
  },
  {
    label: 'Money',
    items: [
      { title: 'Dashboard', path: '/dashboard', icon: LayoutGrid },
      { title: 'Settlements', path: '/settlements', icon: Landmark },
      { title: 'Wallet', path: '/wallet', icon: Wallet },
    ],
  },
  {
    label: 'Marketing',
    items: [{ title: 'Promotions', path: '/promotions', icon: Megaphone }],
  },
  {
    label: 'Store',
    // Hidden from staff: how the shop prices and where it trades is a
    // manager decision, not a counter one.
    minRole: 'manager',
    items: [
      { title: 'Cashback rate', path: '/settings/rate', icon: Percent },
      {
        title: 'Product categories',
        path: '/settings/product-categories',
        icon: Tags,
      },
      { title: 'Branches', path: '/settings/branches', icon: MapPin },
      { title: 'Store profile', path: '/settings/profile', icon: Store, minRole: 'owner' },
    ],
  },
  {
    label: 'Account',
    minRole: 'owner',
    items: [
      { title: 'Bank account', path: '/settings/bank-account', icon: Banknote },
      { title: 'Staff', path: '/settings/staff', icon: Users },
      { title: 'API access', path: '/settings/api-access', icon: Plug },
      {
        title: 'Preferences',
        path: '/settings/preferences',
        icon: SlidersHorizontal,
      },
    ],
  },
];

/** The tier an individual menu entry needs, resolving section defaults. */
export function itemMinRole(
  section: AppMenuSection,
  item: AppMenuItem,
): MerchantStaffRole {
  return item.minRole ?? section.minRole ?? 'staff';
}

/**
 * The first Settings screen `role` may open — /settings itself has no
 * content, so it forwards here. Null when the tier owns none of them
 * (staff), which the caller renders as the manager notice.
 */
export function firstSettingsPathFor(role: MerchantStaffRole): string | null {
  const settings = APP_MENU.find((section) => section.label === 'Settings');
  if (!settings) return null;

  const item = settings.items.find((candidate) =>
    hasRoleAtLeast(role, itemMinRole(settings, candidate)),
  );

  return item?.path ?? null;
}

/**
 * The tier a pathname needs, matched against the menu (longest path wins,
 * so /settings/product-categories beats a hypothetical /settings). Unknown
 * paths are open — screens outside the menu carry their own gates.
 */
export function minRoleForPath(pathname: string): MerchantStaffRole {
  let match: { path: string; role: MerchantStaffRole } | null = null;

  for (const section of APP_MENU) {
    for (const item of section.items) {
      if (pathname !== item.path && !pathname.startsWith(`${item.path}/`)) {
        continue;
      }
      if (match === null || item.path.length > match.path.length) {
        match = { path: item.path, role: itemMinRole(section, item) };
      }
    }
  }

  return match?.role ?? 'staff';
}
