/// Money formatting — the client half of the platform's money law.
///
/// Amounts arrive as INTEGER LAARI and stay integers until the final string.
/// No doubles anywhere: 1 rufiyaa = 100 laari, and float arithmetic on money
/// is banned on this platform end to end (PLAN §4).
///
/// The wording rule mirrors the server's NotificationService::money exactly:
/// "MVR 1,234.56" in English; "1,234.56 ރުފިޔާ" in Dhivehi — the word goes
/// AFTER the figure in Thaana, and "MVR" never appears inside a Thaana
/// sentence.
library;

/// "1,234.56" from 123456 laari. Sign survives; digits are ASCII in both
/// languages (that is how the panels and SMS templates render too).
String formatRufiyaa(int laari) {
  final sign = laari < 0 ? '-' : '';
  final abs = laari.abs();
  final rufiyaa = abs ~/ 100;
  final cents = (abs % 100).toString().padLeft(2, '0');

  final digits = rufiyaa.toString();
  final buffer = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(',');
    buffer.write(digits[i]);
  }

  return '$sign$buffer.$cents';
}

/// The gap between the figure and its currency word is a NON-BREAKING space.
///
/// They are one typographic unit and must never be split across lines. With an
/// ordinary space they were: in Dhivehi the figure sat on one line and
/// "ރުފިޔާ" dropped to the next inside cards that had room for both (owner,
/// 2026-08-21), and English "MVR 1,284.50" can break the same way. This holds
/// wherever the string goes -- standalone in [MoneyText] and interpolated into
/// a sentence alike -- which no widget-level `softWrap: false` would.
const String moneyGap = '\u00A0';

String formatMoney(int laari, {required bool dhivehi}) {
  final amount = formatRufiyaa(laari);

  return dhivehi ? '$amount$moneyGapރުފިޔާ' : 'MVR$moneyGap$amount';
}

/// EXACT typed-MVR → integer laari, or null. The till's amount fields parse
/// through here so "1,250.50" becomes 125050 by string surgery — never
/// `double.parse` (float money is banned end to end, §11). Accepts optional
/// thousands commas and at most two decimals; anything else is null, and the
/// caller renders a validation hint rather than guessing.
int? parseMvrToLaari(String input) {
  // Strip the non-breaking space too: a figure copied back out of the UI
  // carries the one formatMoney puts in, and it must still parse.
  final cleaned =
      input.trim().replaceAll(',', '').replaceAll(moneyGap, '').replaceAll(' ', '');
  if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch(cleaned)) return null;

  final parts = cleaned.split('.');
  final rufiyaa = int.tryParse(parts[0]);
  if (rufiyaa == null) return null; // overflow-length digits
  final cents = parts.length > 1 ? int.parse(parts[1].padRight(2, '0')) : 0;

  return rufiyaa * 100 + cents;
}
