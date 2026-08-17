import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { MerchantLanding } from '@/components/marketing/landing';

/**
 * The root fork (PLAN §WL): a session cookie means a merchant at work —
 * straight to the dashboard exactly as before (the app layout still owns
 * real auth; an expired cookie bounces through /login there). No cookie
 * means a visitor — they get the landing instead of a bare login wall,
 * because this subdomain is the address that gets said aloud and printed.
 *
 * Cookie PRESENCE only, deliberately: validating the session here would
 * cost an API round-trip on every anonymous page view for a question the
 * dashboard's own guard already answers.
 */
export default async function HomePage() {
  const jar = await cookies();

  if (jar.has('manfaa-session')) {
    redirect('/dashboard');
  }

  return <MerchantLanding />;
}
