import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// Wire-shape tests for the PUBLIC storefront read — GET
/// /api/discover/merchants/{slug}, the payload behind the customer app's
/// store page.
///
/// The fixtures below are the keys `DiscoveryService::buildStore()` actually
/// emits (see api/tests/Feature/Customer/StorefrontTest.php, which asserts
/// the exact key list), not the merchant-side spellings of the same columns.
/// That difference is the whole point of these tests: `eligibility_basis` is
/// what the merchant panel calls it, `cashback_basis` is what the shopper's
/// payload calls it, and a model that reads the wrong one renders a blank
/// card forever without failing anything.
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

ManfaaApi _api(_RecordingAdapter adapter) {
  final api = ManfaaApi(session: CustomerSession(MemorySecretStore()));
  api.dio.httpClientAdapter = adapter;
  return api;
}

/// The exact storefront payload for a store that HAS written a description.
const _storeFixture = {
  'name': 'Tropical Mart',
  'name_dv': 'ޓްރޮޕިކަލް މާޓް',
  'slug': 'tropical-mart',
  'category': 'grocery',
  'logo_url': 'https://api.manfaa.mv/api/merchants/tropical-mart/logo',
  'channel': 'both',
  'featured': true,
  'cashback_rate_percent': '5.00',
  'standing_cashback_rate_percent': '2.00',
  'promotion': {
    'cashback_rate_percent': '5.00',
    'ends_at': '2026-09-01T00:00:00+00:00',
    'min_purchase_laari': 10000,
  },
  'description': 'A family grocery on Majeedhee Magu since 1998.',
  'description_dv': 'ދިވެހި ތަޢާރަފެއް.',
  'cashback_basis': 'Invoice total excluding GST and service charge.',
  'contact_phone': '+9607781234',
  'support_phone': '+9607781234',
  'website_url': 'https://tropicalmart.mv',
  'category_rates': [
    {
      'name_en': 'Tobacco',
      'name_dv': 'ދުންފަތް',
      'mode': 'excluded',
      'cashback_rate_percent': null,
    },
    {
      'name_en': 'Fresh produce',
      'name_dv': null,
      'mode': 'rate',
      'cashback_rate_percent': '3.00',
    },
  ],
  'branches': [
    {
      'name': 'Malé main',
      'address': 'Majeedhee Magu',
      'lat': 4.1755,
      'lng': 73.5093,
    },
  ],
  'joined': '2026-03',
};

/// The SAME endpoint for one of the two live stores that predate the
/// description field: the key is simply absent, exactly as the API omits
/// nothing but sends null. Both spellings of "no description" appear in the
/// tests below because both reach the app.
const _preDescriptionFixture = {
  'name': 'Ocean Café',
  'name_dv': null,
  'slug': 'ocean-cafe',
  'category': 'dining',
  'logo_url': null,
  'channel': 'in_store',
  'featured': false,
  'cashback_rate_percent': '5.00',
  'standing_cashback_rate_percent': '5.00',
  'promotion': null,
  'cashback_basis': null,
  'contact_phone': null,
  'support_phone': null,
  'website_url': null,
  'category_rates': <Map<String, dynamic>>[],
  'branches': <Map<String, dynamic>>[],
  'joined': null,
};

void main() {
  group('StorePage wire shape', () {
    test('parses the storefront payload, description included', () {
      final page = StorePage.fromJson(Map<String, dynamic>.from(_storeFixture));

      expect(page.entry.name, 'Tropical Mart');
      expect(page.entry.slug, 'tropical-mart');
      expect(page.description, 'A family grocery on Majeedhee Magu since 1998.');
      expect(page.descriptionDv, 'ދިވެހި ތަޢާރަފެއް.');

      // The neighbours on the same page, read from the keys the storefront
      // actually serves rather than the merchant-side spellings.
      expect(
        page.eligibilityBasis,
        'Invoice total excluding GST and service charge.',
      );
      expect(page.productCategories, hasLength(2));
      expect(page.productCategories.first['name_en'], 'Tobacco');
      expect(page.productCategories.first['mode'], 'excluded');
      expect(page.branches.single.name, 'Malé main');
      expect(page.contactPhone, '+9607781234');
      expect(page.websiteUrl, 'https://tropicalmart.mv');
    });

    test('an ABSENT description stays null — never an empty string', () {
      final page = StorePage.fromJson(
        Map<String, dynamic>.from(_preDescriptionFixture),
      );

      expect(page.description, isNull);
      expect(page.descriptionDv, isNull);
      // The one guard the UI asks: nothing to draw, in either language.
      expect(page.displayDescription(false), isNull);
      expect(page.displayDescription(true), isNull);
    });

    test('an explicit null description is the same as an absent one', () {
      final page = StorePage.fromJson({
        ..._preDescriptionFixture,
        'description': null,
        'description_dv': null,
      });

      expect(page.description, isNull);
      expect(page.displayDescription(false), isNull);
    });

    test('a blank description never becomes a block', () {
      final page = StorePage.fromJson({
        ..._preDescriptionFixture,
        'description': '   \n ',
        'description_dv': '',
      });

      // The raw value survives parsing; the display guard refuses it, so an
      // all-whitespace description renders as absent rather than as a card
      // with nothing in it.
      expect(page.description, '   \n ');
      expect(page.displayDescription(false), isNull);
      expect(page.displayDescription(true), isNull);
    });

    test('Dhivehi readers get the Thaana description when there is one', () {
      final page = StorePage.fromJson(Map<String, dynamic>.from(_storeFixture));

      expect(page.displayDescription(true), 'ދިވެހި ތަޢާރަފެއް.');
      expect(page.descriptionIsThaana(true), isTrue);
      expect(
        page.displayDescription(false),
        'A family grocery on Majeedhee Magu since 1998.',
      );
      expect(page.descriptionIsThaana(false), isFalse);
    });

    test('no Thaana description falls back to the Latin one, not to blank',
        () {
      final page = StorePage.fromJson({
        ..._storeFixture,
        'description_dv': null,
      });

      expect(
        page.displayDescription(true),
        'A family grocery on Majeedhee Magu since 1998.',
      );
      // The fallback text is Latin, so the paragraph stays LTR even though
      // the reader is on the Dhivehi app.
      expect(page.descriptionIsThaana(true), isFalse);
    });

    test('a Latin-blank store never shows Thaana to an English reader', () {
      final page = StorePage.fromJson({
        ..._preDescriptionFixture,
        'description': null,
        'description_dv': 'ދިވެހި ތަޢާރަފެއް.',
      });

      expect(page.displayDescription(false), isNull);
      expect(page.displayDescription(true), 'ދިވެހި ތަޢާރަފެއް.');
    });
  });

  group('GET /discover/merchants/{slug}', () {
    test('unwraps the data envelope and hits the public path', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({'data': _storeFixture}, 200),
      );
      final page = await _api(adapter).store('tropical-mart');

      expect(adapter.requests.single.path,
          endsWith('/discover/merchants/tropical-mart'));
      expect(page.description, 'A family grocery on Majeedhee Magu since 1998.');
    });

    test('a store page with no description parses without a description',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json({'data': _preDescriptionFixture}, 200),
      );
      final page = await _api(adapter).store('ocean-cafe');

      expect(page.entry.name, 'Ocean Café');
      expect(page.displayDescription(false), isNull);
    });
  });
}
