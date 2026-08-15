'use client';

import { useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useMe } from '@/lib/queries';
import { SEARCH_MAX_CHARS } from '@/lib/search';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  LanguageSwitcher,
  ThemeToggle,
} from '@/components/app/header-controls';

/**
 * Shared chrome for every PUBLIC page (/, /discover, /store/[slug]):
 * wordmark home link, the storefront search field, language + theme
 * controls, and the auth corner. No auth guard anywhere near this — a
 * silent, non-blocking GET /api/customer/auth/me probe (retry disabled)
 * swaps Sign in / Create account for a Dashboard button when a session
 * cookie is already present; a 401 simply leaves the signed-out buttons in
 * place.
 */

/**
 * The persistent storefront search. Wide and always present from `md` up —
 * on a cashback storefront, "which stores?" is the question every page is
 * answering, so the field belongs in the chrome rather than only in a hero.
 * Below `md` it collapses to the icon shortcut beside the controls.
 *
 * It only ever navigates: /discover owns the real debounced search, so
 * submitting here hands the query over and an empty submit still lands on
 * the full directory rather than doing nothing.
 */
function HeaderSearch() {
  const { t } = useTranslation();
  const router = useRouter();
  const [value, setValue] = useState('');

  return (
    <form
      role="search"
      className="relative w-full max-w-lg"
      onSubmit={(event) => {
        event.preventDefault();
        // Clamped to the API's q window — an over-long paste must land on
        // /discover as a valid search, never as a 422.
        const q = value.trim().slice(0, SEARCH_MAX_CHARS);
        router.push(
          q === '' ? '/discover' : `/discover?q=${encodeURIComponent(q)}`,
        );
      }}
    >
      <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        type="search"
        value={value}
        onChange={(event) => setValue(event.target.value)}
        maxLength={SEARCH_MAX_CHARS}
        placeholder={t('landing.heroSearchPlaceholder')}
        aria-label={t('nav.searchStores')}
        className="ps-9"
      />
    </form>
  );
}

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

  // /discover carries its own search field at the top of the page; two of
  // them on one screen is one too many.
  const showSearch = pathname !== '/discover';

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-background">
      <div className="container flex h-16 items-center gap-3">
        <div className="flex shrink-0 items-center gap-6">
          <Link
            href="/"
            className="text-lg font-semibold tracking-tight text-mono"
          >
            {t('common.appName')}
          </Link>
          <nav
            aria-label={t('nav.publicNav')}
            className="hidden items-center gap-4 sm:flex"
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

        {/* Always a flex-1 slot, occupied or not, so the controls stay
            pinned to the inline end on every page and at every width. */}
        <div className="flex min-w-0 flex-1 justify-center">
          {showSearch && (
            <div className="hidden w-full justify-center md:flex">
              <HeaderSearch />
            </div>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-2">
          {/* Compact search shortcut wherever the full field is not shown. */}
          {showSearch && (
            <Button variant="ghost" mode="icon" asChild className="md:hidden">
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
          {/* Static docs served by nginx, not a Next.js route — a plain
              anchor, so no client-side navigation is attempted. */}
          <a href="/docs/" className="hover:text-foreground">
            {t('landing.forPartners')}
          </a>
        </div>
      </div>
    </footer>
  );
}
