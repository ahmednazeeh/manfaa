import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The exact bytes GET /merchant/marketplace/products returns today, fed
/// through the parser the Products screen uses.
///
/// Written because that screen reported "Something went wrong" — the app's
/// fallback for an error that is NOT an API error, which means a parse or a
/// type fault rather than a refusal.
const _payload = '''
{"data":[{"id":1,"name":"Apple Pencil","name_dv":"ellow","description":null,
"sku":null,"marketplace_category":null,"cashback_category":null,
"cashback_rate_percent":null,"allow_substitutions":true,"archived":false,
"images":[],"listings":[{"id":1,"branch_id":1,"price_laari":1000,
"compare_at_laari":null,"stock_qty":500,"low_stock_at":null,"state":"active",
"buyable":true,"low_stock":false}]}],"meta":{"pending_changes":[]}}
''';

void main() {
  test('the live products payload parses', () {
    final body = jsonDecode(_payload) as Map<String, dynamic>;

    final rows = (body['data'] as List)
        .whereType<Map>()
        .map((row) => ShopProduct.fromJson(row.cast<String, dynamic>()))
        .toList();

    expect(rows, hasLength(1));
    expect(rows.first.name, 'Apple Pencil');
    expect(rows.first.primary?.priceLaari, 1000);
    expect(rows.first.isActive, isTrue);
  });

  test('a product with no listings is a draft, not a crash', () {
    final row = ShopProduct.fromJson({
      'id': 2,
      'name': 'Unshelved',
      'images': [],
      'listings': [],
    });

    expect(row.isDraft, isTrue);
    expect(row.primary, isNull);
  });

  test('an empty payload parses', () {
    final row = ShopProduct.fromJson(const {});

    expect(row.id, 0);
    expect(row.listings, isEmpty);
  });
}
