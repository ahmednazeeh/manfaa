'use client';

import { ReactNode, useEffect } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { isOnboardingStatus } from '@/lib/api';
import { isUnauthorized, useMe } from '@/lib/queries';
import { loginUrlReturningTo } from '@/lib/return-to';
import { AppLayout } from '@/components/app-layout/app-layout';
import { ErrorBlock } from '@/components/app/async-states';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * Auth guard for every merchant-panel screen: resolves the session via
 * GET /api/merchant/auth/me, bounces to /login on 401, and renders the
 * panel chrome once the merchant user is known.
 *
 * Onboarding gate (§1 decision 2026-08-15): while the merchant is draft,
 * pending_review or rejected the whole panel is off-limits — every screen
 * redirects to /setup (the wizard / waiting / rejection views), which
 * offers logout. The API enforces the same rule server-side; this keeps the
 * navigation honest.
 */
export default function ProtectedLayout({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { data: me, isPending, error } = useMe();

  const onboarding = me !== undefined && isOnboardingStatus(me.merchant.status);

  useEffect(() => {
    if (isUnauthorized(error)) {
      // Carry where they were headed, so a lapsed session does not silently
      // swallow a request the merchant was in the middle of answering.
      router.replace(loginUrlReturningTo(pathname));
    } else if (onboarding) {
      router.replace('/setup');
    }
  }, [error, onboarding, pathname, router]);

  if (isPending || isUnauthorized(error) || onboarding) {
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
