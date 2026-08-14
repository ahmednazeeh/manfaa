'use client';

import { ReactNode, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { isUnauthorized, useMe } from '@/lib/queries';
import { AppLayout } from '@/components/app-layout/app-layout';
import { ErrorBlock } from '@/components/app/async-states';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * Auth guard for every customer screen: resolves the session via
 * GET /api/customer/auth/me, bounces to /login on 401, and renders the
 * app chrome once the customer is known.
 */
export default function ProtectedLayout({ children }: { children: ReactNode }) {
  const router = useRouter();
  const { data: me, isPending, error } = useMe();
  const { t } = useTranslation();

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
          <ErrorBlock error={error} fallback={t('auth.sessionCheckFailed')} />
        </div>
      </div>
    );
  }

  return <AppLayout me={me}>{children}</AppLayout>;
}
