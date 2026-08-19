/// The one timeline (`Customer App Order Tracking.png`).
///
/// Marketplace orders and cashback transactions in a single stream, because
/// that is how a customer thinks about "what have I got going on" — the
/// distinction between an order and a cashback credit is ours, not theirs.
library;

/// One shop's line inside an order card. In a multi-vendor order the SHOPS
/// are the status: one summary word would hide that two are confirmed and
/// one is not.
class ActivityStore {
  const ActivityStore({
    required this.id,
    required this.reference,
    required this.storeName,
    required this.state,
    required this.fulfilment,
    required this.cashbackLaari,
    this.branchName,
    this.pickupCode,
  });

  factory ActivityStore.fromJson(Map<String, dynamic> json) => ActivityStore(
        id: json['id'] as int? ?? 0,
        reference: (json['reference'] as String?) ?? '',
        storeName: (json['store_name'] as String?) ?? '',
        state: (json['state'] as String?) ?? '',
        fulfilment: (json['fulfilment'] as String?) ?? '',
        cashbackLaari: json['cashback_laari'] as int? ?? 0,
        branchName: json['branch_name'] as String?,
        pickupCode: json['pickup_code'] as String?,
      );

  final int id;
  final String reference;
  final String storeName;
  final String state;
  final String fulfilment;
  final int cashbackLaari;
  final String? branchName;
  final String? pickupCode;

  bool get isPickup => fulfilment == 'pickup';

  /// The code is only worth showing while it can still be used.
  bool get showsPickupCode =>
      isPickup && pickupCode != null && state == 'ready';
}

class ActivityOrder {
  const ActivityOrder({
    required this.id,
    required this.reference,
    required this.state,
    required this.paymentState,
    required this.totalPayableLaari,
    required this.cashbackTotalLaari,
    required this.storeCount,
    required this.stores,
  });

  factory ActivityOrder.fromJson(Map<String, dynamic> json) => ActivityOrder(
        id: json['id'] as int? ?? 0,
        reference: (json['reference'] as String?) ?? '',
        state: (json['state'] as String?) ?? '',
        paymentState: (json['payment_state'] as String?) ?? '',
        totalPayableLaari: json['total_payable_laari'] as int? ?? 0,
        cashbackTotalLaari: json['cashback_total_laari'] as int? ?? 0,
        storeCount: json['store_count'] as int? ?? 0,
        stores: ((json['stores'] as List?) ?? const [])
            .whereType<Map>()
            .map((row) => ActivityStore.fromJson(row.cast<String, dynamic>()))
            .toList(growable: false),
      );

  final int id;
  final String reference;
  final String state;
  final String paymentState;
  final int totalPayableLaari;
  final int cashbackTotalLaari;
  final int storeCount;
  final List<ActivityStore> stores;

  /// The one shop whose pickup code should be on the card, if any.
  ActivityStore? get pickupReady =>
      stores.where((store) => store.showsPickupCode).firstOrNull;
}

/// A cashback credit, as the same timeline shows it.
class ActivityTransaction {
  const ActivityTransaction({
    required this.id,
    required this.reference,
    required this.state,
    required this.amountLaari,
    required this.cashbackLaari,
    this.merchantName,
    this.occurredAt,
  });

  factory ActivityTransaction.fromJson(Map<String, dynamic> json) =>
      ActivityTransaction(
        id: json['id'] as int? ?? 0,
        reference: (json['reference'] as String?) ?? '',
        state: (json['state'] as String?) ?? '',
        amountLaari: json['amount_laari'] as int? ?? 0,
        cashbackLaari: json['cashback_laari'] as int? ?? 0,
        merchantName: json['merchant_name'] as String?,
        occurredAt: DateTime.tryParse((json['occurred_at'] as String?) ?? ''),
      );

  final int id;
  final String reference;
  final String state;
  final int amountLaari;
  final int cashbackLaari;
  final String? merchantName;
  final DateTime? occurredAt;
}

/// One row of the timeline — exactly one of [order] or [transaction] is set.
class ActivityEntry {
  const ActivityEntry({required this.kind, this.at, this.order, this.transaction});

  factory ActivityEntry.fromJson(Map<String, dynamic> json) {
    final kind = (json['kind'] as String?) ?? '';

    return ActivityEntry(
      kind: kind,
      at: DateTime.tryParse((json['at'] as String?) ?? ''),
      order: kind == 'order'
          ? ActivityOrder.fromJson(
              ((json['order'] as Map?) ?? const {}).cast<String, dynamic>(),
            )
          : null,
      transaction: kind == 'transaction'
          ? ActivityTransaction.fromJson(
              ((json['transaction'] as Map?) ?? const {})
                  .cast<String, dynamic>(),
            )
          : null,
    );
  }

  final String kind;
  final DateTime? at;
  final ActivityOrder? order;
  final ActivityTransaction? transaction;

  bool get isOrder => order != null;
}

class ActivityPage {
  const ActivityPage({required this.entries, required this.hasMore});

  factory ActivityPage.fromJson(Map<String, dynamic> json) {
    final meta = (json['meta'] as Map?)?.cast<String, dynamic>();
    final page = meta?['current_page'] as int? ?? 1;
    final last = meta?['last_page'] as int? ?? 1;

    return ActivityPage(
      entries: ((json['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => ActivityEntry.fromJson(row.cast<String, dynamic>()))
          .toList(growable: false),
      hasMore: page < last,
    );
  }

  final List<ActivityEntry> entries;
  final bool hasMore;
}
