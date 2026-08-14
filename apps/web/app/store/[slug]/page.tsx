import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { formatRateBp } from '@/lib/format';
import { fetchStoreDetail } from '@/lib/server-api';
import StorePageClient from './store-page-client';

/**
 * Server shell for the PUBLIC store page. A storefront page has to carry its
 * content in the HTML — link unfurls, search engines and no-JS clients never
 * run the client query — so the store is fetched here (60s-cached, see
 * lib/server-api.ts) and handed to the client component as initial data.
 * Unknown and unlisted slugs 404 for real (HTTP status, not just a
 * client-side card); upstream trouble degrades to the pre-SSR behaviour of
 * client-side fetching rather than minting a false 404.
 */

interface StorePageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({
  params,
}: StorePageProps): Promise<Metadata> {
  const { slug } = await params;
  // Next dedupes this fetch with the page's own (same URL, same 60s cache).
  const result = await fetchStoreDetail(slug);
  if (result.kind !== 'found') {
    return {};
  }
  const { store } = result;
  return {
    // Layout template appends "| Manfaa". Integer divide/mod formatting only.
    title: `${store.name} — ${formatRateBp(store.rate_bp)} cashback`,
    description: `Earn ${formatRateBp(store.rate_bp)} cashback in MVR at ${store.name} with Manfaa.`,
  };
}

export default async function StorePage({ params }: StorePageProps) {
  const { slug } = await params;
  const result = await fetchStoreDetail(slug);

  if (result.kind === 'not-found') {
    notFound();
  }

  return (
    <StorePageClient
      slug={slug}
      initialStore={result.kind === 'found' ? result.store : undefined}
    />
  );
}
