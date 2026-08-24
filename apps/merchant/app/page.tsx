import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { MerchantLanding } from '@/components/marketing/landing';

/**
 * The root fork (PLAN §WL): a signed-in merchant goes straight to the
 * dashboard (the app layout still owns real auth; a stale marker bounces
 * through /login there). Anyone else gets the landing, because this
 * subdomain is the address that gets said aloud and printed.
 *
 * The fork reads `manfaa-auth`, NOT the session cookie. manfaa-sid only
 * proves a session EXISTS — /sanctum/csrf-cookie mints one for anybody
 * who ever opened the login form — and forking on it trapped anonymous
 * visitors in / -> /dashboard -> /login for the cookie's whole 8h life
 * (owner report, 2026-08-24). manfaa-auth is set exclusively by a real
 * login (and refreshed by every authenticated /me), cleared on logout,
 * so PRESENCE here finally means "someone signed in". Still presence
 * only: validating the session would cost an API round-trip on every
 * anonymous view for a question the dashboard's own guard answers.
 */
export default async function HomePage() {
  const jar = await cookies();

  if (jar.has('manfaa-auth')) {
    redirect('/dashboard');
  }

  return <MerchantLanding />;
}
