import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_customer/features/home/home_screen.dart';

/// The owner asked for "Aug 25 – 31" rather than the whole formatted date
/// (2026-08-21). The API sends plain ISO dates, and the window is always
/// start-day → end-of-month, so same-month is the case that actually ships —
/// but the boundary case is cheap to hold and the one that would look silly.
void main() {
  test('says the month once when the window sits inside one month', () {
    expect(formatPayoutWindow('2026-08-25', '2026-08-31'), 'Aug 25 – 31');
  });

  test('names both months when the window crosses one', () {
    expect(formatPayoutWindow('2026-08-25', '2026-09-03'), 'Aug 25 – Sep 3');
  });

  test('names both when the year changes', () {
    expect(formatPayoutWindow('2026-12-28', '2027-01-04'), 'Dec 28 – Jan 4');
  });

  test('does not pad the day', () {
    expect(formatPayoutWindow('2026-03-01', '2026-03-09'), 'Mar 1 – 9');
  });

  test('falls back to what the API sent rather than showing nothing', () {
    expect(formatPayoutWindow('soon', 'later'), 'soon – later');
    expect(formatPayoutWindow('', ''), '');
  });
}
