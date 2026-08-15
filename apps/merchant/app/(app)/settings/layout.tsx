'use client';

import { ReactNode } from 'react';
import { usePathname } from 'next/navigation';
import { minRoleForPath } from '@/components/app-layout/menu';
import { RoleGate } from '@/components/app/role-gate';

/**
 * Settings screens split across the tiers (PLAN §1): cashback rate, product
 * categories and branches are manager work; profile, bank account, staff
 * accounts and preferences stay with the owner. The required tier comes
 * from the menu, so the sidebar and this gate can never disagree.
 *
 * Client-side courtesy for anyone who deep-links past a hidden nav entry —
 * the API enforces the same split server-side (403 owner_required /
 * manager_required) on every route.
 */
export default function SettingsLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();

  return <RoleGate min={minRoleForPath(pathname)}>{children}</RoleGate>;
}
