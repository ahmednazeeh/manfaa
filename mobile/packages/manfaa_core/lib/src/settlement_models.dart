/// Typed views over the merchant settlements suite (MR3) — the wire shapes
/// of SettlementController + OutstandingSummary + SettlementPreview +
/// SettlementResource/WalletResource, parsed EXACTLY as the server emits
/// them.
///
/// Same laws as merchant_models.dart: hand-written fromJson, money is
/// INTEGER LAARI end to end, rates are 2-decimal percent STRINGS kept exact,
/// and the server's `*_mvr` display strings are ignored on purpose — they
/// are English-only server formatting, and the app renders laari through
/// formatMoney so dv gets its own shape.
library;

int _laari(Object? v) => switch (v) { final int i => i, _ => 0 };
int _count(Object? v) => switch (v) { final int i => i, _ => 0 };
String _s(Object? v) => v?.toString() ?? '';

Map<String, dynamic> _map(Object? v) =>
    (v as Map?)?.cast<String, dynamic>() ?? const {};

// ---------------------------------------------------------------------------
// Slip pre-flight (the client half of SlipStorage's rules)
// ---------------------------------------------------------------------------

/// SlipStorage::MAX_BYTES — the server refuses anything larger.
const int kMaxSlipBytes = 5 * 1024 * 1024;

/// What the SERVER accepts, decided there by magic bytes. This list is the
/// courtesy check that saves a 5 MB round trip; it is never the authority —
/// a renamed .svg passes here and is still refused by the API
/// (`slip_unsupported_type`).
const Set<String> kSlipExtensions = {'jpg', 'jpeg', 'png', 'webp', 'pdf'};

enum SlipRejection { tooLarge, unsupportedType }

/// Client-side pre-flight on a chosen slip: size FIRST (a 40 MB photo is
/// refused before its bytes are ever read), then the type by extension.
/// Null when the file looks acceptable — the server still decides for real.
SlipRejection? checkSlipFile({required String name, required int sizeBytes}) {
  if (sizeBytes > kMaxSlipBytes) return SlipRejection.tooLarge;
  if (sizeBytes <= 0) return SlipRejection.unsupportedType;

  final extension = name.split('.').last.toLowerCase();
  return kSlipExtensions.contains(extension)
      ? null
      : SlipRejection.unsupportedType;
}

// ---------------------------------------------------------------------------
// Wallet (GET /merchant/wallet — WalletResource)
// ---------------------------------------------------------------------------

/// The merchant wallet: balance + movements, newest first. The first-ever
/// read answers 201 (the row is created on demand) — same body either way.
///
/// Since 2026-08-24 the wallet is PRE-FUNDABLE: the payload also carries the
/// platform's top-up floor, the merchant's auto-settle switch (READ here,
/// WRITTEN only through PATCH /merchant/preferences) and the top-up claims
/// still waiting on the bank or an admin.
class MerchantWalletState {
  MerchantWalletState({
    required this.balanceLaari,
    required this.currency,
    required this.movements,
    this.topUpMinLaari = 0,
    this.autoSettleFromWallet = true,
    this.bankAccounts = const [],
    this.pendingTopUps = const [],
  });

  factory MerchantWalletState.fromJson(Map<String, dynamic> json) =>
      MerchantWalletState(
        balanceLaari: _laari(json['balance_laari']),
        currency: _s(json['currency']),
        movements: [
          for (final item in (json['transactions'] as List? ?? const []))
            WalletMovement.fromJson((item as Map).cast<String, dynamic>()),
        ],
        topUpMinLaari: _laari(json['top_up_min_laari']),
        // The server default is ON (owner decision): a payload that predates
        // the key reads the same way the row was backfilled.
        autoSettleFromWallet: json['auto_settle_from_wallet'] as bool? ?? true,
        bankAccounts: [
          for (final item in (json['bank_accounts'] as List? ?? const []))
            PlatformBankAccount.fromJson((item as Map).cast<String, dynamic>()),
        ],
        pendingTopUps: [
          for (final item in (json['pending_top_ups'] as List? ?? const []))
            WalletTopUpClaim.fromJson((item as Map).cast<String, dynamic>()),
        ],
      );

  final int balanceLaari;
  final String currency;
  final List<WalletMovement> movements;

  /// The smallest transfer the merchant may claim as a top-up (integer
  /// laari; the superadmin's `wallet_top_up_min_laari`, MVR 100 by
  /// default). Zero when the server did not say — the server enforces it
  /// again regardless (`top_up_below_minimum`).
  final int topUpMinLaari;

  /// Whether the hourly run settles validated cashback from this balance
  /// (oldest first, as far as the balance goes). Written through
  /// `MerchantApi.setAutoSettle`, never through the wallet route.
  final bool autoSettleFromWallet;

  /// Where a top-up may be sent: the platform's active accounts, default
  /// first — the same list a settlement's payment instructions carry,
  /// published here so a store that has never settled can still fund
  /// itself. Empty means nothing is configured; never invent one.
  final List<PlatformBankAccount> bankAccounts;

  /// Top-up claims worth showing, newest first: `pending` ones (money the
  /// merchant has sent that is not yet balance) and those `rejected` in
  /// the last week, carrying the reason.
  final List<WalletTopUpClaim> pendingTopUps;
}

/// One wallet top-up claim (WalletTopUpResource, and the slimmer
/// `pending_top_ups[]` entry on the wallet payload — both parse here, so the
/// claim a submit answers and the claim the wallet lists are one shape).
class WalletTopUpClaim {
  WalletTopUpClaim({
    required this.id,
    required this.amountLaari,
    this.bankRef,
    this.platformBankAccountId,
    this.bank,
    required this.state,
    this.rejectedReason,
    this.matchedAt,
    this.rejectedAt,
    required this.createdAt,
  });

  factory WalletTopUpClaim.fromJson(Map<String, dynamic> json) {
    // The wallet payload nests the account as `bank`; the resource as
    // `platform_bank_account`. Either is the same three display fields.
    final bank = json['bank'] is Map
        ? _map(json['bank'])
        : json['platform_bank_account'] is Map
            ? _map(json['platform_bank_account'])
            : null;

    return WalletTopUpClaim(
      id: json['id'] as int? ?? 0,
      amountLaari: _laari(json['amount_laari']),
      bankRef: json['bank_ref'] as String?,
      platformBankAccountId:
          json['platform_bank_account_id'] as int? ?? bank?['id'] as int?,
      bank: bank == null ? null : PlatformBankAccount.fromJson(bank),
      state: _s(json['state']),
      rejectedReason: json['rejected_reason'] as String?,
      matchedAt: json['matched_at'] as String?,
      rejectedAt: json['rejected_at'] as String?,
      createdAt: _s(json['created_at']),
    );
  }

  final int id;
  final int amountLaari;

  /// What the MERCHANT typed — often nothing; the slip carries it.
  final String? bankRef;
  final int? platformBankAccountId;

  /// The platform account the merchant said they paid, or null when the
  /// server did not load it.
  final PlatformBankAccount? bank;

  /// pending | matched | rejected.
  final String state;

  /// Manfaa's reason, only on a rejected claim.
  final String? rejectedReason;
  final String? matchedAt;
  final String? rejectedAt;

  /// ISO 8601 UTC.
  final String createdAt;

  bool get isPending => state == 'pending';
  bool get isMatched => state == 'matched';
  bool get isRejected => state == 'rejected';
}

/// One wallet movement. `amountLaari` is SIGNED — positive credits the
/// wallet (top_up, settlement_credit), negative draws from it (settlement).
class WalletMovement {
  WalletMovement({
    required this.id,
    required this.amountLaari,
    required this.balanceAfterLaari,
    required this.type,
    this.referenceType,
    this.referenceId,
    this.description,
    required this.createdAt,
  });

  factory WalletMovement.fromJson(Map<String, dynamic> json) => WalletMovement(
        id: json['id'] as int? ?? 0,
        amountLaari: _laari(json['amount_laari']),
        balanceAfterLaari: _laari(json['balance_after_laari']),
        type: _s(json['type']),
        referenceType: json['reference_type'] as String?,
        referenceId: json['reference_id'] as int?,
        description: json['description'] as String?,
        createdAt: _s(json['created_at']),
      );

  final int id;
  final int amountLaari;
  final int balanceAfterLaari;

  /// top_up | settlement | settlement_credit — anything newer renders the
  /// generic label, never the raw code.
  final String type;
  final String? referenceType;
  final int? referenceId;
  final String? description;
  final String createdAt;

  bool get isCredit => amountLaari >= 0;
}

// ---------------------------------------------------------------------------
// Discount + payment instructions (shared by preview and settlement)
// ---------------------------------------------------------------------------

/// One prompt-payment discount evaluation (PromptDiscountResult::toArray).
/// A refusal is an ANSWER — `reasonCode` says why, and the UI turns it into
/// a sentence, never an absence.
class SettlementDiscount {
  SettlementDiscount({
    required this.eligible,
    required this.reasonCode,
    required this.ratePercent,
    required this.maxAgeDays,
    required this.discountLaari,
    required this.feeDiscountLaari,
    required this.gstReliefLaari,
  });

  factory SettlementDiscount.fromJson(Map<String, dynamic> json) =>
      SettlementDiscount(
        eligible: json['eligible'] as bool? ?? false,
        reasonCode: _s(json['reason_code']),
        ratePercent: _s(json['rate_percent']),
        maxAgeDays: _count(json['max_age_days']),
        discountLaari: _laari(json['discount_laari']),
        feeDiscountLaari: _laari(json['fee_discount_laari']),
        gstReliefLaari: _laari(json['gst_relief_laari']),
      );

  final bool eligible;

  /// eligible | not_all_outstanding | line_too_old | clock_not_started |
  /// disabled.
  final String reasonCode;

  /// The platform's configured rate as the exact percent string ("5.00").
  final String ratePercent;
  final int maxAgeDays;
  final int discountLaari;
  final int feeDiscountLaari;
  final int gstReliefLaari;

  /// True when the platform has the incentive switched off entirely.
  bool get disabled => reasonCode == 'disabled' || _rateIsZero;

  bool get _rateIsZero {
    final trimmed = ratePercent.replaceAll(RegExp(r'[.0]+$'), '');
    return ratePercent.isNotEmpty && (trimmed.isEmpty || trimmed == '0');
  }
}

/// One platform bank account. The preview/settlement `bank_account` block
/// carries only the three display fields; entries of `bank_accounts`
/// (BankAccountService::activeAccounts) also carry id/currency/is_primary.
class PlatformBankAccount {
  PlatformBankAccount({
    this.id,
    required this.bankName,
    required this.accountNo,
    required this.accountName,
    this.currency,
    this.isPrimary = false,
  });

  factory PlatformBankAccount.fromJson(Map<String, dynamic> json) =>
      PlatformBankAccount(
        id: json['id'] as int?,
        bankName: _s(json['bank_name']),
        accountNo: _s(json['account_no']),
        accountName: _s(json['account_name']),
        currency: json['currency'] as String?,
        isPrimary: json['is_primary'] as bool? ?? false,
      );

  final int? id;

  /// A bank slug ('bml', 'mib') or free text from the pre-slug era — the UI
  /// resolves known slugs to names and prints anything else verbatim.
  final String bankName;
  final String accountNo;
  final String accountName;
  final String? currency;
  final bool isPrimary;
}

/// Where to send the transfer and what to send — the
/// `payment_instructions` block of both the pre-submit preview and a
/// created settlement.
///
/// [reference] is NULL on a preview and set on a real settlement: there is
/// no batch until the receipt lands, so the server quotes no number before
/// then (owner decision 2026-08-18) rather than one the next submitter can
/// take out from under a merchant who already wrote it on a transfer.
class SettlementInstructions {
  SettlementInstructions({
    this.reference,
    required this.amountDueLaari,
    this.bankAccount,
    required this.bankAccounts,
    required this.needsConfiguration,
  });

  factory SettlementInstructions.fromJson(Map<String, dynamic> json) =>
      SettlementInstructions(
        reference: json['reference'] == null ? null : _s(json['reference']),
        amountDueLaari: _laari(json['amount_due_laari']),
        bankAccount: json['bank_account'] is Map
            ? PlatformBankAccount.fromJson(_map(json['bank_account']))
            : null,
        bankAccounts: [
          for (final item in (json['bank_accounts'] as List? ?? const []))
            PlatformBankAccount.fromJson((item as Map).cast<String, dynamic>()),
        ],
        needsConfiguration: json['needs_configuration'] as bool? ?? false,
      );

  /// Null before the settlement exists — see the class doc.
  final String? reference;
  final int amountDueLaari;

  /// The default account to show — null ONLY when nothing is configured
  /// (needsConfiguration true): details are never invented.
  final PlatformBankAccount? bankAccount;

  /// Every account the merchant may send to, one per bank, default first.
  final List<PlatformBankAccount> bankAccounts;
  final bool needsConfiguration;
}

// ---------------------------------------------------------------------------
// Preview (GET /merchant/settlements/preview — SettlementPreview::for)
// ---------------------------------------------------------------------------

/// One eligible transaction as the preview's picker row: the identity the
/// merchant recognises, the server-computed age/overdue verdicts, and the
/// stored money integers. The app never re-derives a date rule or a rate.
class SettlementPickerRow {
  SettlementPickerRow({
    required this.id,
    required this.invoiceNo,
    this.occurredAt,
    this.clockStartAt,
    this.dueAt,
    required this.ageDays,
    required this.overdue,
    required this.cashbackLaari,
    required this.feeLaari,
    required this.feeGstLaari,
    required this.dueLaari,
    required this.selected,
  });

  factory SettlementPickerRow.fromJson(Map<String, dynamic> json) =>
      SettlementPickerRow(
        id: json['id'] as int? ?? 0,
        invoiceNo: _s(json['invoice_no']),
        occurredAt: json['occurred_at'] as String?,
        clockStartAt: json['clock_start_at'] as String?,
        dueAt: json['due_at'] as String?,
        ageDays: _count(json['age_days']),
        overdue: json['overdue'] as bool? ?? false,
        cashbackLaari: _laari(json['cashback_laari']),
        feeLaari: _laari(json['fee_laari']),
        feeGstLaari: _laari(json['fee_gst_laari']),
        dueLaari: _laari(json['due_laari']),
        selected: json['selected'] as bool? ?? false,
      );

  final int id;
  final String invoiceNo;
  final String? occurredAt;
  final String? clockStartAt;
  final String? dueAt;
  final int ageDays;
  final bool overdue;
  final int cashbackLaari;
  final int feeLaari;
  final int feeGstLaari;
  final int dueLaari;
  final bool selected;
}

/// One filter preset over everything eligible: counts, server-summed totals
/// and MEMBERSHIP — the picker switches presets by sending these ids back as
/// the selection, never by working out which rows a preset contains.
class SettlementPresetBucket {
  SettlementPresetBucket({
    required this.count,
    required this.cashbackLaari,
    required this.feeLaari,
    required this.feeGstLaari,
    required this.dueLaari,
    required this.transactionIds,
  });

  factory SettlementPresetBucket.fromJson(Map<String, dynamic> json) =>
      SettlementPresetBucket(
        count: _count(json['count']),
        cashbackLaari: _laari(json['cashback_laari']),
        feeLaari: _laari(json['fee_laari']),
        feeGstLaari: _laari(json['fee_gst_laari']),
        dueLaari: _laari(json['due_laari']),
        transactionIds: [
          for (final id in (json['transaction_ids'] as List? ?? const []))
            if (id is int) id,
        ],
      );

  final int count;
  final int cashbackLaari;
  final int feeLaari;
  final int feeGstLaari;
  final int dueLaari;
  final List<int> transactionIds;
}

/// The priced preview: exactly what this selection costs and where to send
/// it. Reservation-free — nothing is claimed, no reference reserved. The
/// figure a merchant transfers is ALWAYS a figure this endpoint produced.
class SettlementPreviewData {
  SettlementPreviewData({
    required this.asOf,
    required this.transactionIds,
    required this.transactionCount,
    required this.saleTotalLaari,
    required this.cashbackTotalLaari,
    required this.feeTotalLaari,
    required this.feeGstTotalLaari,
    required this.lineTotalLaari,
    required this.creditAppliedLaari,
    required this.discountLaari,
    required this.amountDueBeforeDiscountLaari,
    required this.amountDueLaari,
    this.dueAt,
    this.discount,
    required this.transactions,
    required this.buckets,
    required this.paymentInstructions,
  });

  factory SettlementPreviewData.fromJson(Map<String, dynamic> json) =>
      SettlementPreviewData(
        asOf: _s(json['as_of']),
        transactionIds: [
          for (final id in (json['transaction_ids'] as List? ?? const []))
            if (id is int) id,
        ],
        transactionCount: _count(json['transaction_count']),
        saleTotalLaari: _laari(json['sale_total_laari']),
        cashbackTotalLaari: _laari(json['cashback_total_laari']),
        feeTotalLaari: _laari(json['fee_total_laari']),
        feeGstTotalLaari: _laari(json['fee_gst_total_laari']),
        lineTotalLaari: _laari(json['line_total_laari']),
        creditAppliedLaari: _laari(json['credit_applied_laari']),
        discountLaari: _laari(json['discount_laari']),
        amountDueBeforeDiscountLaari:
            _laari(json['amount_due_before_discount_laari']),
        amountDueLaari: _laari(json['amount_due_laari']),
        dueAt: json['due_at'] as String?,
        // Nullable on purpose: a UI must never invent a discount verdict the
        // server did not send.
        discount: json['discount'] is Map
            ? SettlementDiscount.fromJson(_map(json['discount']))
            : null,
        transactions: [
          for (final item in (json['transactions'] as List? ?? const []))
            SettlementPickerRow.fromJson((item as Map).cast<String, dynamic>()),
        ],
        buckets: {
          for (final MapEntry(:key, :value)
              in _map(json['buckets']).entries)
            if (value is Map)
              key: SettlementPresetBucket.fromJson(
                value.cast<String, dynamic>(),
              ),
        },
        paymentInstructions: SettlementInstructions.fromJson(
          _map(json['payment_instructions']),
        ),
      );

  final String asOf;

  /// The ids this preview PRICED — what a subset submission sends back
  /// unchanged. (A settle-all submission sends `settle_all` instead, so a
  /// sale landing between preview and submit joins the batch.)
  final List<int> transactionIds;
  final int transactionCount;
  final int saleTotalLaari;
  final int cashbackTotalLaari;
  final int feeTotalLaari;
  final int feeGstTotalLaari;
  final int lineTotalLaari;
  final int creditAppliedLaari;
  final int discountLaari;
  final int amountDueBeforeDiscountLaari;

  /// line total − §7 credit − discount: the figure to transfer.
  final int amountDueLaari;

  /// The earliest due_at among the selection (ISO 8601), null when none set.
  final String? dueAt;

  /// ADVISORY — re-decided at submit, never sent back to the server.
  final SettlementDiscount? discount;
  final List<SettlementPickerRow> transactions;

  /// Keyed all | older_than_5 | older_than_10 | overdue.
  final Map<String, SettlementPresetBucket> buckets;
  final SettlementInstructions paymentInstructions;
}

// ---------------------------------------------------------------------------
// Settlement (SettlementResource)
// ---------------------------------------------------------------------------

/// The rejection that cancelled a batch, read off the payment the admin
/// refused — only present on a cancelled settlement with a rejected payment.
class SettlementRejection {
  SettlementRejection({
    this.reason,
    this.rejectedAt,
    this.bankRef,
    required this.paymentId,
  });

  factory SettlementRejection.fromJson(Map<String, dynamic> json) =>
      SettlementRejection(
        reason: json['reason'] as String?,
        rejectedAt: json['rejected_at'] as String?,
        bankRef: json['bank_ref'] as String?,
        paymentId: json['payment_id'] as int? ?? 0,
      );

  final String? reason;
  final String? rejectedAt;
  final String? bankRef;
  final int paymentId;
}

/// The human answer to "what is happening to my transfer" — the resource's
/// merchant_status block. `code` is the machine key the app localises;
/// `message` is the server's English fallback for codes a build predates.
class SettlementMerchantStatus {
  SettlementMerchantStatus({
    required this.code,
    required this.message,
    this.rejection,
  });

  factory SettlementMerchantStatus.fromJson(Map<String, dynamic> json) =>
      SettlementMerchantStatus(
        code: _s(json['code']),
        message: _s(json['message']),
        rejection: json['rejection'] is Map
            ? SettlementRejection.fromJson(_map(json['rejection']))
            : null,
      );

  /// verifying | settled | partially_settled | awaiting_payment | rejected |
  /// cancelled | draft.
  final String code;
  final String message;
  final SettlementRejection? rejection;
}

/// One frozen line of a batch (SettlementLineResource), with the transaction
/// loaded on the detail read.
class SettlementLineInfo {
  SettlementLineInfo({
    required this.id,
    required this.transactionId,
    required this.currency,
    required this.cashbackLaari,
    required this.feeLaari,
    required this.feeGstLaari,
    required this.dueLaari,
    this.allocatedAt,
    this.invoiceNo,
    this.occurredAt,
  });

  factory SettlementLineInfo.fromJson(Map<String, dynamic> json) {
    final transaction = json['transaction'] is Map
        ? _map(json['transaction'])
        : const <String, dynamic>{};

    return SettlementLineInfo(
      id: json['id'] as int? ?? 0,
      transactionId: json['transaction_id'] as int? ?? 0,
      currency: _s(json['currency']),
      cashbackLaari: _laari(json['cashback_laari']),
      feeLaari: _laari(json['fee_laari']),
      feeGstLaari: _laari(json['fee_gst_laari']),
      dueLaari: _laari(json['due_laari']),
      allocatedAt: json['allocated_at'] as String?,
      invoiceNo: transaction['invoice_no'] as String?,
      occurredAt: transaction['occurred_at'] as String?,
    );
  }

  final int id;
  final int transactionId;
  final String currency;
  final int cashbackLaari;
  final int feeLaari;
  final int feeGstLaari;
  final int dueLaari;
  final String? allocatedAt;

  /// From the loaded transaction — the identity the merchant recognises.
  final String? invoiceNo;
  final String? occurredAt;
}

/// One payment with its receipt columns (SettlementPaymentResource).
/// `hasSlip` is what a UI branches on — the slip itself is on a private
/// disk and only an admin can stream it.
class SettlementPaymentInfo {
  SettlementPaymentInfo({
    required this.id,
    required this.settlementId,
    required this.amountLaari,
    required this.currency,
    required this.method,
    this.bankRef,
    required this.hasSlip,
    this.slipMime,
    this.slipSizeBytes,
    required this.state,
    this.matchedAt,
    this.rejectedAt,
    this.rejectionReason,
    required this.createdAt,
  });

  factory SettlementPaymentInfo.fromJson(Map<String, dynamic> json) =>
      SettlementPaymentInfo(
        id: json['id'] as int? ?? 0,
        settlementId: json['settlement_id'] as int? ?? 0,
        amountLaari: _laari(json['amount_laari']),
        currency: _s(json['currency']),
        method: _s(json['method']),
        bankRef: json['bank_ref'] as String?,
        hasSlip: json['has_slip'] as bool? ?? false,
        slipMime: json['slip_mime'] as String?,
        slipSizeBytes: json['slip_size_bytes'] as int?,
        state: _s(json['state']),
        matchedAt: json['matched_at'] as String?,
        rejectedAt: json['rejected_at'] as String?,
        rejectionReason: json['rejection_reason'] as String?,
        createdAt: _s(json['created_at']),
      );

  final int id;
  final int settlementId;
  final int amountLaari;
  final String currency;
  final String method;
  final String? bankRef;
  final bool hasSlip;
  final String? slipMime;
  final int? slipSizeBytes;

  /// pending | matched | rejected.
  final String state;
  final String? matchedAt;
  final String? rejectedAt;
  final String? rejectionReason;
  final String createdAt;
}

/// One settlement batch (SettlementResource). The discount fields are what
/// was GRANTED at submit — already subtracted from amount_due, stamped with
/// the rate it was priced at (null when nothing was granted).
class MerchantSettlement {
  MerchantSettlement({
    required this.id,
    required this.reference,
    required this.state,
    required this.fundingMethod,
    required this.currency,
    required this.saleTotalLaari,
    required this.cashbackTotalLaari,
    required this.feeTotalLaari,
    required this.feeGstTotalLaari,
    required this.discountLaari,
    this.discountRatePercent,
    this.discountReason,
    required this.amountDueLaari,
    required this.amountReceivedLaari,
    this.dueAt,
    required this.createdAt,
    required this.paymentInstructions,
    required this.merchantStatus,
    required this.lines,
    required this.payments,
  });

  factory MerchantSettlement.fromJson(Map<String, dynamic> json) =>
      MerchantSettlement(
        id: json['id'] as int? ?? 0,
        reference: _s(json['reference']),
        state: _s(json['state']),
        fundingMethod: _s(json['funding_method']),
        currency: _s(json['currency']),
        saleTotalLaari: _laari(json['sale_total_laari']),
        cashbackTotalLaari: _laari(json['cashback_total_laari']),
        feeTotalLaari: _laari(json['fee_total_laari']),
        feeGstTotalLaari: _laari(json['fee_gst_total_laari']),
        discountLaari: _laari(json['discount_laari']),
        discountRatePercent: json['discount_rate_percent'] as String?,
        discountReason: json['discount_reason'] as String?,
        amountDueLaari: _laari(json['amount_due_laari']),
        amountReceivedLaari: _laari(json['amount_received_laari']),
        dueAt: json['due_at'] as String?,
        createdAt: _s(json['created_at']),
        paymentInstructions: SettlementInstructions.fromJson(
          _map(json['payment_instructions']),
        ),
        merchantStatus: SettlementMerchantStatus.fromJson(
          _map(json['merchant_status']),
        ),
        // whenLoaded: the list read omits lines entirely; empty either way.
        lines: [
          for (final item in (json['lines'] as List? ?? const []))
            SettlementLineInfo.fromJson((item as Map).cast<String, dynamic>()),
        ],
        payments: [
          for (final item in (json['payments'] as List? ?? const []))
            SettlementPaymentInfo.fromJson(
              (item as Map).cast<String, dynamic>(),
            ),
        ],
      );

  final int id;
  final String reference;

  /// draft | awaiting_payment | payment_review | settled | partially_settled
  /// | cancelled (§6).
  final String state;

  /// bank | wallet.
  final String fundingMethod;
  final String currency;
  final int saleTotalLaari;
  final int cashbackTotalLaari;
  final int feeTotalLaari;
  final int feeGstTotalLaari;
  final int discountLaari;

  /// The rate this batch was priced at ("5.00"); null when nothing granted.
  final String? discountRatePercent;
  final String? discountReason;
  final int amountDueLaari;
  final int amountReceivedLaari;
  final String? dueAt;
  final String createdAt;
  final SettlementInstructions paymentInstructions;
  final SettlementMerchantStatus merchantStatus;
  final List<SettlementLineInfo> lines;
  final List<SettlementPaymentInfo> payments;

  /// What the merchant still owes on this batch, never negative.
  int get remainingLaari {
    final remaining = amountDueLaari - amountReceivedLaari;
    return remaining > 0 ? remaining : 0;
  }
}

/// One page of GET /merchant/settlements — Laravel page pagination
/// (paginate(25)), NOT the cursor shape the transactions list uses.
class SettlementPage {
  SettlementPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  factory SettlementPage.fromJson(Map<String, dynamic> json) {
    final meta = _map(json['meta']);

    return SettlementPage(
      items: [
        for (final item in (json['data'] as List? ?? const []))
          MerchantSettlement.fromJson((item as Map).cast<String, dynamic>()),
      ],
      currentPage: _count(meta['current_page']),
      lastPage: _count(meta['last_page']),
      total: _count(meta['total']),
    );
  }

  final List<MerchantSettlement> items;
  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasMore => currentPage < lastPage;
}
