'use client';

import { PublicFooter, PublicHeader } from '@/components/app/public-header';
import { StoreNotFound } from './store-page-client';

/**
 * 404 boundary for /store/[slug] — rendered (with a real 404 status) when
 * the slug is unknown OR the merchant is not publicly listed; the two are
 * deliberately indistinguishable. Same friendly card the client shows if a
 * store it already loaded later drops off the storefront.
 */
export default function StoreSlugNotFound() {
  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />
      <main className="container flex grow flex-col py-8 lg:py-10">
        <StoreNotFound />
      </main>
      <PublicFooter />
    </div>
  );
}
