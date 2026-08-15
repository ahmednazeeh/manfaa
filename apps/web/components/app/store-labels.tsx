'use client';

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

  return (store) =>
    dhivehi && store.name_dv !== null && store.name_dv.trim() !== ''
      ? store.name_dv
      : store.name;
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
