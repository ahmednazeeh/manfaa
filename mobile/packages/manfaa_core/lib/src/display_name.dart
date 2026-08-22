/// Which of a person's two names to show (owner, 2026-08-21).
///
/// Accounts carry an English name and — since registration started asking a
/// model to transliterate it — a Thaana one. A Dhivehi reader should see the
/// Thaana name; everyone else sees the English one.
///
/// The fallback is the whole point and belongs in ONE place: the Thaana name
/// is produced by a queued job that is allowed to fail, and it is nullable
/// forever after. A screen that reaches for it directly will one day render an
/// empty header for the customer whose name the model could not write.
String displayName({
  required String english,
  required String? dhivehi,
  required bool preferDhivehi,
}) {
  if (!preferDhivehi) return english;

  final thaana = dhivehi?.trim() ?? '';

  return thaana.isEmpty ? english : thaana;
}
