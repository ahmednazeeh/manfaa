'use client';

import { ReactNode, useEffect } from 'react';
import { isUnauthorized, useMe } from '@/lib/queries';
import { AppLayout } from '@/components/app-layout/app-layout';
import { ErrorBlock } from '@/components/app/async-states';
import { ScreenLoader } from '@/components/screen-loader';
import { useRouter } from 'next/navigation';

/**
 * Auth guard for every merchant-panel screen: resolves the session via
 * GET /api/merchant/auth/me, bounces to /login on 401, and renders the
 * panel chrome once the merchant user is known.
 */
export default function ProtectedLayout({ children }: { children: ReactNode }) {
  const router = useRouter();
  const { data: me, isPending, error } = useMe();

  useEffect(() => {
    if (isUnauthorized(error)) {
      router.replace('/login');
    }
  }, [error, router]);

  if (isPending || isUnauthorized(error)) {
    return <ScreenLoader />;
  }

  if (error || !me) {
    return (
      <div className="grow flex items-center justify-center min-h-screen">
        <div className="w-full max-w-md">
          <ErrorBlock
            error={error}
            fallback="Could not reach the server. Refresh to try again."
          />
        </div>
      </div>
    );
  }

  return <AppLayout me={me}>{children}</AppLayout>;
}
