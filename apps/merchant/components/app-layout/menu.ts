import { Landmark, LayoutGrid, ReceiptText, Wallet } from 'lucide-react';
import { LucideIcon } from 'lucide-react';

export interface AppMenuItem {
  title: string;
  path: string;
  icon: LucideIcon;
}

/** The merchant panel's whole navigation (§10 apps/merchant, Phase 1 scope). */
export const APP_MENU: AppMenuItem[] = [
  { title: 'Dashboard', path: '/dashboard', icon: LayoutGrid },
  { title: 'Transactions', path: '/transactions', icon: ReceiptText },
  { title: 'Settlements', path: '/settlements', icon: Landmark },
  { title: 'Wallet', path: '/wallet', icon: Wallet },
];
