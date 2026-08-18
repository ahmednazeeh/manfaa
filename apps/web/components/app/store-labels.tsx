'use client';

import { useCallback } from 'react';
import { type MerchantChannel } from '@manfaa/api-client';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';

/**
 * Display labels for the two curated merchant facets (§1 decisions
 * 2026-08-15): the channel enum and the superadmin-curated store category.
 *
 * Channel: the API enum is `in_store | online | both`, but the literal enum
 * value — and especially the word "both" — must never reach the screen. The
 * exhaustive switch below is the only place the enum is turned into copy;
 * `both` reads "In Store & Online" (localised).
 *
 * Category: the public discovery payloads carry the category SLUG from the
 * curated list (the API exposes labels only on authed admin/merchant
 * routes). The slugs are a small closed set, so their display names live in
 * the locale files under `categories.*` (en + dv, mirroring the curated
 * seed); an unmapped slug — a category added by the superadmin before this
 * app learns its name — degrades to the capitalised slug, never to a 404 or
 * a raw `kebab-case` string.
 */

/**
 * A store's name in the reader's language: its Thaana name when it has one
 * and the page is Dhivehi, otherwise the Latin name.
 *
 * Every store surface goes through this, so a store that has not supplied a
 * Thaana name is never a blank — it simply keeps its Latin name, which is
 * also what the slug and every URL are built from.
 */
export function useStoreName(): (store: {
  name: string;
  name_dv: string | null;
}) => string {
  const { i18n } = useTranslation();
  const dhivehi = i18n.language.startsWith('dv');

  // Memoised on the language, which is the only thing that changes the
  // answer. The nearby map keys a Google-marker effect on this function, and
  // a fresh closure per render would tear down and rebuild every pin
  // whenever anything above the map re-rendered — a keystroke in the page's
  // search box is enough.
  return useCallback(
    (store) =>
      dhivehi && store.name_dv !== null && store.name_dv.trim() !== ''
        ? store.name_dv
        : store.name,
    [dhivehi],
  );
}

/**
 * A store's own description in the reader's language, or null when there is
 * nothing to show.
 *
 * The single guard the store page asks before it draws the block, so
 * "absent", "null" and "   " all resolve to the same answer: no block. The
 * stores that predate the field have no description, and an empty paragraph
 * under the store name would read as a store with nothing to say.
 *
 * Language rule mirrors {@link useStoreName}: Thaana when the page is
 * Dhivehi AND the store wrote a Thaana description, otherwise the Latin
 * one. A blank Latin description is never papered over with the Thaana
 * text — an English reader would get a script they did not ask for.
 */
export function useStoreDescription(): (store: {
  description: string | null;
  description_dv: string | null;
}) => string | null {
  const { i18n } = useTranslation();
  const dhivehi = i18n.language.startsWith('dv');

  return useCallback(
    (store) => {
      const dv = store.description_dv?.trim() ?? '';
      if (dhivehi && dv !== '') {
        return dv;
      }
      const en = store.description?.trim() ?? '';

      return en === '' ? null : en;
    },
    [dhivehi],
  );
}

/** The localised display label for a merchant channel. Never the raw enum. */
export function useChannelLabel(): (channel: MerchantChannel) => string {
  const { t } = useTranslation();

  return (channel) => {
    switch (channel) {
      case 'in_store':
        return t('discover.channelInStore');
      case 'online':
        return t('discover.channelOnline');
      case 'both':
        return t('discover.channelBoth');
    }
  };
}

/** Channel label chip for cards and the store page header. */
export function ChannelChip({ channel }: { channel: MerchantChannel }) {
  const channelLabel = useChannelLabel();

  return (
    <Badge variant="secondary" appearance="light" size="sm">
      {channelLabel(channel)}
    </Badge>
  );
}

/** "home-decor" -> "Home Decor" — the last-resort label for a curated slug
 *  this app has no translation for yet. */
function capitaliseSlug(slug: string): string {
  return slug
    .split('-')
    .filter((word) => word !== '')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * Maps a curated category slug to its localised display name. Returns null
 * for a null/empty slug so callers can drop the element entirely.
 */
export function useCategoryLabel(): (slug: string | null) => string | null {
  const { t } = useTranslation();

  return (slug) => {
    if (slug === null || slug === '') {
      return null;
    }
    return t(`categories.${slug}`, { defaultValue: capitaliseSlug(slug) });
  };
}
