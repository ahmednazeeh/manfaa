'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { getDirection } from '@manfaa/ui';
import { Check, Languages, LogOut, Menu, Moon, Sun } from 'lucide-react';
import { useTheme } from 'next-themes';
import { useTranslation } from 'react-i18next';
import { toAbsoluteUrl } from '@/lib/helpers';
import { SUPPORTED_LANGUAGES, type AppLanguage } from '@/lib/i18n';
import { useLogout } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { useIsMobile } from '@/hooks/use-mobile';
import { useScrollPosition } from '@/hooks/use-scroll-position';
import { useLanguage } from '@/providers/i18n-provider';
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
import { useLayout } from './context';
import { SidebarMenu } from './sidebar-menu';

/**
 * Language names are autonyms — each rendered in its own script and never
 * translated — so they deliberately bypass t().
 */
const LANGUAGE_NAMES: Record<AppLanguage, string> = {
  en: 'English',
  dv: 'ދިވެހި',
};

function LanguageSwitcher() {
  const { language, setLanguage } = useLanguage();
  const { t } = useTranslation();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          mode="icon"
          shape="circle"
          aria-label={t('common.language')}
          className="size-9 hover:bg-primary/10 hover:[&_svg]:text-primary"
        >
          <Languages className="size-4.5!" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-40">
        {SUPPORTED_LANGUAGES.map((lang) => (
          <DropdownMenuItem
            key={lang}
            onClick={() => setLanguage(lang)}
            className={cn(
              'justify-between',
              lang === language && 'bg-muted font-medium',
            )}
          >
            <span
              lang={lang}
              dir={getDirection(lang)}
              className={lang === 'dv' ? 'font-thaana' : undefined}
            >
              {LANGUAGE_NAMES[lang]}
            </span>
            {lang === language && <Check className="size-3.5" />}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();
  const { t } = useTranslation();

  return (
    <Button
      variant="ghost"
      mode="icon"
      shape="circle"
      aria-label={t('common.toggleDarkMode')}
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
          className="size-9 rounded-full border border-border bg-muted text-sm font-semibold text-secondary-foreground inline-flex items-center justify-center shrink-0 cursor-pointer hover:bg-primary/10"
        >
          {initial}
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
  const [isSidebarSheetOpen, setIsSidebarSheetOpen] = useState(false);
  const pathname = usePathname();
  const mobileMode = useIsMobile();
  const scrollPosition = useScrollPosition();
  const headerSticky: boolean = scrollPosition > 0;
  const { t } = useTranslation();

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
              src={toAbsoluteUrl('/media/app/mini-logo.svg')}
              className="h-[25px] w-full"
              alt={t('common.appName')}
            />
          </Link>
          {mobileMode && (
            <Sheet
              open={isSidebarSheetOpen}
              onOpenChange={setIsSidebarSheetOpen}
            >
              <SheetTrigger asChild>
                <Button
                  variant="ghost"
                  mode="icon"
                  aria-label={t('common.openMenu')}
                >
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
