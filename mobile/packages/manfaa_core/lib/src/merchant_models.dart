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

/// GET /merchant/setup (and every wizard PATCH/POST answer) — the whole
/// resumable-wizard state in one object, exactly the server's shape.
///
/// Readable in EVERY status: the waiting and rejected screens render from it
/// too (submitted_at, rejected_reason), not only the editable wizard.
class MerchantSetupState {
  MerchantSetupState({
    required this.status,
    required this.steps,
    required this.values,
    required this.rateBounds,
    required this.categories,
    this.submittedAt,
    this.rejectedReason,
  });

  factory MerchantSetupState.fromJson(Map<String, dynamic> json) =>
      MerchantSetupState(
        status: _s(json['status']),
        steps: MerchantSetupSteps.fromJson(
          (json['steps'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        values: MerchantSetupValues.fromJson(
          (json['values'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        rateBounds: SetupRateBounds.fromJson(
          (json['rate_bounds'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        categories: [
          for (final item in (json['categories'] as List? ?? const []))
            SetupCategory.fromJson((item as Map).cast<String, dynamic>()),
        ],
        submittedAt: json['submitted_at'] as String?,
        rejectedReason: json['rejected_reason'] as String?,
      );

  /// draft | pending_review | rejected | active.
  final String status;
  final MerchantSetupSteps steps;
  final MerchantSetupValues values;
  final SetupRateBounds rateBounds;

  /// The curated category options, served WITH the state so the chips render
  /// from the same payload they save into.
  final List<SetupCategory> categories;

  final String? submittedAt;
  final String? rejectedReason;

  /// Whether the wizard's writes will land (the server refuses with
  /// `setup_not_editable` outside draft|rejected).
  bool get editable => status == 'draft' || status == 'rejected';

  /// Whether the location step may be PASSED without a pin. Read from the
  /// channel the SERVER holds (web parity): a channel merely being
  /// considered on the profile step must not decide whether a later step
  /// blocks. Never a submit requirement either way.
  bool get locationRequired => values.channel != 'online';

  /// The pin on file — a branch without coordinates is a branch, not a pin.
  bool get pinned =>
      values.primaryBranch?.lat != null && values.primaryBranch?.lng != null;
}

/// The server's per-step completion flags.
class MerchantSetupSteps {
  MerchantSetupSteps({
    required this.profile,
    required this.location,
    required this.logo,
    required this.rate,
  });

  factory MerchantSetupSteps.fromJson(Map<String, dynamic> json) =>
      MerchantSetupSteps(
        profile: json['profile'] as bool? ?? false,
        location: json['location'] as bool? ?? false,
        logo: json['logo'] as bool? ?? false,
        rate: json['rate'] as bool? ?? false,
      );

  final bool profile;
  final bool location;
  final bool logo;
  final bool rate;
}

/// The wizard's saved values. Rates stay 2-decimal percent STRINGS (§11);
/// the branch coordinates are the one place doubles are correct — they are
/// geometry, not money.
class MerchantSetupValues {
  MerchantSetupValues({
    required this.name,
    required this.slug,
    required this.category,
    required this.channel,
    required this.eligibilityBasis,
    required this.contactEmail,
    required this.contactPhone,
    required this.supportPhone,
    required this.websiteUrl,
    required this.primaryBranch,
    required this.logoUrl,
    required this.cashbackRatePercent,
  });

  factory MerchantSetupValues.fromJson(Map<String, dynamic> json) =>
      MerchantSetupValues(
        name: _s(json['name']),
        slug: _s(json['slug']),
        category: json['category'] as String?,
        channel: _s(json['channel']),
        eligibilityBasis: json['eligibility_basis'] as String?,
        contactEmail: json['contact_email'] as String?,
        contactPhone: json['contact_phone'] as String?,
        supportPhone: json['support_phone'] as String?,
        websiteUrl: json['website_url'] as String?,
        primaryBranch: json['primary_branch'] is Map
            ? SetupBranch.fromJson(
                (json['primary_branch'] as Map).cast<String, dynamic>(),
              )
            : null,
        logoUrl: json['logo_url'] as String?,
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
      );

  final String name;
  final String slug;

  /// A curated category slug, or null while unpicked (a submit requirement).
  final String? category;

  /// in_store | online | both.
  final String channel;
  final String? eligibilityBasis;
  final String? contactEmail;
  final String? contactPhone;

  /// Null means "same as contact" — the storefront falls back on its own,
  /// so a copy would only go stale (web parity).
  final String? supportPhone;
  final String? websiteUrl;
  final SetupBranch? primaryBranch;
  final String? logoUrl;

  /// "2.00" once the rate step saved; null is a submit blocker.
  final String? cashbackRatePercent;
}

/// The primary branch the location step pins (created on first pin, moved
/// after).
class SetupBranch {
  SetupBranch({
    required this.id,
    required this.name,
    this.address,
    this.lat,
    this.lng,
  });

  factory SetupBranch.fromJson(Map<String, dynamic> json) => SetupBranch(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        address: json['address'] as String?,
        lat: (json['lat'] as num?)?.toDouble(),
        lng: (json['lng'] as num?)?.toDouble(),
      );

  final int id;
  final String name;
  final String? address;
  final double? lat;
  final double? lng;
}

/// The structural rate window ("0.50".."10.00") the rate step renders; the
/// live tier ceiling is enforced server-side on top (`rate_not_priced`).
class SetupRateBounds {
  SetupRateBounds({required this.minPercent, required this.maxPercent});

  factory SetupRateBounds.fromJson(Map<String, dynamic> json) =>
      SetupRateBounds(
        minPercent: _s(json['min_percent']),
        maxPercent: _s(json['max_percent']),
      );

  final String minPercent;
  final String maxPercent;
}

/// One curated store category option (slug + bilingual names).
class SetupCategory {
  SetupCategory({required this.slug, required this.nameEn, this.nameDv});

  factory SetupCategory.fromJson(Map<String, dynamic> json) => SetupCategory(
        slug: _s(json['slug']),
        nameEn: _s(json['name_en']),
        nameDv: json['name_dv'] as String?,
      );

  final String slug;
  final String nameEn;
  final String? nameDv;

  String label({required bool dhivehi}) =>
      dhivehi && (nameDv ?? '').isNotEmpty ? nameDv! : nameEn;
}

/// GET /merchant/customers/lookup?code=NNNNNN — the till's name check
/// before money moves (a typo here credits a stranger).
///
/// The two 200 answers are deliberately thin: `{"valid":true,"name":…}` for
/// a creditable code, `{"valid":false}` for everything else — an unknown
/// code and a non-active customer are byte-identical so the endpoint is no
/// membership oracle. NOTE: unlike the rest of the surface this answer has
/// no `data` wrapper.
class CustomerLookup {
  CustomerLookup({required this.valid, this.name});

  factory CustomerLookup.fromJson(Map<String, dynamic> json) => CustomerLookup(
        valid: json['valid'] as bool? ?? false,
        name: json['name'] as String?,
      );

  final bool valid;

  /// The customer's full name — only present when [valid].
  final String? name;
}

/// One priced rate window on GET /merchant/rate. Rates are 2-decimal percent
/// STRINGS off the wire (§1) and stay strings; the fee pair is null only for
/// a legacy unpriced rate (where the server refuses credits anyway).
class RateWindow {
  RateWindow({
    required this.cashbackRatePercent,
    this.platformFeePercent,
    this.allInPercent,
    required this.effectiveFrom,
    this.effectiveTo,
  });

  factory RateWindow.fromJson(Map<String, dynamic> json) => RateWindow(
        cashbackRatePercent: _s(json['cashback_rate_percent']),
        platformFeePercent: json['platform_fee_percent'] as String?,
        allInPercent: json['all_in_percent'] as String?,
        effectiveFrom: _s(json['effective_from']),
        effectiveTo: json['effective_to'] as String?,
      );

  final String cashbackRatePercent;
  final String? platformFeePercent;
  final String? allInPercent;

  /// ISO 8601 in the business timezone (Indian/Maldives).
  final String effectiveFrom;
  final String? effectiveTo;
}

/// GET /merchant/rate — the standing terms the cost preview estimates from.
class MerchantRate {
  MerchantRate({this.current, this.pending});

  factory MerchantRate.fromJson(Map<String, dynamic> json) => MerchantRate(
        current: json['current'] is Map
            ? RateWindow.fromJson((json['current'] as Map).cast<String, dynamic>())
            : null,
        pending: json['pending'] is Map
            ? RateWindow.fromJson((json['pending'] as Map).cast<String, dynamic>())
            : null,
      );

  /// Null when the store has no effective rate at all — the server refuses
  /// credits in that state, and the till says so instead of previewing.
  final RateWindow? current;

  /// The §7 scheduled decrease window (effective next business midnight);
  /// null when nothing is scheduled.
  final RateWindow? pending;
}

/// One product category on GET /merchant/product-categories — the split
/// editor's vocabulary. The list includes INACTIVE rows on purpose (older
/// transactions still reference them); the editor filters on [active].
class ProductCategory {
  ProductCategory({
    required this.id,
    required this.slug,
    required this.nameEn,
    this.nameDv,
    required this.mode,
    this.cashbackRatePercent,
    required this.active,
    required this.sort,
  });

  factory ProductCategory.fromJson(Map<String, dynamic> json) =>
      ProductCategory(
        id: json['id'] as int? ?? 0,
        slug: _s(json['slug']),
        nameEn: _s(json['name_en']),
        nameDv: json['name_dv'] as String?,
        mode: _s(json['mode']),
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        active: json['active'] as bool? ?? false,
        sort: _count(json['sort']),
      );

  final int id;

  /// The immutable line key — what a credit's `lines[].category` takes.
  final String slug;
  final String nameEn;
  final String? nameDv;

  /// `excluded` (earns nothing, even in promotions) or `rate` (its own
  /// cashback rate, in [cashbackRatePercent]).
  final String mode;

  /// "5.00" for a rate rule; null when excluded.
  final String? cashbackRatePercent;
  final bool active;
  final int sort;

  bool get excluded => mode == 'excluded';

  String label({required bool dhivehi}) =>
      dhivehi && (nameDv ?? '').isNotEmpty ? nameDv! : nameEn;
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
