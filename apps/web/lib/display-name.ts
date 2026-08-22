/**
 * Which of a customer's two names to show.
 *
 * Accounts carry an English name and — since registration started asking a
 * model to transliterate it — a Thaana one. A Dhivehi reader sees the Thaana
 * name; everyone else sees the English one.
 *
 * The fallback is the point: `name_dv` is produced by a queued job that is
 * allowed to fail, and stays null for anyone whose name it could not write.
 * Mirrors `displayName` in manfaa_core so the apps and the website answer the
 * same question the same way.
 */
export function displayName(
  english: string,
  dhivehi: string | null | undefined,
  language: string,
): string {
  if (!language.startsWith('dv')) return english;

  const thaana = (dhivehi ?? '').trim();

  return thaana === '' ? english : thaana;
}
