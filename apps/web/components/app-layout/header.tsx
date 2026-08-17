'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { LogOut } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toAbsoluteUrl } from '@/lib/helpers';
import { useLogout } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { useScrollPosition } from '@/hooks/use-scroll-position';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  LanguageSwitcher,
  ThemeToggle,
} from '@/components/app/header-controls';
import { useLayout } from './context';

function UserMenu() {
  const { me } = useLayout();
  const router = useRouter();
  const logoutMutation = useLogout();
  const { t } = useTranslation();

  const handleLogout = () => {
    logoutMutation.mutate(undefined, {
      onSettled: () => {
        router.replace('/login');
      },
    });
  };

  const initial = me.name.trim().charAt(0).toUpperCase() || '?';

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label={t('common.accountMenu')}
          className="size-9 rounded-full border border-border bg-muted text-sm font-semibold text-secondary-foreground inline-flex items-center justify-center shrink-0 cursor-pointer hover:bg-primary/10 overflow-hidden"
        >
          {me.avatar_url ? (
            /* Plain img: capability URL on the API origin. */
            <img
              src={me.avatar_url}
              alt=""
              className="size-full object-cover"
            />
          ) : (
            initial
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="flex flex-col gap-0.5">
          <span className="text-sm font-medium text-mono">{me.name}</span>
          <span className="text-xs font-normal text-muted-foreground">
            {me.phone}
          </span>
          <span className="text-xs font-normal text-muted-foreground tabular-nums">
            {t('common.codeLine', { code: me.customer_code })}
          </span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onClick={handleLogout}
          disabled={logoutMutation.isPending}
        >
          <LogOut />
          {t('common.logOut')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function Header() {
  const scrollPosition = useScrollPosition();
  const headerSticky: boolean = scrollPosition > 0;
  const { t } = useTranslation();

  return (
    <header
      className={cn(
        'header fixed top-0 z-10 start-0 flex items-stretch shrink-0 border-b border-transparent bg-background end-0 pe-[var(--removed-body-scroll-bar-size,0px)]',
        headerSticky && 'border-b border-border',
      )}
    >
      <div className="container-fluid flex justify-between items-stretch lg:gap-4">
        {/* Mobile logo — navigation lives in the BottomNav on phones. */}
        <div className="flex lg:hidden items-center gap-2.5">
          <Link href="/dashboard" className="shrink-0">
            <img
              src={toAbsoluteUrl('/media/app/mini-logo.svg?v=mf2')}
              className="h-[25px] w-full"
              alt={t('common.appName')}
            />
          </Link>
        </div>

        <div className="flex items-center gap-3 ms-auto">
          <LanguageSwitcher />
          <ThemeToggle />
          <UserMenu />
        </div>
      </div>
    </header>
  );
}
