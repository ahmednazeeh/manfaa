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
import { useDiscovery, useMe, useSignedIn } from '@/lib/queries';
import { SEARCH_MAX_CHARS } from '@/lib/search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { CashbackDemo } from '@/components/app/cashback-demo';
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
import { RotatingWords } from '@/components/app/rotating-words';
import { useStoreName } from '@/components/app/store-labels';
import { StoreAvatar } from '@/components/app/store-avatar';

/**
 * Public landing page (manfaa.app) — the reason to open the app before
 * deciding where to shop. No auth guard: discovery data comes from the
 * public GET /api/discover endpoint, and the shared PublicHeader quietly
 * upgrades to a "Dashboard" button when a customer session cookie is
 * already present.
 *
 * Shape, top to bottom: the category rail (the storefront's navigation),
 * the hero, how-it-works, the store shelves, then the marketplace teaser.
 * How-it-works sits directly under the hero on purpose — a visitor who has
 * just read the headline is asking "how does this work?", and the answer
 * belongs on screen before the shelves start competing for attention.
 *
 * Everything between the rail and the teaser is data-driven and disappears
 * cleanly when there is no data — with ONE live store the page must still
 * read as finished, so a shelf never renders empty, the rail never offers a
 * filter that leads nowhere, and the hero never becomes a carousel with one
 * slide in it.
 *
 * Authed visitors (the same silent me-probe the header uses; react-query
 * dedupes the request) never see a "Create account" CTA anywhere on this
 * page: the hero CTA becomes "Open dashboard", and the
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
 * The promoted-store panel — the SECOND hero panel, and only when there is
 * genuinely something to promote (a store whose live promotion beats its
 * standing rate). With nothing boosted the hero is one calm panel; it is
 * never a carousel waiting for slides.
 */
function PromotedPanel({ entry }: { entry: DiscoveryEntry }) {
  const { t } = useTranslation();
  const storeName = useStoreName();

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
          name={storeName(entry)}
          slug={entry.slug}
          logoUrl={entry.logo_url}
          size="sm"
        />
        <div className="flex min-w-0 flex-col">
          <span className="inline-flex items-center gap-1.5 text-2xs font-semibold tracking-wide uppercase">
            <TrendingUp className="size-3" />
            {t('discover.boosted')}
          </span>
          <span className="truncate text-sm font-medium">
            {storeName(entry)}
          </span>
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

/**
 * The hero: what Manfaa is, in one sentence, next to a phone showing it
 * happening.
 *
 * Deliberately NOT a saturated full-bleed panel any more. The previous
 * version painted a three-stop teal→indigo gradient across the whole width,
 * which fought every card below it; the page now opens on its own
 * background with a single soft wash behind the phone, and the colour
 * budget is spent on the one thing worth looking at — the cashback landing.
 *
 * Copy on one side, demonstration on the other. Under RTL the two swap
 * automatically: the grid order is source order, which the browser mirrors,
 * so a Dhivehi reader gets the text on the right where they start reading.
 */
function Hero({ promoted }: { promoted: DiscoveryEntry | null }) {
  const { t } = useTranslation();

  const channels = [
    t('landing.heroChannelInStore'),
    t('landing.heroChannelOnline'),
    t('landing.heroChannelDineIn'),
    t('landing.heroChannelServices'),
  ];

  return (
    <section className="container pt-6 pb-10 lg:pt-10 lg:pb-14">
      <div className="grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
        <div className="flex max-w-xl flex-col gap-5">
          {/* Three deliberate lines: the promise, then "when you", then the
              changing channel on its own. Keeping the rotating phrase on a
              line of its own is what stops the sentence above it reflowing
              as the phrase changes length — and it is the shape rakuten.com
              uses for the same kind of headline. `block` on each part, not
              <br>, so a narrow screen may still wrap within a part. */}
          <h1 className="font-display text-4xl leading-[1.05] text-balance text-mono sm:text-5xl lg:text-6xl">
            <span className="block">{t('landing.heroTitleLead')}</span>
            <span className="block">{t('landing.heroTitleWhenYou')}</span>
            <RotatingWords words={channels} className="block text-primary" />
          </h1>
          <p className="text-sm/relaxed text-pretty text-muted-foreground sm:text-base/relaxed">
            {t('landing.heroSubtitle')}
          </p>
          <div className="flex flex-wrap items-center gap-3">
            <PrimaryCta />
            <Button size="lg" variant="outline" asChild>
              <a href="#how-it-works">{t('landing.howTitle')}</a>
            </Button>
          </div>
          {/* The friction-removers, in the one place someone decides whether
              to sign up. */}
          <p className="text-xs text-muted-foreground">
            {t('landing.trustLine')}
          </p>
        </div>

        <CashbackDemo />
      </div>

      {/* The boosted store, when there is one, reads as a card under the
          hero rather than a second hero — it is one store, not the pitch. */}
      {promoted !== null && (
        <div className="mt-8 max-w-sm lg:mt-10">
          <PromotedPanel entry={promoted} />
        </div>
      )}
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

/**
 * One step of how-it-works, laid out icon-beside-text so three of them fit
 * on one line instead of stacking into a tall centred column.
 */
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
    <li className="flex items-start gap-3">
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
        <Icon className="size-4" />
        <span className="absolute -top-1.5 -end-1.5 flex size-4 items-center justify-center rounded-full bg-primary text-[0.625rem] font-semibold text-primary-foreground">
          {step}
        </span>
      </span>
      <div className="flex min-w-0 flex-col gap-0.5">
        <h3 className="text-sm font-semibold text-mono">{title}</h3>
        <p className="text-xs/relaxed text-muted-foreground">{body}</p>
      </div>
    </li>
  );
}

/**
 * Three steps in a row, immediately under the hero: a visitor who has just
 * read the headline is asking "how?", and the answer should be on screen
 * before the store shelves start competing for attention. Compact on
 * purpose — it is a reassurance, not a chapter.
 */
function HowItWorks() {
  const { t } = useTranslation();

  return (
    <section
      id="how-it-works"
      className="scroll-mt-20 border-y border-border bg-muted/30"
    >
      <ol className="container grid gap-5 py-6 sm:grid-cols-3 sm:gap-8">
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

/**
 * The search block that opens the storefront for a SIGNED-OUT visitor,
 * sitting with the category rail below how-it-works: by then they know what
 * Manfaa is and the question has changed from "what is this?" to "who takes
 * it?".
 */
function SearchAndCategories({
  sections,
  isPending,
}: {
  sections: DiscoverySections | undefined;
  isPending: boolean;
}) {
  const { t } = useTranslation();

  return (
    <section className="border-b border-border bg-background">
      <div className="container flex flex-col items-center gap-4 pt-8 pb-2">
        <h2 className="font-display text-2xl text-mono sm:text-3xl">
          {t('landing.findStoreTitle')}
        </h2>
        <HeroSearch />
      </div>
      {isPending && <CategoryRailSkeleton />}
      {sections !== undefined && <CategoryRail sections={sections} />}
    </section>
  );
}

export default function LandingPage() {
  // Coordinate-free on purpose: the landing has no "near you" shelf any
  // more — Nearby is a rail entry point, and geolocation is only ever asked
  // for on /discover, from the button the visitor presses there.
  const { data, isPending } = useDiscovery(null);
  const signedIn = useSignedIn();

  // The single best live promotion becomes the hero's second panel; the
  // boosted shelf below still lists it with everything else that is live.
  const promoted = data?.increased[0] ?? null;

  const storefront =
    data !== undefined &&
    (hasListedStores(data) ? (
      <Shelves sections={data} />
    ) : (
      <AllEmptyBlock />
    ));

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />
      <main className="grow">
        {signedIn ? (
          /* A customer who already has an account does not need the pitch —
             they came to shop. Categories on top, then the shelves, which is
             the storefront /discover shows them. */
          <>
            {isPending && <CategoryRailSkeleton />}
            {data !== undefined && <CategoryRail sections={data} />}
            {storefront}
          </>
        ) : (
          /* Signed out: say what this is, then how it works, and only then
             open the directory. */
          <>
            <Hero promoted={promoted} />
            <HowItWorks />
            <SearchAndCategories sections={data} isPending={isPending} />
            {storefront}
          </>
        )}

        <MarketplaceTeaser />
      </main>
      <PublicFooter />
    </div>
  );
}
