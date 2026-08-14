'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Banknote,
  Globe,
  LoaderCircle,
  MapPin,
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
import { useDiscovery, useMe } from '@/lib/queries';
import { SEARCH_MAX_CHARS } from '@/lib/search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  DiscoverySection,
  PromoCard,
  useLocationRequest,
} from '@/components/app/discovery';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';

/**
 * Public landing page (manfaa.app) — the reason to open the app before
 * deciding where to shop. No auth guard: discovery data comes from the
 * public GET /api/discover endpoint, and the shared PublicHeader quietly
 * upgrades to a "Dashboard" button when a customer session cookie is
 * already present.
 *
 * Authed visitors (the same silent me-probe the header uses; react-query
 * dedupes the request) never see a "Create account" CTA anywhere on this
 * page: the hero and how-it-works CTAs become "Open dashboard", and the
 * marketplace teaser's get-notified CTA collapses to a you're-all-set line
 * (being notified just means having an account). While the probe is still
 * in flight — and always when it 401s — the signed-out page renders
 * unchanged.
 */

/**
 * The landing page's primary CTA: Create account for visitors, Open
 * dashboard once the me-probe confirms a session.
 */
function PrimaryCta() {
  const { t } = useTranslation();
  const { data: me } = useMe();

  return (
    <Button size="lg" asChild>
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

function Hero() {
  const { t } = useTranslation();

  return (
    <section className="container flex flex-col items-center gap-5 py-16 text-center lg:py-24">
      <h1 className="max-w-3xl text-balance text-3xl font-semibold tracking-tight text-mono sm:text-4xl lg:text-5xl">
        {t('landing.heroTitle')}
      </h1>
      <p className="max-w-xl text-pretty text-base text-muted-foreground lg:text-lg">
        {t('landing.heroSubtitle')}
      </p>
      <HeroSearch />
      <div className="flex flex-wrap items-center justify-center gap-3 pt-2">
        <PrimaryCta />
        <Button size="lg" variant="outline" asChild>
          <a href="#how-it-works">{t('landing.howTitle')}</a>
        </Button>
      </div>
    </section>
  );
}

/** Shown when the API has no merchants in any section yet. */
function AllEmptyBlock() {
  const { t } = useTranslation();
  const { data: me } = useMe();

  return (
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
  );
}

function Discovery() {
  const { geo, coords, requestLocation } = useLocationRequest();
  const { data, isPlaceholderData } = useDiscovery(coords);
  const { t } = useTranslation();

  // Nothing renders while loading and nothing renders on error — a public
  // landing page carries itself on the hero and how-it-works instead of
  // showing spinners or API errors.
  if (!data) {
    return null;
  }

  const allEmpty =
    data.increased.length === 0 &&
    data.featured.length === 0 &&
    data.online.length === 0 &&
    data.nearby.length === 0;

  if (allEmpty) {
    return (
      <section className="container pb-16">
        <AllEmptyBlock />
      </section>
    );
  }

  return (
    <section className="container flex flex-col gap-10 pb-16">
      {/* Boosted rates lead the page whenever any are live. The landing
          shelves are the teaser — every "view all" points at the full
          /discover directory. */}
      <DiscoverySection
        icon={TrendingUp}
        title={t('landing.increasedTitle')}
        entries={data.increased}
        card={PromoCard}
        viewAllHref="/discover"
      />

      <DiscoverySection
        icon={Sparkles}
        title={t('discover.featured')}
        entries={data.featured}
        viewAllHref="/discover"
      />

      <DiscoverySection
        icon={Globe}
        title={t('discover.online')}
        entries={data.online}
        viewAllHref="/discover"
      />

      {/* Nearby is gesture-gated: the section renders its prompt copy until
          the visitor explicitly shares their location. */}
      <DiscoverySection
        icon={Store}
        title={t('discover.nearby')}
        entries={data.nearby}
        viewAllHref="/discover"
        emptyText={
          geo.kind === 'granted'
            ? // While the coord-scoped refetch is still in flight the shelf
              // shows previous (coord-less) data — say "finding stores", not
              // "none within 10 km".
              isPlaceholderData
              ? t('discover.locating')
              : t('discover.nearbyEmpty')
            : geo.kind === 'denied'
              ? t('discover.locationDenied')
              : geo.kind === 'unavailable'
                ? t('discover.locationUnavailable')
                : t('discover.nearbyAskLocation')
        }
      >
        {geo.kind !== 'granted' && (
          <Button
            variant="outline"
            size="sm"
            onClick={requestLocation}
            disabled={geo.kind === 'locating'}
          >
            {geo.kind === 'locating' ? (
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
        )}
      </DiscoverySection>
    </section>
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
    <section className="container pb-16">
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
  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />
      <main className="grow">
        <Hero />
        <Discovery />
        <MarketplaceTeaser />
        <HowItWorks />
      </main>
      <PublicFooter />
    </div>
  );
}
