'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useLayout } from '@/components/app-layout/context';
import { firstSettingsPathFor } from '@/components/app-layout/menu';
import { RoleGateNotice } from '@/components/app/role-gate';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * /settings has no content of its own — it forwards to the first tab the
 * signed-in tier owns: Profile for an owner, Cashback rate for a manager
 * (PLAN §1). Staff own none of them and get the notice instead.
 */
export default function SettingsIndexPage() {
  const router = useRouter();
  const { me } = useLayout();
  const target = firstSettingsPathFor(me.role);

  useEffect(() => {
    if (target !== null) {
      router.replace(target);
    }
  }, [router, target]);

  if (target === null) {
    return <RoleGateNotice required="manager" />;
  }

  return <ScreenLoader />;
}
