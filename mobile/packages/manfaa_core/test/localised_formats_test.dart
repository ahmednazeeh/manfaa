import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// Owner report, 2026-08-21: in Dhivehi the amount and "ރުފިޔާ" were splitting
/// across two lines inside cards that had room for both, and every month name
/// in the app was reading in English.
void main() {
  group('money never breaks across lines', () {
    test('English joins MVR to the figure with a non-breaking space', () {
      final s = formatMoney(128450, dhivehi: false);
      expect(s, 'MVR 1,284.50');
      expect(s.contains(' '), isFalse, reason: 'an ordinary space could wrap');
    });

    test('Dhivehi joins the figure to ރުފިޔާ the same way', () {
      final s = formatMoney(500, dhivehi: true);
      expect(s, '5.00 ރުފިޔާ');
      expect(s.contains(' '), isFalse, reason: 'an ordinary space could wrap');
    });

    test('a figure copied back out of the UI still parses', () {
      // The non-breaking space rides along on a copy-paste; parsing must not
      // care, or a merchant pasting an amount gets a silent null.
      expect(parseMvrToLaari('MVR 1,284.50'.replaceFirst('MVR ', '')),
          128450);
      expect(parseMvrToLaari('1,284.50'), 128450);
    });
  });

  group('month names follow the reader', () {
    test('English stays abbreviated', () {
      expect(monthName(8, dhivehi: false), 'Aug');
      expect(monthName(1, dhivehi: false), 'Jan');
      expect(monthName(12, dhivehi: false), 'Dec');
    });

    test('Dhivehi is Thaana, not the Latin intl falls back to', () {
      expect(monthName(8, dhivehi: true), 'އޯގަސްޓު');
      expect(monthName(1, dhivehi: true), 'ޖަނަވަރީ');
      // The whole point: no Latin letters survive.
      for (var m = 1; m <= 12; m++) {
        expect(
          RegExp(r'[A-Za-z]').hasMatch(monthName(m, dhivehi: true)),
          isFalse,
          reason: 'month $m still reads in Latin',
        );
      }
    });

    test('an impossible month does not take the screen down', () {
      expect(monthName(0, dhivehi: true), '');
      expect(monthName(13, dhivehi: false), '');
    });
  });

group('which name a reader sees', () {
  test('English readers always see the English name', () {
    expect(
      displayName(english: 'Ahmed Nazeeh', dhivehi: 'އަހްމަދު', preferDhivehi: false),
      'Ahmed Nazeeh',
    );
  });

  test('Dhivehi readers see the Thaana name', () {
    expect(
      displayName(english: 'Ahmed Nazeeh', dhivehi: 'އަހްމަދު ނަޒީހު', preferDhivehi: true),
      'އަހްމަދު ނަޒީހު',
    );
  });

  test('a customer whose name was never written still has a name on screen', () {
    // The writer is allowed to fail. This is the case that would otherwise
    // render an empty greeting.
    for (final missing in [null, '', '   ']) {
      expect(
        displayName(english: 'Ahmed Nazeeh', dhivehi: missing, preferDhivehi: true),
        'Ahmed Nazeeh',
      );
    }
  });
});
}
