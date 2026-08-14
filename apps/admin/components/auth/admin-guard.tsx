'use client';

import { createContext, ReactNode, useContext, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import {
  ADMIN_ME_QUERY_KEY,
  getAdminMe,
  type AdminUser,
} from '@/lib/admin-auth';
import { ScreenLoader } from '@/components/screen-loader';

const AdminUserContext = createContext<AdminUser | null>(null);

/** The signed-in admin. Only usable under <AdminGuard>. */
export function useAdminUser(): AdminUser {
  const user = useContext(AdminUserContext);
  if (user === null) {
    throw new Error('useAdminUser must be used within an AdminGuard');
  }
  return user;
}

/**
 * Client-side gate for the admin shell: resolves the session via
 * /api/admin/auth/me and bounces to /login when it is missing. The server
 * enforces the `admin` guard on every endpoint regardless — this only
 * decides what to render.
 */
export function AdminGuard({ children }: { children: ReactNode }) {
  const router = useRouter();

  const { data, error, isPending } = useQuery({
    queryKey: ADMIN_ME_QUERY_KEY,
    queryFn: ({ signal }) => getAdminMe({ signal }),
    retry: false,
    staleTime: 60_000,
  });

  useEffect(() => {
    if (error) {
      router.replace('/login');
    }
  }, [error, router]);

  if (isPending || error) {
    return <ScreenLoader />;
  }

  return (
    <AdminUserContext.Provider value={data.data}>
      {children}
    </AdminUserContext.Provider>
  );
}
