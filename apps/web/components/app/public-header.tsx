'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useMe } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { StoreSearch } from '@/components/app/store-search';
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
        // The underline is the active state; colour alone was too quiet to
        // read as navigation at all — "Discover" looked like a word beside
        // the wordmark. -bottom-[1.3rem] parks it on the header's own
        // border so the item appears to own that segment of it.
        'relative text-sm font-medium transition-colors hover:text-foreground',
        active
          ? 'text-foreground after:absolute after:inset-x-0 after:-bottom-[1.3rem] after:h-0.5 after:rounded-full after:bg-primary after:content-[""]'
          : 'text-muted-foreground',
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
            {/* The only route to the merchant side from the storefront.
                External host, so a plain anchor rather than a Link. */}
            <a
              href="https://merchant.manfaa.app/signup"
              rel="noopener"
              className="hidden text-sm font-medium text-muted-foreground transition-colors hover:text-foreground lg:inline"
            >
              {t('nav.becomeMerchant')}
            </a>
          </nav>
        </div>

        {/* Always a flex-1 slot, occupied or not, so the controls stay
            pinned to the inline end on every page and at every width. */}
        <div className="flex min-w-0 flex-1 justify-center">
          {showSearch && (
            <div className="hidden w-full justify-center md:flex">
              <StoreSearch className="max-w-lg" />
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
              <Link href="/dashboard">{t('nav.myCashback')}</Link>
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
