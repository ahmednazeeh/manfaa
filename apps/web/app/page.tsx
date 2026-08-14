import { redirect } from 'next/navigation';

/** The auth guard on /dashboard bounces logged-out visitors to /login. */
export default function HomePage() {
  redirect('/dashboard');
}
