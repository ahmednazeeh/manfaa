import {
  Banknote,
  Compass,
  Gift,
  House,
  Landmark,
  LayoutGrid,
  LucideIcon,
  ReceiptText,
} from 'lucide-react';

export interface AppMenuItem {
  /** Translation key under `nav.` — resolved with t() at render time. */
  titleKey: string;
  path: string;
  icon: LucideIcon;
}

/**
 * The customer app's whole navigation (§10 apps/web, Phase 3 scope).
 * Home and Discover deliberately point at PUBLIC pages (/ and /discover) —
 * the storefront is the shop window; the panel is the wallet. The public
 * header's Dashboard button closes the loop back into the panel.
 */
export const APP_MENU: AppMenuItem[] = [
  { titleKey: 'nav.home', path: '/', icon: House },
  { titleKey: 'nav.dashboard', path: '/dashboard', icon: LayoutGrid },
  { titleKey: 'nav.transactions', path: '/transactions', icon: ReceiptText },
  // Payouts (money that LANDED) sits beside transactions (money EARNED) and
  // above the payout account (where it lands) — earn, receive, configure.
  { titleKey: 'nav.payouts', path: '/payouts', icon: Banknote },
  // Refer friends sits with the money pages — it is another way to earn.
  { titleKey: 'nav.referrals', path: '/referrals', icon: Gift },
  { titleKey: 'nav.discover', path: '/discover', icon: Compass },
  { titleKey: 'nav.payoutAccount', path: '/payout-account', icon: Landmark },
];
