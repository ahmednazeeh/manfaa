/// Typed views over the merchant app's response shapes.
///
/// Same laws as models.dart: hand-written fromJson, no codegen, money is
/// INTEGER LAARI throughout and never parsed into doubles, rates are
/// 2-decimal percent STRINGS from the wire (display text, kept exact).
/// Shapes mirror the server controllers exactly — SessionController,
/// HomeController (merchant halves), TransactionResource.
library;

int _laari(Object? v) => switch (v) { final int i => i, _ => 0 };
int _count(Object? v) => switch (v) { final int i => i, _ => 0 };
String _s(Object? v) => v?.toString() ?? '';

/// GET /merchant/me — fresh identity AND fresh permissions.
///
/// The till builds its navigation from [permissions], and the array
/// returned at sign-in would otherwise be frozen for the token's 90 days —
/// so the app calls this on every launch and resume (guide §3; cheap via
/// ETag) and MerchantSession caches the answer for offline paint.
class MerchantMe {
  MerchantMe({
    required this.user,
    required this.merchant,
    required this.permissions,
  });

  factory MerchantMe.fromJson(Map<String, dynamic> json) => MerchantMe(
        user: MerchantUserInfo.fromJson(
          (json['user'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        merchant: MerchantInfo.fromJson(
          (json['merchant'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        permissions: [
          for (final slug in (json['permissions'] as List? ?? const []))
            slug.toString(),
        ],
      );

  final MerchantUserInfo user;
  final MerchantInfo merchant;

  /// The flat resolved permission slugs, exactly as the web panel's `can()`
  /// reads them. The server enforces regardless; this only gates drawing.
  final List<String> permissions;

  bool can(String slug) => permissions.contains(slug);
}

/// The signed-in staff member (`user` in sign-in and /merchant/me).
class MerchantUserInfo {
  MerchantUserInfo({required this.id, required this.name, required this.email});

  factory MerchantUserInfo.fromJson(Map<String, dynamic> json) =>
      MerchantUserInfo(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        email: _s(json['email']),
      );

  final int id;
  final String name;
  final String email;
}

/// The store (`merchant` in sign-in and /merchant/me).
class MerchantInfo {
  MerchantInfo({
    required this.id,
    required this.name,
    required this.slug,
    this.status,
  });

  factory MerchantInfo.fromJson(Map<String, dynamic> json) => MerchantInfo(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        slug: _s(json['slug']),
        status: json['status'] as String?,
      );

  final int id;
  final String name;
  final String slug;

  /// draft | pending_review | active | suspended | rejected. Null at
  /// sign-in (the payload omits it); /merchant/me always carries it — the
  /// till says "we are not trading yet" rather than letting a cashier find
  /// out on a refused credit.
  final String? status;
}

/// GET /merchant/home — the till's first screen in ONE request.
class MerchantHome {
  MerchantHome({
    required this.merchantName,
    required this.merchantStatus,
    required this.today,
    this.outstanding,
    this.openSettlement,
  });

  factory MerchantHome.fromJson(Map<String, dynamic> json) {
    final merchant = (json['merchant'] as Map?) ?? const {};

    return MerchantHome(
      merchantName: _s(merchant['name']),
      merchantStatus: _s(merchant['status']),
      today: MerchantToday.fromJson(
        (json['today'] as Map?)?.cast<String, dynamic>() ?? {},
      ),
      outstanding: json['outstanding'] is Map
          ? MerchantOutstanding.fromJson(
              (json['outstanding'] as Map).cast<String, dynamic>(),
            )
          : null,
      openSettlement: json['open_settlement'] is Map
          ? OpenSettlement.fromJson(
              (json['open_settlement'] as Map).cast<String, dynamic>(),
            )
          : null,
    );
  }

  final String merchantName;
  final String merchantStatus;

  /// Today's tally in the BUSINESS day (Malé time, not UTC) — reversed and
  /// written-off sales excluded so the till agrees with the receipt roll.
  final MerchantToday today;

  /// Null for accounts without `settlements.view` — a credits-only cashier
  /// must not learn the store's commercial standing from the app. Null, not
  /// absent: the till renders a stable shape either way.
  final MerchantOutstanding? outstanding;

  /// The open (not settled, not cancelled) batch, newest first — null when
  /// none, or when the account may not see settlements.
  final OpenSettlement? openSettlement;
}

class MerchantToday {
  MerchantToday({
    required this.creditCount,
    required this.eligibleLaari,
    required this.cashbackLaari,
  });

  factory MerchantToday.fromJson(Map<String, dynamic> json) => MerchantToday(
        creditCount: _count(json['credit_count']),
        eligibleLaari: _laari(json['eligible_laari']),
        cashbackLaari: _laari(json['cashback_laari']),
      );

  final int creditCount;
  final int eligibleLaari;
  final int cashbackLaari;
}

/// The store's outstanding payables (OutstandingSummary::forMerchant, minus
/// `as_of` which the mobile projection strips for the ETag's sake).
///
/// The server's `*_mvr` display strings are ignored on purpose: they are
/// English-only server-side formatting, and the app renders laari through
/// formatMoney so dv gets its own shape.
class MerchantOutstanding {
  MerchantOutstanding({
    required this.total,
    required this.buckets,
    required this.pendingAdjustmentCount,
    required this.pendingAdjustmentCreditLaari,
  });

  factory MerchantOutstanding.fromJson(Map<String, dynamic> json) {
    final pending = (json['pending_adjustments'] as Map?) ?? const {};

    return MerchantOutstanding(
      total: OutstandingSlice.fromJson(
        (json['total'] as Map?)?.cast<String, dynamic>() ?? {},
      ),
      buckets: {
        for (final MapEntry(:key, :value)
            in ((json['buckets'] as Map?) ?? const {}).entries)
          if (value is Map)
            key.toString(): OutstandingSlice.fromJson(
              value.cast<String, dynamic>(),
            ),
      },
      pendingAdjustmentCount: _count(pending['count']),
      pendingAdjustmentCreditLaari: _laari(pending['credit_laari']),
    );
  }

  final OutstandingSlice total;

  /// Aging buckets keyed '0_5' | '6_10' | '11_15' | 'overdue', in server
  /// order — every key always present, zeroed when empty.
  final Map<String, OutstandingSlice> buckets;

  /// §7 reversal credits not yet netted into a batch. The credit is a
  /// NEGATIVE sum, shown so outstanding and the next batch's amount due
  /// reconcile.
  final int pendingAdjustmentCount;
  final int pendingAdjustmentCreditLaari;
}

/// One row of the outstanding summary — the total or a single age bucket.
class OutstandingSlice {
  OutstandingSlice({
    required this.count,
    required this.cashbackLaari,
    required this.feeLaari,
    required this.feeGstLaari,
    required this.payableLaari,
  });

  factory OutstandingSlice.fromJson(Map<String, dynamic> json) =>
      OutstandingSlice(
        count: _count(json['count']),
        cashbackLaari: _laari(json['cashback_laari']),
        feeLaari: _laari(json['fee_laari']),
        feeGstLaari: _laari(json['fee_gst_laari']),
        payableLaari: _laari(json['payable_laari']),
      );

  final int count;
  final int cashbackLaari;
  final int feeLaari;
  final int feeGstLaari;
  final int payableLaari;
}

/// The open settlement batch on /merchant/home.
class OpenSettlement {
  OpenSettlement({
    required this.id,
    required this.reference,
    required this.state,
    required this.amountDueLaari,
    this.dueAt,
  });

  factory OpenSettlement.fromJson(Map<String, dynamic> json) => OpenSettlement(
        id: json['id'] as int? ?? 0,
        reference: _s(json['reference']),
        state: _s(json['state']),
        amountDueLaari: _laari(json['amount_due_laari']),
        dueAt: json['due_at'] as String?,
      );

  final int id;
  final String reference;
  final String state;
  final int amountDueLaari;
  final String? dueAt;
}

/// One sale as the merchant sees it (TransactionResource — the panel's own
/// shape, reused verbatim on mobile).
///
/// TWO KINDS OF RATE live here: `cashbackRatePercent`/`platformFeePercent`
/// are the BASE terms frozen onto the row; the `effective*` pair is what
/// the sale ACTUALLY earned (cashback/eligible). They differ on a mixed
/// basket, where each line priced at its own category rate — per-line truth
/// is in [lines].
class MerchantTransaction {
  MerchantTransaction({
    required this.id,
    required this.origin,
    required this.invoiceNo,
    required this.state,
    required this.reasonCode,
    required this.backdated,
    required this.currency,
    required this.eligibleLaari,
    required this.saleLaari,
    required this.cashbackRatePercent,
    required this.platformFeePercent,
    required this.effectiveCashbackRatePercent,
    required this.effectivePlatformFeePercent,
    required this.cashbackLaari,
    required this.feeLaari,
    required this.feeGstLaari,
    required this.occurredAt,
    required this.receivedAt,
    required this.lines,
  });

  factory MerchantTransaction.fromJson(Map<String, dynamic> json) =>
      MerchantTransaction(
        id: json['id'] as int? ?? 0,
        origin: _s(json['origin']),
        invoiceNo: _s(json['invoice_no']),
        state: _s(json['state']),
        reasonCode: json['reason_code'] as String?,
        backdated: json['backdated'] as bool? ?? false,
        currency: _s(json['currency']),
        eligibleLaari: _laari(json['eligible_laari']),
        saleLaari: json['sale_laari'] as int?,
        cashbackRatePercent: _s(json['cashback_rate_percent']),
        platformFeePercent: _s(json['platform_fee_percent']),
        effectiveCashbackRatePercent:
            _s(json['effective_cashback_rate_percent']),
        effectivePlatformFeePercent: _s(json['effective_platform_fee_percent']),
        cashbackLaari: _laari(json['cashback_laari']),
        feeLaari: _laari(json['fee_laari']),
        feeGstLaari: _laari(json['fee_gst_laari']),
        occurredAt: _s(json['occurred_at']),
        receivedAt: _s(json['received_at']),
        lines: [
          for (final item in (json['lines'] as List? ?? const []))
            MerchantTransactionLine.fromJson(
              (item as Map).cast<String, dynamic>(),
            ),
        ],
      );

  final int id;

  /// manual | api | import — where the credit came from.
  final String origin;
  final String invoiceNo;
  final String state;
  final String? reasonCode;

  /// Credited outside the validation window — payable immediately, and the
  /// merchant can never reverse it.
  final bool backdated;
  final String currency;
  final int eligibleLaari;

  /// The full sale amount — null when the credit was keyed without one
  /// (only the eligible amount is required).
  final int? saleLaari;
  final String cashbackRatePercent;
  final String platformFeePercent;
  final String effectiveCashbackRatePercent;
  final String effectivePlatformFeePercent;
  final int cashbackLaari;
  final int feeLaari;
  final int feeGstLaari;
  final String occurredAt;
  final String receivedAt;

  /// The pricing split of a lined credit — empty on single-rate sales
  /// (the server omits the key unless the lines were loaded).
  final List<MerchantTransactionLine> lines;
}

/// One priced line of a lined credit (TransactionLineResource).
class MerchantTransactionLine {
  MerchantTransactionLine({
    required this.category,
    required this.categoryNameEn,
    required this.amountLaari,
    required this.cashbackRatePercent,
    required this.platformFeePercent,
    required this.cashbackLaari,
    required this.feeLaari,
    required this.pricedBy,
    required this.sort,
  });

  factory MerchantTransactionLine.fromJson(Map<String, dynamic> json) =>
      MerchantTransactionLine(
        category: json['category'] as String?,
        categoryNameEn: json['category_name_en'] as String?,
        amountLaari: _laari(json['amount_laari']),
        cashbackRatePercent: _s(json['cashback_rate_percent']),
        platformFeePercent: _s(json['platform_fee_percent']),
        cashbackLaari: _laari(json['cashback_laari']),
        feeLaari: _laari(json['fee_laari']),
        pricedBy: _s(json['priced_by']),
        sort: _count(json['sort']),
      );

  /// The category slug the line priced at — null for the default
  /// "everything else" bucket.
  final String? category;
  final String? categoryNameEn;
  final int amountLaari;

  /// The rate this LINE actually priced at ("0.00" for an excluded
  /// category); the fee that followed it beside it.
  final String cashbackRatePercent;
  final String platformFeePercent;
  final int cashbackLaari;
  final int feeLaari;
  final String pricedBy;
  final int sort;
}

/// One line of a split-by-category credit, as the till composes it
/// (input to createCredit — `lines[]` on the wire).
class CreditLine {
  const CreditLine({this.category, required this.amountLaari});

  /// One of the merchant's product-category slugs, or null for the default
  /// "everything else" bucket.
  final String? category;
  final int amountLaari;

  /// The server requires `category` PRESENT even when null.
  Map<String, dynamic> toJson() => {
        'category': category,
        'amount_laari': amountLaari,
      };
}

/// POST /merchant/credits — the recorded sale, plus whether this answer is
/// an idempotent REPLAY of an earlier commit (guide §6: the replay arrives
/// as 200 with `Idempotency-Replay: true`; the first commit is a 201 —
/// either way the sale is recorded and the queue entry clears).
class MerchantCreditResult {
  MerchantCreditResult({required this.transaction, required this.replayed});

  final MerchantTransaction transaction;

  /// True when the server answered from its idempotency record: this sale
  /// was already committed by an earlier attempt whose response was lost.
  final bool replayed;
}
