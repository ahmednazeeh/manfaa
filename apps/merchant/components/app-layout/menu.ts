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
 *  - owner    + store profile, bank account, staff accounts, preferences.
 *
 * Cosmetic only — the API enforces the same split server-side (403
 * `owner_required` / `manager_required`) on every route.
 */
export const APP_MENU: AppMenuSection[] = [
  {
    items: [
      { title: 'Credit customer', path: '/credit', icon: HandCoins },
      { title: 'Dashboard', path: '/dashboard', icon: LayoutGrid },
      { title: 'Transactions', path: '/transactions', icon: ReceiptText },
      { title: 'Settlements', path: '/settlements', icon: Landmark },
      { title: 'Wallet', path: '/wallet', icon: Wallet },
      { title: 'Promotions', path: '/promotions', icon: Megaphone },
    ],
  },
  {
    label: 'Settings',
    // The whole section is hidden from staff; managers see the three
    // operating screens, owners see all seven.
    minRole: 'manager',
    items: [
      { title: 'Cashback rate', path: '/settings/rate', icon: Percent },
      {
        title: 'Product categories',
        path: '/settings/product-categories',
        icon: Tags,
      },
      { title: 'Branches', path: '/settings/branches', icon: MapPin },
      { title: 'Profile', path: '/settings/profile', icon: Store, minRole: 'owner' },
      {
        title: 'Bank account',
        path: '/settings/bank-account',
        icon: Banknote,
        minRole: 'owner',
      },
      { title: 'Staff', path: '/settings/staff', icon: Users, minRole: 'owner' },
      {
        title: 'Preferences',
        path: '/settings/preferences',
        icon: SlidersHorizontal,
        minRole: 'owner',
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
