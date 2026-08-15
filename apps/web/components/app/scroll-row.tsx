'use client';

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/**
 * A horizontally scrollable row — the storefront's category rail and every
 * landing shelf are built on it.
 *
 * The chevrons appear ONLY when the content actually overflows, and each
 * one disappears at its own end of the travel. With a single store on the
 * platform there is nothing to scroll, and a permanently dead arrow is the
 * exact thing that makes a sparse page read as broken.
 *
 * Keyboard: every item in a row is a link, so Tab walks the row and the
 * browser scrolls each focused item into view; the chevrons are real
 * buttons and are reachable the same way. The scroller itself therefore
 * takes no tab stop of its own — it is never a scroll-only region.
 *
 * RTL: in a `dir="rtl"` scroller `scrollLeft` runs 0 → -max, so the travel
 * direction is read from the computed style and every end test uses the
 * ABSOLUTE offset. The buttons are labelled back/forward rather than
 * left/right, and their glyphs mirror with rtl:rotate-180.
 */

/** Hides the scrollbar without hiding the overflow — the chevrons and the
 *  swipe gesture are the affordances. */
const HIDE_SCROLLBAR =
  '[scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden';

const CHEVRON_BUTTON =
  'absolute top-1/2 z-10 hidden size-9 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background text-foreground shadow-sm transition-colors hover:bg-muted md:flex';

export function ScrollRow({
  label,
  className,
  children,
}: {
  /** Accessible name of the row (its own heading, usually). */
  label: string;
  className?: string;
  /** `<li>` items — the caller owns each item's width and snap alignment. */
  children: ReactNode;
}) {
  const { t } = useTranslation();
  const scroller = useRef<HTMLUListElement>(null);
  const [travel, setTravel] = useState({ back: false, forward: false });

  const measure = useCallback(() => {
    const element = scroller.current;

    if (element === null) {
      return;
    }

    // Absolute offset: LTR scrolls 0 → max, RTL scrolls 0 → -max.
    const offset = Math.abs(element.scrollLeft);
    const max = element.scrollWidth - element.clientWidth;
    // 1px of slack — fractional layout widths make an exact comparison
    // flip the chevrons on and off as the row settles.
    const back = max > 1 && offset > 1;
    const forward = max > 1 && offset < max - 1;

    setTravel((current) =>
      current.back === back && current.forward === forward
        ? current // same value, same object: never re-render on a no-op.
        : { back, forward },
    );
  }, []);

  // Deliberately dependency-free: it runs after every render, which is what
  // catches a shelf whose entries changed. The equality guard inside
  // measure() is what stops that from looping.
  useEffect(measure);

  useEffect(() => {
    const element = scroller.current;

    if (element === null || typeof ResizeObserver === 'undefined') {
      return;
    }

    // Covers viewport resizes and the container reflowing around them; a
    // content change is covered by the render-time measure above.
    const observer = new ResizeObserver(measure);
    observer.observe(element);

    return () => observer.disconnect();
  }, [measure]);

  const step = (direction: 1 | -1) => {
    const element = scroller.current;

    if (element === null) {
      return;
    }

    const sign =
      window.getComputedStyle(element).direction === 'rtl' ? -1 : 1;

    element.scrollBy({
      left: sign * direction * Math.max(element.clientWidth * 0.8, 160),
      behavior: 'smooth',
    });
  };

  return (
    <div className="relative">
      <ul
        ref={scroller}
        // Preflight strips list semantics in Safari; role="list" restores them.
        role="list"
        aria-label={label}
        onScroll={measure}
        className={cn(
          'flex snap-x items-stretch gap-4 overflow-x-auto scroll-smooth pb-1',
          HIDE_SCROLLBAR,
          className,
        )}
      >
        {children}
      </ul>

      {travel.back && (
        <button
          type="button"
          onClick={() => step(-1)}
          aria-label={t('common.scrollBack')}
          className={cn(CHEVRON_BUTTON, 'start-1')}
        >
          <ChevronLeft className="size-4 rtl:rotate-180" />
        </button>
      )}

      {travel.forward && (
        <button
          type="button"
          onClick={() => step(1)}
          aria-label={t('common.scrollForward')}
          className={cn(CHEVRON_BUTTON, 'end-1')}
        >
          <ChevronRight className="size-4 rtl:rotate-180" />
        </button>
      )}
    </div>
  );
}
