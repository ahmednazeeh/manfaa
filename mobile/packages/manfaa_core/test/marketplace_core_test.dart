import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The merchant marketplace half of MerchantApi, against the shapes the PHP
/// controllers actually return.
///
/// Written after a real failure: `getJson` UNWRAPS the `data` envelope, and
/// two calls read `['data']` again on the already-unwrapped body. One
/// returned null for every field — so an approved vendor was told its store
/// does not sell — and the other cast a LIST to a Map and threw, which the
/// Products screen showed as an unexplained error. Both were client faults
/// while the server answered 200 the whole time.
class _Adapter implements HttpClientAdapter {
  _Adapter(this._respond);

  final ResponseBody Function(RequestOptions options) _respond;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      _respond(options);

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Object payload) => ResponseBody.fromString(
      jsonEncode(payload),
      200,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );

MerchantApi _api(ResponseBody Function(RequestOptions) respond) {
  final api = MerchantApi(session: MerchantSession(MemorySecretStore()));
  api.dio.httpClientAdapter = _Adapter(respond);

  return api;
}

void main() {
  test('an approved vendor reads as active, not as not_enrolled', () async {
    // The exact shape GET /merchant/marketplace/enrolment returns.
    final api = _api((_) => _json({
          'data': {
            'state': 'active',
            'business_type': 'sole_prop',
            'fulfilment': 'both',
          },
        }));

    expect(await api.marketplaceState(), 'active');
  });

  test('a store that never applied reads as not_enrolled', () async {
    final api = _api((_) => _json({
          'data': {'state': 'not_enrolled', 'business_type': null},
        }));

    expect(await api.marketplaceState(), 'not_enrolled');
  });

  test('the products LIST parses instead of throwing on its envelope',
      () async {
    final api = _api((_) => _json({
          'data': [
            {
              'id': 1,
              'name': 'Apple Pencil',
              'archived': false,
              'images': <Object>[],
              'listings': [
                {
                  'id': 1,
                  'branch_id': 1,
                  'price_laari': 1000,
                  'stock_qty': 500,
                  'state': 'active',
                  'buyable': true,
                  'low_stock': false,
                },
              ],
            },
          ],
          'meta': {'pending_changes': <Object>[]},
        }));

    final rows = await api.shopProducts();

    expect(rows, hasLength(1));
    expect(rows.first.name, 'Apple Pencil');
    expect(rows.first.primary?.priceLaari, 1000);
  });

  test('an empty shelf is an empty list, not a failure', () async {
    final api = _api((_) => _json({
          'data': <Object>[],
          'meta': {'pending_changes': <Object>[]},
        }));

    expect(await api.shopProducts(), isEmpty);
  });

  test('one order unwraps its envelope exactly once', () async {
    final api = _api((_) => _json({
          'data': {
            'id': 7,
            'reference': 'MF-2026-1084-A',
            'state': 'new',
            'fulfilment': 'delivery',
            'payment_state': 'verified',
            'customer': {'name': 'Aminath Shaza', 'phone': '7771234'},
            'items': <Object>[],
          },
        }));

    final order = await api.shopOrder(7);

    expect(order.reference, 'MF-2026-1084-A');
    expect(order.customerName, 'Aminath Shaza');
    expect(order.isPaid, isTrue);
  });
}
