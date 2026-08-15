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
}

export interface AppMenuSection {
  /** Rendered as a group label when set. */
  label?: string;
  /**
   * Hidden from staff in the nav. Cosmetic only — the API enforces the
   * owner gate server-side (403 owner_required) on every settings route.
   */
  ownerOnly?: boolean;
  items: AppMenuItem[];
}

/**
 * The merchant panel's whole navigation (§10 apps/merchant). Credit
 * customer sits on top — it is the counter screen staff live in. The
 * Settings section is owner-only in the nav via me.role; staff keep the
 * credit screen and the read screens.
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
    ownerOnly: true,
    items: [
      { title: 'Cashback rate', path: '/settings/rate', icon: Percent },
      {
        title: 'Product categories',
        path: '/settings/product-categories',
        icon: Tags,
      },
      { title: 'Profile', path: '/settings/profile', icon: Store },
      { title: 'Bank account', path: '/settings/bank-account', icon: Banknote },
      { title: 'Branches', path: '/settings/branches', icon: MapPin },
      { title: 'Staff', path: '/settings/staff', icon: Users },
      {
        title: 'Preferences',
        path: '/settings/preferences',
        icon: SlidersHorizontal,
      },
    ],
  },
];
