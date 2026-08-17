'use client';

import { useEffect, useRef } from 'react';
import Link from 'next/link';
import type { DiscoveryEntry, DiscoverySections } from '@manfaa/api-client';
import {
  Banknote,
  Clock,
  Globe,
  Handshake,
  LoaderCircle,
  MapPin,
  Percent,
  QrCode,
  ReceiptText,
  Repeat,
  ShoppingBag,
  SlidersHorizontal,
  Sparkles,
  Store,
  TicketPercent,
  TrendingUp,
  UserRoundPlus,
  Users,
  UtensilsCrossed,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate, formatRate } from '@/lib/format';
import { useDiscovery, useMe, useSignedIn } from '@/lib/queries';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { CashbackDemo } from '@/components/app/cashback-demo';
import {
  CategoryRail,
  CategoryRailSkeleton,
} from '@/components/app/category-rail';
import { CustomerBanner } from '@/components/app/customer-banner';
import {
  diningEntries,
  hasListedStores,
  inStoreEntries,
  ShelfHeader,
  StoreShelf,
  useLocationRequest,
  type LocationRequest,
} from '@/components/app/discovery';
import { FeaturedOffers } from '@/components/app/featured-offers';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';
import { RotatingWords } from '@/components/app/rotating-words';
import { StoreAvatar } from '@/components/app/store-avatar';
import { useStoreName } from '@/components/app/store-labels';
import { StoreSearch } from '@/components/app/store-search';

/**
 * Public landing page (manfaa.app) — the reason to open the app before
 * deciding where to shop. No auth guard: discovery data comes from the
 * public GET /api/discover endpoint, and the shared PublicHeader quietly
 * upgrades to a "Dashboard" button when a customer session cookie is
 * already present.
 *
 * Shape, top to bottom: the hero, how-it-works, real-money, search + the
 * category rail (the storefront's navigation), the curated offer banners,
 * the store shelves, then the evergreen tail — why-Manfaa, the marketplace
 * teaser and the merchant chapter (the full ForMerchants pitch for
 * visitors, the one-line MerchantCta band for members). How-it-works sits
 * directly under the hero on purpose — a visitor who has just read the
 * headline is asking "how does this work?", and the answer belongs on
 * screen before the shelves start competing for attention. Signed in, the
 * pitch sections give way to the personal banner and the storefront leads.
 *
 * Everything between the rail and the teaser is data-driven and disappears
 * cleanly when there is no data — with ONE live store the page must still
 * read as finished, so a shelf never renders empty, the rail never offers a
 * filter that leads nowhere, and the hero never becomes a carousel with one
 * slide in it. (A shelf with entries always renders, though, even when its
 * stores all appear on other shelves too — see Shelves.)
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
    <Button
      size="lg"
      asChild
      className={cn(
        'bg-brand text-brand-foreground hover:bg-brand/90',
        className,
      )}
    >
      {me ? (
        <Link href="/dashboard">{t('landing.openDashboard')}</Link>
      ) : (
        <Link href="/signup">{t('landing.createAccount')}</Link>
      )}
    </Button>
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
        <div className="flex max-w-2xl flex-col gap-5">
          {/* Three deliberate lines: the promise, then "when you", then the
              changing channel on its own. Keeping the rotating phrase on a
              line of its own is what stops the sentence above it reflowing
              as the phrase changes length — and it is the shape rakuten.com
              uses for the same kind of headline. `block` on each part, not
              <br>, so a narrow screen may still wrap within a part. */}
          {/* No text-balance: the three lines are authored, and balancing
              would re-split "Earn cash back in MVR" into two even halves
              that fight the break below it. RotatingWords owns its own
              display — passing a display class here would override the
              grid that holds its box open. */}
          {/* font-dv-display is TEMPORARY and Dhivehi-only (see
              packages/ui/src/styles.css, where the face under trial is
              chosen) — the Latin headline keeps font-display, so one class
              serves both languages. */}
          <h1 className="font-display font-dv-display text-3xl leading-[1.08] text-mono sm:text-4xl lg:text-5xl">
            <span className="block">{t('landing.heroTitleLead')}</span>
            <span className="block">{t('landing.heroTitleWhenYou')}</span>
            <RotatingWords words={channels} className="text-brand" />
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
          <span className="flex size-12 items-center justify-center rounded-full bg-brand-soft text-brand">
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
 * The "Near you" shelf — the one shelf whose emptiness depends on a
 * PERMISSION, not on the catalogue, so it cannot follow the plain
 * render-when-non-empty rule the others do.
 *
 * The section never asks for location on page load. It renders as a shell
 * with a "Use my location" button, and geolocation runs only from that
 * press — except when the browser reports the permission as ALREADY granted
 * (see LandingPage's silent adoption), in which case the fix is fetched
 * without a prompt and the shelf simply appears populated. Denied or
 * unavailable, the section hides entirely; granted but genuinely empty
 * (nothing within the API's radius), it hides too — an honest storefront
 * never keeps a heading with nothing under it.
 */
function NearMeShelf({
  sections,
  location,
  refetching,
}: {
  sections: DiscoverySections;
  location: LocationRequest;
  /** True while the coord-scoped refetch is still showing coord-less data. */
  refetching: boolean;
}) {
  const { t } = useTranslation();
  const { geo, requestLocation } = location;

  if (geo.kind === 'denied' || geo.kind === 'unavailable') {
    return null;
  }

  if (geo.kind === 'granted' && !refetching) {
    // StoreShelf renders nothing when the granted fix found no stores.
    return (
      <StoreShelf
        icon={MapPin}
        title={t('discover.nearby')}
        entries={sections.nearby.slice(0, SHELF_LIMIT)}
        viewAllHref="/discover?view=nearby"
      />
    );
  }

  const busy =
    geo.kind === 'locating' || (geo.kind === 'granted' && refetching);

  return (
    <section className="flex flex-col gap-3">
      <ShelfHeader icon={MapPin} title={t('discover.nearby')} />
      <Card>
        <CardContent className="flex flex-col items-center gap-3 p-6 text-center sm:flex-row sm:justify-between sm:text-start">
          <p className="text-sm text-muted-foreground">
            {t('discover.nearbyAskLocation')}
          </p>
          <Button
            variant="outline"
            size="sm"
            className="shrink-0"
            onClick={requestLocation}
            disabled={busy}
          >
            {busy ? (
              <>
                <LoaderCircle className="animate-spin" />
                {t('discover.locating')}
              </>
            ) : (
              <>
                <MapPin />
                {t('discover.useMyLocation')}
              </>
            )}
          </Button>
        </CardContent>
      </Card>
    </section>
  );
}

/**
 * The store shelves — every facet of the storefront, each rendered whenever
 * it has at least one store, even when the same store appears on several of
 * them (owner decision 2026-08-17, overruling the old renders-only-if-it-
 * adds-a-new-store dedup: a landing with one shelf read as "too simple",
 * and a store repeating under Featured, In store and Dine in reads as a
 * storefront, not a bug).
 *
 * Order is the owner's: Featured, Boosted, Near you, In store, Online,
 * Dine in, New stores, Highest cashback. "Near you" is the one special
 * case (permission-gated — see NearMeShelf); "Dine in" is a client-derived
 * category facet (see diningEntries). Each shelf is a SHELF_LIMIT teaser
 * whose "view all" opens the same facet full-width on /discover.
 */
function Shelves({
  sections,
  location,
  refetching,
}: {
  sections: DiscoverySections;
  location: LocationRequest;
  refetching: boolean;
}) {
  const { t } = useTranslation();

  const shelves: Array<{
    key: string;
    icon: LucideIcon;
    title: string;
    entries: DiscoveryEntry[];
    viewAllHref: string;
  }> = [
    {
      key: 'featured',
      icon: Sparkles,
      title: t('discover.featured'),
      entries: sections.featured,
      viewAllHref: '/discover?view=featured',
    },
    {
      key: 'boosted',
      icon: TrendingUp,
      title: t('discover.increased'),
      entries: sections.increased,
      viewAllHref: '/discover?view=boosted',
    },
  ];

  const afterNearby: typeof shelves = [
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
    {
      key: 'dining',
      icon: UtensilsCrossed,
      title: t('discover.dineIn'),
      entries: diningEntries(sections),
      viewAllHref: '/discover?view=dining',
    },
    {
      key: 'recent',
      icon: Clock,
      title: t('discover.newStores'),
      entries: sections.recently_added,
      viewAllHref: '/discover?view=recent',
    },
    {
      key: 'top-cashback',
      icon: Percent,
      title: t('discover.topCashback'),
      entries: sections.top_cashback,
      viewAllHref: '/discover?view=top-cashback',
    },
  ];

  // StoreShelf itself renders nothing for an empty list, so an empty facet
  // costs no markup — the wrapper's gap only separates shelves that exist.
  const shelf = (entry: (typeof shelves)[number]) => (
    <StoreShelf
      key={entry.key}
      icon={entry.icon}
      title={entry.title}
      entries={entry.entries.slice(0, SHELF_LIMIT)}
      viewAllHref={entry.viewAllHref}
    />
  );

  return (
    <div className="container flex flex-col gap-10 pb-14">
      {shelves.map(shelf)}
      <NearMeShelf
        sections={sections}
        location={location}
        refetching={refetching}
      />
      {afterNearby.map(shelf)}
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
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand">
        <Icon className="size-4" />
        <span className="absolute -top-1.5 -end-1.5 flex size-4 items-center justify-center rounded-full bg-brand text-[0.625rem] font-semibold text-brand-foreground">
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
          <span className="flex size-12 items-center justify-center rounded-full bg-brand-soft text-brand">
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
 * The one claim that separates Manfaa from a loyalty scheme, given its own
 * band rather than a bullet: the reward is rufiyaa in a bank account, not
 * points in a wallet only we honour. It sits after how-it-works, where a
 * reader has just learned the mechanics and is deciding whether they are
 * worth it.
 */
function RealMoney() {
  const { t } = useTranslation();

  return (
    <section className="border-b border-border bg-brand-soft">
      <div className="container flex flex-col items-center gap-3 py-10 text-center lg:py-14">
        <Banknote className="size-7 text-brand" />
        <h2 className="font-display text-2xl text-balance text-mono sm:text-3xl">
          {t('landing.realMoneyTitle')}
        </h2>
        <p className="max-w-xl text-sm/relaxed text-pretty text-muted-foreground">
          {t('landing.realMoneyBody')}
        </p>
      </div>
    </section>
  );
}

/**
 * One value proposition of the Why-Manfaa trio: icon above, then the claim
 * and its one-line justification. Icon-above rather than icon-beside on
 * purpose — how-it-works already owns the icon-beside-text row shape, and
 * two identical trios on one page would read as the same section twice.
 */
function WhyPoint({
  icon: Icon,
  title,
  body,
}: {
  icon: LucideIcon;
  title: string;
  body: string;
}) {
  return (
    <li className="flex flex-col gap-2.5">
      <span className="flex size-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
        <Icon className="size-4.5" />
      </span>
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold text-mono">{title}</h3>
        <p className="text-xs/relaxed text-muted-foreground">{body}</p>
      </div>
    </li>
  );
}

/**
 * The evergreen trio that keeps a young catalogue from ending abruptly:
 * three claims that stay true with one store or a hundred, and that no
 * other section on the page already makes. How-it-works owns the mechanics,
 * RealMoney owns rufiyaa-not-points, the trust line owns free/no-card — so
 * this trio carries what is left: no coupons, everything tracked, built for
 * the Maldives. It renders for members too: for them it is the reminder of
 * what the code in their pocket does, not a pitch.
 */
function WhyManfaa() {
  const { t } = useTranslation();

  return (
    <section className="container py-12 lg:py-14">
      <h2 className="pb-6 font-display text-2xl text-mono sm:text-3xl">
        {t('landing.whyTitle')}
      </h2>
      <ul className="grid gap-6 sm:grid-cols-3 sm:gap-8">
        <WhyPoint
          icon={TicketPercent}
          title={t('landing.why1Title')}
          body={t('landing.why1Body')}
        />
        <WhyPoint
          icon={ReceiptText}
          title={t('landing.why2Title')}
          body={t('landing.why2Body')}
        />
        <WhyPoint
          icon={MapPin}
          title={t('landing.why3Title')}
          body={t('landing.why3Body')}
        />
      </ul>
    </section>
  );
}

/**
 * The other side of the marketplace, addressed once, at the very end: a
 * store owner who scrolled the whole storefront is the one visitor this
 * page was not written for, and this band hands them their door. External
 * host (merchant.manfaa.app), so a plain anchor rather than a Link — the
 * same signup URL the header's quiet nav item points at.
 */
function MerchantCta() {
  const { t } = useTranslation();

  return (
    <section className="border-t border-border bg-muted/30">
      <div className="container flex flex-col items-start gap-4 py-10 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-4">
          <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand">
            <Store className="size-5" />
          </span>
          <div className="flex flex-col gap-1">
            <h2 className="text-lg font-semibold text-mono">
              {t('landing.merchantCtaTitle')}
            </h2>
            <p className="max-w-xl text-sm text-muted-foreground">
              {t('landing.merchantCtaBody')}
            </p>
          </div>
        </div>
        <Button variant="outline" asChild className="shrink-0">
          <a href="https://merchant.manfaa.app/signup" rel="noopener">
            {t('nav.becomeMerchant')}
          </a>
        </Button>
      </div>
    </section>
  );
}

/** One value point of the merchant pitch — icon tile beside the claim, the
 *  how-it-works row shape, drawn in the panel band's own inks. */
function MerchantPoint({
  icon: Icon,
  title,
  body,
}: {
  icon: LucideIcon;
  title: string;
  body: string;
}) {
  return (
    <li className="flex items-start gap-3">
      <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-panel-foreground/10 text-panel-accent">
        <Icon className="size-4.5" />
      </span>
      <div className="flex min-w-0 flex-col gap-1">
        <h3 className="text-sm font-semibold">{title}</h3>
        <p className="text-xs/relaxed text-panel-foreground/80">{body}</p>
      </div>
    </li>
  );
}

/**
 * The merchant pitch — SIGNED-OUT visitors only (owner request 2026-08-17:
 * the signed-out landing must tell store owners why they should join, not
 * just shoppers). A member sees the small MerchantCta band instead; the
 * full sales pitch is for the store owner who wandered in from a search.
 *
 * Its own chapter by design: the panel gradient the hero once wore, so it
 * reads as a change of audience — everything above this band talks to
 * shoppers, this band talks to the till side of the counter. The ink
 * tokens guarantee ≥ 4.5:1 for panel-foreground on every stop.
 *
 * The credibility block names Rakuten, Honey, Dell, Microsoft and Samsung
 * as COMPARISONS — the model they proved, never a relationship — and the
 * footnote says so outright.
 */
function ForMerchants() {
  const { t } = useTranslation();

  return (
    <section className="bg-gradient-to-br from-panel-from via-panel-via to-panel-to text-panel-foreground">
      <div className="container flex flex-col gap-8 py-14 lg:py-16">
        <div className="flex max-w-2xl flex-col gap-3">
          <span className="inline-flex items-center gap-2 text-2xs font-semibold tracking-wide text-panel-accent uppercase">
            <Store className="size-3.5" />
            {t('landing.merchantsEyebrow')}
          </span>
          <h2 className="font-display text-2xl text-balance sm:text-3xl">
            {t('landing.merchantsTitle')}
          </h2>
          <p className="text-sm/relaxed text-pretty text-panel-foreground/80">
            {t('landing.merchantsIntro')}
          </p>
        </div>

        <ul className="grid gap-6 sm:grid-cols-2 lg:gap-8">
          <MerchantPoint
            icon={Users}
            title={t('landing.merchantsPoint1Title')}
            body={t('landing.merchantsPoint1Body')}
          />
          <MerchantPoint
            icon={Handshake}
            title={t('landing.merchantsPoint2Title')}
            body={t('landing.merchantsPoint2Body')}
          />
          <MerchantPoint
            icon={Repeat}
            title={t('landing.merchantsPoint3Title')}
            body={t('landing.merchantsPoint3Body')}
          />
          <MerchantPoint
            icon={SlidersHorizontal}
            title={t('landing.merchantsPoint4Title')}
            body={t('landing.merchantsPoint4Body')}
          />
        </ul>

        {/* The proof: the model's pedigree, phrased as comparison only. */}
        <div className="flex flex-col gap-3 rounded-2xl bg-panel-to/40 p-6 ring-1 ring-panel-foreground/15">
          <h3 className="text-sm font-semibold">
            {t('landing.merchantsProofTitle')}
          </h3>
          <p className="max-w-3xl text-sm/relaxed text-panel-foreground/80">
            {t('landing.merchantsProof1')}
          </p>
          <p className="max-w-3xl text-sm/relaxed text-panel-foreground/80">
            {t('landing.merchantsProof2')}
          </p>
          <p className="text-2xs text-panel-foreground/60">
            {t('landing.merchantsDisclaimer')}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-4">
          <Button
            size="lg"
            asChild
            className="bg-panel-foreground text-panel-to hover:bg-panel-foreground/90"
          >
            <a href="https://merchant.manfaa.app/signup" rel="noopener">
              {t('nav.becomeMerchant')}
            </a>
          </Button>
          <span className="text-xs text-panel-foreground/70">
            {t('landing.merchantsCtaHint')}
          </span>
        </div>
      </div>
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
        <StoreSearch size="lg" className="max-w-xl" />
      </div>
      {isPending && <CategoryRailSkeleton />}
      {sections !== undefined && <CategoryRail sections={sections} />}
    </section>
  );
}

export default function LandingPage() {
  /**
   * ONE geolocation state for the landing, feeding the discovery query's
   * key — the "Near you" shelf and every card's distance line come from the
   * same coord-scoped payload. Geolocation itself only ever runs from the
   * shelf's own button (never a prompt on page load), with one exception:
   * when the Permissions API reports the choice as ALREADY granted, the fix
   * is fetched silently — getCurrentPosition cannot prompt in that state —
   * so a returning visitor who said yes once sees "Near you" populated
   * without pressing anything again.
   */
  const location = useLocationRequest();
  const requestRef = useRef(location.requestLocation);
  requestRef.current = location.requestLocation;
  useEffect(() => {
    if (
      typeof navigator === 'undefined' ||
      navigator.permissions === undefined
    ) {
      return;
    }
    let cancelled = false;
    navigator.permissions
      .query({ name: 'geolocation' })
      .then((status) => {
        if (!cancelled && status.state === 'granted') {
          requestRef.current();
        }
      })
      // A browser without geolocation in its Permissions API: keep the
      // gesture-gated button, exactly as if the check did not exist.
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, []);

  const { data, isPending, isPlaceholderData } = useDiscovery(location.coords);
  const signedIn = useSignedIn();

  // The single best live promotion becomes the hero's second panel; the
  // boosted shelf below still lists it with everything else that is live.
  const promoted = data?.increased[0] ?? null;

  const storefront =
    data !== undefined &&
    (hasListedStores(data) ? (
      <Shelves
        sections={data}
        location={location}
        refetching={isPlaceholderData}
      />
    ) : (
      <AllEmptyBlock />
    ));

  // The admin-curated offer banners, leading the shelves exactly as they do
  // on /discover. The wrapper's bottom padding exists only when the row
  // does — with zero live offers nothing renders, not even the spacing.
  const offers = data !== undefined && data.offers.length > 0 && (
    <div className="pb-8">
      <FeaturedOffers offers={data.offers} />
    </div>
  );

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />
      <main className="grow">
        {signedIn ? (
          /* A customer who already has an account does not need the pitch —
             they came to shop. Categories on top, then the shelves, which is
             the storefront /discover shows them. */
          <>
            <CustomerBanner />
            {isPending && <CategoryRailSkeleton />}
            {data !== undefined && <CategoryRail sections={data} />}
            {offers}
            {storefront}
          </>
        ) : (
          /* Signed out: say what this is, then how it works, and only then
             open the directory. */
          <>
            <Hero promoted={promoted} />
            <HowItWorks />
            <RealMoney />
            <SearchAndCategories sections={data} isPending={isPending} />
            {offers}
            {storefront}
          </>
        )}

        {/* Evergreen from here down — true with one store or a hundred, so
            the page ends deliberately however thin the catalogue above. */}
        <WhyManfaa />
        <MarketplaceTeaser />
        {/* Members get the quiet one-line band; a signed-out visitor might
            BE a store owner, so they get the full pitch chapter. */}
        {signedIn ? <MerchantCta /> : <ForMerchants />}
      </main>
      <PublicFooter />
    </div>
  );
}
