import { ComponentType } from 'react';
import {
  BadgePercent,
  Coins,
  CreditCard,
  KeyRound,
  Megaphone,
  MessageSquare,
  Palette,
  Percent,
  Plug,
  Radio,
  ReceiptText,
  Settings2,
  ShieldCheck,
  ShoppingBag,
  SlidersHorizontal,
  Smartphone,
  Tags,
  type LucideIcon,
} from 'lucide-react';

export interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Hidden unless the signed-in admin's role is superadmin. */
  superadminOnly?: boolean;
  /**
   * Hidden while the marketplace is switched off. These are the screens
   * whose API routes carry EnsureMarketplaceEnabled, so with it off they
   * could only ever show a 403.
   */
  marketplaceOnly?: boolean;
  /** Optional live counter rendered after the label (e.g. queue size). */
  badge?: ComponentType;
}

/**
 * Settings, grouped by the question each one answers (owner, 2026-08-25:
 * twelve flat entries under a sidebar that already carried seventeen).
 *
 * The URLs are deliberately UNCHANGED — grouping is navigation, not
 * routing, and every bookmark and deep link an admin already holds keeps
 * working. A group whose every child is hidden from this admin (the
 * superadmin-only ones) is not rendered at all, so a plain admin never
 * opens an empty drawer.
 */
export interface SettingsGroup {
  label: string;
  icon: LucideIcon;
  items: NavItem[];
}

export const SETTINGS_GROUPS: SettingsGroup[] = [
  {
    label: 'Money',
    icon: Coins,
    items: [
      { href: '/settings/fee-tiers', label: 'Fee tiers', icon: Percent },
      {
        href: '/settings/fee-promotions',
        label: 'Fee promotions',
        icon: BadgePercent,
      },
      {
        href: '/settings/tax',
        label: 'GST',
        icon: ReceiptText,
        superadminOnly: true,
      },
      {
        href: '/settings/bank-accounts',
        label: 'Bank accounts',
        icon: CreditCard,
      },
      { href: '/settings/transfers', label: 'Transfer API', icon: Radio },
    ],
  },
  {
    label: 'Storefront',
    icon: ShoppingBag,
    items: [
      { href: '/settings/appearance', label: 'Appearance', icon: Palette },
      {
        href: '/settings/store-categories',
        label: 'Store categories',
        icon: Tags,
      },
      { href: '/settings/offers', label: 'Featured offers', icon: Megaphone },
    ],
  },
  {
    label: 'System',
    icon: Settings2,
    items: [
      { href: '/settings/platform', label: 'Platform', icon: SlidersHorizontal },
      {
        href: '/settings/notifications',
        label: 'Notifications',
        icon: MessageSquare,
      },
      {
        href: '/settings/app-releases',
        label: 'App releases',
        icon: Smartphone,
      },
    ],
  },
  {
    label: 'Access',
    icon: KeyRound,
    items: [
      {
        href: '/settings/admins',
        label: 'Admins',
        icon: ShieldCheck,
        superadminOnly: true,
      },
      {
        href: '/settings/platform-clients',
        label: 'Connected platforms',
        icon: Plug,
        superadminOnly: true,
      },
    ],
  },
];

