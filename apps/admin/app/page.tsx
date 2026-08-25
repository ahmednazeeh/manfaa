import { redirect } from 'next/navigation';

/**
 * The panel's front door. It lands on the dashboard, not on a queue: which
 * queue matters today is the question the dashboard answers, and answering it
 * by always opening Settlements made every other queue something you had to
 * remember to go and look at.
 */
export default function HomePage() {
  redirect('/dashboard');
}
