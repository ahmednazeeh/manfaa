'use client';

import { ReactNode } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Banknote,
  CreditCard,
  Landmark,
  LogOut,
  Percent,
  Scale,
  ShieldCheck,
  SlidersHorizontal,
  Store,
  type LucideIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import { adminLogout } from '@/lib/admin-auth';
import { apiErrorMessage } from '@/lib/api-error';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAdminUser } from '@/components/auth/admin-guard';
import { ThemeToggle } from '@/components/shell/theme-toggle';

interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Hidden unless the signed-in admin's role is superadmin. */
  superadminOnly?: boolean;
}

const NAV_ITEMS: NavItem[] = [
  { href: '/settlements', label: 'Settlements', icon: Landmark },
  { href: '/merchants', label: 'Merchants', icon: Store },
  { href: '/payouts', label: 'Payout batches', icon: Banknote },
  { href: '/reconciliation', label: 'Reconciliation', icon: Scale },
];

const SETTINGS_ITEMS: NavItem[] = [
  { href: '/settings/platform', label: 'Platform', icon: SlidersHorizontal },
  { href: '/settings/fee-tiers', label: 'Fee tiers', icon: Percent },
  { href: '/settings/bank-accounts', label: 'Bank accounts', icon: CreditCard },
  {
    href: '/settings/admins',
    label: 'Admins',
    icon: ShieldCheck,
    superadminOnly: true,
  },
];

function NavLink({ item, active }: { item: NavItem; active: boolean }) {
  const Icon = item.icon;
  return (
    <Link
      href={item.href}
      className={cn(
        'flex shrink-0 items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
        active
          ? 'bg-accent text-accent-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      )}
    >
      <Icon className="size-4 shrink-0" />
      {item.label}
    </Link>
  );
}

function NavLinks({ orientation }: { orientation: 'vertical' | 'horizontal' }) {
  const pathname = usePathname();
  const user = useAdminUser();

  const settingsItems = SETTINGS_ITEMS.filter(
    (item) => !item.superadminOnly || user.role === 'superadmin',
  );

  const isActive = (href: string) =>
    pathname === href || pathname.startsWith(`${href}/`);

  if (orientation === 'horizontal') {
    return (
      <nav className="flex flex-row gap-1 overflow-x-auto pb-px">
        {[...NAV_ITEMS, ...settingsItems].map((item) => (
          <NavLink key={item.href} item={item} active={isActive(item.href)} />
        ))}
      </nav>
    );
  }

  return (
    <nav className="flex flex-col gap-1">
      {NAV_ITEMS.map((item) => (
        <NavLink key={item.href} item={item} active={isActive(item.href)} />
      ))}
      <div className="mt-4 mb-1 px-3 text-xs font-semibold tracking-wide text-muted-foreground/70 uppercase">
        Settings
      </div>
      {settingsItems.map((item) => (
        <NavLink key={item.href} item={item} active={isActive(item.href)} />
      ))}
    </nav>
  );
}

/**
 * Admin shell: fixed sidebar on large screens, horizontal nav on small ones,
 * signed-in admin with role, theme toggle and logout in the header. The
 * Settings group hides superadmin-only items for plain admins — display
 * only; the server's guard is what enforces access. Logical properties
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
            <ThemeToggle />
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
