'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useLayout } from '@/components/app-layout/context';
import { firstSettingsPathFor } from '@/components/app-layout/menu';
import { RoleGateNotice } from '@/components/app/role-gate';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * /settings has no content of its own — it forwards to the first tab this
 * account may open. An account holding none of them gets the notice with no
 * permission named: the missing one is not a single permission but every
 * settings permission at once, and picking one to blame would send the
 * reader to ask for the wrong thing.
 */
export default function SettingsIndexPage() {
  const router = useRouter();
  const { me } = useLayout();
  const target = firstSettingsPathFor(me);

  useEffect(() => {
    if (target !== null) {
      router.replace(target);
    }
  }, [router, target]);

  if (target === null) {
    return <RoleGateNotice />;
  }

  return <ScreenLoader />;
}
