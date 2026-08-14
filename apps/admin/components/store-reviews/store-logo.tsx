import { cn } from '@/lib/utils';

const SIZE_CLASSES = {
  /** Table thumbnail. */
  sm: 'size-9 rounded-md text-xs',
  /** Review-sheet preview. */
  lg: 'size-24 rounded-xl text-xl',
} as const;

/** Deterministic initials fallback, mirroring the public storefront tiles. */
function initials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0]!.toUpperCase())
      .join('') || '?'
  );
}

/**
 * The store's uploaded logo, or an initials tile while the wizard's logo
 * step is still empty. Plain <img>: logos are served by the API host and
 * next/image would need a remotePatterns allowlist for no gain here.
 */
export function StoreLogo({
  name,
  logoUrl,
  size = 'sm',
  className,
}: {
  name: string;
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
        <img
          src={logoUrl}
          alt={name}
          loading="lazy"
          className="size-full object-contain"
        />
      </span>
    );
  }

  return (
    <span
      className={cn(
        'flex shrink-0 items-center justify-center border border-border bg-muted font-semibold text-muted-foreground',
        SIZE_CLASSES[size],
        className,
      )}
      aria-hidden="true"
    >
      {initials(name)}
    </span>
  );
}
