'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import type { DiscoveryEntry, DiscoverySections } from '@manfaa/api-client';
import {
  Banknote,
  Clock,
  Globe,
  QrCode,
  Search,
  ShoppingBag,
  Sparkles,
  Store,
  TrendingUp,
  UserRoundPlus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate, formatRate } from '@/lib/format';
import { useDiscovery, useMe } from '@/lib/queries';
import { SEARCH_MAX_CHARS } from '@/lib/search';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  CategoryRail,
  CategoryRailSkeleton,
} from '@/components/app/category-rail';
import {
  hasListedStores,
  inStoreEntries,
  StoreShelf,
} from '@/components/app/discovery';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';
import { StoreAvatar } from '@/components/app/store-avatar';

/**
 * Public landing page (manfaa.app) — the reason to open the app before
 * deciding where to shop. No auth guard: discovery data comes from the
 * public GET /api/discover endpoint, and the shared PublicHeader quietly
 * upgrades to a "Dashboard" button when a customer session cookie is
 * already present.
 *
 * Shape, top to bottom: the category rail (the storefront's navigation),
 * the hero panel, the store shelves, then how-it-works and the marketplace
 * teaser. Everything between the header and how-it-works is data-driven and
 * disappears cleanly when there is no data — with ONE live store the page
 * must still read as finished, so a shelf never renders empty, the rail
 * never offers a filter that leads nowhere, and the hero never becomes a
 * carousel with one slide in it.
 *
 * Authed visitors (the same silent me-probe the header uses; react-query
 * dedupes the request) never see a "Create account" CTA anywhere on this
 * page: the hero and how-it-works CTAs become "Open dashboard", and the
 * marketplace teaser's get-notified CTA collapses to a you're-all-set line
 * (being notified just means having an account). While the probe is still
 * in flight — and always when it 401s — the signed-out page renders
 * unchanged.
 */

/** Cards per landing shelf. The shelves are teasers; "see all" carries the
 *  rest, and the full directory is always one tap away. */
const SHELF_LIMIT = 12;

/**
 * The landing page's primary CTA: Create account for visitors, Open
 * dashboard once the me-probe confirms a session.
 */
function PrimaryCta({ className }: { className?: string }) {
  const { t } = useTranslation();
  const { data: me } = useMe();

  return (
    <Button size="lg" asChild className={className}>
      {me ? (
        <Link href="/dashboard">{t('landing.openDashboard')}</Link>
      ) : (
        <Link href="/signup">{t('landing.createAccount')}</Link>
      )}
    </Button>
  );
}

/**
 * Hero search: submit/enter hands the query to /discover, where the real
 * (debounced, min-2-chars) search lives. An empty submit still lands on the
 * full directory — never a dead end.
 */
function HeroSearch() {
  const { t } = useTranslation();
  const router = useRouter();
  const [value, setValue] = useState('');

  return (
    <form
      role="search"
      className="relative w-full max-w-md"
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
        variant="lg"
        value={value}
        onChange={(event) => setValue(event.target.value)}
        maxLength={SEARCH_MAX_CHARS}
        placeholder={t('landing.heroSearchPlaceholder')}
        aria-label={t('nav.searchStores')}
        className="ps-9 pe-24"
      />
      <Button
        type="submit"
        size="sm"
        className="absolute end-1.5 top-1/2 -translate-y-1/2"
      >
        {t('common.search')}
      </Button>
    </form>
  );
}

/**
 * Flat geometric decoration for the hero panel: CSS shapes only — no
 * photography, no external images, nothing to load. All logical insets, so
 * the composition mirrors under RTL instead of tearing.
 */
function PanelShapes() {
  return (
    <div
      aria-hidden="true"
      className="pointer-events-none absolute inset-0 overflow-hidden"
    >
      <div className="absolute -top-28 -end-20 size-80 rounded-full bg-panel-foreground/10" />
      <div className="absolute -bottom-20 -start-16 size-56 rotate-12 rounded-[3rem] bg-panel-foreground/5" />
      <div className="absolute top-14 end-36 hidden size-24 rounded-full border-4 border-panel-accent/40 lg:block" />
      <div className="absolute bottom-10 end-16 hidden h-2 w-44 rounded-full bg-panel-accent/70 lg:block" />
    </div>
  );
}

/**
 * The promoted-store panel — the SECOND hero panel, and only when there is
 * genuinely something to promote (a store whose live promotion beats its
 * standing rate). With nothing boosted the hero is one calm panel; it is
 * never a carousel waiting for slides.
 */
function PromotedPanel({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();

  return (
    <Link
      href={`/store/${entry.slug}`}
      className="group relative flex flex-col justify-between gap-5 overflow-hidden rounded-2xl bg-panel-accent p-6 text-panel-to focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-16 -end-10 size-48 rounded-full bg-panel-to/10"
      />

      <div className="relative flex items-center gap-3">
        <StoreAvatar
          name={entry.name}
          slug={entry.slug}
          logoUrl={entry.logo_url}
          size="sm"
        />
        <div className="flex min-w-0 flex-col">
          <span className="inline-flex items-center gap-1.5 text-2xs font-semibold tracking-wide uppercase">
            <TrendingUp className="size-3" />
            {t('discover.boosted')}
          </span>
          <span className="truncate text-sm font-medium">{entry.name}</span>
        </div>
      </div>

      <div className="relative flex flex-col gap-1">
        <div className="flex flex-wrap items-baseline gap-x-2">
          <span className="text-4xl font-bold tracking-tight">
            {formatRate(entry.cashback_rate_percent)}
          </span>
          <span className="text-sm">{t('discover.cashbackLabel')}</span>
        </div>
        {/* Full-strength ink, not a faded one: the accent panel is bright,
            and a translucent tint on it drops under 4.5:1. The meta line is
            demoted by size and by the strike, never by contrast. */}
        <div className="flex flex-wrap items-center gap-x-3 text-xs">
          <span className="line-through">
            {t('discover.usuallyRate', {
              rate: formatRate(entry.standing_cashback_rate_percent),
            })}
          </span>
          {entry.promo_ends_at !== null && (
            <span>
              {t('discover.promoUntil', {
                date: formatDate(entry.promo_ends_at),
              })}
            </span>
          )}
        </div>
      </div>

      <span className="relative text-sm font-semibold group-hover:underline">
        {t('landing.viewStore')}
      </span>
    </Link>
  );
}

/** Headline, search and CTA on the Manfaa panel, plus the promoted store
 *  beside it when one exists. */
function Hero({ promoted }: { promoted: DiscoveryEntry | null }) {
  const { t } = useTranslation();

  return (
    <section className="container pb-10 lg:pb-14">
      <div
        className={cn(
          'grid gap-4',
          promoted !== null && 'lg:grid-cols-3',
        )}
      >
        <div
          className={cn(
            'relative isolate overflow-hidden rounded-2xl bg-linear-to-br from-panel-from via-panel-via to-panel-to px-6 py-12 text-panel-foreground sm:px-10 lg:py-16',
            promoted !== null && 'lg:col-span-2',
          )}
        >
          <PanelShapes />
          <div className="relative flex max-w-xl flex-col gap-5">
            <h1 className="text-3xl font-semibold tracking-tight text-balance sm:text-4xl lg:text-5xl">
              {t('landing.heroTitle')}
            </h1>
            <p className="text-sm/relaxed text-pretty text-panel-foreground/90 sm:text-base/relaxed">
              {t('landing.heroSubtitle')}
            </p>
            <HeroSearch />
            <div className="flex flex-wrap items-center gap-3">
              <PrimaryCta className="bg-panel-foreground text-panel-to hover:bg-panel-foreground/90" />
              <Button
                size="lg"
                variant="ghost"
                asChild
                className="border border-panel-foreground/40 text-panel-foreground hover:bg-panel-foreground/10 hover:text-panel-foreground"
              >
                <a href="#how-it-works">{t('landing.howTitle')}</a>
              </Button>
            </div>
          </div>
        </div>

        {promoted !== null && <PromotedPanel entry={promoted} />}
      </div>
    </section>
  );
}

/** Shown when the API has no merchants in any section yet. */
function AllEmptyBlock() {
  const { t } = useTranslation();
  const { data: me } = useMe();

  return (
    <section className="container pb-14">
      <Card className="mx-auto w-full max-w-xl">
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Store className="size-5" />
          </span>
          <div className="flex flex-col gap-1.5">
            <h2 className="text-lg font-semibold text-mono">
              {t('landing.emptyTitle')}
            </h2>
            <p className="text-sm text-muted-foreground">
              {t('landing.emptyBody')}
            </p>
          </div>
          <Button asChild>
            {me ? (
              <Link href="/dashboard">{t('landing.openDashboard')}</Link>
            ) : (
              <Link href="/signup">{t('landing.createAccount')}</Link>
            )}
          </Button>
        </CardContent>
      </Card>
    </section>
  );
}

/**
 * The store shelves.
 *
 * A shelf renders only if it puts at least one store on the page that no
 * shelf above it already has. That single rule is what keeps a sparse
 * platform looking deliberate: with one live store, "Featured" shows it
 * once and "Recently added", "In store" and "Online" stand down rather than
 * printing the same card four times under four headings. The facets those
 * shelves would have offered are not lost — they are exactly the entry
 * points in the category rail above, and each one still opens its own view
 * of the directory.
 */
function Shelves({ sections }: { sections: DiscoverySections }) {
  const { t } = useTranslation();

  const candidates: Array<{
    key: string;
    icon: LucideIcon;
    title: string;
    entries: DiscoveryEntry[];
    viewAllHref: string;
  }> = [
    {
      key: 'boosted',
      icon: TrendingUp,
      title: t('discover.increased'),
      entries: sections.increased,
      viewAllHref: '/discover?view=boosted',
    },
    {
      key: 'featured',
      icon: Sparkles,
      title: t('discover.featured'),
      entries: sections.featured,
      viewAllHref: '/discover?view=featured',
    },
    {
      key: 'recent',
      icon: Clock,
      title: t('discover.recentlyAdded'),
      entries: sections.recently_added,
      viewAllHref: '/discover?view=recent',
    },
    {
      key: 'in-store',
      icon: Store,
      title: t('discover.inStore'),
      entries: inStoreEntries(sections),
      viewAllHref: '/discover?view=in-store',
    },
    {
      key: 'online',
      icon: Globe,
      title: t('discover.online'),
      entries: sections.online,
      viewAllHref: '/discover?view=online',
    },
  ];

  const alreadyShown = new Set<string>();
  const shelves = [];

  for (const candidate of candidates) {
    const entries = candidate.entries.slice(0, SHELF_LIMIT);

    if (
      entries.length === 0 ||
      entries.every((entry) => alreadyShown.has(entry.slug))
    ) {
      continue;
    }

    for (const entry of entries) {
      alreadyShown.add(entry.slug);
    }

    shelves.push({ ...candidate, entries });
  }

  if (shelves.length === 0) {
    return null;
  }

  return (
    <div className="container flex flex-col gap-10 pb-14">
      {shelves.map((shelf) => (
        <StoreShelf
          key={shelf.key}
          icon={shelf.icon}
          title={shelf.title}
          entries={shelf.entries}
          viewAllHref={shelf.viewAllHref}
        />
      ))}
    </div>
  );
}

function Step({
  icon: Icon,
  step,
  title,
  body,
}: {
  icon: LucideIcon;
  step: number;
  title: string;
  body: string;
}) {
  return (
    <li className="flex flex-col items-center gap-3 text-center">
      <span className="relative flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
        <Icon className="size-5" />
        <span className="absolute -top-1 -end-1 flex size-5 items-center justify-center rounded-full bg-primary text-[0.6875rem] font-semibold text-primary-foreground">
          {step}
        </span>
      </span>
      <div className="flex flex-col gap-1">
        <h3 className="text-base font-semibold text-mono">{title}</h3>
        <p className="max-w-xs text-sm text-muted-foreground">{body}</p>
      </div>
    </li>
  );
}

function HowItWorks() {
  const { t } = useTranslation();

  return (
    <section
      id="how-it-works"
      className="scroll-mt-20 border-t border-border bg-muted/40"
    >
      <div className="container flex flex-col items-center gap-10 py-14 lg:py-20">
        <h2 className="text-2xl font-semibold tracking-tight text-mono">
          {t('landing.howTitle')}
        </h2>
        <ol className="grid w-full gap-10 sm:grid-cols-3 sm:gap-6">
          <Step
            icon={UserRoundPlus}
            step={1}
            title={t('landing.step1Title')}
            body={t('landing.step1Body')}
          />
          <Step
            icon={QrCode}
            step={2}
            title={t('landing.step2Title')}
            body={t('landing.step2Body')}
          />
          <Step
            icon={Banknote}
            step={3}
            title={t('landing.step3Title')}
            body={t('landing.step3Body')}
          />
        </ol>
        <PrimaryCta />
      </div>
    </section>
  );
}

/**
 * Multivendor-marketplace teaser (PLAN "Later": multi-vendor marketplace).
 * One restrained block, no email capture — "get notified" simply means
 * having an account, so the CTA is the same Create account flow for
 * visitors, and collapses to a "you're all set" line once signed in.
 */
function MarketplaceTeaser() {
  const { t } = useTranslation();
  const { data: me } = useMe();

  return (
    <section className="container py-14">
      <Card className="mx-auto w-full max-w-3xl">
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
            <ShoppingBag className="size-5" />
          </span>
          <div className="flex flex-col items-center gap-1.5">
            <div className="flex flex-wrap items-center justify-center gap-2">
              <h2 className="text-lg font-semibold text-mono">
                {t('landing.marketplaceTitle')}
              </h2>
              <Badge variant="secondary" appearance="light" size="sm">
                {t('landing.marketplaceComingSoon')}
              </Badge>
            </div>
            <p className="max-w-lg text-sm text-muted-foreground">
              {t('landing.marketplaceBody')}
            </p>
          </div>
          {me ? (
            <span className="text-xs text-muted-foreground">
              {t('landing.marketplaceSignedInHint')}
            </span>
          ) : (
            <div className="flex flex-col items-center gap-1.5">
              <Button variant="outline" asChild>
                <Link href="/signup">{t('landing.marketplaceCta')}</Link>
              </Button>
              <span className="text-xs text-muted-foreground">
                {t('landing.marketplaceCtaHint')}
              </span>
            </div>
          )}
        </CardContent>
      </Card>
    </section>
  );
}

export default function LandingPage() {
  // Coordinate-free on purpose: the landing has no "near you" shelf any
  // more — Nearby is a rail entry point, and geolocation is only ever asked
  // for on /discover, from the button the visitor presses there.
  const { data, isPending } = useDiscovery(null);

  // The single best live promotion becomes the hero's second panel; the
  // boosted shelf below still lists it with everything else that is live.
  const promoted = data?.increased[0] ?? null;

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />
      <main className="grow">
        {/* Nothing renders while loading and nothing renders on error — a
            public landing page carries itself on the hero and how-it-works
            instead of showing spinners or API errors. The rail is the one
            exception: it reserves its height so the hero below it does not
            jump when the payload lands. */}
        {isPending && <CategoryRailSkeleton />}
        {data !== undefined && <CategoryRail sections={data} />}

        <Hero promoted={promoted} />

        {data !== undefined &&
          (hasListedStores(data) ? (
            <Shelves sections={data} />
          ) : (
            <AllEmptyBlock />
          ))}

        <HowItWorks />
        <MarketplaceTeaser />
      </main>
      <PublicFooter />
    </div>
  );
}
