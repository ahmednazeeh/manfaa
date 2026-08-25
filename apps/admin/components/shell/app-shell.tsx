'use client';

import { ReactNode, useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  getAdminAttention,
  type DashboardAttentionQueue,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  BadgeCheck,
  Banknote,
  ChevronRight,
  ClipboardCheck,
  FileDiff,
  FileSpreadsheet,
  HandCoins,
  Landmark,
  LayoutDashboard,
  LogOut,
  Map as MapIcon,
  PiggyBank,
  Receipt,
  Scale,
  Settings2,
  ShieldAlert,
  Store,
  Users,
  Wallet,
} from 'lucide-react';
import { toast } from 'sonner';
import { adminLogout } from '@/lib/admin-auth';
import { apiErrorMessage } from '@/lib/api-error';
import { ADMIN_ATTENTION_QUERY_KEY } from '@/lib/dashboard';
import { adminRoleLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import { useMarketplaceEnabled } from '@/hooks/use-marketplace-enabled';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useAdminUser } from '@/components/auth/admin-guard';
import { ManfaaLogo } from '@/components/shell/manfaa-logo';
import {
  SETTINGS_GROUPS,
  type NavItem,
  type SettingsGroup,
} from '@/components/shell/settings-nav';
import { ThemeToggle } from '@/components/shell/theme-toggle';


/**
 * EVERY NAV BADGE, ONE POLL.
 *
 * The four badges below are four numbers out of the SAME six the dashboard's
 * attention panel shows, so they read the same endpoint on the same shared
 * query key rather than each fetching the LIST behind its queue to pull one
 * scalar off it. That mattered twice over. It was four HTTP round trips and
 * eight queries a minute for four integers — /holds alone ran a grouped
 * reason pass, a merchant join and a paginated lateral join so a badge could
 * read `summary.total`. And four independent timers meant a badge and the
 * dashboard tile it links to were read at different instants, which is the
 * one disagreement the attention counts exist to make impossible.
 *
 * The landing page seeds this key from the payload it already fetched
 * (see the dashboard page), so on that screen there is one poll, not five.
 *
 * Nothing renders at zero: a wall of permanent grey zeros in the nav trains
 * the reader to stop seeing the badges at all.
 */
function useAttentionCounts() {
  return useQuery({
    queryKey: ADMIN_ATTENTION_QUERY_KEY,
    queryFn: ({ signal }) => getAdminAttention({ signal }),
    refetchInterval: 60_000,
    staleTime: 30_000,
  });
}

function QueueBadge({ queue }: { queue: DashboardAttentionQueue }) {
  const query = useAttentionCounts();
  const count = query.data?.[queue] ?? 0;

  if (count === 0) {
    return null;
  }

  return (
    <Badge variant="warning" size="sm" shape="circle">
      {count}
    </Badge>
  );
}

/** Merchant wallet top-up claims the bank-history verifier could not settle. */
function PendingTopUpsBadge() {
  return <QueueBadge queue="wallet_top_ups_pending" />;
}

/**
 * Transactions sitting under fraud or dispute review. A hold is inert — the
 * customer's cashback stays Pending and the store's settlement clock does not
 * run — so the count belongs where an admin sees it without going looking.
 */
function OpenHoldsBadge() {
  return <QueueBadge queue="holds_open" />;
}

/** Self-signed-up stores waiting in the approval queue. */
function PendingStoreReviewsBadge() {
  return <QueueBadge queue="store_reviews_pending" />;
}

/**
 * LIVE stores waiting on a decision about what they want to change (MR9).
 * Sits beside the store-approval badge on purpose: the two queues are one
 * job, and a change nobody looks at is a store stuck serving a claim it has
 * already stopped standing behind.
 */
function PendingChangeRequestsBadge() {
  return <QueueBadge queue="change_requests_pending" />;
}

const NAV_ITEMS: NavItem[] = [
  // First, and the panel's landing page: it is the only screen that answers
  // "what needs me today" without knowing which queue to open first.
  { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { href: '/settlements', label: 'Settlements', icon: Landmark },
  {
    href: '/wallet-top-ups',
    label: 'Wallet top-ups',
    icon: PiggyBank,
    badge: PendingTopUpsBadge,
  },
  { href: '/merchants', label: 'Merchants', icon: Store },
  { href: '/customers', label: 'Customers', icon: Users },
  { href: '/zones', label: 'Zones', icon: MapIcon },
  {
    href: '/holds',
    label: 'Holds',
    icon: ShieldAlert,
    badge: OpenHoldsBadge,
  },
  {
    href: '/store-reviews',
    label: 'Store reviews',
    icon: ClipboardCheck,
    badge: PendingStoreReviewsBadge,
  },
  {
    href: '/change-requests',
    label: 'Change requests',
    icon: FileDiff,
    badge: PendingChangeRequestsBadge,
  },
  { href: '/payouts', label: 'Payout batches', icon: Banknote },
  { href: '/pending-payments', label: 'Pending payments', icon: Wallet },
  {
    href: '/merchant-settlements',
    label: 'Merchant settlements',
    icon: HandCoins,
    marketplaceOnly: true,
  },
  {
    href: '/marketplace/kyb',
    label: 'Marketplace KYB',
    icon: BadgeCheck,
    marketplaceOnly: true,
  },
  {
    href: '/marketplace/payments',
    label: 'Order payments',
    icon: Receipt,
    marketplaceOnly: true,
  },
  { href: '/reconciliation', label: 'Reconciliation', icon: Scale },
  {
    href: '/reports',
    label: 'Reports',
    icon: FileSpreadsheet,
    superadminOnly: true,
  },
];

function NavLink({ item, active }: { item: NavItem; active: boolean }) {
  const Icon = item.icon;
  const CountBadge = item.badge;
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
      <span className="min-w-0 flex-1">{item.label}</span>
      {CountBadge ? <CountBadge /> : null}
    </Link>
  );
}

/**
 * One settings group in the sidebar: a disclosure that opens itself when
 * the page you are on lives inside it, so navigating never leaves you
 * hunting for where you are. Open state is local to the session — a
 * remembered drawer that disagrees with the current page is worse than
 * one that simply follows it.
 */
function SettingsGroupNav({
  group,
  items,
  isActive,
}: {
  group: SettingsGroup;
  items: NavItem[];
  isActive: (href: string) => boolean;
}) {
  const holdsActive = items.some((item) => isActive(item.href));
  const [open, setOpen] = useState(holdsActive);
  const Icon = group.icon;

  // Follow the page: land inside a closed group (a deep link, a redirect)
  // and it opens. Closing it by hand still sticks while you stay put.
  useEffect(() => {
    if (holdsActive) setOpen(true);
  }, [holdsActive]);

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <CollapsibleTrigger
        className={cn(
          'flex w-full shrink-0 items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
          holdsActive && !open
            ? 'bg-accent/50 text-foreground'
            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
        )}
      >
        <Icon className="size-4 shrink-0" />
        <span className="min-w-0 flex-1 text-start">{group.label}</span>
        <ChevronRight
          className={cn(
            'size-3.5 shrink-0 transition-transform rtl:-scale-x-100',
            open && 'rotate-90',
          )}
        />
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className="mt-1 flex flex-col gap-1 ps-3">
          {items.map((item) => (
            <NavLink key={item.href} item={item} active={isActive(item.href)} />
          ))}
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}

function NavLinks({ orientation }: { orientation: 'vertical' | 'horizontal' }) {
  const pathname = usePathname();
  const user = useAdminUser();

  // Undefined while the flag loads — treated as ON, so the nav does not
  // flicker items away on every page load.
  const marketplace = useMarketplaceEnabled() ?? true;

  const visible = (item: NavItem) =>
    (!item.superadminOnly || user.role === 'superadmin') &&
    (!item.marketplaceOnly || marketplace);

  const navItems = NAV_ITEMS.filter(visible);

  // A group with nothing this admin may see is not rendered at all.
  const settingsGroups = SETTINGS_GROUPS.map((group) => ({
    group,
    items: group.items.filter(visible),
  })).filter(({ items }) => items.length > 0);

  const isActive = (href: string) =>
    pathname === href || pathname.startsWith(`${href}/`);

  if (orientation === 'horizontal') {
    // The narrow nav is a single scrolling row, so twelve settings
    // entries buried the work screens. One door to the hub instead.
    return (
      <nav className="flex flex-row gap-1 overflow-x-auto pb-px">
        {navItems.map((item) => (
          <NavLink key={item.href} item={item} active={isActive(item.href)} />
        ))}
        {settingsGroups.length > 0 && (
          <NavLink
            item={{ href: '/settings', label: 'Settings', icon: Settings2 }}
            active={isActive('/settings')}
          />
        )}
      </nav>
    );
  }

  return (
    <nav className="flex flex-col gap-1">
      {navItems.map((item) => (
        <NavLink key={item.href} item={item} active={isActive(item.href)} />
      ))}
      {settingsGroups.length > 0 && (
        <Link
          href="/settings"
          className="mt-4 mb-1 px-3 text-xs font-semibold tracking-wide text-muted-foreground/70 uppercase transition-colors hover:text-foreground"
        >
          Settings
        </Link>
      )}
      {settingsGroups.map(({ group, items }) => (
        <SettingsGroupNav
          key={group.label}
          group={group}
          items={items}
          isActive={isActive}
        />
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
          <Link href="/dashboard" className="flex items-center gap-2">
            <ManfaaLogo />
            <span className="text-lg font-semibold text-muted-foreground">
              Admin
            </span>
          </Link>
        </div>
        <div className="flex-1 overflow-y-auto p-3">
          <NavLinks orientation="vertical" />
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 items-center justify-between gap-4 border-b border-border px-5">
          <Link
            href="/dashboard"
            className="flex items-center gap-2 lg:hidden"
          >
            <ManfaaLogo />
            <span className="text-lg font-semibold text-muted-foreground">
              Admin
            </span>
          </Link>
          <div className="hidden lg:block" />
          <div className="flex items-center gap-3">
            <ThemeToggle />
            <div className="flex items-center gap-2 text-sm">
              <span className="font-medium">{user.name}</span>
              <Badge variant="secondary" appearance="light" size="sm">
                {adminRoleLabel(user.role)}
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
