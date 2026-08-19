/// The multi-vendor cart, as the server prices it
/// (`Cart Page Collapsible By Merchant.png`, `Cart Page Expanded.png`).
///
/// Every number here is computed server-side and simply rendered: delivery
/// thresholds, per-shop minimums and cashback projections all have to agree
/// with what checkout charges, and a cart that did its own arithmetic would
/// be a second opinion waiting to disagree with the till.
library;

import 'market_models.dart';

class Cart {
  const Cart({
    required this.subcarts,
    required this.itemsLaari,
    required this.deliveryLaari,
    required this.totalPayableLaari,
    required this.cashbackLaari,
    required this.storeCount,
    required this.canCheckout,
    required this.needsAddress,
    this.addressId,
  });

  factory Cart.fromJson(Map<String, dynamic> json) => Cart(
        subcarts: ((json['subcarts'] as List?) ?? const [])
            .map((row) => Subcart.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
        itemsLaari: json['items_laari'] as int? ?? 0,
        deliveryLaari: json['delivery_laari'] as int? ?? 0,
        totalPayableLaari: json['total_payable_laari'] as int? ?? 0,
        cashbackLaari: json['cashback_laari'] as int? ?? 0,
        storeCount: json['store_count'] as int? ?? 0,
        canCheckout: json['can_checkout'] as bool? ?? false,
        needsAddress: json['needs_address'] as bool? ?? false,
        addressId: json['address_id'] as int?,
      );

  final List<Subcart> subcarts;
  final int itemsLaari;
  final int deliveryLaari;
  final int totalPayableLaari;
  final int cashbackLaari;
  final int storeCount;

  /// One boolean over every subcart. The button is disabled on this, and the
  /// screen still names WHICH shop is short.
  final bool canCheckout;
  final bool needsAddress;
  final int? addressId;

  bool get isEmpty => subcarts.isEmpty;

  /// Every line across every shop — what the floating bar counts.
  int get itemCount =>
      subcarts.fold(0, (sum, subcart) => sum + subcart.items.length);

  /// The shops that cannot be checked out, in the words the button needs.
  List<Subcart> get blocking => subcarts
      .where((subcart) => !subcart.delivery.minimumMet || !subcart.allAvailable)
      .toList(growable: false);
}

/// One shop's card — the collapsible section in the mockup.
class Subcart {
  const Subcart({
    required this.branchId,
    required this.merchantId,
    required this.storeName,
    required this.branchName,
    required this.items,
    required this.itemsLaari,
    required this.cashbackLaari,
    this.cashbackRatePercent,
    required this.delivery,
    required this.allAvailable,
  });

  factory Subcart.fromJson(Map<String, dynamic> json) => Subcart(
        branchId: json['branch_id'] as int? ?? 0,
        merchantId: json['merchant_id'] as int? ?? 0,
        storeName: (json['store_name'] as String?) ?? '',
        branchName: (json['branch_name'] as String?) ?? '',
        items: ((json['items'] as List?) ?? const [])
            .map((row) => CartLine.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
        itemsLaari: json['items_laari'] as int? ?? 0,
        cashbackLaari: json['cashback_laari'] as int? ?? 0,
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        delivery: DeliveryTerms.fromJson(
          (json['delivery'] as Map?)?.cast<String, dynamic>() ?? const {},
        ),
        allAvailable: json['all_available'] as bool? ?? true,
      );

  final int branchId;
  final int merchantId;
  final String storeName;
  final String branchName;
  final List<CartLine> items;
  final int itemsLaari;
  final int cashbackLaari;
  final String? cashbackRatePercent;
  final DeliveryTerms delivery;

  /// False when something in this shop's basket has sold out. The line says
  /// which — it is flagged where it sits, never dropped.
  final bool allAvailable;

  /// "Island Mart — Malé". Brand and island, never a bare branch id.
  String get title => '$storeName — $branchName';

  int get subtotalLaari => itemsLaari + delivery.feeLaari;
}

class CartLine {
  const CartLine({
    required this.cartItemId,
    required this.branchProductId,
    required this.productId,
    required this.name,
    this.nameDv,
    required this.qty,
    required this.unitPriceLaari,
    required this.lineTotalLaari,
    required this.cashbackLaari,
    required this.priceChanged,
    this.priceWasLaari,
    required this.available,
    this.stockQty,
  });

  factory CartLine.fromJson(Map<String, dynamic> json) => CartLine(
        cartItemId: json['cart_item_id'] as int? ?? 0,
        branchProductId: json['branch_product_id'] as int? ?? 0,
        productId: json['product_id'] as int? ?? 0,
        name: (json['name'] as String?) ?? '',
        nameDv: json['name_dv'] as String?,
        qty: json['qty'] as int? ?? 0,
        unitPriceLaari: json['unit_price_laari'] as int? ?? 0,
        lineTotalLaari: json['line_total_laari'] as int? ?? 0,
        cashbackLaari: json['cashback_laari'] as int? ?? 0,
        priceChanged: json['price_changed'] as bool? ?? false,
        priceWasLaari: json['price_was_laari'] as int?,
        available: json['available'] as bool? ?? true,
        stockQty: json['stock_qty'] as int?,
      );

  final int cartItemId;
  final int branchProductId;
  final int productId;
  final String name;
  final String? nameDv;
  final int qty;
  final int unitPriceLaari;
  final int lineTotalLaari;
  final int cashbackLaari;

  /// The price moved while this sat in the basket. Said out loud rather than
  /// applied silently — a basket that quietly bills yesterday's price is
  /// worse than one that admits the change.
  final bool priceChanged;
  final int? priceWasLaari;

  /// Sold out since it went in. Stays on screen, flagged: a row that
  /// vanishes reads as a bug, and nobody can act on what they cannot see.
  final bool available;
  final int? stockQty;

  String displayName(bool dhivehi) =>
      dhivehi && (nameDv?.isNotEmpty ?? false) ? nameDv! : name;
}
