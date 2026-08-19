/// The shop's marketplace order queue and shelf
/// (`Orders.png`, `Order Details.png`, `products.png`).
library;

/// One line on an order, as the shop must pick it.
class ShopOrderItem {
  const ShopOrderItem({
    required this.id,
    required this.name,
    required this.qty,
    required this.fulfilledQty,
    required this.amended,
    required this.refundLaari,
    required this.unitPriceLaari,
    required this.lineTotalLaari,
  });

  factory ShopOrderItem.fromJson(Map<String, dynamic> json) => ShopOrderItem(
        id: json['id'] as int? ?? 0,
        name: (json['name'] as String?) ?? '',
        qty: json['qty'] as int? ?? 0,
        // What the shop actually put in the bag. Equal to qty until
        // somebody edits the order.
        fulfilledQty: json['fulfilled_qty'] as int? ?? 0,
        amended: json['amended'] as bool? ?? false,
        refundLaari: json['refund_laari'] as int? ?? 0,
        unitPriceLaari: json['unit_price_laari'] as int? ?? 0,
        lineTotalLaari: json['line_total_laari'] as int? ?? 0,
      );

  final int id;
  final String name;
  final int qty;
  final int fulfilledQty;
  final bool amended;
  final int refundLaari;
  final int unitPriceLaari;
  final int lineTotalLaari;
}

/// One shop's half of a customer's basket — a SUBORDER, which is the unit a
/// shop accepts, picks and hands over.
class ShopOrder {
  const ShopOrder({
    required this.id,
    required this.reference,
    required this.state,
    required this.fulfilment,
    required this.paymentState,
    required this.customerName,
    required this.customerPhone,
    required this.branchName,
    required this.itemsLaari,
    required this.deliveryLaari,
    required this.subtotalLaari,
    required this.cashbackLaari,
    required this.items,
    this.pickupCode,
    this.rejectReason,
    this.placedAt,
    this.address,
  });

  factory ShopOrder.fromJson(Map<String, dynamic> json) {
    final customer = (json['customer'] as Map?)?.cast<String, dynamic>();

    return ShopOrder(
      id: json['id'] as int? ?? 0,
      reference: (json['reference'] as String?) ?? '',
      state: (json['state'] as String?) ?? '',
      fulfilment: (json['fulfilment'] as String?) ?? '',
      // Whether the customer has actually paid us — the first thing a shop
      // needs to know before touching an order.
      paymentState: (json['payment_state'] as String?) ?? '',
      customerName: (customer?['name'] as String?) ?? '',
      customerPhone: (customer?['phone'] as String?) ?? '',
      branchName: (json['branch_name'] as String?) ?? '',
      itemsLaari: json['items_laari'] as int? ?? 0,
      deliveryLaari: json['delivery_laari'] as int? ?? 0,
      subtotalLaari: json['subtotal_laari'] as int? ?? 0,
      cashbackLaari: json['cashback_laari'] as int? ?? 0,
      pickupCode: json['pickup_code'] as String?,
      rejectReason: json['reject_reason'] as String?,
      placedAt: DateTime.tryParse((json['placed_at'] as String?) ?? ''),
      address: (json['address'] as Map?)?.cast<String, dynamic>(),
      items: ((json['items'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => ShopOrderItem.fromJson(row.cast<String, dynamic>()))
          .toList(growable: false),
    );
  }

  final int id;
  final String reference;
  final String state;
  final String fulfilment;
  final String paymentState;
  final String customerName;
  final String customerPhone;
  final String branchName;
  final int itemsLaari;
  final int deliveryLaari;
  final int subtotalLaari;
  final int cashbackLaari;
  final String? pickupCode;
  final String? rejectReason;
  final DateTime? placedAt;
  final Map<String, dynamic>? address;
  final List<ShopOrderItem> items;

  bool get isDelivery => fulfilment == 'delivery';

  /// Paid AND verified. The card says so, because a shop that starts picking
  /// an unpaid order is a shop we have cost money.
  bool get isPaid => paymentState == 'verified';

  int get itemCount => items.fold(0, (sum, item) => sum + item.qty);

  /// The single line of an address, as a card can show it.
  String get addressLine {
    final parts = [
      address?['address'],
      address?['island'],
    ].whereType<String>().where((part) => part.trim().isNotEmpty);

    return parts.join(', ');
  }
}

/// The queue plus the two tiles above it.
class ShopOrderPage {
  const ShopOrderPage({
    required this.orders,
    required this.newCount,
    required this.awaitingActionCount,
  });

  factory ShopOrderPage.fromJson(Map<String, dynamic> json) {
    final meta = (json['meta'] as Map?)?.cast<String, dynamic>();

    return ShopOrderPage(
      orders: ((json['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => ShopOrder.fromJson(row.cast<String, dynamic>()))
          .toList(growable: false),
      newCount: meta?['new_count'] as int? ?? 0,
      awaitingActionCount: meta?['awaiting_action_count'] as int? ?? 0,
    );
  }

  final List<ShopOrder> orders;
  final int newCount;
  final int awaitingActionCount;
}

/// One shelf listing, as the app may edit it: price, stock, visibility.
class ShopListing {
  const ShopListing({
    required this.id,
    required this.branchId,
    required this.priceLaari,
    required this.state,
    required this.buyable,
    required this.lowStock,
    this.stockQty,
  });

  factory ShopListing.fromJson(Map<String, dynamic> json) => ShopListing(
        id: json['id'] as int? ?? 0,
        branchId: json['branch_id'] as int? ?? 0,
        priceLaari: json['price_laari'] as int? ?? 0,
        state: (json['state'] as String?) ?? '',
        buyable: json['buyable'] as bool? ?? false,
        lowStock: json['low_stock'] as bool? ?? false,
        stockQty: json['stock_qty'] as int?,
      );

  final int id;
  final int branchId;
  final int priceLaari;
  final String state;
  final bool buyable;
  final bool lowStock;
  final int? stockQty;

  bool get outOfStock => stockQty != null && stockQty! <= 0;
}

/// A product as the shelf screen lists it.
class ShopProduct {
  const ShopProduct({
    required this.id,
    required this.name,
    required this.archived,
    required this.listings,
    this.sku,
    this.shelf,
    this.imageUrl,
  });

  factory ShopProduct.fromJson(Map<String, dynamic> json) {
    final shelf = (json['marketplace_category'] as Map?)?.cast<String, dynamic>();
    final images = (json['images'] as List?) ?? const [];

    return ShopProduct(
      id: json['id'] as int? ?? 0,
      name: (json['name'] as String?) ?? '',
      archived: json['archived'] as bool? ?? false,
      sku: json['sku'] as String?,
      shelf: shelf?['name_en'] as String?,
      imageUrl: images.isEmpty
          ? null
          : (images.first as Map?)?.cast<String, dynamic>()['url'] as String?,
      listings: ((json['listings'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => ShopListing.fromJson(row.cast<String, dynamic>()))
          .toList(growable: false),
    );
  }

  final int id;
  final String name;
  final bool archived;
  final String? sku;
  final String? shelf;
  final String? imageUrl;
  final List<ShopListing> listings;

  /// The app edits ONE shop's listing at a time; a product with none is on
  /// no shelf yet and is a draft.
  ShopListing? get primary => listings.isEmpty ? null : listings.first;

  bool get isDraft => listings.isEmpty || archived;

  bool get isOutOfStock => primary?.outOfStock ?? false;

  bool get isLowStock => (primary?.lowStock ?? false) && !isOutOfStock;

  bool get isActive => !isDraft && (primary?.buyable ?? false) && !isOutOfStock;
}
