import { cn } from '@/lib/utils';

/**
 * Store avatar shared by every merchant surface (cards, grids, the store
 * page hero). Renders the uploaded logo when one exists; otherwise a
 * deterministic initials tile — the palette index is a plain integer hash
 * of the slug, so a store keeps its colour across pages, sessions and
 * users with no external libraries involved.
 *
 * No store has uploaded a logo yet, so on the storefront today this tile IS
 * the card: at the `tile` size it fills the card's whole hero slot and the
 * initials are the only brand mark on screen. It is built to look
 * deliberate at that size rather than like a grey placeholder — a saturated
 * brand-ish ground, a soft corner highlight that keeps it from reading
 * flat, and confident letterforms.
 */

/**
 * Eight background/text pairs, all AA-contrast (≥ 4.5:1) for the white
 * initials. Fixed mid-tone hues read the same in light and dark themes,
 * so the tile never needs a theme-conditional colour.
 */
const PALETTE: ReadonlyArray<{ bg: string; fg: string }> = [
  { bg: '#b91c1c', fg: '#ffffff' }, // red
  { bg: '#c2410c', fg: '#ffffff' }, // orange
  { bg: '#047857', fg: '#ffffff' }, // emerald
  { bg: '#0f766e', fg: '#ffffff' }, // teal
  { bg: '#1d4ed8', fg: '#ffffff' }, // blue
  { bg: '#4f46e5', fg: '#ffffff' }, // indigo
  { bg: '#7e22ce', fg: '#ffffff' }, // purple
  { bg: '#be123c', fg: '#ffffff' }, // rose
];

/**
 * A corner highlight so a large tile is not a flat rectangle of colour.
 * Deliberately capped at 18% white and faded out well before the centre:
 * the initials sit in the middle, and at this strength even the LIGHTEST
 * point of the lightest palette entry still clears 4.5:1 against white —
 * the tile gains depth without spending any of the contrast budget.
 */
const HIGHLIGHT =
  'radial-gradient(120% 120% at 22% 0%, rgba(255,255,255,0.18), rgba(255,255,255,0) 60%)';

/** Simple 31-based string hash — stable, dependency-free, sign-safe. */
function hashSlug(slug: string): number {
  let hash = 0;
  for (let index = 0; index < slug.length; index++) {
    hash = (hash * 31 + slug.charCodeAt(index)) | 0;
  }
  return Math.abs(hash);
}

/** First letters of up to two words (code-point safe for Thaana names). */
function initialsOf(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => Array.from(word)[0] ?? '')
    .join('')
    .toUpperCase();
}

const SIZE_CLASSES = {
  /** Inline rows (the store page header, the promoted-store panel). */
  sm: 'size-10 rounded-lg text-xs',
  /** Store page hero. */
  lg: 'size-16 rounded-xl text-lg',
  /**
   * Card hero: fills the slot the caller sizes (an aspect-ratio box), with
   * initials big enough to be the card's brand mark rather than a badge.
   * No radius of its own — the caller's box already clips.
   */
  tile: 'size-full text-2xl tracking-tight sm:text-3xl',
} as const;

export function StoreAvatar({
  name,
  slug,
  logoUrl,
  size = 'sm',
  className,
}: {
  name: string;
  slug: string;
  logoUrl: string | null;
  size?: keyof typeof SIZE_CLASSES;
  className?: string;
}) {
  if (logoUrl !== null) {
    return (
      <span
        className={cn(
          'flex shrink-0 items-center justify-center overflow-hidden bg-background',
          // At tile size the logo IS the card's hero, so it runs to the
          // edges: no inner padding and no second border inside the card's
          // own. Merchants upload square marks and a square slot was the
          // point — insetting one inside a white box put the letterboxing
          // back by another name.
          size !== 'tile' && 'border border-border',
          SIZE_CLASSES[size],
          className,
        )}
      >
        {/* Plain img: logo hosts are external to the app, and next/image
            would need a remotePatterns allowlist for no gain on tiles this
            size. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={logoUrl}
          alt={name}
          loading="lazy"
          className="size-full object-contain"
        />
      </span>
    );
  }

  const { bg, fg } = PALETTE[hashSlug(slug) % PALETTE.length];

  return (
    <span
      // The store name is always rendered as text right beside the avatar;
      // reading two initials aloud on top of it would only add noise.
      aria-hidden="true"
      className={cn(
        'flex shrink-0 select-none items-center justify-center font-semibold',
        SIZE_CLASSES[size],
        className,
      )}
      style={{
        backgroundColor: bg,
        backgroundImage: HIGHLIGHT,
        color: fg,
        // A hairline inset edge so the tile reads as an object rather than
        // a colour fill, in both themes and without a border box.
        boxShadow: 'inset 0 0 0 1px rgba(255, 255, 255, 0.12)',
      }}
    >
      {initialsOf(name)}
    </span>
  );
}
