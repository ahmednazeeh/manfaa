'use client';

import { useEffect } from 'react';

export type Direction = 'ltr' | 'rtl';

/**
 * Languages written right-to-left. Dhivehi (Thaana script) is the one we
 * ship; the set exists so a future RTL locale is a one-line change.
 */
const RTL_LANGUAGES: ReadonlySet<string> = new Set(['dv']);

/** Writing direction for a BCP-47 language tag: 'dv' (and 'dv-MV') -> 'rtl'. */
export function getDirection(language: string): Direction {
  const base = language.toLowerCase().split('-')[0];
  return RTL_LANGUAGES.has(base) ? 'rtl' : 'ltr';
}

/**
 * Resolves the writing direction for the active language and keeps
 * `<html dir="...">` in sync with it, so logical CSS properties
 * (padding-inline-start, ...) mirror the whole app when Dhivehi is active.
 *
 * The attribute is applied in an effect: server markup ships the default
 * direction and the persisted choice takes over after hydration, which is
 * why the root <html> carries suppressHydrationWarning.
 */
export function useDirection(language: string): Direction {
  const direction = getDirection(language);

  useEffect(() => {
    document.documentElement.setAttribute('dir', direction);
  }, [direction]);

  return direction;
}
