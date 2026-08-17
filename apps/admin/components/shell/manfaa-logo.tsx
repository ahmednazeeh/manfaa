import { cn } from '@/lib/utils';

/**
 * The Manfaa letter logo, drawn inline so no binary asset ships: a coral
 * twin-peak "M" — one stroked path with round caps, a rounded W flipped —
 * beside the wordmark in bold navy. The navy flips to the foreground colour
 * in dark mode; the coral mark is the constant. The favicon
 * (app/favicon.ico) is this same mark rasterised.
 */

export const MANFAA_CORAL = '#F4626B';

/** The mark alone — for tight spots (a collapsed rail, a favicon preview). */
export function ManfaaMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 32 32"
      fill="none"
      aria-hidden="true"
      className={className}
    >
      <path
        d="M4.5 26 L10.25 7 L16 21.5 L21.75 7 L27.5 26"
        stroke={MANFAA_CORAL}
        strokeWidth="4.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/** Mark + wordmark, sized to sit in a 64px header row like the old text. */
export function ManfaaLogo({ className }: { className?: string }) {
  return (
    <span className={cn('flex items-center gap-2', className)}>
      <ManfaaMark className="size-6 shrink-0" />
      <span className="text-lg font-bold tracking-tight text-[#1E2A4A] dark:text-foreground">
        Manfaa
      </span>
    </span>
  );
}
