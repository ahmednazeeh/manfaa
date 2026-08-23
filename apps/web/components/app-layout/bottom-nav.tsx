'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  PackageOpen,
  Store,
  Wallet,
  Banknote,
  Compass,
  Gift,
  House,
  Landmark,
  LayoutGrid,
  LogOut,
  Menu,
  ReceiptText,
  type LucideIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useLogout } from '@/lib/queries';
import { cn } from '@/lib/utils';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';

/**
 * Phone navigation for the authenticated app. The sidebar collapses into a
 * hamburger below lg, which nobody finds — so on small screens the four
 * destinations that matter sit in a fixed bottom bar, and everything else
 * the sidebar held (Payouts, Discover, log out) lives behind "More".
 *
 * Hidden at lg and up, where the real sidebar exists. The bar is a flex
 * row of logical-order items, so Dhivehi mirrors it for free; the active
 * item is the one place down here that spends the brand accent.
 */

interface BarItem {
  titleKey: string;
  path: string;
  icon: LucideIcon;
}

const BAR_ITEMS: BarItem[] = [
  { titleKey: 'nav.home', path: '/', icon: House },
  { titleKey: 'nav.dashboard', path: '/dashboard', icon: LayoutGrid },
  // Marketplace (PLAN-marketplace.md §10). The routes 404 while the platform
  // switch is off, and the pages show their empty state — the bar keeps its
  // slot rather than reflowing under the user mid-session.
  { titleKey: 'nav.market', path: '/market', icon: Store },
  { titleKey: 'nav.transactions', path: '/transactions', icon: ReceiptText },
];

/** The sidebar destinations that did not earn a bar slot. */
const MORE_ITEMS: BarItem[] = [
  { titleKey: 'nav.orders', path: '/orders', icon: PackageOpen },
  { titleKey: 'nav.wallet', path: '/wallet', icon: Wallet },
  { titleKey: 'nav.payoutAccountShort', path: '/payout-account', icon: Landmark },
  { titleKey: 'nav.payouts', path: '/payouts', icon: Banknote },
  { titleKey: 'nav.referrals', path: '/referrals', icon: Gift },
  { titleKey: 'nav.discover', path: '/discover', icon: Compass },
];

function isActive(pathname: string, path: string): boolean {
  return path === '/'
    ? pathname === '/'
    : pathname === path || pathname.startsWith(`${path}/`);
}

const BAR_ITEM_CLASS =
  'flex min-w-0 flex-1 flex-col items-center justify-center gap-1 rounded-md py-1.5 text-muted-foreground transition-colors hover:text-foreground';

function BarLabel({ children }: { children: string }) {
  return (
    <span className="max-w-full truncate text-2xs font-medium leading-none">
      {children}
    </span>
  );
}

export function BottomNav() {
  const pathname = usePathname();
  const router = useRouter();
  const logoutMutation = useLogout();
  const { t } = useTranslation();
  const [moreOpen, setMoreOpen] = useState(false);

  // Close the More sheet when navigation lands somewhere.
  useEffect(() => {
    setMoreOpen(false);
  }, [pathname]);

  const handleLogout = () => {
    logoutMutation.mutate(undefined, {
      onSettled: () => {
        router.replace('/login');
      },
    });
  };

  const moreActive = MORE_ITEMS.some((item) => isActive(pathname, item.path));

  return (
    <nav
      aria-label={t('nav.appNav')}
      className="fixed bottom-0 start-0 end-0 z-20 border-t border-border bg-background pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
      <div className="flex items-stretch gap-1 px-2 py-1">
        {BAR_ITEMS.map((item) => {
          const active = isActive(pathname, item.path);
          return (
            <Link
              key={item.path}
              href={item.path}
              aria-current={active ? 'page' : undefined}
              className={cn(
                BAR_ITEM_CLASS,
                active && 'text-brand hover:text-brand',
              )}
            >
              <item.icon className="size-5 shrink-0" />
              <BarLabel>{t(item.titleKey)}</BarLabel>
            </Link>
          );
        })}

        <Sheet open={moreOpen} onOpenChange={setMoreOpen}>
          <SheetTrigger asChild>
            <button
              type="button"
              className={cn(
                BAR_ITEM_CLASS,
                moreActive && 'text-brand hover:text-brand',
              )}
            >
              <Menu className="size-5 shrink-0" />
              <BarLabel>{t('nav.more')}</BarLabel>
            </button>
          </SheetTrigger>
          <SheetContent
            side="bottom"
            className="gap-0 rounded-t-xl p-0 pb-[env(safe-area-inset-bottom)]"
          >
            <SheetHeader className="border-b border-border px-5 py-4">
              <SheetTitle className="text-sm font-semibold">
                {t('nav.more')}
              </SheetTitle>
            </SheetHeader>
            <SheetBody className="flex flex-col p-2">
              {MORE_ITEMS.map((item) => {
                const active = isActive(pathname, item.path);
                return (
                  <Link
                    key={item.path}
                    href={item.path}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-muted',
                      active ? 'text-brand' : 'text-foreground',
                    )}
                  >
                    <item.icon className="size-4.5" />
                    {t(item.titleKey)}
                  </Link>
                );
              })}
              <div className="my-1 h-px bg-border" />
              <button
                type="button"
                onClick={handleLogout}
                disabled={logoutMutation.isPending}
                className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-start text-sm font-medium text-destructive transition-colors hover:bg-destructive/5 disabled:opacity-50"
              >
                <LogOut className="size-4.5" />
                {t('common.logOut')}
              </button>
            </SheetBody>
          </SheetContent>
        </Sheet>
      </div>
    </nav>
  );
}
