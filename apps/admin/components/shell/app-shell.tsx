'use client';

import { ReactNode } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Banknote, Landmark, LogOut, Scale, Store } from 'lucide-react';
import { toast } from 'sonner';
import { adminLogout } from '@/lib/admin-auth';
import { apiErrorMessage } from '@/lib/api-error';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAdminUser } from '@/components/auth/admin-guard';

const NAV_ITEMS = [
  { href: '/settlements', label: 'Settlements', icon: Landmark },
  { href: '/merchants', label: 'Merchants', icon: Store },
  { href: '/payouts', label: 'Payout batches', icon: Banknote },
  { href: '/reconciliation', label: 'Reconciliation', icon: Scale },
] as const;

function NavLinks({ orientation }: { orientation: 'vertical' | 'horizontal' }) {
  const pathname = usePathname();

  return (
    <nav
      className={cn(
        'flex gap-1',
        orientation === 'vertical'
          ? 'flex-col'
          : 'flex-row overflow-x-auto pb-px',
      )}
    >
      {NAV_ITEMS.map(({ href, label, icon: Icon }) => {
        const active = pathname === href || pathname.startsWith(`${href}/`);
        return (
          <Link
            key={href}
            href={href}
            className={cn(
              'flex shrink-0 items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              active
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
            )}
          >
            <Icon className="size-4 shrink-0" />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}

/**
 * Admin shell: fixed sidebar on large screens, horizontal nav on small ones,
 * signed-in admin with role and logout in the header. Logical properties
 * (border-e, ps-/pe-) keep the layout RTL-safe for the Dhivehi pass.
 */
export function AppShell({ children }: { children: ReactNode }) {
  const user = useAdminUser();
  const router = useRouter();
  const queryClient = useQueryClient();

  const logout = useMutation({
    mutationFn: () => adminLogout(),
    onSuccess: () => {
      queryClient.clear();
      router.replace('/login');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <div className="flex min-h-screen w-full">
      <aside className="hidden w-60 shrink-0 flex-col border-e border-border bg-muted/30 lg:flex">
        <div className="flex h-16 items-center border-b border-border px-5">
          <Link href="/settlements" className="text-lg font-semibold">
            Manfaa <span className="text-muted-foreground">Admin</span>
          </Link>
        </div>
        <div className="flex-1 overflow-y-auto p-3">
          <NavLinks orientation="vertical" />
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 items-center justify-between gap-4 border-b border-border px-5">
          <Link href="/settlements" className="text-lg font-semibold lg:hidden">
            Manfaa <span className="text-muted-foreground">Admin</span>
          </Link>
          <div className="hidden lg:block" />
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-2 text-sm">
              <span className="font-medium">{user.name}</span>
              <Badge variant="secondary" appearance="light" size="sm">
                {user.role}
              </Badge>
            </div>
            <Button
              variant="outline"
              size="sm"
              onClick={() => logout.mutate()}
              disabled={logout.isPending}
            >
              <LogOut />
              Log out
            </Button>
          </div>
        </header>

        <div className="border-b border-border px-5 py-2 lg:hidden">
          <NavLinks orientation="horizontal" />
        </div>

        <main className="min-w-0 flex-1 p-5 lg:p-7">{children}</main>
      </div>
    </div>
  );
}
