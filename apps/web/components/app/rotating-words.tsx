'use client';

import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * The headline's changing phrase — "…when you **shop in store** / **shop
 * online** / **dine in** / **book services**".
 *
 * Only ONE phrase is ever visible. A crossfade between two runs of heading
 * text stacked on each other reads as a smudge rather than a transition, so
 * the cycle is strictly sequential: fade the current phrase out, swap the
 * text while nothing is on screen, fade the next one in.
 *
 * The box still cannot move, because a headline that reflows every few
 * seconds is worse than no animation at all: every phrase is rendered
 * invisibly in the same grid cell, so the element is permanently as wide and
 * as tall as the LONGEST phrase and the visible copy is painted over that
 * reserved space.
 *
 * Assistive tech gets one visually-hidden node with the whole list, and the
 * animated copy is aria-hidden — a phrase that keeps changing underneath a
 * screen reader is noise, not information.
 *
 * Under `prefers-reduced-motion` the cycle never starts: the first phrase
 * stays put, and the hidden list still carries the rest.
 */

/** How long the phrase is fully visible before it starts leaving. */
const HOLD_MS = 2400;

/** Fade length. Must match the duration class below. */
const FADE_MS = 300;

export function RotatingWords({
  words,
  className,
}: {
  words: string[];
  className?: string;
}) {
  const [index, setIndex] = useState(0);
  const [shown, setShown] = useState(true);

  useEffect(() => {
    if (words.length < 2) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    let swap: number | undefined;

    const cycle = window.setInterval(() => {
      setShown(false);
      swap = window.setTimeout(() => {
        setIndex((current) => (current + 1) % words.length);
        setShown(true);
      }, FADE_MS);
    }, HOLD_MS + FADE_MS);

    return () => {
      window.clearInterval(cycle);
      if (swap !== undefined) window.clearTimeout(swap);
    };
  }, [words.length]);

  return (
    // The caller's classes come first so a display utility from a call
    // site cannot win the merge and collapse the grid — which silently
    // turns the stacked phrases into a tall column of blank lines.
    <span className={cn(className, 'relative inline-grid text-start')}>
      {/* Invisible, unanimated, and never removed: this is what holds the
          box open at the width of the longest phrase. */}
      {words.map((word) => (
        <span
          key={word}
          aria-hidden="true"
          className="invisible col-start-1 row-start-1"
        >
          {word}
        </span>
      ))}

      <span
        aria-hidden="true"
        className={cn(
          'col-start-1 row-start-1 transition-[opacity,transform] duration-300 ease-out motion-reduce:transition-none',
          shown ? 'translate-y-0 opacity-100' : '-translate-y-1 opacity-0',
        )}
      >
        {words[index]}
      </span>

      <span className="sr-only">{words.join(', ')}</span>
    </span>
  );
}
