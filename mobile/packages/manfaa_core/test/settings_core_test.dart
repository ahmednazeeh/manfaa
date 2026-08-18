import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// MR5 wire-shape tests: the settings estate half of MerchantApi — profile,
/// standing rate change, preferences, product-category writes. Same law as
/// every core test: shapes mirror the PHP controllers exactly, money is
/// integer laari, percents are EXACT strings.
class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this._respond);

  final ResponseBody Function(RequestOptions options) _respond;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return _respond(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Object payload, int status) => ResponseBody.fromString(
      jsonEncode(payload),
      status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );

MerchantApi _api(_RecordingAdapter adapter) {
  final api =
      MerchantApi(session: MerchantSession(MemorySecretStore()));
  api.dio.httpClientAdapter = adapter;
  return api;
}

/// The exact MerchantProfileResource shape.
const _profileFixture = {
  'data': {
    'id': 3,
    'name': 'Tropical Mart',
    'name_dv': 'ޓްރޮޕިކަލް މާޓް',
    'slug': 'tropical-mart',
    'status': 'active',
    'category': 'grocery',
    'category_retired': false,
    'channel': 'in_store',
    'eligibility_basis': 'Everything except tobacco.',
    'description': 'A neighbourhood grocery on Majeedhee Magu, open daily.',
    'contact_email': 'hello@tropicalmart.mv',
    'contact_phone': '+9607781234',
    'support_phone': '+9603334455',
    'website_url': 'https://www.tropicalmart.mv',
    // MR9 — the key is ALWAYS on the wire; null is "nothing queued".
    'pending_change': null,
  },
};

/// The exact RateController::show / ::store `data` half (RateResource).
const _rateData = {
  'current': {
    'cashback_rate_percent': '2.00',
    'platform_fee_percent': '0.75',
    'all_in_percent': '2.75',
    'effective_from': '2026-08-01T00:00:00+05:00',
    'effective_to': null,
  },
  'pending': null,
};

/// The exact MerchantPreferencesResource shape.
const _preferencesFixture = {
  'data': {
    'settlement_method': 'bank',
    'min_eligible_laari': 10000,
    'validation_window_days': 3,
    'validation_window_max_days': 3,
  },
};

/// The exact ProductCategoryResource shape.
const _categoryFixture = {
  'data': {
    'id': 9,
    'slug': 'fresh-produce',
    'name_en': 'Fresh Produce',
    'name_dv': 'ތާޒާ ތަކެތި',
    'mode': 'rate',
    'cashback_rate_percent': '3.00',
    'active': true,
    'sort': 0,
    'created_at': '2026-08-17T09:00:00+00:00',
    'updated_at': '2026-08-17T09:00:00+00:00',
  },
};

void main() {
  group('profile', () {
    test('GET parses the exact MerchantProfileResource shape', () async {
      final adapter = _RecordingAdapter((_) => _json(_profileFixture, 200));
      final profile = await _api(adapter).profile();

      expect(adapter.requests.single.method, 'GET');
      expect(adapter.requests.single.path, '/merchant/profile');
      expect(profile.id, 3);
      expect(profile.name, 'Tropical Mart');
      expect(profile.nameDv, 'ޓްރޮޕިކަލް މާޓް');
      expect(profile.slug, 'tropical-mart');
      expect(profile.status, 'active');
      expect(profile.category, 'grocery');
      expect(profile.categoryRetired, isFalse);
      expect(profile.channel, 'in_store');
      expect(profile.eligibilityBasis, 'Everything except tobacco.');
      expect(
        profile.description,
        'A neighbourhood grocery on Majeedhee Magu, open daily.',
      );
      expect(profile.contactEmail, 'hello@tropicalmart.mv');
      expect(profile.contactPhone, '+9607781234');
      expect(profile.supportPhone, '+9603334455');
      expect(profile.websiteUrl, 'https://www.tropicalmart.mv');
      expect(profile.pendingChange, isNull);
    });

    test('PATCH sends every editable key — explicit nulls clear', () async {
      final adapter = _RecordingAdapter((_) => _json(_profileFixture, 200));

      await _api(adapter).updateProfile(
        name: 'Tropical Mart',
        channel: 'both',
        contactPhone: '+9607781234',
        supportPhone: '+9607781234',
      );

      final request = adapter.requests.single;
      expect(request.method, 'PATCH');
      expect(request.path, '/merchant/profile');
      // All keys present, nulls explicit — and NO category key at all.
      expect(request.data, {
        'name': 'Tropical Mart',
        'name_dv': null,
        'channel': 'both',
        'eligibility_basis': null,
        'description': null,
        'contact_email': null,
        'contact_phone': '+9607781234',
        'support_phone': '+9607781234',
        'website_url': null,
      });
    });

    test('the description travels on the PATCH, verbatim', () async {
      final adapter = _RecordingAdapter((_) => _json(_profileFixture, 200));

      await _api(adapter).updateProfile(
        name: 'Tropical Mart',
        channel: 'in_store',
        description: 'A neighbourhood grocery on Majeedhee Magu, open daily.',
      );

      expect(
        (adapter.requests.single.data as Map)['description'],
        'A neighbourhood grocery on Majeedhee Magu, open daily.',
      );
    });

    test('category travels ONLY when changed', () async {
      final adapter = _RecordingAdapter((_) => _json(_profileFixture, 200));

      await _api(adapter).updateProfile(
        name: 'Tropical Mart',
        channel: 'in_store',
        categoryChanged: true,
        category: 'dining',
      );

      expect(
        (adapter.requests.single.data as Map)['category'],
        'dining',
      );
    });

    test('category_retired parses true', () {
      final profile = MerchantProfile.fromJson(const {
        'id': 3,
        'name': 'Tea Plus',
        'slug': 'tea-plus',
        'status': 'active',
        'category': 'kiosk',
        'category_retired': true,
        'channel': 'in_store',
      });

      expect(profile.categoryRetired, isTrue);
    });
  });

  group('rate change', () {
    test('the rate goes out as the exact percent STRING typed', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {'data': _rateData, 'change': null}, 200),
      );

      await _api(adapter).changeRate('2.50');

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/merchant/rate');
      expect(request.data, {'cashback_rate_percent': '2.50'});
    });

    test('parses data{current,pending} AND the root-level change', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'current': {
              'cashback_rate_percent': '2.00',
              'platform_fee_percent': '0.75',
              'all_in_percent': '2.75',
              'effective_from': '2026-08-01T00:00:00+05:00',
              'effective_to': '2026-08-18T00:00:00+05:00',
            },
            'pending': {
              'cashback_rate_percent': '1.50',
              'platform_fee_percent': '0.50',
              'all_in_percent': '2.00',
              'effective_from': '2026-08-18T00:00:00+05:00',
              'effective_to': null,
            },
          },
          'change': {
            'previous': {
              'cashback_rate_percent': '2.00',
              'platform_fee_percent': '0.75',
              'all_in_percent': '2.75',
            },
            'new': {
              'cashback_rate_percent': '1.50',
              'platform_fee_percent': '0.50',
              'all_in_percent': '2.00',
            },
            'effective_at': '2026-08-18T00:00:00+05:00',
            'applies': 'next_business_midnight',
            'tier_changed': true,
          },
        }, 200),
      );

      final result = await _api(adapter).changeRate('1.50');

      // Percent strings survive EXACTLY — never re-derived.
      expect(result.rate.current?.cashbackRatePercent, '2.00');
      expect(result.rate.pending?.cashbackRatePercent, '1.50');
      expect(result.rate.pending?.effectiveFrom, '2026-08-18T00:00:00+05:00');

      final change = result.change!;
      expect(change.previous?.cashbackRatePercent, '2.00');
      expect(change.next.cashbackRatePercent, '1.50');
      expect(change.next.platformFeePercent, '0.50');
      expect(change.next.allInPercent, '2.00');
      expect(change.applies, 'next_business_midnight');
      expect(change.effectiveAt, '2026-08-18T00:00:00+05:00');
      expect(change.tierChanged, isTrue);
    });

    test('a first-ever rate has a null previous', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': _rateData,
          'change': {
            'previous': null,
            'new': {
              'cashback_rate_percent': '2.00',
              'platform_fee_percent': '0.75',
              'all_in_percent': '2.75',
            },
            'effective_at': '2026-08-17T14:00:00+05:00',
            'applies': 'immediately',
            'tier_changed': false,
          },
        }, 200),
      );

      final result = await _api(adapter).changeRate('2.00');

      expect(result.change?.previous, isNull);
      expect(result.change?.applies, 'immediately');
    });

    test('rate_not_priced surfaces through the envelope typed', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'error': {
            'code': 'rate_not_priced',
            'message': 'No fee tier prices this rate.',
            'meta': <String, Object>{},
          },
        }, 422),
      );

      await expectLater(
        _api(adapter).changeRate('19.00'),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', ApiCode.rateNotPriced)
              .having((e) => e.status, 'status', 422),
        ),
      );
    });
  });

  group('preferences', () {
    test('the READ is an empty PATCH — {} on the wire, values off the answer',
        () async {
      final adapter =
          _RecordingAdapter((_) => _json(_preferencesFixture, 200));

      final prefs = await _api(adapter).preferences();

      final request = adapter.requests.single;
      expect(request.method, 'PATCH');
      expect(request.path, '/merchant/preferences');
      expect(request.data, const <String, Object?>{});
      expect(prefs.settlementMethod, 'bank');
      expect(prefs.minEligibleLaari, 10000);
      expect(prefs.validationWindowDays, 3);
      expect(prefs.validationWindowMaxDays, 3);
    });

    test('the update sends ONLY the given keys, laari as integers', () async {
      final adapter =
          _RecordingAdapter((_) => _json(_preferencesFixture, 200));

      await _api(adapter).updatePreferences(
        minEligibleLaari: 10000,
        validationWindowDays: 2,
      );

      // No settlement_method key — absent means untouched, never cleared.
      expect(adapter.requests.single.data, {
        'min_eligible_laari': 10000,
        'validation_window_days': 2,
      });
    });
  });

  group('product category writes', () {
    test('create (rate mode) carries the exact percent string', () async {
      final adapter = _RecordingAdapter((_) => _json(_categoryFixture, 201));

      final category = await _api(adapter).createProductCategory(
        nameEn: 'Fresh Produce',
        nameDv: 'ތާޒާ ތަކެތި',
        mode: 'rate',
        cashbackRatePercent: '3.00',
      );

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/merchant/product-categories');
      expect(request.data, {
        'name_en': 'Fresh Produce',
        'name_dv': 'ތާޒާ ތަކެތި',
        'mode': 'rate',
        'cashback_rate_percent': '3.00',
      });
      expect(category.slug, 'fresh-produce');
      expect(category.cashbackRatePercent, '3.00');
    });

    test('create (excluded) OMITS the percent key — the server prohibits it',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_categoryFixture, 201));

      await _api(adapter).createProductCategory(
        nameEn: 'Tobacco',
        nameDv: 'ދުންފަތް',
        mode: 'excluded',
      );

      expect(adapter.requests.single.data, {
        'name_en': 'Tobacco',
        'name_dv': 'ދުންފަތް',
        'mode': 'excluded',
      });
    });

    test('update is partial: absent keys stay untouched', () async {
      final adapter = _RecordingAdapter((_) => _json(_categoryFixture, 200));

      await _api(adapter).updateProductCategory(9, active: false);

      final request = adapter.requests.single;
      expect(request.method, 'PATCH');
      expect(request.path, '/merchant/product-categories/9');
      expect(request.data, {'active': false});
    });

    test('switching a rule to excluded sends mode WITHOUT a percent',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_categoryFixture, 200));

      await _api(adapter).updateProductCategory(9, mode: 'excluded');

      expect(adapter.requests.single.data, {'mode': 'excluded'});
    });
  });
}
