/// Month names, in the two scripts the apps speak.
///
/// Manual rather than `intl`, for the reason the date helpers already carry:
/// intl ships no Divehi date symbols, so `DateFormat` silently falls back to
/// English and every date in the app reads in Latin — which is what the owner
/// reported on 2026-08-21.
///
/// Dhivehi has no conventional three-letter abbreviation the way English does,
/// so the full name is used; a date range says the month once, which keeps it
/// short enough.
const List<String> _monthsEn = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

const List<String> _monthsDv = [
  'ޖަނަވަރީ',
  'ފެބްރުވަރީ',
  'މާރިޗު',
  'އޭޕްރީލް',
  'މޭ',
  'ޖޫން',
  'ޖުލައި',
  'އޯގަސްޓު',
  'ސެޕްޓެންބަރު',
  'އޮކްޓޫބަރު',
  'ނޮވެންބަރު',
  'ޑިސެންބަރު',
];

/// [month] is 1-12. Out-of-range returns an empty string rather than throwing:
/// a date that cannot be named must not take a screen down.
String monthName(int month, {required bool dhivehi}) {
  if (month < 1 || month > 12) return '';

  return (dhivehi ? _monthsDv : _monthsEn)[month - 1];
}
