"use client";

/**
 * The platform's own logo, wherever a surface needs one.
 *
 * Points at /api/brand/{slot}, which is served same-origin on every app host
 * and ALWAYS answers an image — the packaged default until a superadmin
 * uploads a replacement. That guarantee is why there is no loading state, no
 * "has a brand been set?" query and no per-app fallback: the mark simply
 * renders, and a new upload appears on the next page load without rebuilding
 * anything.
 *
 * Light and dark are two <img> elements rather than a themed `src`, because
 * a themed src cannot be resolved during the first render and the logo would
 * visibly swap after hydration. The two utilities used — `dark:hidden` and
 * `hidden dark:block` — are emitted by both consuming apps' own source, so
 * they survive Tailwind's scan even though it does not scan this package.
 */
export type BrandMarkShape = "landscape" | "square";

export interface BrandMarkProps {
  /** `landscape` for headers, `square` where a wordmark would be too wide. */
  shape?: BrandMarkShape;
  /** Sizing and layout, from the consuming app. Height is what you want. */
  className?: string;
  /**
   * Empty by default: the mark almost always sits beside the product name,
   * where announcing it again is noise. Pass a name where it stands alone.
   */
  alt?: string;
}

export function BrandMark({
  shape = "landscape",
  className,
  alt = "",
}: BrandMarkProps) {
  const base = `/api/brand/${shape}`;
  const decorative = alt === "";

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element -- served by our API, not a build asset */}
      <img
        src={`${base}_light`}
        alt={alt}
        aria-hidden={decorative || undefined}
        className={className ? `${className} dark:hidden` : "dark:hidden"}
      />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={`${base}_dark`}
        alt={alt}
        aria-hidden={decorative || undefined}
        className={
          className ? `${className} hidden dark:block` : "hidden dark:block"
        }
      />
    </>
  );
}
