'use client';

import { useEffect, useState } from 'react';

/**
 * Applies the superadmin-chosen storefront accent (GET /api/theme).
 *
 * The admin picks ONE colour; this derives the whole token set from its
 * HUE and CHROMA with the same lightness-governed recipe the hand-tuned
 * teal (and briefly coral) used — lightness is pinned at the values that
 * cleared AA on white and on the dark ground, so no picked colour can make
 * text unreadable. Null (nothing picked) injects nothing: the stylesheet's
 * built-in lagoon stays byte-identical.
 *
 * sessionStorage remembers the last answer so repeat navigations restyle
 * before first paint; only a first-ever visit briefly shows the built-in
 * hue while the fetch is in flight.
 */

const STORAGE_KEY = 'manfaa-brand';

/** sRGB hex → OKLCH (Björn Ottosson's OKLab). Returns hue° and chroma. */
function hexToHueChroma(hex: string): { hue: number; chroma: number } | null {
  const m = /^#([0-9a-f]{6})$/i.exec(hex);
  if (!m) return null;

  const n = parseInt(m[1], 16);
  const expand = (v: number) => {
    const c = v / 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  const r = expand((n >> 16) & 255);
  const g = expand((n >> 8) & 255);
  const b = expand(n & 255);

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const mm = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  const a = 1.9779984951 * l - 2.428592205 * mm + 0.4505937099 * s;
  const bb = 0.0259040371 * l + 0.782771766 * mm - 0.808675766 * s;

  const chroma = Math.sqrt(a * a + bb * bb);
  const hue = ((Math.atan2(bb, a) * 180) / Math.PI + 360) % 360;

  return { hue, chroma };
}

/** The tuning recipe, generalized: L pinned, C capped, hue carried. */
function cssFor(hex: string): string | null {
  const okl = hexToHueChroma(hex);
  if (!okl) return null;

  const h = okl.hue.toFixed(1);
  const light = Math.min(okl.chroma, 0.22).toFixed(3);
  const dark = Math.min(okl.chroma, 0.15).toFixed(3);
  const softC = Math.min(okl.chroma * 0.2, 0.03).toFixed(3);

  return [
    `:root{--brand:oklch(0.48 ${light} ${h});`,
    `--brand-foreground:oklch(0.99 0.01 ${h});`,
    `--brand-soft:oklch(0.96 ${softC} ${h});}`,
    `.dark{--brand:oklch(0.78 ${dark} ${h});`,
    `--brand-foreground:oklch(0.22 0.04 ${h});`,
    `--brand-soft:oklch(0.27 0.045 ${h});}`,
  ].join('');
}

export function BrandThemeApplier() {
  const [css, setCss] = useState<string | null>(() => {
    if (typeof window === 'undefined') return null;
    try {
      const cached = sessionStorage.getItem(STORAGE_KEY);
      return cached ? cssFor(cached) : null;
    } catch {
      return null;
    }
  });

  useEffect(() => {
    const controller = new AbortController();

    fetch('/api/theme', {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((body: { data?: { brand?: string | null } } | null) => {
        const brand = body?.data?.brand ?? null;
        try {
          if (brand) {
            sessionStorage.setItem(STORAGE_KEY, brand);
          } else {
            sessionStorage.removeItem(STORAGE_KEY);
          }
        } catch {
          // Private mode — the fetch still styles this page view.
        }
        setCss(brand ? cssFor(brand) : null);
      })
      .catch(() => {
        // Offline or refused: the built-in hue is always correct.
      });

    return () => controller.abort();
  }, []);

  if (!css) return null;

  return <style id="brand-override">{css}</style>;
}
