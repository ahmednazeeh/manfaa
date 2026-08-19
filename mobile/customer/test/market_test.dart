import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

void main() {
  group('marketplace models', () {
    test('a delivery quote reads its own progress', () {
      final terms = DeliveryTerms.fromJson({
        'delivers': true,
        'fee_laari': 2500,
        'fee_waived': false,
        'free_delivery_over_laari': 50000,
        'order_minimum_laari': 20000,
        'minimum_met': false,
        'shortfall_laari': 8000,
        'to_free_delivery_laari': 38000,
        'eta_min': 30,
        'eta_max': 60,
      });

      // The exact numbers the cart's warning and the progress bar render.
      expect(terms.shortfallLaari, 8000);
      expect(terms.toFreeDeliveryLaari, 38000);
      expect(terms.etaLabel, '30–60 min');
    });

    test('an eta with one bound does not render a dash to nowhere', () {
      expect(DeliveryTerms.fromJson({'eta_min': 45}).etaLabel, '45 min');
      expect(DeliveryTerms.fromJson({'eta_max': 45}).etaLabel, '45 min');
      expect(DeliveryTerms.fromJson(const {}).etaLabel, isNull);
    });

    test('a shop nobody has rated has NO rating, not zero stars', () {
      final branch = MarketBranch.fromJson({
        'branch_id': 1,
        'store_name': 'Island Mart',
        'branch_name': 'Malé',
        'rating': null,
        'rating_count': 0,
        'delivery': const {'delivers': true},
      });

      // Zero stars would libel a new shop on its first day.
      expect(branch.rating, isNull);
      expect(branch.ratingCount, 0);
    });

    test('a subcart titles itself with brand AND island', () {
      final subcart = Subcart.fromJson({
        'branch_id': 3,
        'store_name': 'Island Mart',
        'branch_name': 'Hulhumalé',
        'items': const [],
        'delivery': const {'delivers': true},
      });

      // Never a bare branch id — the shopper chose a shop, not a row.
      expect(subcart.title, 'Island Mart — Hulhumalé');
    });

    test('the cart names which shops are blocking checkout', () {
      final cart = Cart.fromJson({
        'store_count': 2,
        'can_checkout': false,
        'subcarts': [
          {
            'branch_id': 1,
            'store_name': 'Island Mart',
            'branch_name': 'Malé',
            'items': [
              {'cart_item_id': 1, 'branch_product_id': 9, 'qty': 2},
            ],
            'all_available': true,
            'delivery': {'delivers': true, 'minimum_met': true},
          },
          {
            'branch_id': 2,
            'store_name': 'Horizon',
            'branch_name': 'Malé',
            'items': [
              {'cart_item_id': 2, 'branch_product_id': 8, 'qty': 1},
            ],
            'all_available': true,
            'delivery': {
              'delivers': true,
              'minimum_met': false,
              'shortfall_laari': 3000,
            },
          },
        ],
      });

      // The button says WHICH shop is short rather than refusing silently.
      expect(cart.blocking, hasLength(1));
      expect(cart.blocking.first.storeName, 'Horizon');
      expect(cart.itemCount, 2);
    });

    test('a sold-out line blocks its own shop', () {
      final cart = Cart.fromJson({
        'subcarts': [
          {
            'branch_id': 1,
            'store_name': 'Island Mart',
            'branch_name': 'Malé',
            'items': const [],
            'all_available': false,
            'delivery': {'delivers': true, 'minimum_met': true},
          },
        ],
      });

      expect(cart.blocking, hasLength(1));
    });

    test('a price that moved is carried, not hidden', () {
      final line = CartLine.fromJson({
        'cart_item_id': 1,
        'branch_product_id': 5,
        'name': 'Rice',
        'qty': 1,
        'unit_price_laari': 12000,
        'price_changed': true,
        'price_was_laari': 10000,
        'available': true,
      });

      // Quietly billing yesterday's price is worse than saying it changed.
      expect(line.priceChanged, isTrue);
      expect(line.priceWasLaari, 10000);
      expect(line.unitPriceLaari, 12000);
    });
  });
}
