'use client';

import { ReactNode } from 'react';
import { AdminGuard } from '@/components/auth/admin-guard';
import { AppShell } from '@/components/shell/app-shell';

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <AdminGuard>
      <AppShell>{children}</AppShell>
    </AdminGuard>
  );
}
