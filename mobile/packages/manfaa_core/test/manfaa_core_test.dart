import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

void main() {
  group('money', () {
    // The server writes "MVR 1,234.56" (en) and "1,234.56 ރުފިޔާ" (dv);
    // these strings must match the panels and the SMS templates exactly.
    test('formats laari integers into the canonical strings', () {
      expect(formatMoney(0, dhivehi: false), 'MVR 0.00');
      expect(formatMoney(100, dhivehi: false), 'MVR 1.00');
      expect(formatMoney(123456, dhivehi: false), 'MVR 1,234.56');
      expect(formatMoney(100000000, dhivehi: false), 'MVR 1,000,000.00');
      expect(formatMoney(5, dhivehi: false), 'MVR 0.05');
    });

    test('dhivehi puts the word AFTER the figure and never says MVR', () {
      final text = formatMoney(123456, dhivehi: true);
      expect(text, '1,234.56 ރުފިޔާ');
      expect(text.contains('MVR'), isFalse);
    });

    test('a reversal-driven negative survives formatting', () {
      expect(formatMoney(-50, dhivehi: false), 'MVR -0.50');
    });
  });

  group('phone normalisation', () {
    test('mirrors the server Msisdn rule', () {
      expect(normalizePhone('7712345'), '+9607712345');
      expect(normalizePhone('9712345'), '+9609712345');
      expect(normalizePhone('+960 771 2345'), '+9607712345');
      // Unparseable input passes through for the server to refuse — never
      // silently blanked, same stance as OtpAuthController.
      expect(normalizePhone('12345'), '12345');
    });
  });

  group('error envelope', () {
    test('maps the envelope to code, message and meta', () {
      final e = MobileApiException.fromResponse({
        'error': {
          'code': 'rate_limited',
          'message': 'Too many attempts. Please wait a moment and try again.',
          'meta': {'retry_after_seconds': 300},
        },
      }, 429);

      expect(e.code, ApiCode.rateLimited);
      expect(e.retryAfterSeconds, 300);
      expect(e.message, contains('wait'));
    });

    test('degrades an un-enveloped body to a status code, never a crash', () {
      final e = MobileApiException.fromResponse('<html>bad gateway</html>', 502);
      expect(e.code, ApiCode.serverError);
      // The message is prose, not the payload — raw HTML must never reach a
      // screen.
      expect(e.message.contains('<'), isFalse);
    });

    test('exposes field messages for validation_failed', () {
      final e = MobileApiException.fromResponse({
        'error': {
          'code': 'validation_failed',
          'message': 'Some of the details entered need correcting.',
          'meta': {
            'fields': {
              'phone': ['auth.failed'],
            },
          },
        },
      }, 422);

      expect(e.fieldMessages('phone'), ['auth.failed']);
      expect(e.fieldMessages('password'), isEmpty);
    });
  });

  group('config gate', () {
    MobileConfig config({int min = 3, int latest = 5}) => MobileConfig.fromJson({
          'apps': {
            'customer': {
              'android': {
                'minimum_build': min,
                'latest_build': latest,
                'store_url': 'https://play.example/manfaa',
              },
            },
          },
          'features': {'customer_claims': false},
        });

    test('a build below minimum is hard-blocked', () {
      final gate = resolveGate(config(), platform: 'android', build: 2);
      expect(gate.verdict, UpdateVerdict.required);
      expect(gate.storeUrl, isNotEmpty);
    });

    test('a build between minimum and latest gets an optional nudge', () {
      expect(
        resolveGate(config(), platform: 'android', build: 4).verdict,
        UpdateVerdict.optional,
      );
    });

    test('a current build passes and an unknown platform never blocks', () {
      expect(
        resolveGate(config(), platform: 'android', build: 5).verdict,
        UpdateVerdict.none,
      );
      expect(
        resolveGate(config(), platform: 'ios', build: 1).verdict,
        UpdateVerdict.none,
      );
    });
  });

  group('session', () {
    test('round-trips and wipes, keeping locale across sign-out', () async {
      final session = SessionStore(MemorySecretStore());
      await session.init();
      expect(session.signedIn, isFalse);

      await session.saveSession(
        token: 't-1',
        customerCode: '374230',
        customerName: 'Ahmed',
      );
      await session.setLocale('dv');

      expect(session.signedIn, isTrue);
      expect(session.customerCode, '374230');

      await session.wipe();

      expect(session.signedIn, isFalse);
      expect(session.customerCode, isNull);
      // Losing your language because a token expired would be hostile.
      expect(session.locale, 'dv');
    });

    test('bumps the revision on every transition the router must see', () async {
      final session = SessionStore(MemorySecretStore());
      var seen = 0;
      session.revision.addListener(() => seen++);

      await session.saveSession(
        token: 't',
        customerCode: 'c',
        customerName: 'n',
      );
      await session.wipe();

      expect(seen, 2);
    });
  });
}
