'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { type DirectoryEntry, type PaginationMeta } from '@manfaa/api-client';
import {
  Globe,
  LoaderCircle,
  MapPin,
  Search,
  Sparkles,
  Store,
  TrendingUp,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { isUnprocessable, useDirectory, useDiscovery } from '@/lib/queries';
import { SEARCH_MAX_CHARS, SEARCH_MIN_CHARS } from '@/lib/search';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import {
  DiscoverySection,
  MerchantCard,
  PromoCard,
  useLocationRequest,
} from '@/components/app/discovery';
import { ListPagination } from '@/components/app/list-pagination';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';

/**
 * The single PUBLIC discovery page — the former /discover shelves and the
 * former /stores directory merged into one screen. Search + category chips
 * sit at the top; with no active filter the curated shelves render first and
 * the full paginated "All stores" grid follows; the moment a query or
 * category is active the shelves step aside and the filtered grid takes
 * over. q / category / page live in the URL (searchParams), so results are
 * shareable and the back button walks filter history. Nearby stays
 * gesture-gated: geolocation only ever runs from the button press.
 */

const SEARCH_DEBOUNCE_MS = 300;

/**
 * The API bounds `q` to SEARCH_MIN_CHARS..SEARCH_MAX_CHARS — too-short
 * needles are never sent, too-long ones (a paste past the input's
 * maxLength, or an oversized q in a shared URL) are clamped to the maximum
 * rather than bounced off the API as a 422.
 */
function toEffectiveQ(raw: string): string {
  const trimmed = raw.trim().slice(0, SEARCH_MAX_CHARS);
  return trimmed.length >= SEARCH_MIN_CHARS ? trimmed : '';
}

/**
 * Directory rows are the discovery-entry shape minus distance (the
 * directory is not geographic) — MerchantCard is reused with a null
 * distance so nothing renders in that slot.
 */
function toCardEntry(entry: DirectoryEntry) {
  return { ...entry, distance_m: null };
}

function CategoryChips({
  categories,
  selected,
  onSelect,
}: {
  categories: string[];
  selected: string | null;
  onSelect: (category: string | null) => void;
}) {
  const { t } = useTranslation();

  if (categories.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <button
        type="button"
        onClick={() => onSelect(null)}
        className={cn(
          'rounded-full border px-3 py-1 text-sm transition-colors',
          selected === null
            ? 'border-primary bg-primary text-primary-foreground'
            : 'border-border text-secondary-foreground hover:border-primary/40 hover:text-foreground',
        )}
      >
        {t('stores.allCategories')}
      </button>
      {categories.map((category) => (
        <button
          key={category}
          type="button"
          onClick={() => onSelect(selected === category ? null : category)}
          className={cn(
            'rounded-full border px-3 py-1 text-sm transition-colors',
            selected === category
              ? 'border-primary bg-primary text-primary-foreground'
              : 'border-border text-secondary-foreground hover:border-primary/40 hover:text-foreground',
          )}
        >
          {category}
        </button>
      ))}
    </div>
  );
}

/** Honest empty state: filtered no-match vs. an empty platform. */
function DirectoryEmpty({
  filtered,
  onClear,
}: {
  filtered: boolean;
  onClear: () => void;
}) {
  const { t } = useTranslation();

  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-4 p-10 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Store className="size-5" />
        </span>
        <div className="flex flex-col gap-1">
          <p className="text-base font-medium text-mono">
            {filtered ? t('stores.noResults') : t('discover.empty')}
          </p>
          {filtered && (
            <p className="text-sm text-muted-foreground">
              {t('stores.noResultsHint')}
            </p>
          )}
        </div>
        {filtered && (
          <Button variant="outline" size="sm" onClick={onClear}>
            {t('stores.clearFilters')}
          </Button>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * The curated shelves — rendered only while no query/category is active.
 * Order per plan: Increased first, then Featured, Nearby (gesture-gated),
 * Online. No per-shelf "view all" links: the full grid is on this same
 * page, right below.
 */
function CuratedShelves() {
  const { geo, coords, requestLocation } = useLocationRequest();
  const { data, isPending, isPlaceholderData, error } = useDiscovery(coords);
  const { t } = useTranslation();

  if (isPending) {
    return <LoadingBlock lines={5} />;
  }
  if (error) {
    return <ErrorBlock error={error} />;
  }
  if (!data) {
    return null;
  }

  return (
    <div className="flex flex-col gap-8">
      <DiscoverySection
        icon={TrendingUp}
        title={t('discover.increased')}
        entries={data.increased}
        emptyText={t('discover.sectionEmpty')}
        card={PromoCard}
      />

      <DiscoverySection
        icon={Sparkles}
        title={t('discover.featured')}
        entries={data.featured}
        emptyText={t('discover.sectionEmpty')}
      />

      <DiscoverySection
        icon={Store}
        title={t('discover.nearby')}
        entries={data.nearby}
        emptyText={
          geo.kind === 'granted'
            ? // Previous (coord-less) data is on screen while the
              // coord-scoped refetch runs; don't claim "none nearby" yet.
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

      <DiscoverySection
        icon={Globe}
        title={t('discover.online')}
        entries={data.online}
        emptyText={t('discover.sectionEmpty')}
      />
    </div>
  );
}

export default function DiscoverClient() {
  const { t } = useTranslation();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  // --- URL state (q + category + page live in searchParams) ---------------
  const urlQ = searchParams.get('q')?.trim() ?? '';
  const effectiveUrlQ = toEffectiveQ(urlQ);
  const category = searchParams.get('category');
  const rawPage = Number.parseInt(searchParams.get('page') ?? '1', 10);
  const page = Number.isFinite(rawPage) && rawPage >= 1 ? rawPage : 1;

  const [searchInput, setSearchInput] = useState(urlQ);

  /**
   * Writes filter state into the URL. Defaults (empty q, no category,
   * page 1) are dropped so the canonical unfiltered URL stays /discover.
   * `replace` is for keystroke debounces (typing must not stack history
   * entries); chip clicks, pagination and clears `push` so the back button
   * steps through them.
   */
  const applyParams = useCallback(
    (
      next: { q?: string; category?: string | null; page?: number },
      mode: 'push' | 'replace',
    ) => {
      const params = new URLSearchParams(searchParams.toString());
      if (next.q !== undefined) {
        if (next.q === '') params.delete('q');
        else params.set('q', next.q);
      }
      if (next.category !== undefined) {
        if (next.category === null) params.delete('category');
        else params.set('category', next.category);
      }
      if (next.page !== undefined) {
        if (next.page <= 1) params.delete('page');
        else params.set('page', String(next.page));
      }
      const encoded = params.toString();
      router[mode](`${pathname}${encoded === '' ? '' : `?${encoded}`}`, {
        scroll: false,
      });
    },
    [searchParams, pathname, router],
  );

  // The last q value THIS component wrote to the URL — used to tell our own
  // navigation apart from external ones (back/forward, a shared link).
  const lastWrittenQ = useRef(effectiveUrlQ);

  // Debounced keystrokes -> URL. Filter changes reset to page 1 so a
  // shrunken result set is never opened on a page past its end.
  useEffect(() => {
    const handle = setTimeout(() => {
      const effective = toEffectiveQ(searchInput);
      if (effective === effectiveUrlQ) {
        return;
      }
      lastWrittenQ.current = effective;
      applyParams({ q: effective, page: 1 }, 'replace');
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [searchInput, effectiveUrlQ, applyParams]);

  // URL -> input, only when the change was NOT our own write. Guarding on
  // lastWrittenQ (not on the current input) means a URL update landing while
  // the user is mid-word never clobbers what they've typed since.
  useEffect(() => {
    if (effectiveUrlQ === lastWrittenQ.current) {
      return;
    }
    lastWrittenQ.current = effectiveUrlQ;
    setSearchInput(effectiveUrlQ);
  }, [effectiveUrlQ]);

  const filtered = effectiveUrlQ !== '' || category !== null;

  const { data, isPending, isPlaceholderData, error } = useDirectory({
    q: effectiveUrlQ === '' ? undefined : effectiveUrlQ,
    category: category ?? undefined,
    page,
  });

  const clearFilters = () => {
    setSearchInput('');
    lastWrittenQ.current = '';
    applyParams({ q: '', category: null, page: 1 }, 'push');
  };

  // Adapt the directory meta to the Laravel pagination shape the shared
  // pager renders; total is exact so last_page is exact.
  const pagerMeta: PaginationMeta | null = data
    ? {
        current_page: data.meta.page,
        last_page: Math.max(1, Math.ceil(data.meta.total / data.meta.per_page)),
        from:
          data.meta.total === 0
            ? null
            : (data.meta.page - 1) * data.meta.per_page + 1,
        to:
          data.meta.total === 0
            ? null
            : Math.min(data.meta.page * data.meta.per_page, data.meta.total),
        total: data.meta.total,
        per_page: data.meta.per_page,
        links: [],
        path: null,
      }
    : null;

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />

      <main className="container grow py-8 lg:py-10">
        <div className="flex flex-col gap-1.5 pb-6">
          <h1 className="text-2xl font-semibold tracking-tight text-mono">
            {t('discover.title')}
          </h1>
          <p className="text-sm text-muted-foreground">
            {t('discover.description')}
          </p>
        </div>

        {/* Search + chips lead the page in both modes. */}
        <div className="flex flex-col gap-4 pb-8">
          <div className="relative w-full max-w-md">
            <Search className="absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="search"
              variant="lg"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              maxLength={SEARCH_MAX_CHARS}
              placeholder={t('stores.searchPlaceholder')}
              aria-label={t('stores.searchPlaceholder')}
              className="ps-9"
            />
            {searchInput.trim().length > 0 &&
              searchInput.trim().length < SEARCH_MIN_CHARS && (
                <p className="pt-1.5 text-xs text-muted-foreground">
                  {t('stores.searchMin', { min: SEARCH_MIN_CHARS })}
                </p>
              )}
          </div>

          {data && (
            <CategoryChips
              categories={data.meta.categories}
              selected={category}
              onSelect={(next) =>
                applyParams({ category: next, page: 1 }, 'push')
              }
            />
          )}
        </div>

        <div className="flex flex-col gap-8 pb-10">
          {/* Curated shelves only while nothing is filtered. */}
          {!filtered && <CuratedShelves />}

          {isPending && <LoadingBlock lines={6} />}
          {/* A 422 means the URL carried filter params outside the API's
              bounds (clamping covers q; category/page can still arrive
              oversized in a crafted link). That's "nothing matches", not an
              outage — show the localised empty state with its clear-filters
              reset instead of the API's raw English validation prose. */}
          {!isPending && error && isUnprocessable(error) && (
            <DirectoryEmpty filtered onClear={clearFilters} />
          )}
          {!isPending && error && !isUnprocessable(error) && (
            <ErrorBlock error={error} />
          )}

          {data && (
            <section
              className={cn(
                'flex flex-col gap-4',
                // The previous page stays on screen while the next loads.
                isPlaceholderData && 'opacity-60',
              )}
            >
              {filtered ? (
                <div className="flex flex-wrap items-center gap-3">
                  <h2 className="text-base font-semibold text-mono">
                    {t('stores.resultsCount', { count: data.meta.total })}
                  </h2>
                  <Button variant="outline" size="sm" onClick={clearFilters}>
                    {t('stores.clearFilters')}
                  </Button>
                </div>
              ) : (
                <h2 className="flex items-center gap-2 text-base font-semibold text-mono">
                  <Store className="size-4 text-muted-foreground" />
                  {t('stores.title')}
                </h2>
              )}

              {data.data.length === 0 ? (
                <DirectoryEmpty filtered={filtered} onClear={clearFilters} />
              ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                  {data.data.map((entry) => (
                    <MerchantCard key={entry.slug} entry={toCardEntry(entry)} />
                  ))}
                </div>
              )}

              {pagerMeta && (
                <ListPagination
                  meta={pagerMeta}
                  onPageChange={(next) => applyParams({ page: next }, 'push')}
                />
              )}
            </section>
          )}
        </div>
      </main>

      <PublicFooter />
    </div>
  );
}
