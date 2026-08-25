import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// GET /merchant/fee-promotion, parsed the way a till has to parse it.
///
/// The theme of this file is that NOTHING here may throw. A promotion is
/// the one payload the app reads that a superadmin can change at any minute
/// from an admin screen this build has never seen, so every question the UI
/// asks — is there one, what does it charge, does it still apply — has to
/// answer safely for a payload from the future.
void main() {
  /// The server's answer when a promotion IS running: every key present.
  Map<String, dynamic> live({
    String kind = 'introductory',
    String? fee = '0.00',
    String? endsAt = '2099-01-01T00:00:00+00:00',
    int? daysRemaining = 13,
    String? en = 'No platform fee for your first 60 days.',
    String? dv = 'ފުރަތަމަ 60 ދުވަހު ފީއެއް ނުނަގާނެއެވެ.',
  }) => {
    'active': true,
    'kind': kind,
    'kind_label': 'Introductory offer',
    'platform_fee_percent': fee,
    'ends_at': endsAt,
    'days_remaining': daysRemaining,
    'banner_en': en,
    'banner_dv': dv,
  };

  final past = DateTime.utc(2020, 1, 1);
  final now = DateTime.utc(2026, 8, 25, 12);

  group('parsing', () {
    test('reads the live introductory shape', () {
      final promo = MerchantFeePromotion.fromJson(live());

      expect(promo.active, isTrue);
      expect(promo.kind, FeePromotionKind.introductory);
      expect(promo.platformFeePercent, '0.00');
      expect(promo.feeBp, 0);
      expect(promo.daysRemaining, 13);
      expect(promo.endsAt, DateTime.utc(2099, 1, 1));
      expect(promo.showableAt(now), isTrue);
    });

    test('reads the platform-wide kind', () {
      final promo = MerchantFeePromotion.fromJson(
        live(kind: 'platform_wide', fee: '0.25'),
      );

      expect(promo.kind, FeePromotionKind.platformWide);
      expect(promo.feeBp, 25);
      expect(promo.showableAt(now), isTrue);
    });

    test('the server\'s inactive answer shows nothing', () {
      final promo = MerchantFeePromotion.fromJson(const {
        'active': false,
        'kind': null,
        'kind_label': null,
        'platform_fee_percent': null,
        'ends_at': null,
        'days_remaining': null,
        'banner_en': null,
        'banner_dv': null,
      });

      expect(promo.showableAt(now), isFalse);
      expect(promo.kind, isNull);
      expect(promo.feeBp, isNull);
    });

    test('an empty body shows nothing rather than throwing', () {
      final promo = MerchantFeePromotion.fromJson(const {});

      expect(promo.active, isFalse);
      expect(promo.showableAt(now), isFalse);
    });

    // THE DEFENSIVE CONTRACT the round was asked for: a kind this build has
    // never heard of degrades to showing nothing, never to a throw and
    // never to a banner with a made-up heading.
    test('an unknown kind degrades to showing nothing', () {
      final promo = MerchantFeePromotion.fromJson(live(kind: 'seasonal_2027'));

      expect(promo.active, isTrue, reason: 'the server said so');
      expect(promo.kind, isNull, reason: 'this build does not know it');
      expect(promo.showableAt(now), isFalse);
      // And it must not silently discount the till's quote either.
      expect(promo.chargedFeeBpAt(75, now), 75);
    });

    test('garbage types anywhere never throw', () {
      final promo = MerchantFeePromotion.fromJson(const {
        'active': 'yes',
        'kind': 42,
        'platform_fee_percent': 0.25,
        'ends_at': 'not-a-date',
        'days_remaining': '13',
        'banner_en': <String>[],
        'banner_dv': null,
      });

      expect(promo.active, isFalse, reason: 'only a real true is true');
      expect(promo.kind, isNull);
      expect(promo.endsAt, isNull);
      expect(promo.daysRemaining, isNull);
      expect(promo.showableAt(now), isFalse);
    });

    test('a fee that is not wire-legal is refused', () {
      for (final bad in ['', 'free', '0.125', '-1.00', '1,00']) {
        final promo = MerchantFeePromotion.fromJson(live(fee: bad));
        expect(promo.feeBp, isNull, reason: bad);
        expect(promo.showableAt(now), isFalse, reason: bad);
      }
    });

    test('a fee above the 20% ceiling is a bad parse, not a promotion', () {
      final promo = MerchantFeePromotion.fromJson(live(fee: '25.00'));

      expect(promo.feeBp, isNull);
      expect(promo.showableAt(now), isFalse);
      expect(promo.chargedFeeBpAt(75, now), 75);
    });

    test('20.00 exactly is still legal', () {
      expect(MerchantFeePromotion.fromJson(live(fee: '20.00')).feeBp, 2000);
    });
  });

  group('the window', () {
    test('an offer whose end has passed shows nothing', () {
      final promo = MerchantFeePromotion.fromJson(
        live(endsAt: '2026-08-25T00:00:00+00:00'),
      );

      expect(promo.expiredAt(now), isTrue);
      expect(promo.showableAt(now), isFalse);
      // And the till goes straight back to quoting the tier fee.
      expect(promo.chargedFeeBpAt(75, now), 75);
    });

    test('the end is EXCLUSIVE — the instant itself is already over', () {
      final ends = DateTime.utc(2026, 8, 25, 12);
      final promo = MerchantFeePromotion.fromJson(
        live(endsAt: ends.toIso8601String()),
      );

      expect(promo.expiredAt(ends.subtract(const Duration(seconds: 1))), isFalse);
      expect(promo.expiredAt(ends), isTrue);
    });

    test('no end at all never expires', () {
      final promo = MerchantFeePromotion.fromJson(live(endsAt: null));

      expect(promo.expiredAt(now), isFalse);
      expect(promo.showableAt(now), isTrue);
    });

    test('an unreadable end is treated as no end, not as expired', () {
      final promo = MerchantFeePromotion.fromJson(live(endsAt: 'soon'));

      expect(promo.endsAt, isNull);
      expect(promo.showableAt(now), isTrue);
    });

    test('days_remaining is the server\'s and is never re-derived', () {
      final promo = MerchantFeePromotion.fromJson(
        live(endsAt: '2099-01-01T00:00:00+00:00', daysRemaining: 1),
      );

      expect(promo.daysRemaining, 1);
    });
  });

  group('the pricing rule', () {
    // TermsResolver::priceAt() — charged fee bp = min(promotion, tier).
    test('the promotion wins when it is cheaper', () {
      final promo = MerchantFeePromotion.fromJson(live(fee: '0.00'));

      expect(promo.chargedFeeBpAt(75, now), 0);
      expect(promo.chargedFeeBpAt(100, now), 0);
    });

    test('THE MERCHANT WINS — a cheaper tier is left alone', () {
      final promo = MerchantFeePromotion.fromJson(live(fee: '0.50'));

      expect(promo.chargedFeeBpAt(25, now), 25);
      expect(promo.chargedFeeBpAt(75, now), 50);
    });

    test('an exact tie changes nothing', () {
      final promo = MerchantFeePromotion.fromJson(live(fee: '0.75'));

      expect(promo.chargedFeeBpAt(75, now), 75);
    });

    test('an unpriced rate stays unpriced rather than gaining a fee', () {
      final promo = MerchantFeePromotion.fromJson(live(fee: '0.00'));

      expect(promo.chargedFeeBpAt(null, now), isNull);
    });

    test('nothing running prices exactly as before', () {
      expect(MerchantFeePromotion.none.chargedFeeBpAt(75, now), 75);
      expect(MerchantFeePromotion.none.chargedFeeBpAt(null, now), isNull);
      expect(MerchantFeePromotion.none.showableAt(past), isFalse);
    });
  });

  group('the banner copy', () {
    test('serves the reader\'s language', () {
      final promo = MerchantFeePromotion.fromJson(live());

      expect(promo.banner(dhivehi: false), startsWith('No platform fee'));
      expect(promo.banner(dhivehi: true), contains('ދުވަހު'));
    });

    test('falls back to the other language rather than showing no words', () {
      final onlyEn = MerchantFeePromotion.fromJson(live(dv: null));
      final onlyDv = MerchantFeePromotion.fromJson(live(en: '   '));

      expect(onlyEn.banner(dhivehi: true), startsWith('No platform fee'));
      expect(onlyDv.banner(dhivehi: false), contains('ދުވަހު'));
    });

    test('no copy at all is null, never an invented sentence', () {
      final promo = MerchantFeePromotion.fromJson(live(en: null, dv: null));

      expect(promo.banner(dhivehi: false), isNull);
      expect(promo.banner(dhivehi: true), isNull);
      // The offer itself is still real: a fee with no words still prices.
      expect(promo.showableAt(now), isTrue);
    });
  });

  test('the wire vocabulary is exactly the server enum', () {
    expect(
      FeePromotionKind.values.map((k) => k.wire),
      ['introductory', 'platform_wide'],
    );
    expect(FeePromotionKind.fromWire('introductory'),
        FeePromotionKind.introductory);
    expect(FeePromotionKind.fromWire('platform_wide'),
        FeePromotionKind.platformWide);
    expect(FeePromotionKind.fromWire('PLATFORM_WIDE'), isNull);
    expect(FeePromotionKind.fromWire(null), isNull);
    expect(FeePromotionKind.fromWire(7), isNull);
  });
}
