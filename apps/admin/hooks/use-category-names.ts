'use client';

import { useMemo } from 'react';
import { listStoreCategories } from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';

/**
 * Curated store-category slug -> its English name.
 *
 * A store's `category` travels as a slug by value (§1, 2026-08-15), so any
 * screen that shows one has to resolve it or print machine code. Shares its
 * query key — and therefore its cache entry — with every other consumer, and
 * is cached hard because the catalogue moves at admin speed, not at review
 * speed.
 *
 * A slug with no entry is not an error: a category can be deactivated while a
 * store still carries it. Callers fall back to the raw slug.
 */
export function useCategoryNames(): Map<string, string> {
  const query = useQuery({
    queryKey: ['admin', 'store-categories'],
    queryFn: ({ signal }) => listStoreCategories({ signal }),
    staleTime: 5 * 60_000,
  });

  return useMemo(() => {
    const names = new Map<string, string>();
    for (const category of query.data?.data ?? []) {
      names.set(category.slug, category.name_en);
    }
    return names;
  }, [query.data]);
}
