/// Orders and the wallet, customer side
/// (`Order Received.png`, `Customer App Order Tracking.png`).
library;

class CustomerOrder {
  const CustomerOrder({
    required this.id,
    required this.reference,
    required this.state,
    required this.paymentState,
    required this.paymentMethod,
    required this.itemsLaari,
    required this.deliveryLaari,
    required this.totalPayableLaari,
    required this.cashbackTotalLaari,
    required this.storeCount,
    required this.suborders,
    this.placedAt,
    this.address,
  });

  factory CustomerOrder.fromJson(Map<String, dynamic> json) => CustomerOrder(
        id: json['id'] as int? ?? 0,
        reference: (json['reference'] as String?) ?? '',
        state: (json['state'] as String?) ?? '',
        paymentState: (json['payment_state'] as String?) ?? '',
        paymentMethod: (json['payment_method'] as String?) ?? '',
        itemsLaari: json['items_laari'] as int? ?? 0,
        deliveryLaari: json['delivery_laari'] as int? ?? 0,
        totalPayableLaari: json['total_payable_laari'] as int? ?? 0,
        cashbackTotalLaari: json['cashback_total_laari'] as int? ?? 0,
        storeCount: json['store_count'] as int? ?? 0,
        placedAt: json['placed_at'] as String?,
        address: (json['address'] as Map?)?.cast<String, dynamic>(),
        suborders: ((json['suborders'] as List?) ?? const [])
            .map((row) =>
                CustomerSuborder.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
      );

  final int id;
  final String reference;
  final String state;

  /// awaiting_proof → proof_submitted → verified. Nothing is confirmed until
  /// a human has seen the transfer.
  final String paymentState;
  final String paymentMethod;
  final int itemsLaari;
  final int deliveryLaari;
  final int totalPayableLaari;
  final int cashbackTotalLaari;
  final int storeCount;
  final String? placedAt;
  final Map<String, dynamic>? address;
  final List<CustomerSuborder> suborders;

  bool get needsReceipt => paymentState == 'awaiting_proof' || paymentState == 'refused';
}

/// One shop's part of an order — the unit that is accepted, cut and handed
/// over. In a multi-vendor order the shops ARE the status.
class CustomerSuborder {
  const CustomerSuborder({
    required this.id,
    required this.reference,
    required this.storeName,
    required this.branchName,
    required this.fulfilment,
    required this.state,
    required this.itemsLaari,
    required this.deliveryLaari,
    required this.subtotalLaari,
    required this.cashbackLaari,
    required this.items,
    this.rejectReason,
    this.pickupCode,
  });

  factory CustomerSuborder.fromJson(Map<String, dynamic> json) =>
      CustomerSuborder(
        id: json['id'] as int? ?? 0,
        reference: (json['reference'] as String?) ?? '',
        storeName: (json['store_name'] as String?) ?? '',
        branchName: (json['branch_name'] as String?) ?? '',
        fulfilment: (json['fulfilment'] as String?) ?? 'delivery',
        state: (json['state'] as String?) ?? '',
        itemsLaari: json['items_laari'] as int? ?? 0,
        deliveryLaari: json['delivery_laari'] as int? ?? 0,
        subtotalLaari: json['subtotal_laari'] as int? ?? 0,
        cashbackLaari: json['cashback_laari'] as int? ?? 0,
        rejectReason: json['reject_reason'] as String?,
        pickupCode: json['pickup_code'] as String?,
        items: ((json['items'] as List?) ?? const [])
            .map((row) =>
                OrderLine.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
      );

  final int id;
  final String reference;
  final String storeName;
  final String branchName;
  final String fulfilment;
  final String state;
  final int itemsLaari;
  final int deliveryLaari;
  final int subtotalLaari;
  final int cashbackLaari;
  final String? rejectReason;

  /// Shown at the counter for a collection order.
  final String? pickupCode;
  final List<OrderLine> items;

  String get title => '$storeName — $branchName';

  /// Something in this order was cut by the shop.
  bool get wasAmended => items.any((line) => line.amended);

  int get refundedLaari =>
      items.fold(0, (sum, line) => sum + line.refundLaari);
}

class OrderLine {
  const OrderLine({
    required this.id,
    required this.name,
    required this.qty,
    required this.fulfilledQty,
    required this.amended,
    required this.refundLaari,
    required this.unitPriceLaari,
    required this.lineTotalLaari,
  });

  factory OrderLine.fromJson(Map<String, dynamic> json) => OrderLine(
        id: json['id'] as int? ?? 0,
        name: (json['name'] as String?) ?? '',
        qty: json['qty'] as int? ?? 0,
        fulfilledQty: json['fulfilled_qty'] as int? ?? 0,
        amended: json['amended'] as bool? ?? false,
        refundLaari: json['refund_laari'] as int? ?? 0,
        unitPriceLaari: json['unit_price_laari'] as int? ?? 0,
        lineTotalLaari: json['line_total_laari'] as int? ?? 0,
      );

  final int id;
  final String name;

  /// What was ORDERED. Never changes — the strike-through is drawn from the
  /// gap between this and [fulfilledQty].
  final int qty;

  /// What the shop will actually supply.
  final int fulfilledQty;
  final bool amended;
  final int refundLaari;
  final int unitPriceLaari;
  final int lineTotalLaari;

  bool get removed => fulfilledQty == 0;
}

/// Where to bring it.
class CustomerAddressEntry {
  const CustomerAddressEntry({
    required this.id,
    required this.label,
    required this.recipientName,
    required this.phone,
    required this.building,
    required this.isDefault,
    this.island,
    this.areaMagu,
    this.apartmentFloor,
    this.deliveryNote,
    this.lat,
    this.lng,
    this.zoneId,
    this.zoneName,
  });

  factory CustomerAddressEntry.fromJson(Map<String, dynamic> json) =>
      CustomerAddressEntry(
        id: json['id'] as int? ?? 0,
        label: (json['label'] as String?) ?? '',
        recipientName: (json['recipient_name'] as String?) ?? '',
        phone: (json['phone'] as String?) ?? '',
        building: (json['building'] as String?) ?? '',
        isDefault: json['is_default'] as bool? ?? false,
        island: json['island'] as String?,
        areaMagu: json['area_magu'] as String?,
        apartmentFloor: json['apartment_floor'] as String?,
        deliveryNote: json['delivery_note'] as String?,
        lat: (json['lat'] as num?)?.toDouble(),
        lng: (json['lng'] as num?)?.toDouble(),
        zoneId: json['zone_id'] as int?,
        zoneName: json['zone_name'] as String?,
      );

  final int id;
  final String label;
  final String recipientName;
  final String phone;
  final String building;
  final bool isDefault;
  final String? island;
  final String? areaMagu;
  final String? apartmentFloor;
  final String? deliveryNote;
  final double? lat;
  final double? lng;

  /// Resolved from the PIN, never from what was typed. Null means no branch
  /// can quote delivery there yet — honest, not an error.
  final int? zoneId;
  final String? zoneName;

  String get oneLine => [
        building,
        if ((apartmentFloor ?? '').isNotEmpty) apartmentFloor,
        if ((areaMagu ?? '').isNotEmpty) areaMagu,
        if ((island ?? '').isNotEmpty) island,
      ].whereType<String>().join(', ');
}

/// A real, stored balance — distinct from the derived cashback figure.
class WalletState {
  const WalletState({
    required this.balanceLaari,
    required this.minimumWithdrawalLaari,
    required this.canWithdraw,
    required this.hasBankAccount,
    required this.entries,
    required this.withdrawals,
  });

  factory WalletState.fromJson(Map<String, dynamic> json) => WalletState(
        balanceLaari: json['balance_laari'] as int? ?? 0,
        minimumWithdrawalLaari: json['minimum_withdrawal_laari'] as int? ?? 0,
        canWithdraw: json['can_withdraw'] as bool? ?? false,
        hasBankAccount: json['has_bank_account'] as bool? ?? false,
        entries: ((json['entries'] as List?) ?? const [])
            .map((row) =>
                WalletEntry.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
        withdrawals: ((json['withdrawals'] as List?) ?? const [])
            .map((row) =>
                WalletWithdrawal.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
      );

  final int balanceLaari;
  final int minimumWithdrawalLaari;
  final bool canWithdraw;
  final bool hasBankAccount;
  final List<WalletEntry> entries;
  final List<WalletWithdrawal> withdrawals;
}

class WalletEntry {
  const WalletEntry({
    required this.id,
    required this.amountLaari,
    required this.balanceAfterLaari,
    required this.type,
    this.description,
    this.at,
  });

  factory WalletEntry.fromJson(Map<String, dynamic> json) => WalletEntry(
        id: json['id'] as int? ?? 0,
        amountLaari: json['amount_laari'] as int? ?? 0,
        balanceAfterLaari: json['balance_after_laari'] as int? ?? 0,
        type: (json['type'] as String?) ?? '',
        description: json['description'] as String?,
        at: json['at'] as String?,
      );

  final int id;

  /// Signed: credits positive, withdrawals negative.
  final int amountLaari;
  final int balanceAfterLaari;
  final String type;
  final String? description;
  final String? at;
}

class WalletWithdrawal {
  const WalletWithdrawal({
    required this.id,
    required this.amountLaari,
    required this.state,
    this.requestedAt,
    this.bankReference,
  });

  factory WalletWithdrawal.fromJson(Map<String, dynamic> json) =>
      WalletWithdrawal(
        id: json['id'] as int? ?? 0,
        amountLaari: json['amount_laari'] as int? ?? 0,
        state: (json['state'] as String?) ?? '',
        requestedAt: json['requested_at'] as String?,
        bankReference: json['bank_reference'] as String?,
      );

  final int id;
  final int amountLaari;
  final String state;
  final String? requestedAt;

  /// The bank's own reference. Never an approval-queue id — quoting one of
  /// those at a bank gets nowhere.
  final String? bankReference;
}
