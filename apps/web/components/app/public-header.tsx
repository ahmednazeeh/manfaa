'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useMe } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  LanguageSwitcher,
  ThemeToggle,
} from '@/components/app/header-controls';

/**
 * Shared chrome for every PUBLIC page (/, /discover, /store/[slug]):
 * wordmark home link, storefront nav, language + theme controls, and the
 * auth corner. No auth guard anywhere near this — a silent, non-blocking
 * GET /api/customer/auth/me probe (retry disabled) swaps Sign in / Create
 * account for a Dashboard button when a session cookie is already present;
 * a 401 simply leaves the signed-out buttons in place.
 */

function NavLink({
  href,
  active,
  children,
}: {
  href: string;
  active: boolean;
  children: string;
}) {
  return (
    <Link
      href={href}
      aria-current={active ? 'page' : undefined}
      className={cn(
        'text-sm font-medium transition-colors hover:text-foreground',
        active ? 'text-foreground' : 'text-muted-foreground',
      )}
    >
      {children}
    </Link>
  );
}

export function PublicHeader() {
  const { t } = useTranslation();
  const pathname = usePathname();
  const { data: me } = useMe();

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-background">
      <div className="container flex h-16 items-center justify-between gap-3">
        <div className="flex items-center gap-6">
          <Link
            href="/"
            className="text-lg font-semibold tracking-tight text-mono"
          >
            {t('common.appName')}
          </Link>
          <nav
            aria-label={t('nav.publicNav')}
            className="flex items-center gap-4"
          >
            {/* /discover is the single all-in-one directory now; store
                pages highlight it too — they're reached through it. */}
            <NavLink
              href="/discover"
              active={
                pathname === '/discover' || pathname.startsWith('/store/')
              }
            >
              {t('nav.discover')}
            </NavLink>
          </nav>
        </div>

        <div className="flex items-center gap-2">
          {/* Compact search shortcut on every page except the discovery
              page itself (its own search bar is right there). */}
          {pathname !== '/discover' && (
            <Button variant="ghost" mode="icon" asChild>
              <Link href="/discover" aria-label={t('nav.searchStores')}>
                <Search className="size-4.5" />
              </Link>
            </Button>
          )}
          <LanguageSwitcher />
          <ThemeToggle />
          {me ? (
            <Button asChild>
              <Link href="/dashboard">{t('landing.dashboard')}</Link>
            </Button>
          ) : (
            <>
              {/* The ghost Sign in hides on the narrowest screens so the
                  nav + primary CTA always fit; /signup links to sign-in. */}
              <Button variant="ghost" className="hidden sm:inline-flex" asChild>
                <Link href="/login">{t('auth.signIn')}</Link>
              </Button>
              <Button asChild>
                <Link href="/signup">{t('landing.createAccount')}</Link>
              </Button>
            </>
          )}
        </div>
      </div>
    </header>
  );
}

/** Footer shared by the public pages; the Marketplace entry is a deliberate
 *  inert teaser (no link target exists yet). */
export function PublicFooter() {
  const { t } = useTranslation();
  const currentYear = new Date().getFullYear();

  return (
    <footer className="border-t border-border">
      <div className="container flex flex-col items-center justify-between gap-3 py-6 text-sm text-muted-foreground md:flex-row">
        <span>{t('common.footerLine', { year: currentYear })}</span>
        <div className="flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
          <Link href="/discover" className="hover:text-foreground">
            {t('nav.stores')}
          </Link>
          {/* Inert on purpose — the marketplace has no page yet. */}
          <span>{t('landing.marketplaceFooter')}</span>
          <a
            href="https://merchant.manfaa.app"
            rel="noopener"
            className="hover:text-foreground"
          >
            {t('landing.forMerchants')}
          </a>
          {/* Integration docs get a real /docs link later. */}
          <span>{t('landing.forPartners')}</span>
        </div>
      </div>
    </footer>
  );
}
