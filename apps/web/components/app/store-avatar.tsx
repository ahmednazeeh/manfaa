import { cn } from '@/lib/utils';

/**
 * Store avatar shared by every merchant surface (cards, grids, the store
 * page hero). Renders the uploaded logo when one exists; otherwise a
 * deterministic initials tile — the palette index is a plain integer hash
 * of the slug, so a store keeps its colour across pages, sessions and
 * users with no external libraries involved.
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
  /** Card rows. */
  sm: 'size-10 rounded-lg text-xs',
  /** Store page hero. */
  lg: 'size-16 rounded-xl text-lg',
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
          'flex shrink-0 items-center justify-center overflow-hidden border border-border bg-background',
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
      style={{ backgroundColor: bg, color: fg }}
    >
      {initialsOf(name)}
    </span>
  );
}
