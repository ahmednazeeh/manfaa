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

String formatMoney(int laari, {required bool dhivehi}) {
  final amount = formatRufiyaa(laari);

  return dhivehi ? '$amount ރުފިޔާ' : 'MVR $amount';
}
