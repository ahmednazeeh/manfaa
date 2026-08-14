import { ReactNode } from 'react';

/**
 * Pass-through layout whose only job is to give the /store/[slug] segment
 * its own boundary, so the sibling not-found.tsx (the friendly "store isn't
 * on Manfaa" card) is what renders — with a real 404 status — when page.tsx
 * calls notFound(). Without a segment layout, notFound() bubbles to the root
 * and lands on Next's unbranded default 404.
 */
export default function StoreSlugLayout({ children }: { children: ReactNode }) {
  return children;
}
