/// Typed views over the merchant app's response shapes.
///
/// Same laws as models.dart: hand-written fromJson, no codegen, money is
/// INTEGER LAARI throughout and never parsed into doubles, rates are
/// 2-decimal percent STRINGS from the wire (display text, kept exact).
/// Shapes mirror the server controllers exactly — SessionController,
/// HomeController (merchant halves), TransactionResource.
library;

int _laari(Object? v) => switch (v) {
  final int i => i,
  _ => 0,
};
int _count(Object? v) => switch (v) {
  final int i => i,
  _ => 0,
};
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
    required this.month,
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
      month: MerchantMonth.fromJson(
        (json['month'] as Map?)?.cast<String, dynamic>() ?? {},
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

  /// The calendar month so far, same boundaries and exclusions as [today]
  /// — what the store EARNED through Manfaa, beside what it owes.
  final MerchantMonth month;

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

/// The month so far: the takings side of the dashboard. `average` is the
/// server's own floored integer so every surface shows one number.
class MerchantMonth {
  MerchantMonth({
    required this.creditCount,
    required this.eligibleLaari,
    required this.cashbackLaari,
    required this.averageEligibleLaari,
  });

  factory MerchantMonth.fromJson(Map<String, dynamic> json) => MerchantMonth(
    creditCount: _count(json['credit_count']),
    eligibleLaari: _laari(json['eligible_laari']),
    cashbackLaari: _laari(json['cashback_laari']),
    averageEligibleLaari: _laari(json['average_eligible_laari']),
  );

  final int creditCount;
  final int eligibleLaari;
  final int cashbackLaari;
  final int averageEligibleLaari;
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
    this.feeGstPercent = '0.00',
    this.feeTreatment = 'on_top',
    required this.occurredAt,
    required this.receivedAt,
    required this.lines,
  });

  factory MerchantTransaction.fromJson(
    Map<String, dynamic> json,
  ) => MerchantTransaction(
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
    effectiveCashbackRatePercent: _s(json['effective_cashback_rate_percent']),
    effectivePlatformFeePercent: _s(json['effective_platform_fee_percent']),
    cashbackLaari: _laari(json['cashback_laari']),
    feeLaari: _laari(json['fee_laari']),
    feeGstLaari: _laari(json['fee_gst_laari']),
    // Absent on a build older than the tax round: "0.00" reads the same
    // way a GST-free row does, which is exactly what those rows were.
    feeGstPercent: json['fee_gst_percent'] == null
        ? '0.00'
        : _s(json['fee_gst_percent']),
    feeTreatment: json['fee_treatment'] == null
        ? 'on_top'
        : _s(json['fee_treatment']),
    occurredAt: _s(json['occurred_at']),
    receivedAt: _s(json['received_at']),
    lines: [
      for (final item in (json['lines'] as List? ?? const []))
        MerchantTransactionLine.fromJson((item as Map).cast<String, dynamic>()),
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

  /// GST on Manfaa's fee, in laari — ALWAYS the tax and never part of
  /// [feeLaari], whichever treatment priced it. Zero when GST does not
  /// apply, which is the only reason a UI ever hides the line.
  final int feeGstLaari;

  /// The GST rate STAMPED on this row, as the exact 2-decimal percent
  /// string ("8.00"). Frozen at pricing time: a later rate change never
  /// re-reads an old sale.
  final String feeGstPercent;

  /// on_top | inclusive — how the stamped fee and GST were derived. The
  /// merchant owes cashback + fee + GST either way, so this changes no
  /// figure on screen; it is carried because the wire carries it.
  final String feeTreatment;
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
    this.feeGstLaari = 0,
    this.feeGstPercent = '0.00',
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
        feeGstLaari: _laari(json['fee_gst_laari']),
        feeGstPercent: json['fee_gst_percent'] == null
            ? '0.00'
            : _s(json['fee_gst_percent']),
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

  /// The GST this LINE carries, and the rate it was stamped at. The server
  /// taxes per line (ceiling per line), so the lines sum to the header —
  /// a header-level re-derivation would disagree by a laari.
  final int feeGstLaari;
  final String feeGstPercent;
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
    required this.description,
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
        description: json['description'] as String?,
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

  /// The store's own words about itself, up to 180 WORDS (the server's
  /// App\Rules\MaxWords — a word ceiling refuses neither a long Dhivehi
  /// word nor a short English one unfairly). Shown to shoppers on the store
  /// page, and a SUBMIT requirement: empty is the `description` key in
  /// `setup_incomplete.missing[]`.
  final String? description;
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

/// The GST terms a sale recorded RIGHT NOW would be stamped with — the one
/// FORWARD-LOOKING thing GET /merchant/rate publishes, and the only reason
/// the till can quote a cost before the sale exists.
///
/// Every other GST figure the app reads is the tax STAMPED on a row that
/// already happened, which is right for a receipt and useless for a quote.
///
/// `split` is `App\Domain\Tax\FeeTax::split()`, laari for laari:
///
///   on_top     net = fee                   gst = ceil(fee × bp / 10000)
///   inclusive  gst = ceil(fee × bp / (10000 + bp))   net = fee − gst
///
/// A rate of "0.00" — the platform today, and the default when an older
/// server omits the block — is the identity under both treatments, so the
/// quote is byte-identical to what it was before this existed.
class MerchantTaxTerms {
  const MerchantTaxTerms({
    this.gstRatePercent = '0.00',
    this.feeTreatment = 'on_top',
  });

  factory MerchantTaxTerms.fromJson(Map<String, dynamic> json) =>
      MerchantTaxTerms(
        gstRatePercent: json['gst_rate_percent'] == null
            ? '0.00'
            : _s(json['gst_rate_percent']),
        feeTreatment: json['fee_treatment'] == null
            ? 'on_top'
            : _s(json['fee_treatment']),
      );

  /// PLAN §1 wire format: a 2-decimal percent string, never basis points.
  final String gstRatePercent;

  /// `on_top` | `inclusive`.
  final String feeTreatment;

  bool get inclusive => feeTreatment == 'inclusive';

  /// [net fee revenue, tax owed to MIRA] for a priced fee.
  (int, int) split(int feeLaari, int rateBp) {
    if (rateBp <= 0 || feeLaari <= 0) {
      return (feeLaari, 0);
    }
    if (!inclusive) {
      return (feeLaari, (feeLaari * rateBp + 9999) ~/ 10000);
    }
    final divisor = 10000 + rateBp;
    final gst = (feeLaari * rateBp + divisor - 1) ~/ divisor;
    return (feeLaari - gst, gst);
  }
}

/// GET /merchant/rate — the standing terms the cost preview estimates from.
class MerchantRate {
  MerchantRate({
    this.current,
    this.pending,
    this.tax = const MerchantTaxTerms(),
  });

  factory MerchantRate.fromJson(Map<String, dynamic> json) => MerchantRate(
    current: json['current'] is Map
        ? RateWindow.fromJson((json['current'] as Map).cast<String, dynamic>())
        : null,
    pending: json['pending'] is Map
        ? RateWindow.fromJson((json['pending'] as Map).cast<String, dynamic>())
        : null,
    // Absent on a server older than this build: no tax, which is what the
    // platform charged before the field existed.
    tax: json['tax'] is Map
        ? MerchantTaxTerms.fromJson(
            (json['tax'] as Map).cast<String, dynamic>(),
          )
        : const MerchantTaxTerms(),
  );

  /// Null when the store has no effective rate at all — the server refuses
  /// credits in that state, and the till says so instead of previewing.
  final RateWindow? current;

  /// The §7 scheduled decrease window (effective next business midnight);
  /// null when nothing is scheduled.
  final RateWindow? pending;

  /// The GST terms a sale priced RIGHT NOW would freeze onto itself.
  final MerchantTaxTerms tax;
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

/// GET/PATCH /merchant/profile — the store's public identity
/// (MerchantProfileResource, verbatim). The slug never moves with a rename:
/// The answer to POST /merchant/publication — the store's own on/off switch.
class StorePublication {
  const StorePublication({
    required this.published,
    required this.unpublishedAt,
    required this.customersNotified,
  });

  factory StorePublication.fromJson(Map<String, dynamic> json) =>
      StorePublication(
        published: json['published'] as bool? ?? true,
        unpublishedAt: json['unpublished_at'] as String?,
        customersNotified: json['customers_notified'] as bool? ?? false,
      );

  final bool published;
  final String? unpublishedAt;

  /// Whether THIS call reached any customer. False means either nothing
  /// changed, or the day's message has already gone out — the screen says
  /// so rather than letting the merchant assume a broadcast.
  final bool customersNotified;
}

/// it is the address on every shared link and printed card.
class MerchantProfile {
  MerchantProfile({
    required this.id,
    required this.name,
    this.nameDv,
    required this.slug,
    required this.status,
    this.published = true,
    this.category,
    required this.categoryRetired,
    required this.channel,
    this.eligibilityBasis,
    this.description,
    this.contactEmail,
    this.contactPhone,
    this.supportPhone,
    this.websiteUrl,
    this.pendingChange,
  });

  factory MerchantProfile.fromJson(Map<String, dynamic> json) =>
      MerchantProfile(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        nameDv: json['name_dv'] as String?,
        slug: _s(json['slug']),
        status: _s(json['status']),
        // Defaults TRUE for an older server that does not send the key: a
        // store the app cannot prove is paused is treated as trading, which
        // is the state it was in before this field existed.
        published: json['published'] as bool? ?? true,
        category: json['category'] as String?,
        categoryRetired: json['category_retired'] as bool? ?? false,
        channel: _s(json['channel']),
        eligibilityBasis: json['eligibility_basis'] as String?,
        description: json['description'] as String?,
        contactEmail: json['contact_email'] as String?,
        contactPhone: json['contact_phone'] as String?,
        supportPhone: json['support_phone'] as String?,
        websiteUrl: json['website_url'] as String?,
        pendingChange: json['pending_change'] is Map
            ? MerchantChangeRequest.fromJson(
                (json['pending_change'] as Map).cast<String, dynamic>(),
              )
            : null,
      );

  final int id;
  final String name;
  final String? nameDv;
  final String slug;

  /// draft | pending_review | rejected | active | suspended | closed.
  final String status;

  /// On the app right now. INDEPENDENT of [status]: publication is the
  /// merchant's own switch and status is the account lifecycle, so an
  /// `active` store may be paused. Never infer one from the other.
  final bool published;

  /// A curated category slug, or null while unpicked.
  final String? category;

  /// The store still holds a curated category the superadmin has since
  /// retired: the save path tolerates the unchanged value, and this flag is
  /// what tells the UI to prompt for a new pick instead of a silent 422.
  final bool categoryRetired;

  /// in_store | online | both.
  final String channel;

  /// The §11 free-text mirror of the agreement — ONE string, displayed to
  /// customers, never used in computation.
  final String? eligibilityBasis;

  /// The store's own words about itself (≤180 WORDS), on the public store
  /// page. A public CLAIM, so on a live store an edit to it queues for
  /// review with the name and the category — never applies on the spot.
  final String? description;
  final String? contactEmail;
  final String? contactPhone;

  /// Always materialised server-side (Merchant::booted()): a blank support
  /// phone stores the contact number itself. "Same as contact" is therefore
  /// a COMPARISON the app makes, never a separate stored flag.
  final String? supportPhone;
  final String? websiteUrl;

  /// MR9 — the store's own public claims waiting on a reviewer, or null.
  /// The fields ABOVE stay the LIVE values while this is set: they are what
  /// a shopper sees until an admin approves, and a form that pre-filled the
  /// proposed values instead would re-submit them on the next unrelated save.
  final MerchantChangeRequest? pendingChange;
}

/// PATCH /merchant/preferences — the owner-editable earning knobs
/// (MerchantPreferencesResource). There is no GET: an EMPTY PATCH validates
/// cleanly, changes nothing and answers the current values — the read model
/// the web panel uses too.
class MerchantPreferences {
  MerchantPreferences({
    required this.settlementMethod,
    required this.minEligibleLaari,
    required this.validationWindowDays,
    required this.validationWindowMaxDays,
    this.autoSettleFromWallet = true,
  });

  factory MerchantPreferences.fromJson(Map<String, dynamic> json) =>
      MerchantPreferences(
        settlementMethod: _s(json['settlement_method']),
        minEligibleLaari: _laari(json['min_eligible_laari']),
        validationWindowDays: _count(json['validation_window_days']),
        validationWindowMaxDays: _count(json['validation_window_max_days']),
        autoSettleFromWallet: json['auto_settle_from_wallet'] as bool? ?? true,
      );

  /// bank | wallet — how settlements are funded by default (§7).
  final String settlementMethod;

  /// Whether the hourly run settles validated cashback from the wallet
  /// balance (owner, 2026-08-24; default ON). The wallet screen renders it
  /// from the wallet payload and writes it through this resource.
  final bool autoSettleFromWallet;

  /// Sales below this earn no cashback (integer laari; platform bound
  /// 0–100000).
  final int minEligibleLaari;

  /// Days a sale stays refundable before its cashback becomes payable.
  final int validationWindowDays;

  /// The ADMIN-governed ceiling the PATCH validates against — served so the
  /// form renders the real bound instead of hard-coding one.
  final int validationWindowMaxDays;
}

/// The §4 cost picture of one rate inside a change summary: the exact
/// percent strings, fee fields null only for a legacy unpriced rate.
class RateCost {
  RateCost({
    required this.cashbackRatePercent,
    this.platformFeePercent,
    this.allInPercent,
  });

  factory RateCost.fromJson(Map<String, dynamic> json) => RateCost(
    cashbackRatePercent: _s(json['cashback_rate_percent']),
    platformFeePercent: json['platform_fee_percent'] as String?,
    allInPercent: json['all_in_percent'] as String?,
  );

  final String cashbackRatePercent;
  final String? platformFeePercent;
  final String? allInPercent;
}

/// The `change` half of POST /merchant/rate — the SERVER's own answer on
/// when the new rate takes hold (§7: increases now, decreases at the next
/// business midnight). The app renders this verbatim and never re-derives
/// the timing.
class RateChangeSummary {
  RateChangeSummary({
    this.previous,
    required this.next,
    required this.effectiveAt,
    required this.applies,
    required this.tierChanged,
  });

  factory RateChangeSummary.fromJson(Map<String, dynamic> json) =>
      RateChangeSummary(
        previous: json['previous'] is Map
            ? RateCost.fromJson(
                (json['previous'] as Map).cast<String, dynamic>(),
              )
            : null,
        next: RateCost.fromJson(
          (json['new'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        effectiveAt: _s(json['effective_at']),
        applies: _s(json['applies']),
        tierChanged: json['tier_changed'] as bool? ?? false,
      );

  /// Null when the store had no standing rate at all before this change.
  final RateCost? previous;

  /// `new` on the wire — Dart keyword, so `next` here.
  final RateCost next;

  /// ISO 8601 in the business timezone.
  final String effectiveAt;

  /// immediately | next_business_midnight.
  final String applies;
  final bool tierChanged;
}

/// The whole POST /merchant/rate answer: the fresh current/pending windows
/// (same shape as GET /merchant/rate) plus the change summary.
class RateChangeResult {
  RateChangeResult({required this.rate, this.change});

  final MerchantRate rate;

  /// Null only if the server chose not to describe the change (defensive —
  /// today it always does).
  final RateChangeSummary? change;
}

/// A role AS IT APPEARS NEXT TO A PERSON (MerchantRoleResource::summary) —
/// on each staff row and nowhere else. Just enough to print it and to know
/// it is the frozen one; the permission set belongs to [MerchantRole].
class MerchantRoleSummary {
  MerchantRoleSummary({
    required this.id,
    required this.name,
    this.nameDv,
    required this.isOwner,
  });

  factory MerchantRoleSummary.fromJson(Map<String, dynamic> json) =>
      MerchantRoleSummary(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        nameDv: json['name_dv'] as String?,
        isOwner: json['is_owner'] as bool? ?? false,
      );

  final int id;
  final String name;
  final String? nameDv;

  /// The immutable flag the last-owner guard keys on (D4) — never any
  /// permission, however wide.
  final bool isOwner;

  String label({required bool dhivehi}) =>
      dhivehi && (nameDv ?? '').isNotEmpty ? nameDv! : name;
}

/// One merchant panel account on GET /merchant/staff
/// (MerchantStaffResource). No password material ever appears here — the
/// generated temporary password is returned exactly once, on creation,
/// NEXT TO (not inside) this resource.
class MerchantStaff {
  MerchantStaff({
    required this.id,
    required this.name,
    required this.email,
    this.role,
    required this.isActive,
    this.createdAt,
  });

  factory MerchantStaff.fromJson(Map<String, dynamic> json) => MerchantStaff(
    id: json['id'] as int? ?? 0,
    name: _s(json['name']),
    email: _s(json['email']),
    role: json['role'] is Map
        ? MerchantRoleSummary.fromJson(
            (json['role'] as Map).cast<String, dynamic>(),
          )
        : null,
    isActive: json['is_active'] as bool? ?? true,
    createdAt: json['created_at'] as String?,
  );

  final int id;
  final String name;
  final String email;

  /// Null where a role somehow is not set — the UI renders the gap rather
  /// than inventing a name.
  final MerchantRoleSummary? role;

  /// False is the ONLY removal — there is no staff DELETE, so audit trails
  /// keep resolving.
  final bool isActive;
  final String? createdAt;
}

/// POST /merchant/staff — the created account plus the ONE-TIME temporary
/// password, which exists only in this response (only its hash survives
/// server-side) and is never retrievable again.
class StaffInviteResult {
  StaffInviteResult({required this.staff, required this.tempPassword});

  final MerchantStaff staff;
  final String tempPassword;
}

/// One of the merchant's OWN roles (MerchantRoleResource) — the roles
/// screen's shape. `permissions` is the RESOLVED set: the owner role's
/// stored column is empty because its authority is the flag (§2.3), so the
/// server resolves it to the whole catalogue before it travels.
class MerchantRole {
  MerchantRole({
    required this.id,
    required this.name,
    this.nameDv,
    required this.slug,
    required this.isOwner,
    required this.isSystem,
    required this.permissions,
    required this.staffCount,
    this.createdAt,
    this.updatedAt,
  });

  factory MerchantRole.fromJson(Map<String, dynamic> json) => MerchantRole(
    id: json['id'] as int? ?? 0,
    name: _s(json['name']),
    nameDv: json['name_dv'] as String?,
    slug: _s(json['slug']),
    isOwner: json['is_owner'] as bool? ?? false,
    isSystem: json['is_system'] as bool? ?? false,
    permissions: [
      for (final slug in (json['permissions'] as List? ?? const []))
        slug.toString(),
    ],
    staffCount: _count(json['staff_count']),
    createdAt: json['created_at'] as String?,
    updatedAt: json['updated_at'] as String?,
  );

  final int id;
  final String name;
  final String? nameDv;

  /// Stable per merchant and never rewritten by a rename — the seeded
  /// presets are recognised by it.
  final String slug;

  /// Frozen apart from its name: permissions un-editable, un-deletable,
  /// and only an owner may hand it out.
  final bool isOwner;
  final bool isSystem;

  /// The resolved permission slugs this role holds.
  final List<String> permissions;

  /// How many accounts stand on this role — shown on the list, and the
  /// reason a delete is refused. Deactivated accounts count.
  final int staffCount;
  final String? createdAt;
  final String? updatedAt;

  String label({required bool dhivehi}) =>
      dhivehi && (nameDv ?? '').isNotEmpty ? nameDv! : name;
}

/// One permission as the SERVED catalogue names it (RolesController
/// ::permissions). Published rather than hardcoded (D8) so a permission
/// added by a later deploy renders — with its own wording, under the right
/// heading — in an app build that predates it.
class PermissionInfo {
  PermissionInfo({
    required this.slug,
    required this.label,
    required this.group,
  });

  factory PermissionInfo.fromJson(Map<String, dynamic> json) => PermissionInfo(
    slug: _s(json['slug']),
    label: _s(json['label']),
    group: _s(json['group']),
  );

  final String slug;

  /// The server's own English wording — displayed verbatim; the app never
  /// invents a label for a slug it does not know.
  final String label;
  final String group;
}

/// One heading of the catalogue, in the server's stacking order.
class PermissionGroupInfo {
  PermissionGroupInfo({
    required this.slug,
    required this.label,
    required this.permissions,
  });

  factory PermissionGroupInfo.fromJson(Map<String, dynamic> json) =>
      PermissionGroupInfo(
        slug: _s(json['slug']),
        label: _s(json['label']),
        permissions: [
          for (final item in (json['permissions'] as List? ?? const []))
            PermissionInfo.fromJson((item as Map).cast<String, dynamic>()),
        ],
      );

  final String slug;
  final String label;
  final List<PermissionInfo> permissions;
}

/// GET /merchant/permissions — the whole grouped catalogue the roles
/// editor renders its checkbox grid from.
class PermissionCatalogue {
  PermissionCatalogue({required this.groups});

  factory PermissionCatalogue.fromJson(Map<String, dynamic> json) =>
      PermissionCatalogue(
        groups: [
          for (final item in (json['groups'] as List? ?? const []))
            PermissionGroupInfo.fromJson((item as Map).cast<String, dynamic>()),
        ],
      );

  final List<PermissionGroupInfo> groups;
}

/// One branch on GET /merchant/branches (MerchantBranchResource). The pin
/// is a nullable PAIR — both halves or neither, never one (the server
/// refuses a lone coordinate).
class MerchantBranch {
  MerchantBranch({
    required this.id,
    required this.name,
    this.address,
    this.lat,
    this.lng,
  });

  factory MerchantBranch.fromJson(Map<String, dynamic> json) => MerchantBranch(
    id: json['id'] as int? ?? 0,
    name: _s(json['name']),
    address: json['address'] as String?,
    lat: (json['lat'] as num?)?.toDouble(),
    lng: (json['lng'] as num?)?.toDouble(),
  );

  final int id;
  final String name;
  final String? address;

  /// Geometry, not money — the one place doubles are correct.
  final double? lat;
  final double? lng;

  bool get pinned => lat != null && lng != null;
}

/// GET /merchant/branches, whole: the estate itself plus `meta`'s pending
/// branch change requests (MR9). A live store's branch writes never land
/// directly, so the list alone is only half the truth — what is QUEUED is
/// the other half, and the two must arrive together or the screen would
/// paint a store that silently ignored the owner's last three saves.
class MerchantBranchEstate {
  const MerchantBranchEstate({
    required this.branches,
    this.pendingChanges = const [],
  });

  factory MerchantBranchEstate.fromJson(
    Map<String, dynamic> json,
  ) => MerchantBranchEstate(
    branches: [
      for (final item in (json['data'] as List? ?? const []))
        MerchantBranch.fromJson((item as Map).cast<String, dynamic>()),
    ],
    pendingChanges: [
      for (final item
          in ((json['meta'] as Map?)?['pending_changes'] as List? ?? const []))
        MerchantChangeRequest.fromJson((item as Map).cast<String, dynamic>()),
    ],
  );

  final List<MerchantBranch> branches;

  /// Pending branch requests only — the server filters the profile kind out
  /// of this collection (BranchesController::index).
  final List<MerchantChangeRequest> pendingChanges;

  /// New branches waiting on a reviewer: they have no row in [branches] yet,
  /// so a screen that only draws the list would show nothing at all for a
  /// save the owner just made.
  List<MerchantChangeRequest> get pendingCreates => [
    for (final change in pendingChanges)
      if (change.kind == MerchantChangeRequest.kindBranchCreate) change,
  ];

  /// The request waiting against an EXISTING branch (update or removal), or
  /// null. One pending request per target is the server's law (MR9
  /// supersede), so the first match is the answer.
  MerchantChangeRequest? pendingFor(int branchId) {
    for (final change in pendingChanges) {
      if (change.branchId == branchId) return change;
    }
    return null;
  }
}

/// One queued store change (MerchantChangeRequestResource) — MR9.
///
/// A LIVE store's public claims (its name, category, channel, logo, website,
/// the what-earns-cashback promise) and its whole branch estate do not move
/// when the owner saves: they queue for admin review and the store keeps
/// serving the CURRENT values until someone approves. This is that queued
/// request, in the one shape every surface reads it.
///
/// [current] is the SUBMIT-TIME snapshot, not the live row, so the diff
/// survives later edits. A logo change rides [changes] as the field `logo`
/// whose `from`/`to` are authorising preview URLs (they need the caller's
/// bearer token, so they are not plain image sources).
class MerchantChangeRequest {
  const MerchantChangeRequest({
    required this.id,
    required this.merchantId,
    required this.kind,
    required this.kindLabel,
    required this.status,
    this.branchId,
    this.branchName,
    this.changes = const [],
    this.proposed = const {},
    this.current = const {},
    this.submittedAt,
    this.reviewedAt,
    this.rejectedReason,
  });

  factory MerchantChangeRequest.fromJson(Map<String, dynamic> json) =>
      MerchantChangeRequest(
        id: json['id'] as int? ?? 0,
        merchantId: json['merchant_id'] as int? ?? 0,
        kind: _s(json['kind']),
        kindLabel: _s(json['kind_label']),
        status: _s(json['status']),
        branchId: json['branch_id'] as int?,
        branchName: json['branch_name'] as String?,
        changes: [
          for (final item in (json['changes'] as List? ?? const []))
            ChangeRequestDiff.fromJson((item as Map).cast<String, dynamic>()),
        ],
        // `is Map`, not a cast: a request that proposes NOTHING — a branch
        // removal — carries an empty PHP array, which json_encode writes as
        // `[]`, and casting that to a Map would throw on the one kind whose
        // whole point is that it proposes no fields.
        proposed: json['proposed'] is Map
            ? (json['proposed'] as Map).cast<String, dynamic>()
            : const {},
        current: json['current'] is Map
            ? (json['current'] as Map).cast<String, dynamic>()
            : const {},
        submittedAt: json['submitted_at'] as String?,
        reviewedAt: json['reviewed_at'] as String?,
        rejectedReason: json['rejected_reason'] as String?,
      );

  static const kindProfile = 'profile';
  static const kindBranchCreate = 'branch_create';
  static const kindBranchUpdate = 'branch_update';
  static const kindBranchDelete = 'branch_delete';

  final int id;
  final int merchantId;

  /// profile | branch_create | branch_update | branch_delete. A STRING, like
  /// every other enum on this wire: a kind a later deploy adds must still
  /// parse and render, never crash this build.
  final String kind;

  /// The server's English name for the kind. The apps localize from [kind]
  /// and keep this as the fallback for a kind they do not know yet.
  final String kindLabel;

  /// pending | approved | rejected | superseded.
  final String status;

  /// Null for a profile change and for a new branch (there is no row yet);
  /// nulled again once a removal is approved.
  final int? branchId;

  /// The snapshot's `name`, which for a BRANCH kind is the branch's name as
  /// submitted — a removal's whole subject, and the only name a rename still
  /// remembers. For a PROFILE change the same key holds the STORE's name, so
  /// read this only when [isBranch].
  final String? branchName;

  final List<ChangeRequestDiff> changes;

  /// The proposed values, wire-keyed (`logo_path` published as `logo_url`).
  final Map<String, dynamic> proposed;

  /// The submit-time snapshot the diff was built against.
  final Map<String, dynamic> current;

  /// ISO 8601 UTC (the row's created_at).
  final String? submittedAt;
  final String? reviewedAt;
  final String? rejectedReason;

  bool get isPending => status == 'pending';
  bool get isProfile => kind == kindProfile;
  bool get isBranch => kind != kindProfile;
  bool get isBranchDelete => kind == kindBranchDelete;
  bool get isBranchCreate => kind == kindBranchCreate;
}

/// One before/after line of a queued change. [from] and [to] are whatever
/// the field holds on the wire — a string, a number, or null for "not set"
/// — never re-typed here.
class ChangeRequestDiff {
  const ChangeRequestDiff({required this.field, this.from, this.to});

  factory ChangeRequestDiff.fromJson(Map<String, dynamic> json) =>
      ChangeRequestDiff(
        field: _s(json['field']),
        from: json['from'],
        to: json['to'],
      );

  /// The wire key — `logo` for a logo change, the column name otherwise.
  final String field;
  final Object? from;
  final Object? to;
}

/// The answer to a gated write: what landed, and what is now waiting.
///
/// A profile PATCH can do BOTH in one request — the instant contact keys
/// apply on the spot (and are reflected in [profile]) while the claims queue
/// — which is why this carries the fresh profile as well as [queued].
class ProfileSaveResult {
  const ProfileSaveResult({required this.profile, this.queued});

  final MerchantProfile profile;

  /// The change request the gated half created, or null when everything the
  /// save carried applied immediately (nothing gated actually differed, or
  /// the store is not live yet).
  final MerchantChangeRequest? queued;

  bool get pending => queued != null;
}

/// The answer to a branch create/update: the row when it landed, the queued
/// request when it did not. Exactly one of the two is set.
class BranchSaveResult {
  const BranchSaveResult({this.branch, this.queued});

  /// The saved branch — null when the write queued instead (a new branch
  /// has no row at all until an admin approves it).
  final MerchantBranch? branch;
  final MerchantChangeRequest? queued;

  bool get pending => queued != null;
}

/// The answer to a branch delete: 204 (gone) or 202 (queued, still present).
class BranchDeleteResult {
  const BranchDeleteResult({this.queued});

  final MerchantChangeRequest? queued;

  bool get pending => queued != null;
}

/// The answer to a logo upload. [logoUrl] is always the logo the STORE IS
/// STILL SERVING: a gated upload stages the file privately and changes
/// nothing a shopper sees, so painting the new bytes as live would be a lie.
class LogoUploadResult {
  const LogoUploadResult({this.logoUrl, this.queued});

  final String? logoUrl;
  final MerchantChangeRequest? queued;

  bool get pending => queued != null;
}

/// One promotion (PromotionResource): a time-boxed cashback BOOST above the
/// standing rate. Rates are 2-decimal percent STRINGS off the wire; the fee
/// pair is null only for a stale draft whose rate the current fee schedule
/// no longer prices — still listed so it can be cancelled, refused on
/// publish. Amounts are integer laari; timestamps are ISO 8601 in the
/// business timezone.
class Promotion {
  Promotion({
    required this.id,
    required this.merchantId,
    this.branchId,
    required this.status,
    required this.isLive,
    required this.cashbackRatePercent,
    this.platformFeePercent,
    this.allInPercent,
    required this.startsAt,
    required this.endsAt,
    this.minPurchaseLaari,
    this.maxCashbackPerCustomerLaari,
    this.publishedAt,
    this.cancelledAt,
  });

  factory Promotion.fromJson(Map<String, dynamic> json) => Promotion(
    id: json['id'] as int? ?? 0,
    merchantId: json['merchant_id'] as int? ?? 0,
    branchId: json['branch_id'] as int?,
    status: _s(json['status']),
    isLive: json['is_live'] as bool? ?? false,
    cashbackRatePercent: _s(json['cashback_rate_percent']),
    platformFeePercent: json['platform_fee_percent'] as String?,
    allInPercent: json['all_in_percent'] as String?,
    startsAt: _s(json['starts_at']),
    endsAt: _s(json['ends_at']),
    minPurchaseLaari: json['min_purchase_laari'] as int?,
    maxCashbackPerCustomerLaari:
        json['max_cashback_per_customer_laari'] as int?,
    publishedAt: json['published_at'] as String?,
    cancelledAt: json['cancelled_at'] as String?,
  );

  final int id;
  final int merchantId;

  /// Null means the whole store; an id scopes the boost to one branch.
  final int? branchId;

  /// draft | published | ended | cancelled.
  final String status;

  /// Published AND inside its window right now — the server's own answer,
  /// never re-derived from the timestamps client-side.
  final bool isLive;
  final String cashbackRatePercent;
  final String? platformFeePercent;
  final String? allInPercent;
  final String startsAt;
  final String endsAt;
  final int? minPurchaseLaari;
  final int? maxCashbackPerCustomerLaari;
  final String? publishedAt;
  final String? cancelledAt;
}

/// The §4 cost picture the server sends BESIDE a created/published
/// promotion (`cost_preview` at the root, next to `data`): what the
/// merchant pays during the boost versus their standing terms, with the
/// tier-cliff warning data. Rendered verbatim — never derived client-side.
class PromotionCostPreview {
  PromotionCostPreview({
    required this.promo,
    this.standing,
    this.allInDeltaPercent,
    required this.tierChanged,
  });

  factory PromotionCostPreview.fromJson(Map<String, dynamic> json) =>
      PromotionCostPreview(
        promo: RateCost.fromJson(
          (json['promo'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        standing: json['standing'] is Map
            ? RateCost.fromJson(
                (json['standing'] as Map).cast<String, dynamic>(),
              )
            : null,
        allInDeltaPercent: json['all_in_delta_percent'] as String?,
        tierChanged: json['tier_changed'] as bool? ?? false,
      );

  final RateCost promo;

  /// Null when the store has no standing rate at the window start.
  final RateCost? standing;

  /// The server's signed 2-decimal delta in percentage points ("0.26",
  /// "-0.26"), or null without a standing comparison.
  final String? allInDeltaPercent;
  final bool tierChanged;
}

/// A promotion write's whole answer: the promotion plus the cost preview
/// the server served beside it (create and publish carry one; cancel does
/// not).
class PromotionWriteResult {
  PromotionWriteResult({required this.promotion, this.costPreview});

  final Promotion promotion;
  final PromotionCostPreview? costPreview;
}

// --------------------------------------------------------- closure (MR8)

/// One store on the phone number the closure OTP proved — the verify
/// answer's list. Money is integer laari; `canClose` is the server's own
/// verdict (payable outstanding == 0), never re-derived client-side.
class ClosureStore {
  ClosureStore({
    required this.id,
    required this.name,
    required this.status,
    required this.outstandingLaari,
    required this.canClose,
  });

  factory ClosureStore.fromJson(Map<String, dynamic> json) => ClosureStore(
    id: json['id'] as int? ?? 0,
    name: _s(json['name']),
    status: _s(json['status']),
    outstandingLaari: json['outstanding_laari'] as int? ?? 0,
    canClose: json['can_close'] as bool? ?? false,
  );

  final int id;
  final String name;
  final String status;

  /// Payable outstanding, integer laari. A store owing money cannot close —
  /// settling stays open, closing waits.
  final int outstandingLaari;
  final bool canClose;
}

/// POST /merchant/account-closure/verify — the single-use closure token
/// (15-minute life) plus every non-closed store on the proven number.
class ClosureVerification {
  ClosureVerification({
    required this.closureToken,
    required this.expiresInMinutes,
    required this.stores,
  });

  factory ClosureVerification.fromJson(Map<String, dynamic> json) =>
      ClosureVerification(
        closureToken: _s(json['closure_token']),
        expiresInMinutes: json['expires_in_minutes'] as int? ?? 0,
        stores: [
          for (final item in (json['stores'] as List? ?? const []))
            ClosureStore.fromJson((item as Map).cast<String, dynamic>()),
        ],
      );

  final String closureToken;
  final int expiresInMinutes;
  final List<ClosureStore> stores;
}
