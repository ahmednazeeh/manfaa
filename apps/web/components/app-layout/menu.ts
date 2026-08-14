import {
  Compass,
  Landmark,
  LayoutGrid,
  LucideIcon,
  MessageSquareWarning,
  ReceiptText,
} from 'lucide-react';

export interface AppMenuItem {
  /** Translation key under `nav.` — resolved with t() at render time. */
  titleKey: string;
  path: string;
  icon: LucideIcon;
}

/** The customer app's whole navigation (§10 apps/web, Phase 3 scope). */
export const APP_MENU: AppMenuItem[] = [
  { titleKey: 'nav.dashboard', path: '/dashboard', icon: LayoutGrid },
  { titleKey: 'nav.transactions', path: '/transactions', icon: ReceiptText },
  { titleKey: 'nav.claims', path: '/claims', icon: MessageSquareWarning },
  { titleKey: 'nav.discover', path: '/discover', icon: Compass },
  { titleKey: 'nav.payoutAccount', path: '/payout-account', icon: Landmark },
];
