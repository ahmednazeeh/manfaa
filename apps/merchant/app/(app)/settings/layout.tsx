'use client';

import { ReactNode } from 'react';
import { usePathname } from 'next/navigation';
import { permissionForPath } from '@/components/app-layout/menu';
import { RoleGate } from '@/components/app/role-gate';

/**
 * Each settings screen stands on the permission its own read route is gated
 * with (PLAN §13b), taken from the menu so the sidebar and this gate can
 * never disagree. A settings path the menu does not know is left open — it
 * carries its own gate, and inventing a requirement here would shut a
 * screen nobody meant to shut.
 *
 * Client-side courtesy for anyone who deep-links past a hidden nav entry —
 * the API enforces the same split server-side (403 `permission_required`)
 * on every route.
 */
export default function SettingsLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const permission = permissionForPath(pathname);

  if (permission === null) {
    return <>{children}</>;
  }

  return <RoleGate permission={permission}>{children}</RoleGate>;
}
