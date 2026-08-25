'use client';

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useCompleteOnboardingTour } from '@/lib/queries';
import type { TourId } from './tours';

/**
 * The screen the tours are about. Both walk the dashboard's own regions, so
 * starting one from anywhere else means going there first — a highlight
 * pointing at a card that is not on this screen is the trap the brief said
 * never to build.
 */
export const TOUR_HOME = '/dashboard';

interface TourContextValue {
  /** The tour being walked, or null. */
  activeTour: TourId | null;
  /**
   * Start (or restart) a tour. Navigates to the dashboard first when the
   * reader is somewhere else; the overlay waits for the regions to exist
   * rather than assuming the route change already painted.
   */
  startTour: (id: TourId) => void;
  /**
   * End it, and say what the reader did:
   *
   *  - `finished` — walked to the last step;
   *  - `declined` — pressed the bubble's own "End tour" control or its ✕.
   *    An answer to the offer, and treated as one;
   *  - `dismissed` — a stray tap on the backdrop, or Escape. NOT an answer.
   *
   * The first two stop the server offering the walkthrough; the third
   * leaves the account alone. This is deliberately the SAME rule the till
   * app applies to the one shared field both surfaces write
   * (`onboarding_tour_completed_at`): its "Skip tour" button answers, its
   * system back gesture — the phone's equivalent of a stray tap — does not.
   */
  endTour: (reason: 'finished' | 'declined' | 'dismissed') => void;
}

const TourContext = createContext<TourContextValue | null>(null);

export function useTour(): TourContextValue {
  const context = useContext(TourContext);
  if (context === null) {
    throw new Error('useTour must be used within a TourProvider');
  }
  return context;
}

export function TourProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [activeTour, setActiveTour] = useState<TourId | null>(null);
  // Destructured because react-query keeps `mutate` referentially stable
  // while the result object it hangs off is new on every render — depending
  // on the object would rebuild endTour, and the context value with it, on
  // every render of the panel.
  const { mutate: markTourCompleted } = useCompleteOnboardingTour();
  /**
   * Whatever had the keyboard when the tour was asked for — the tasklist's
   * own button, usually. Focus goes back there when it ends, because a tour
   * that leaves focus on <body> hands a keyboard user back to the top of
   * the document and makes them walk the whole page again.
   */
  const returnFocusTo = useRef<HTMLElement | null>(null);

  const startTour = useCallback(
    (id: TourId) => {
      const active = document.activeElement;
      returnFocusTo.current =
        active instanceof HTMLElement && active !== document.body
          ? active
          : null;

      if (pathname !== TOUR_HOME) {
        router.push(TOUR_HOME);
      }

      setActiveTour(id);
    },
    [pathname, router],
  );

  const endTour = useCallback(
    (reason: 'finished' | 'declined' | 'dismissed') => {
      setActiveTour(null);

      if (reason !== 'dismissed') {
        // Fire and forget: the walkthrough is over either way, and a failed
        // POST costs the reader nothing worse than being offered the tour
        // again on their next visit.
        markTourCompleted();
      }

      const target = returnFocusTo.current;
      returnFocusTo.current = null;

      if (target !== null && target.isConnected) {
        target.focus();
      }
    },
    [markTourCompleted],
  );

  const value = useMemo(
    () => ({ activeTour, startTour, endTour }),
    [activeTour, startTour, endTour],
  );

  return <TourContext.Provider value={value}>{children}</TourContext.Provider>;
}
