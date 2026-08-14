import { Suspense } from 'react';
import { LoadingBlock } from '@/components/app/async-states';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';
import DiscoverClient from './discover-client';

/**
 * PUBLIC discovery page — the app's single all-in-one directory (the former
 * /stores page 308-redirects here, filters intact; see next.config.mjs).
 * The client component reads q/category/page from useSearchParams, which
 * requires a Suspense boundary for the static prerender of this route; the
 * fallback carries the same chrome so a direct load paints the page frame
 * immediately instead of a blank document.
 */
export default function DiscoverPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen w-full flex-col bg-background">
          <PublicHeader />
          <main className="container grow py-8 lg:py-10">
            <LoadingBlock lines={6} />
          </main>
          <PublicFooter />
        </div>
      }
    >
      <DiscoverClient />
    </Suspense>
  );
}
