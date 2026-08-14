import { redirect } from 'next/navigation';

/** /settings on its own lands on the first settings screen. */
export default function SettingsIndexPage() {
  redirect('/settings/platform');
}
