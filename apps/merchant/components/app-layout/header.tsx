'use client';

import { useEffect, useState } from 'react';
import { LogOut, Menu, Moon, Sun } from 'lucide-react';
import { toAbsoluteUrl } from '@/lib/helpers';
import { cn } from '@/lib/utils';
import { useIsMobile } from '@/hooks/use-mobile';
import { useScrollPosition } from '@/hooks/use-scroll-position';
import { useLogout } from '@/lib/queries';
import { LanguageSwitcher } from '@/components/app/language-switcher';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetHeader,
  SheetTrigger,
} from '@/components/ui/sheet';
import { useTheme } from 'next-themes';
import { usePathname, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useLayout } from './context';
import { SidebarMenu } from './sidebar-menu';

function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();

  return (
    <Button
      variant="ghost"
      mode="icon"
      shape="circle"
      aria-label="Toggle dark mode"
      className="size-9 hover:bg-primary/10 hover:[&_svg]:text-primary"
      onClick={() => setTheme(resolvedTheme === 'dark' ? 'light' : 'dark')}
    >
      <Sun className="size-4.5! dark:hidden" />
      <Moon className="size-4.5! hidden dark:block" />
    </Button>
  );
}

function UserMenu() {
  const { me } = useLayout();
  const router = useRouter();
  const logoutMutation = useLogout();

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
          aria-label="Account menu"
          className="size-9 rounded-full border border-border bg-muted text-sm font-semibold text-secondary-foreground inline-flex items-center justify-center shrink-0 cursor-pointer hover:bg-primary/10"
        >
          {initial}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="flex flex-col gap-0.5">
          <span className="text-sm font-medium text-mono">{me.name}</span>
          <span className="text-xs font-normal text-muted-foreground">
            {me.email}
          </span>
          <span className="text-xs font-normal text-muted-foreground">
            {me.merchant.name}
          </span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onClick={handleLogout}
          disabled={logoutMutation.isPending}
        >
          <LogOut />
          Log out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function Header() {
  const [isSidebarSheetOpen, setIsSidebarSheetOpen] = useState(false);
  const pathname = usePathname();
  const mobileMode = useIsMobile();
  const scrollPosition = useScrollPosition();
  const headerSticky: boolean = scrollPosition > 0;

  // Close the mobile menu when the route changes.
  useEffect(() => {
    setIsSidebarSheetOpen(false);
  }, [pathname]);

  return (
    <header
      className={cn(
        'header fixed top-0 z-10 start-0 flex items-stretch shrink-0 border-b border-transparent bg-background end-0 pe-[var(--removed-body-scroll-bar-size,0px)]',
        headerSticky && 'border-b border-border',
      )}
    >
      <div className="container-fluid flex justify-between items-stretch lg:gap-4">
        {/* Mobile logo + menu */}
        <div className="flex lg:hidden items-center gap-2.5">
          <Link href="/dashboard" className="shrink-0">
            <img
              src={toAbsoluteUrl('/media/app/mini-logo.svg?v=mf2')}
              className="h-[25px] w-full"
              alt="Manfaa"
            />
          </Link>
          {mobileMode && (
            <Sheet
              open={isSidebarSheetOpen}
              onOpenChange={setIsSidebarSheetOpen}
            >
              <SheetTrigger asChild>
                <Button variant="ghost" mode="icon" aria-label="Open menu">
                  <Menu className="text-muted-foreground/70" />
                </Button>
              </SheetTrigger>
              <SheetContent
                className="p-0 gap-0 w-[275px]"
                side="left"
                close={false}
              >
                <SheetHeader className="p-0 space-y-0" />
                <SheetBody className="p-0 overflow-y-auto">
                  <SidebarMenu className="lg:h-auto" />
                </SheetBody>
              </SheetContent>
            </Sheet>
          )}
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
