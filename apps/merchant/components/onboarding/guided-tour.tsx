'use client';

import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
} from 'react';
import { usePathname } from 'next/navigation';
import { ArrowLeft, ArrowRight, Check, X } from 'lucide-react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { findTourAnchor } from './anchors';
import { TOUR_HOME, useTour } from './tour-provider';
import { tourById, type TourId } from './tours';

/**
 * THE GUIDED TOUR (owner, 2026-08-25): dim the dashboard, put a ring round
 * one real region of it, and anchor a bubble to that region explaining what
 * it is for.
 *
 * WHY THIS IS HAND-WRITTEN AND NOT A LIBRARY. The panel had no coachmark
 * primitive (checked: components/ui has dialog, popover, hover-card,
 * tooltip, sheet — all of them anchor to a TRIGGER they own, and every step
 * here anchors to an element rendered by a different file). The candidates
 * — driver.js, react-joyride, shepherd — are 25–60 kB of runtime for a
 * spotlight rectangle and a focus trap, and each one wants to own scroll,
 * body classes and z-index in a shell (demo1) that already owns all three.
 * The whole mechanism below is ~200 lines with no dependency added: a
 * `box-shadow` with a very large spread makes the dim, `getBoundingClientRect`
 * on an animation frame keeps the ring on the element while the page
 * scrolls or reflows, and the bubble is positioned from the same rect.
 *
 * THE RULE THAT SHAPES ALL OF IT: never trap anyone.
 *  - a step whose element is not on screen is SKIPPED, not pointed at. A
 *    cashier without `wallet.view` has no wallet card, a phone has no
 *    sidebar, and a card whose query is still loading is not there yet;
 *  - if NO step of a tour has a live element, the tour ends instead of
 *    dimming the screen over nothing;
 *  - Escape ends it from any step, so does the backdrop and the ✕. The
 *    ✕ and the bubble's "End tour" say the reader ANSWERED the offer and
 *    stop the server making it again (the till app's "Skip tour" does the
 *    same to the same shared field); Escape and a stray tap on the dim do
 *    not, exactly as the phone's back gesture does not;
 *  - Tab cycles inside the bubble and cannot wander into the dimmed page
 *    behind it, which is unreachable by mouse anyway;
 *  - focus goes back where it came from when it ends (see TourProvider).
 */

/** Breathing room between the highlighted element and the ring. */
const SPOTLIGHT_PADDING = 8;
/** Between the ring and the bubble. */
const BUBBLE_GAP = 14;
const BUBBLE_WIDTH = 330;
/** Never closer than this to the edge of the viewport. */
const EDGE = 10;
/** Below this the bubble stops trying to sit beside anything. */
const PHONE_WIDTH = 640;
/**
 * How long to wait for a region to appear before giving up on the whole
 * tour. Covers a route change to the dashboard plus the queries its cards
 * are waiting on; a second and a half of a dimmed screen with no ring would
 * read as a bug, and never appearing at all reads as nothing happened.
 */
const RESOLVE_TIMEOUT_MS = 3000;
/**
 * And how long to wait for the route change to the dashboard that a tour
 * started from another screen asks for. Longer, because this one is a real
 * navigation over a real connection.
 */
const NAVIGATION_TIMEOUT_MS = 8000;

interface Rect {
  top: number;
  left: number;
  width: number;
  height: number;
}

interface Frame {
  /** Index into `tour.steps` of the step actually being shown. */
  stepIndex: number;
  /** Where it is on screen, right now. */
  rect: Rect;
  /** Position among the steps that CAN be shown, 1-based, and how many. */
  position: number;
  total: number;
  /** True when nothing follows this step on this screen. */
  last: boolean;
  viewportWidth: number;
  viewportHeight: number;
}

export function GuidedTour() {
  const { activeTour, endTour } = useTour();

  if (activeTour === null) {
    return null;
  }

  // Keyed by tour id so switching tours rebuilds the overlay from step one
  // rather than carrying an index into a different list of steps.
  return <TourOverlay key={activeTour} tourId={activeTour} onEnd={endTour} />;
}

function TourOverlay({
  tourId,
  onEnd,
}: {
  tourId: TourId;
  onEnd: (reason: 'finished' | 'declined' | 'dismissed') => void;
}) {
  const { t } = useTranslation();
  const tour = tourById(tourId);
  const [frame, setFrame] = useState<Frame | null>(null);
  const [bubbleHeight, setBubbleHeight] = useState(0);
  const [mounted, setMounted] = useState(false);
  const bubbleRef = useRef<HTMLDivElement>(null);
  const primaryRef = useRef<HTMLButtonElement>(null);
  /**
   * The step the reader has walked TO. What is actually shown is the first
   * live step at or after it, so a region that never rendered costs a press
   * of Next rather than a dead end.
   */
  const cursor = useRef(0);
  /** Whether a step has been drawn yet — see the route guard below. */
  const walked = useRef(false);
  const pathname = usePathname();

  // Portals need a document; Next renders this component on the server too.
  useEffect(() => setMounted(true), []);

  const end = useCallback(
    (reason: 'finished' | 'declined' | 'dismissed') => onEnd(reason),
    [onEnd],
  );

  /**
   * One animation-frame loop owns everything positional: which step is
   * showable, where its element is, and how big the viewport is. Scroll,
   * resize, a sidebar collapsing, a card arriving late and an element
   * leaving the DOM are then all the same event, which is why there are no
   * scroll or resize listeners here. State is written only when something
   * actually changed, so a still page re-renders nothing.
   */
  useEffect(() => {
    if (tour === undefined) {
      end('dismissed');
      return;
    }

    /**
     * NOTHING TICKS UNTIL THE DASHBOARD IS THE PAGE. Two of the sidebar's
     * nav rows are anchors, and the sidebar is on every screen — so a tour
     * started from /wallet would find its LAST step live, open there, and
     * skip the four dashboard steps it exists to explain. Wait for the
     * route the provider pushed; give up if it never arrives, and leave at
     * once if the reader navigates away mid-tour (the browser's Back
     * button is the only way out that is not the ✕).
     */
    if (pathname !== TOUR_HOME) {
      if (walked.current) {
        end('dismissed');
        return;
      }
      const timer = window.setTimeout(
        () => end('dismissed'),
        NAVIGATION_TIMEOUT_MS,
      );
      return () => window.clearTimeout(timer);
    }

    let raf = 0;
    let signature = '';
    const startedAt = Date.now();

    const tick = () => {
      raf = window.requestAnimationFrame(tick);

      const live: number[] = [];
      const elements: (HTMLElement | null)[] = tour.steps.map((step, index) => {
        const element = findTourAnchor(step.anchor);
        if (element !== null) {
          live.push(index);
        }
        return element;
      });

      if (live.length === 0) {
        // Still painting, or this account can reach none of these regions.
        if (Date.now() - startedAt > RESOLVE_TIMEOUT_MS) {
          end('dismissed');
        }
        return;
      }

      const stepIndex = live.find((index) => index >= cursor.current);

      if (stepIndex === undefined) {
        // Walked past the last region there is: that is the end of the tour,
        // and reaching it is finishing it.
        end('finished');
        return;
      }

      const element = elements[stepIndex];
      if (element === null) {
        return;
      }

      const box = element.getBoundingClientRect();
      const next: Frame = {
        stepIndex,
        rect: {
          top: box.top - SPOTLIGHT_PADDING,
          left: box.left - SPOTLIGHT_PADDING,
          width: box.width + SPOTLIGHT_PADDING * 2,
          height: box.height + SPOTLIGHT_PADDING * 2,
        },
        position: live.indexOf(stepIndex) + 1,
        total: live.length,
        last: live[live.length - 1] === stepIndex,
        viewportWidth: window.innerWidth,
        viewportHeight: window.innerHeight,
      };

      const nextSignature = JSON.stringify(next);
      if (nextSignature !== signature) {
        signature = nextSignature;
        walked.current = true;
        setFrame(next);
      }
    };

    raf = window.requestAnimationFrame(tick);

    return () => window.cancelAnimationFrame(raf);
  }, [tour, end, pathname]);

  const shownStep = frame?.stepIndex ?? null;
  const bubbleMounted = frame !== null;

  /**
   * Bring the region into view and put the keyboard on the bubble's main
   * button, once per step. `preventScroll` on the focus call matters: the
   * browser would otherwise scroll the bubble into view and undo the
   * centring we just asked for, which on a phone leaves the ring off screen.
   */
  useEffect(() => {
    if (tour === undefined || shownStep === null) {
      return;
    }

    const element = findTourAnchor(tour.steps[shownStep].anchor);
    element?.scrollIntoView({ block: 'center', inline: 'nearest' });
    primaryRef.current?.focus({ preventScroll: true });
  }, [tour, shownStep]);

  // The bubble's own height decides whether it fits below the ring, so it
  // has to be measured rather than guessed — the body is two to four lines
  // depending on the language and the width.
  useLayoutEffect(() => {
    const bubble = bubbleRef.current;
    if (bubble === null) {
      return;
    }

    const observer = new ResizeObserver(() => {
      setBubbleHeight(bubble.getBoundingClientRect().height);
    });
    observer.observe(bubble);
    setBubbleHeight(bubble.getBoundingClientRect().height);

    return () => observer.disconnect();
  }, [bubbleMounted]);

  const goBack = useCallback(() => {
    if (tour === undefined || frame === null) {
      return;
    }
    // The previous LIVE step, not the previous defined one.
    for (let index = frame.stepIndex - 1; index >= 0; index -= 1) {
      // Only the cursor moves. The frame loop is what redraws, so there is
      // no second path that could disagree with it about which step is up.
      if (findTourAnchor(tour.steps[index].anchor) !== null) {
        cursor.current = index;
        return;
      }
    }
  }, [tour, frame]);

  const goNext = useCallback(() => {
    if (frame === null) {
      return;
    }
    if (frame.last) {
      end('finished');
      return;
    }
    cursor.current = frame.stepIndex + 1;
  }, [frame, end]);

  /**
   * Escape ends it from anywhere, and Tab is kept inside the bubble — from
   * outside it too, because clicking the dim area moves focus to <body> and
   * the next Tab would otherwise walk into a page the reader cannot see.
   */
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        // The keyboard's version of the phone's back gesture, and read the
        // same way: a way out, not an answer. The ✕ two Tabs away is the
        // answer, and it is inside the trap with everything else.
        end('dismissed');
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const bubble = bubbleRef.current;
      if (bubble === null) {
        return;
      }

      const focusable = Array.from(
        bubble.querySelectorAll<HTMLElement>(
          'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
        ),
      );

      if (focusable.length === 0) {
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;

      if (active === null || !bubble.contains(active)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus({ preventScroll: true });
        return;
      }

      if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus({ preventScroll: true });
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus({ preventScroll: true });
      }
    };

    window.addEventListener('keydown', onKeyDown, true);
    return () => window.removeEventListener('keydown', onKeyDown, true);
  }, [end]);

  if (!mounted || tour === undefined || frame === null) {
    return null;
  }

  const step = tour.steps[frame.stepIndex];
  const phone = frame.viewportWidth < PHONE_WIDTH;
  const bubble = placeBubble(frame, bubbleHeight, phone);

  return createPortal(
    <div className="fixed inset-0 z-[60]" data-slot="guided-tour">
      {/* Swallows every click on the page beneath — a tour that let someone
          press a dimmed button would be teaching one thing and doing
          another — and ends the tour, which is what a tap outside a
          coachmark means everywhere else. */}
      <button
        type="button"
        // 'dismissed', not 'declined': an accidental tap beside the bubble
        // must not spend the one walkthrough this person is offered.
        // Out of the tab order deliberately: the keyboard route out is
        // Escape and the two controls in the bubble, and a full-screen tab
        // stop between them would be a stop on nothing.
        tabIndex={-1}
        aria-hidden="true"
        className="absolute inset-0 cursor-default"
        onClick={() => end('dismissed')}
      />

      {/* The ring, and the dim: one very large spread shadow is the whole
          scrim, so there is no second layer to keep in step with it. */}
      <div
        aria-hidden="true"
        // No transition on the geometry ON PURPOSE: the rect is recomputed
        // every animation frame, so an easing curve would leave the ring
        // trailing behind its own element the whole time the page scrolls.
        className="pointer-events-none absolute rounded-xl ring-2 ring-primary shadow-[0_0_0_9999px_rgba(9,9,11,0.66)]"
        style={{
          top: frame.rect.top,
          left: frame.rect.left,
          width: frame.rect.width,
          height: frame.rect.height,
        }}
      />

      <div
        ref={bubbleRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="guided-tour-title"
        aria-describedby="guided-tour-body"
        className={cn(
          // overflow-y-auto pairs with the maxHeight below: on a short phone
          // in Dhivehi the body can run past the screen, and a bubble whose
          // buttons are off the bottom is the trap this whole file avoids.
          'absolute flex flex-col gap-3 overflow-y-auto rounded-xl border border-border bg-background p-4 shadow-xl',
          bubbleHeight === 0 && 'invisible',
        )}
        style={{
          top: bubble.top,
          left: bubble.left,
          width: phone ? frame.viewportWidth - EDGE * 2 : BUBBLE_WIDTH,
          maxHeight: frame.viewportHeight - EDGE * 2,
        }}
      >
        {/* shrink-0 on all three: the wrapper is a scrolling flex column,
            and without it the children would squash to fit instead of
            letting the bubble scroll. */}
        <div className="flex shrink-0 items-start justify-between gap-2">
          <div className="flex flex-col gap-0.5">
            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {t('onboarding.tour.progress', {
                current: frame.position,
                total: frame.total,
              })}
            </span>
            <h2
              id="guided-tour-title"
              className="text-sm font-semibold text-mono"
            >
              {t(`${step.i18nKey}.title`)}
            </h2>
          </div>
          <Button
            variant="ghost"
            mode="icon"
            size="sm"
            aria-label={t('onboarding.tour.end')}
            className="-me-1 -mt-1 size-7 shrink-0"
            onClick={() => end('declined')}
          >
            <X className="size-4!" />
          </Button>
        </div>

        <p
          id="guided-tour-body"
          className="shrink-0 text-sm leading-relaxed text-secondary-foreground"
        >
          {t(`${step.i18nKey}.body`)}
        </p>

        <div className="flex shrink-0 items-center justify-between gap-2 pt-0.5">
          {/* Skippable at EVERY step, in the same place every step, and it
              says what it does rather than "Got it". */}
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground"
            onClick={() => end('declined')}
          >
            {t('onboarding.tour.end')}
          </Button>
          <div className="flex items-center gap-2">
            {frame.position > 1 && (
              <Button variant="outline" size="sm" onClick={goBack}>
                <ArrowLeft className="rtl:rotate-180" />
                {t('common.back')}
              </Button>
            )}
            <Button ref={primaryRef} size="sm" onClick={goNext}>
              {frame.last ? (
                <>
                  <Check />
                  {t('onboarding.tour.done')}
                </>
              ) : (
                <>
                  {t('onboarding.tour.next')}
                  <ArrowRight className="rtl:rotate-180" />
                </>
              )}
            </Button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}

/**
 * Where the bubble goes, in viewport pixels.
 *
 * Below the ring, else above it, else beside it, else clamped into the
 * viewport — in that order, because reading order runs downwards and a
 * bubble above its subject makes the eye travel twice. On a phone it stops
 * negotiating and takes the full width at whichever END of the screen the
 * ring is not, which is the only arrangement that reliably shows both.
 *
 * Left/top are computed from a rect that is already visual, so this is
 * correct in RTL without a mirror case.
 */
function placeBubble(
  frame: Frame,
  bubbleHeight: number,
  phone: boolean,
): { top: number; left: number } {
  const { rect, viewportWidth, viewportHeight } = frame;

  if (phone) {
    const ringMiddle = rect.top + rect.height / 2;
    const top =
      ringMiddle < viewportHeight * 0.55
        ? viewportHeight - bubbleHeight - EDGE
        : EDGE;

    return { left: EDGE, top: Math.max(EDGE, top) };
  }

  const clampLeft = (value: number) =>
    Math.min(
      Math.max(value, EDGE),
      Math.max(EDGE, viewportWidth - BUBBLE_WIDTH - EDGE),
    );
  const clampTop = (value: number) =>
    Math.min(
      Math.max(value, EDGE),
      Math.max(EDGE, viewportHeight - bubbleHeight - EDGE),
    );

  const centred = clampLeft(rect.left + rect.width / 2 - BUBBLE_WIDTH / 2);
  const below = rect.top + rect.height + BUBBLE_GAP;
  if (below + bubbleHeight <= viewportHeight - EDGE) {
    return { top: below, left: centred };
  }

  const above = rect.top - BUBBLE_GAP - bubbleHeight;
  if (above >= EDGE) {
    return { top: above, left: centred };
  }

  const beside = clampTop(rect.top + rect.height / 2 - bubbleHeight / 2);
  const toTheRight = rect.left + rect.width + BUBBLE_GAP;
  if (toTheRight + BUBBLE_WIDTH <= viewportWidth - EDGE) {
    return { top: beside, left: toTheRight };
  }

  const toTheLeft = rect.left - BUBBLE_GAP - BUBBLE_WIDTH;
  if (toTheLeft >= EDGE) {
    return { top: beside, left: toTheLeft };
  }

  return { top: clampTop(rect.top), left: centred };
}
