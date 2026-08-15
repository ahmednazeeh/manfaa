'use client';

import Link from 'next/link';
import type { DiscoveryCategory, DiscoverySections } from '@manfaa/api-client';
import {
  Baby,
  BadgePercent,
  Bike,
  BookOpen,
  Briefcase,
  Building2,
  CakeSlice,
  Camera,
  Car,
  Coffee,
  Croissant,
  Dumbbell,
  Fish,
  Flower2,
  Fuel,
  Gamepad2,
  Gem,
  Gift,
  Glasses,
  Globe,
  GraduationCap,
  Hammer,
  Headphones,
  HeartPulse,
  LampDesk,
  Laptop,
  LayoutGrid,
  MapPin,
  Package,
  Paintbrush,
  PawPrint,
  Pill,
  Plane,
  Scissors,
  Ship,
  Shirt,
  ShoppingBag,
  ShoppingCart,
  Smartphone,
  Sofa,
  Sparkles,
  Stethoscope,
  Store,
  Tag,
  TrendingUp,
  UtensilsCrossed,
  Watch,
  Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { hasListedStores, inStoreEntries } from '@/components/app/discovery';
import { ScrollRow } from '@/components/app/scroll-row';
import { useCategoryLabel } from '@/components/app/store-labels';

/**
 * The storefront's category rail — a scrolling row of icon-over-label
 * entries, and the first thing a visitor sees under the header.
 *
 * Two kinds of entry sit in one row:
 *
 *   1. The API's CURATED categories (§1: the superadmin-curated list stores
 *      pick from), already in the platform's own sort order and already
 *      filtered to categories a live store actually carries — the rail
 *      never offers a chip that leads nowhere. Labels come from the
 *      payload's `name_en` / `name_dv`, so a category renamed in admin
 *      renames here without a deploy; the app's own `categories.*` locale
 *      map is only the fallback for a language the row has no name in.
 *      Iconography likewise comes from the payload: uploaded artwork
 *      (`icon_url`) first, then the curated glyph name (`icon`), then this
 *      file's neutral tag — so admin changes any of it without a deploy.
 *
 *   2. The non-category entry points the read model genuinely supports —
 *      All stores, Boosted, In store, Online, Nearby. Each one is hidden
 *      unless there is something behind it; only "All stores" (which is the
 *      whole directory) and "Nearby" (which is an action, not a claim —
 *      it asks for location and answers honestly either way) are
 *      unconditional.
 *
 * Every entry lands on /discover with the filter already applied, so the
 * rail is navigation, never decoration.
 */

/**
 * The glyphs an admin can pick, keyed by the lucide NAME the API stores in
 * `icon`. Lucide cannot be looked up dynamically without shipping the whole
 * icon set, so this map is the client half of the contract in
 * App\Models\StoreCategory::ICONS — a name the API allows but this map has
 * not got degrades to a neutral tag rather than a blank tile.
 */
const GLYPHS: Record<string, LucideIcon> = {
  baby: Baby,
  'badge-percent': BadgePercent,
  bike: Bike,
  'book-open': BookOpen,
  briefcase: Briefcase,
  'building-2': Building2,
  'cake-slice': CakeSlice,
  camera: Camera,
  car: Car,
  coffee: Coffee,
  croissant: Croissant,
  dumbbell: Dumbbell,
  fish: Fish,
  'flower-2': Flower2,
  fuel: Fuel,
  'gamepad-2': Gamepad2,
  gem: Gem,
  gift: Gift,
  glasses: Glasses,
  'graduation-cap': GraduationCap,
  hammer: Hammer,
  headphones: Headphones,
  'heart-pulse': HeartPulse,
  'lamp-desk': LampDesk,
  laptop: Laptop,
  package: Package,
  paintbrush: Paintbrush,
  'paw-print': PawPrint,
  pill: Pill,
  plane: Plane,
  scissors: Scissors,
  ship: Ship,
  shirt: Shirt,
  'shopping-bag': ShoppingBag,
  'shopping-cart': ShoppingCart,
  smartphone: Smartphone,
  sofa: Sofa,
  sparkles: Sparkles,
  stethoscope: Stethoscope,
  store: Store,
  tag: Tag,
  'utensils-crossed': UtensilsCrossed,
  watch: Watch,
  wrench: Wrench,
};

const DEFAULT_CATEGORY_ICON = Tag;

/**
 * The label for one curated category in the active language: the API's own
 * name when it has one, otherwise this app's `categories.*` map (which
 * itself degrades to the capitalised slug). Dhivehi rows whose `name_dv` is
 * still null therefore read as Dhivehi wherever the app knows the word, and
 * as the English curated name only as a last resort.
 */
function useDiscoveryCategoryLabel(): (category: DiscoveryCategory) => string {
  const { i18n } = useTranslation();
  const fallbackLabel = useCategoryLabel();

  return (category) => {
    const apiName = i18n.language.startsWith('dv')
      ? category.name_dv
      : category.name_en;

    if (apiName !== null && apiName.trim() !== '') {
      return apiName;
    }

    return fallbackLabel(category.slug) ?? category.name_en;
  };
}

function RailItem({
  href,
  icon: Icon,
  iconUrl,
  label,
  count,
}: {
  href: string;
  icon: LucideIcon;
  /** Uploaded artwork; drawn instead of the glyph when present. */
  iconUrl?: string | null;
  label: string;
  /** Merchants behind this entry; omitted for entries that are actions. */
  count?: number;
}) {
  const { t } = useTranslation();
  const hasArtwork = iconUrl !== undefined && iconUrl !== null;

  return (
    <li className="shrink-0 snap-start">
      <Link
        href={href}
        className="group flex w-20 flex-col items-center gap-2 rounded-lg py-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <span
          className={cn(
            'flex size-14 items-center justify-center overflow-hidden rounded-2xl transition-colors',
            // Uploaded artwork brings its own colour, so the tile stays a
            // neutral, unhovered frame around it — tinting the ground under
            // a full-bleed image only muddies it.
            hasArtwork
              ? 'border border-border bg-card'
              : 'bg-brand-soft text-brand group-hover:bg-brand group-hover:text-brand-foreground',
          )}
        >
          {hasArtwork ? (
            // Plain img: category icons are served by the API origin, and
            // next/image would need a remotePatterns allowlist for no gain
            // at this size (same reasoning as StoreAvatar).
            <img
              src={iconUrl}
              alt=""
              loading="lazy"
              className="size-full object-cover"
            />
          ) : (
            <Icon className="size-6" strokeWidth={1.75} />
          )}
        </span>
        <span className="flex flex-col items-center gap-0.5">
          <span className="line-clamp-2 text-center text-xs leading-tight font-medium text-mono">
            {label}
          </span>
          {count !== undefined && (
            <>
              {/* The number alone on screen; the whole phrase for a screen
                  reader, so the link never announces a bare digit. */}
              <span aria-hidden="true" className="text-2xs text-muted-foreground">
                {count}
              </span>
              <span className="sr-only">{t('landing.railCount', { count })}</span>
            </>
          )}
        </span>
      </Link>
    </li>
  );
}

export function CategoryRail({ sections }: { sections: DiscoverySections }) {
  const { t } = useTranslation();
  const categoryLabel = useDiscoveryCategoryLabel();

  // A rail of entry points into an empty directory is worse than no rail.
  if (!hasListedStores(sections)) {
    return null;
  }

  const inStoreCount = inStoreEntries(sections).length;

  return (
    <nav aria-label={t('landing.railLabel')} className="container py-4">
      <ScrollRow label={t('landing.railLabel')} className="gap-2 sm:gap-3">
        <RailItem
          href="/discover"
          icon={LayoutGrid}
          label={t('stores.title')}
        />

        {sections.increased.length > 0 && (
          <RailItem
            href="/discover?view=boosted"
            icon={TrendingUp}
            label={t('discover.boosted')}
            count={sections.increased.length}
          />
        )}

        {inStoreCount > 0 && (
          <RailItem
            href="/discover?view=in-store"
            icon={Store}
            label={t('discover.inStore')}
            count={inStoreCount}
          />
        )}

        {sections.online.length > 0 && (
          <RailItem
            href="/discover?view=online"
            icon={Globe}
            label={t('discover.online')}
            count={sections.online.length}
          />
        )}

        {/* Unconditional: nearby is a request for location, not a claim
            about what is nearby — the view answers honestly either way. */}
        <RailItem
          href="/discover?view=nearby"
          icon={MapPin}
          label={t('discover.nearby')}
        />

        {sections.categories.map((category) => (
          <RailItem
            key={category.slug}
            href={`/discover?category=${encodeURIComponent(category.slug)}`}
            icon={
              (category.icon === null
                ? undefined
                : GLYPHS[category.icon]) ?? DEFAULT_CATEGORY_ICON
            }
            iconUrl={category.icon_url}
            label={categoryLabel(category)}
            count={category.merchant_count}
          />
        ))}
      </ScrollRow>
    </nav>
  );
}

/**
 * Height-matched placeholder so the hero does not jump when the rail
 * arrives (the discovery payload is fetched client-side). It mirrors
 * RailItem's box exactly — tile, label, count — because matching the HEIGHT
 * is the entire point of it existing.
 */
export function CategoryRailSkeleton() {
  return (
    <div className="container py-4" aria-hidden="true">
      <div className="flex gap-2 overflow-hidden sm:gap-3">
        {Array.from({ length: 8 }, (_, index) => (
          <div
            key={index}
            className="flex w-20 shrink-0 flex-col items-center gap-2 py-1"
          >
            <div className="size-14 rounded-2xl bg-muted" />
            <div className="flex flex-col items-center gap-0.5">
              <div className="h-[0.9375rem] w-12 rounded bg-muted" />
              <div className="h-3 w-4 rounded bg-muted" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
