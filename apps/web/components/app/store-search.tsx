'use client';

import { useEffect, useId, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatRate } from '@/lib/format';
import { useDirectory, useDiscovery } from '@/lib/queries';
import { SEARCH_MAX_CHARS, SEARCH_MIN_CHARS } from '@/lib/search';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StoreAvatar } from '@/components/app/store-avatar';
import { useStoreName } from '@/components/app/store-labels';

/**
 * The storefront's search box, with suggestions.
 *
 * Suggestions are two kinds, because "where can I earn?" is asked both ways:
 * a SHOP by name, and a KIND of shop. Stores come from the directory search
 * the query would run anyway; categories are filtered client-side out of the
 * discovery payload the page already holds, so typing costs one request
 * rather than two.
 *
 * The field still works with the panel ignored: Enter always navigates to
 * /discover with the query, which is where the real, paginated search lives.
 * Suggestions are a shortcut, never the only route — a visitor who types
 * faster than the network still gets the results page.
 */

/** Debounce for the suggestion request. Long enough to skip whole words. */
const SUGGEST_DELAY_MS = 220;

/** Suggestions are a shortcut, not a directory — a short list or none. */
const STORE_LIMIT = 5;
const CATEGORY_LIMIT = 4;

export function StoreSearch({
  size = 'default',
  className,
}: {
  /** `lg` is the hero's field; `default` sits in the header. */
  size?: 'default' | 'lg';
  className?: string;
}) {
  const { t, i18n } = useTranslation();
  const router = useRouter();
  const storeName = useStoreName();
  const panelId = useId();

  const [value, setValue] = useState('');
  const [debounced, setDebounced] = useState('');
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const query = value.trim().slice(0, SEARCH_MAX_CHARS);
  const longEnough = debounced.length >= SEARCH_MIN_CHARS;

  useEffect(() => {
    const id = window.setTimeout(() => setDebounced(query), SUGGEST_DELAY_MS);
    return () => window.clearTimeout(id);
  }, [query]);

  // Close on an outside click or Escape — a panel that outlives the
  // visitor's attention is worse than no panel.
  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const { data: results, isFetching } = useDirectory(
    longEnough ? { q: debounced, per_page: STORE_LIMIT } : {},
  );
  const { data: discovery } = useDiscovery(null);

  const stores = longEnough ? (results?.data ?? []).slice(0, STORE_LIMIT) : [];

  const needle = debounced.toLowerCase();
  const categories = longEnough
    ? (discovery?.categories ?? [])
        .filter((category) => {
          const dv = category.name_dv ?? '';
          return (
            category.name_en.toLowerCase().includes(needle) ||
            dv.includes(debounced) ||
            category.slug.includes(needle)
          );
        })
        .slice(0, CATEGORY_LIMIT)
    : [];

  const hasSuggestions = stores.length > 0 || categories.length > 0;
  const showPanel = open && longEnough;

  const submit = () => {
    setOpen(false);
    router.push(query === '' ? '/discover' : `/discover?q=${encodeURIComponent(query)}`);
  };

  return (
    <div ref={containerRef} className={cn('relative w-full', className)}>
      <form
        role="search"
        onSubmit={(event) => {
          event.preventDefault();
          submit();
        }}
      >
        <Search
          className={cn(
            'pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground',
          )}
        />
        <Input
          type="search"
          variant={size === 'lg' ? 'lg' : undefined}
          value={value}
          onChange={(event) => {
            setValue(event.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          maxLength={SEARCH_MAX_CHARS}
          placeholder={t('landing.searchPlaceholder')}
          aria-label={t('nav.searchStores')}
          aria-expanded={showPanel}
          aria-controls={showPanel ? panelId : undefined}
          role="combobox"
          autoComplete="off"
          className={cn('ps-9', size === 'lg' ? 'pe-24' : 'pe-3')}
        />
        {size === 'lg' && (
          <Button
            type="submit"
            size="sm"
            className="absolute end-1.5 top-1/2 -translate-y-1/2 bg-brand text-brand-foreground hover:bg-brand/90"
          >
            {t('common.search')}
          </Button>
        )}
      </form>

      {showPanel && (
        <div
          id={panelId}
          className="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-border bg-popover shadow-lg"
        >
          {hasSuggestions ? (
            <ul className="max-h-96 overflow-y-auto py-1.5">
              {stores.length > 0 && (
                <li>
                  <p className="px-3 pt-1.5 pb-1 text-2xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {t('discover.storesLabel')}
                  </p>
                  <ul>
                    {stores.map((store) => (
                      <li key={store.slug}>
                        <Link
                          href={`/store/${store.slug}`}
                          onClick={() => setOpen(false)}
                          className="flex items-center gap-3 px-3 py-2 hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        >
                          <StoreAvatar
                            name={storeName(store)}
                            slug={store.slug}
                            logoUrl={store.logo_url}
                            size="sm"
                          />
                          <span className="flex min-w-0 flex-col">
                            <span className="truncate text-sm font-medium text-mono">
                              {storeName(store)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                              {t('discover.rate', {
                                rate: formatRate(store.cashback_rate_percent),
                              })}
                            </span>
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                </li>
              )}

              {categories.length > 0 && (
                <li>
                  <p className="px-3 pt-2 pb-1 text-2xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {t('discover.categoryLabel')}
                  </p>
                  <ul>
                    {categories.map((category) => (
                      <li key={category.slug}>
                        <Link
                          href={`/discover?category=${encodeURIComponent(category.slug)}`}
                          onClick={() => setOpen(false)}
                          className="flex items-center gap-3 px-3 py-2 hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        >
                          <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
                            {category.icon_url !== null ? (
                              <img
                                src={category.icon_url}
                                alt=""
                                className="size-full object-cover"
                              />
                            ) : (
                              <Search className="size-4 text-muted-foreground" />
                            )}
                          </span>
                          <span className="flex min-w-0 flex-col">
                            <span className="truncate text-sm font-medium text-mono">
                              {i18n.language.startsWith('dv') &&
                              category.name_dv !== null
                                ? category.name_dv
                                : category.name_en}
                            </span>
                            <span className="text-xs text-muted-foreground">
                              {t('landing.railCount', {
                                count: category.merchant_count,
                              })}
                            </span>
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                </li>
              )}
            </ul>
          ) : (
            <p className="px-3 py-4 text-center text-sm text-muted-foreground">
              {isFetching ? t('common.loading') : t('discover.noSuggestions')}
            </p>
          )}

          <button
            type="button"
            onClick={submit}
            className="flex w-full items-center gap-2 border-t border-border px-3 py-2.5 text-start text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <Search className="size-3.5 shrink-0" />
            {t('discover.searchAllFor', { query })}
          </button>
        </div>
      )}
    </div>
  );
}
