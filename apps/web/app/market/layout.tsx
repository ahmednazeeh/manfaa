'use client';

import { ReactNode } from 'react';
import Link from 'next/link';
import { useMe } from '@/lib/queries';
import { Button } from '@/components/ui/button';

/**
 * The shop front — PUBLIC, and deliberately not the dashboard.
 *
 * The Market used to live inside the authenticated app shell, which meant a
 * customer had to sign in before they could see a single shop, and what they
 * finally reached wore a sidebar and looked like an account screen rather
 * than somewhere to buy things (owner report 2026-08-19).
 *
 * Nobody signs in to find out whether a marketplace is worth signing in for.
 * The catalogue endpoints were already public — it was only this wrapper
 * that locked the door. Signing in is still required to have a cart, and
 * that ask comes at the point it means something.
 */
export default function MarketLayout({ children }: { children: ReactNode }) {
  // Not a guard — nobody is turned away. Just whether to offer the way in.
  const { data: me } = useMe();

  return (
    // `grow w-full` is load-bearing: the root <body> is itself a ROW flex
    // container, so a child without them collapses to its content width and
    // the whole shop ends up in a corner. The authenticated shell gets this
    // from its own `flex grow` wrapper; a page outside it must say so.
    <div className="flex min-h-full w-full grow flex-col bg-background">
      <header className="sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur">
        <div className="mx-auto flex w-full max-w-5xl items-center gap-3 px-4 py-3">
          <Link href="/market" className="text-lg font-semibold">
            Manfaa
          </Link>

          <div className="ms-auto flex items-center gap-2">
            {me ? (
              <Button variant="ghost" size="sm" asChild>
                <Link href="/dashboard">My account</Link>
              </Button>
            ) : (
              <>
                <Button variant="ghost" size="sm" asChild>
                  <Link href="/login">Sign in</Link>
                </Button>
                <Button size="sm" asChild>
                  <Link href="/signup">Join Manfaa</Link>
                </Button>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-5xl grow px-4 py-5 pb-28">
        {children}
      </main>
    </div>
  );
}
