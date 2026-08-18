/// Typed views over the mobile API's response shapes.
///
/// Hand-written and minimal on purpose: the shapes come from a versioned
/// contract (/api/mobile/v1 will not change under an installed app), and a
/// codegen dependency would outweigh a dozen small fromJson constructors.
/// Money fields are INTEGER LAARI throughout — never parsed into doubles.
library;

int _laari(Object? v) => switch (v) { final int i => i, _ => 0 };
String _s(Object? v) => v?.toString() ?? '';

/// GET /config — the version gate and feature switches.
class MobileConfig {
  MobileConfig({required this.gates, required this.features});

  factory MobileConfig.fromJson(Map<String, dynamic> json) {
    final apps = json['apps'] as Map? ?? const {};

    return MobileConfig(
      gates: {
        for (final MapEntry(:key, :value) in apps.entries)
          if (value is Map)
            key.toString(): {
              for (final platform in ['android', 'ios'])
                if (value[platform] is Map)
                  platform: AppGate.fromJson(
                    (value[platform] as Map).cast<String, dynamic>(),
                  ),
            },
      },
      features: (json['features'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }

  /// Version gates by app name ('customer', 'merchant'), then platform.
  /// The server serves every app's gates to every caller; each app reads
  /// its own via [resolveGate]'s `app` parameter.
  final Map<String, Map<String, AppGate>> gates;

  /// The customer app's view, kept because that app predates per-app gates.
  Map<String, AppGate> get customerGates => gates['customer'] ?? const {};

  final Map<String, dynamic> features;
}

class AppGate {
  AppGate({
    required this.minimumBuild,
    required this.latestBuild,
    required this.storeUrl,
  });

  factory AppGate.fromJson(Map<String, dynamic> json) => AppGate(
        minimumBuild: json['minimum_build'] as int? ?? 1,
        latestBuild: json['latest_build'] as int? ?? 1,
        storeUrl: _s(json['store_url']),
      );

  final int minimumBuild;
  final int latestBuild;
  final String storeUrl;
}

/// GET /customer/me
class CustomerMe {
  CustomerMe({
    required this.id,
    required this.name,
    required this.customerCode,
    required this.phone,
    required this.hasPayoutAccount,
    this.avatarUrl,
  });

  factory CustomerMe.fromJson(Map<String, dynamic> json) => CustomerMe(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        customerCode: _s(json['customer_code']),
        phone: _s(json['phone']),
        hasPayoutAccount: json['has_payout_account'] as bool? ?? false,
        avatarUrl: json['avatar_url'] as String?,
      );

  final int id;
  final String name;
  final String customerCode;
  final String phone;
  final bool hasPayoutAccount;

  /// Null until the customer sets a profile picture.
  final String? avatarUrl;
}

/// GET /customer/home — the whole first screen in one response.
class HomeData {
  HomeData({
    required this.customerName,
    required this.customerCode,
    required this.confirmedLaari,
    required this.pendingLaari,
    required this.paidThisMonthLaari,
    required this.minimumPayoutLaari,
    required this.payoutWindowStart,
    required this.payoutWindowEnd,
    required this.hasPayoutAccount,
    this.avatarUrl,
  });

  factory HomeData.fromJson(Map<String, dynamic> json) {
    final customer = (json['customer'] as Map?) ?? const {};
    final balance = (json['balance'] as Map?) ?? const {};
    final payout = (json['payout'] as Map?) ?? const {};
    final window = (payout['next_window'] as Map?) ?? const {};

    return HomeData(
      customerName: _s(customer['name']),
      customerCode: _s(customer['customer_code']),
      avatarUrl: customer['avatar_url'] as String?,
      confirmedLaari: _laari(balance['confirmed_laari']),
      pendingLaari: _laari(balance['pending_laari']),
      paidThisMonthLaari: _laari(balance['paid_this_month_laari']),
      minimumPayoutLaari: _laari(payout['minimum_laari']),
      payoutWindowStart: _s(window['starts_at']),
      payoutWindowEnd: _s(window['ends_at']),
      hasPayoutAccount: payout['has_account'] as bool? ?? false,
    );
  }

  final String customerName;
  final String customerCode;

  /// Null until the customer sets a profile picture — the top bar's circle.
  final String? avatarUrl;

  /// §10, carried into the client type system: confirmed and pending are
  /// SEPARATE fields and nothing here offers a sum.
  final int confirmedLaari;
  final int pendingLaari;
  final int paidThisMonthLaari;

  final int minimumPayoutLaari;
  final String payoutWindowStart;
  final String payoutWindowEnd;
  final bool hasPayoutAccount;
}

/// GET /customer/devices — the lost-phone screen.
class DeviceEntry {
  DeviceEntry({
    required this.id,
    required this.deviceName,
    required this.signedInAt,
    required this.lastUsedAt,
    required this.isCurrentDevice,
  });

  factory DeviceEntry.fromJson(Map<String, dynamic> json) => DeviceEntry(
        id: json['id'] as int? ?? 0,
        deviceName: _s(json['device_name']),
        signedInAt: _s(json['signed_in_at']),
        lastUsedAt: _s(json['last_used_at']),
        isCurrentDevice: json['is_current_device'] as bool? ?? false,
      );

  final int id;
  final String deviceName;
  final String signedInAt;
  final String lastUsedAt;
  final bool isCurrentDevice;
}

class SignInResult {
  SignInResult({
    required this.token,
    required this.customerName,
    required this.customerCode,
  });

  final String token;
  final String customerName;
  final String customerCode;
}

/// POST /customer/auth/otp/verify — one proof of possession, two outcomes.
class OtpVerifyOutcome {
  OtpVerifyOutcome._({required this.signedIn, this.signupToken});

  factory OtpVerifyOutcome.signedIn() => OtpVerifyOutcome._(signedIn: true);

  factory OtpVerifyOutcome.registrationRequired(String signupToken) =>
      OtpVerifyOutcome._(signedIn: false, signupToken: signupToken);

  final bool signedIn;

  /// Set only when registration is required.
  final String? signupToken;
}

/// One page of a cursor-paged mobile list (guide §6 shape).
class CursorPage<T> {
  CursorPage({required this.items, this.nextCursor, required this.hasMore});

  final List<T> items;
  final String? nextCursor;
  final bool hasMore;
}

/// One earning, as the customer sees it (§6 mapping): the simplified
/// status, a translatable reason KEY, and only the amounts that concern
/// them — never the merchant's commercial terms.
class TransactionEntry {
  TransactionEntry({
    required this.id,
    required this.merchantName,
    required this.merchantSlug,
    required this.invoiceNo,
    required this.eligibleLaari,
    required this.cashbackLaari,
    required this.status,
    required this.statusReason,
    required this.occurredAt,
  });

  factory TransactionEntry.fromJson(Map<String, dynamic> json) {
    final merchant = (json['merchant'] as Map?) ?? const {};

    return TransactionEntry(
      id: json['id'] as int? ?? 0,
      merchantName: _s(merchant['name']),
      merchantSlug: _s(merchant['slug']),
      invoiceNo: _s(json['invoice_no']),
      eligibleLaari: _laari(json['eligible_laari']),
      cashbackLaari: _laari(json['cashback_laari']),
      status: _s(json['status']),
      statusReason: json['status_reason'] as String?,
      occurredAt: _s(json['occurred_at']),
    );
  }

  final int id;
  final String merchantName;
  final String merchantSlug;
  final String invoiceNo;
  final int eligibleLaari;
  final int cashbackLaari;

  /// pending | confirmed | paid | reversed | unpaid — a KEY the app
  /// translates; raw internal state never crosses the wire.
  final String status;

  /// Reason KEY (validation_window, merchant_settlement_window,
  /// under_review, merchant_not_settled, or a reversal reason_code) —
  /// null for settled rewards.
  final String? statusReason;

  final String occurredAt;
}

/// One payout — money that actually reached (or bounced from) the bank.
class PayoutEntry {
  PayoutEntry({
    required this.id,
    required this.amountLaari,
    required this.status,
    required this.failureReason,
    required this.bank,
    required this.accountMasked,
    required this.reference,
    required this.periodStart,
    required this.periodEnd,
    required this.transactionCount,
    required this.paidAt,
  });

  factory PayoutEntry.fromJson(Map<String, dynamic> json) => PayoutEntry(
        id: json['id'] as int? ?? 0,
        amountLaari: _laari(json['amount_laari']),
        status: _s(json['status']),
        failureReason: json['failure_reason'] as String?,
        bank: _s(json['bank']),
        accountMasked: json['account_masked'] as String?,
        reference: json['reference'] as String?,
        periodStart: _s(json['period_start']),
        periodEnd: _s(json['period_end']),
        transactionCount: json['transaction_count'] as int? ?? 0,
        paidAt: json['paid_at'] as String?,
      );

  final int id;
  final int amountLaari;

  /// sent | paid | failed.
  final String status;
  final String? failureReason;
  final String bank;
  final String? accountMasked;
  final String? reference;
  final String periodStart;
  final String periodEnd;
  final int transactionCount;
  final String? paidAt;
}

/// A payout with the purchases it covered — sum the lines for a total; the
/// lines are the evidence and the amount is the payment.
class PayoutDetail {
  PayoutDetail({required this.payout, required this.covered});

  factory PayoutDetail.fromJson(Map<String, dynamic> json) => PayoutDetail(
        payout: PayoutEntry.fromJson(json),
        covered: [
          for (final item in (json['transactions'] as List? ?? const []))
            CoveredPurchase.fromJson((item as Map).cast<String, dynamic>()),
        ],
      );

  final PayoutEntry payout;
  final List<CoveredPurchase> covered;
}

class CoveredPurchase {
  CoveredPurchase({
    required this.merchantName,
    required this.invoiceNo,
    required this.cashbackLaari,
    required this.occurredAt,
  });

  factory CoveredPurchase.fromJson(Map<String, dynamic> json) {
    final merchant = (json['merchant'] as Map?) ?? const {};

    return CoveredPurchase(
      merchantName: _s(merchant['name']),
      invoiceNo: _s(json['invoice_no']),
      cashbackLaari: _laari(json['cashback_laari']),
      occurredAt: _s(json['occurred_at']),
    );
  }

  final String merchantName;
  final String invoiceNo;
  final int cashbackLaari;
  final String occurredAt;
}

/// A store as the public discovery feed presents it — the rate is a
/// 2-decimal percent STRING from the wire (never parsed to a double; it is
/// display text and stays exact).
class StoreEntry {
  StoreEntry({
    required this.name,
    required this.nameDv,
    required this.slug,
    required this.category,
    required this.logoUrl,
    required this.channel,
    required this.ratePercent,
    required this.standingRatePercent,
    required this.promoEndsAt,
    required this.distanceM,
  });

  factory StoreEntry.fromJson(Map<String, dynamic> json) => StoreEntry(
        name: _s(json['name']),
        nameDv: json['name_dv'] as String?,
        slug: _s(json['slug']),
        category: json['category'] as String?,
        logoUrl: json['logo_url'] as String?,
        channel: _s(json['channel']),
        ratePercent: _s(json['cashback_rate_percent']),
        standingRatePercent: _s(json['standing_cashback_rate_percent']),
        promoEndsAt: json['promo_ends_at'] as String?,
        distanceM: switch (json['distance_m']) {
          final num d => d.toDouble(),
          _ => null,
        },
      );

  final String name;

  /// Null when the store has not supplied one — fall back to [name].
  final String? nameDv;
  final String slug;
  final String? category;
  final String? logoUrl;

  /// in_store | online | both.
  final String channel;
  final String ratePercent;
  final String standingRatePercent;
  final String? promoEndsAt;
  final double? distanceM;

  /// A promotion is running when the live rate beats the standing one.
  bool get boosted => ratePercent != standingRatePercent;

  String displayName(bool dhivehi) =>
      dhivehi && (nameDv?.isNotEmpty ?? false) ? nameDv! : name;
}

/// A curated category with its live store count.
class CategoryEntry {
  CategoryEntry({
    required this.slug,
    required this.nameEn,
    required this.nameDv,
    required this.iconUrl,
    required this.count,
  });

  factory CategoryEntry.fromJson(Map<String, dynamic> json) => CategoryEntry(
        slug: _s(json['slug']),
        nameEn: _s(json['name_en'] ?? json['name']),
        nameDv: json['name_dv'] as String?,
        iconUrl: json['icon_url'] as String?,
        count: json['count'] as int? ?? json['merchant_count'] as int? ?? 0,
      );

  final String slug;
  final String nameEn;
  final String? nameDv;
  final String? iconUrl;
  final int count;
}

/// A featured offer banner — ONE of two kinds, never a blend (§13b): an
/// `image` banner IS the artwork edge to edge; a `text` banner is composed
/// by the client from these words plus the LIVE rate.
class OfferEntry {
  OfferEntry({
    required this.id,
    required this.kind,
    required this.title,
    required this.titleDv,
    required this.blurb,
    required this.blurbDv,
    required this.badge,
    required this.badgeDv,
    required this.imageUrl,
    required this.endsAt,
    required this.merchant,
  });

  factory OfferEntry.fromJson(Map<String, dynamic> json) => OfferEntry(
        id: json['id'] as int? ?? 0,
        kind: _s(json['kind']),
        title: _s(json['title']),
        titleDv: json['title_dv'] as String?,
        blurb: json['blurb'] as String?,
        blurbDv: json['blurb_dv'] as String?,
        badge: json['badge'] as String?,
        badgeDv: json['badge_dv'] as String?,
        imageUrl: json['image_url'] as String?,
        endsAt: json['ends_at'] as String?,
        merchant: StoreEntry.fromJson(
          (json['merchant'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
      );

  final int id;

  /// image | text.
  final String kind;
  final String title;
  final String? titleDv;
  final String? blurb;
  final String? blurbDv;
  final String? badge;
  final String? badgeDv;
  final String? imageUrl;
  final String? endsAt;
  final StoreEntry merchant;
}

/// GET /api/discover — the landing shelves.
class DiscoverFeed {
  DiscoverFeed({
    required this.shelves,
    required this.categories,
    required this.offers,
  });

  factory DiscoverFeed.fromJson(Map<String, dynamic> json) {
    List<StoreEntry> shelf(Object? raw) => [
          for (final item in (raw as List? ?? const []))
            StoreEntry.fromJson((item as Map).cast<String, dynamic>()),
        ];

    return DiscoverFeed(
      shelves: {
        for (final key in const [
          'increased',
          'featured',
          'nearby',
          'recently_added',
          'in_store',
          'online',
        ])
          if (json[key] is List) key: shelf(json[key]),
      },
      categories: [
        for (final item in (json['categories'] as List? ?? const []))
          CategoryEntry.fromJson((item as Map).cast<String, dynamic>()),
      ],
      offers: [
        for (final item in (json['offers'] as List? ?? const []))
          OfferEntry.fromJson((item as Map).cast<String, dynamic>()),
      ],
    );
  }

  /// Keyed by section name, in the plan's display order: increased first.
  final Map<String, List<StoreEntry>> shelves;
  final List<CategoryEntry> categories;
  final List<OfferEntry> offers;
}

/// GET /api/discover/merchants/{slug} — one store's public page.
class StorePage {
  StorePage({
    required this.entry,
    required this.description,
    required this.descriptionDv,
    required this.eligibilityBasis,
    required this.branches,
    required this.productCategories,
    required this.contactPhone,
    required this.websiteUrl,
  });

  factory StorePage.fromJson(Map<String, dynamic> json) => StorePage(
        entry: StoreEntry.fromJson(json),
        // Nullable on the wire and NOT a defaulted empty string: the stores
        // that predate the field send no description at all, and the page
        // must render as if the block did not exist rather than as a blank
        // one.
        description: json['description'] as String?,
        descriptionDv: json['description_dv'] as String?,
        // The storefront payload names this `cashback_basis`; the
        // `eligibility_basis` fallback is the merchant-side spelling, kept
        // so any payload that ever used it still fills the card.
        eligibilityBasis:
            (json['cashback_basis'] ?? json['eligibility_basis']) as String?,
        branches: [
          for (final item in (json['branches'] as List? ?? const []))
            StoreBranch.fromJson((item as Map).cast<String, dynamic>()),
        ],
        // Ditto: the storefront serves the rate card as `category_rates`.
        productCategories: [
          for (final item
              in ((json['category_rates'] ?? json['product_categories'])
                      as List? ??
                  const []))
            (item as Map).cast<String, dynamic>(),
        ],
        // The API serves the number under both keys; older payloads only
        // carried support_phone, so fall back rather than show no phone.
        contactPhone:
            (json['contact_phone'] ?? json['support_phone']) as String?,
        websiteUrl: json['website_url'] as String?,
      );

  final StoreEntry entry;

  /// The store's own words about itself. Null for every store that has not
  /// written one — the stores already live predate the field, and the live
  /// storefront answers `"description": null` for them today.
  final String? description;

  /// The Thaana description; null when the store wrote only the Latin one.
  final String? descriptionDv;

  /// §11: displayed to customers because the merchant defines their own
  /// eligible amount — the over-promise guard.
  final String? eligibilityBasis;
  final List<StoreBranch> branches;
  final List<Map<String, dynamic>> productCategories;
  final String? contactPhone;
  final String? websiteUrl;

  /// The description in the reader's language, or null when there is
  /// nothing to show — the single guard the UI asks before it draws the
  /// block, so "unset", "null" and "  " all mean the same thing: no block.
  ///
  /// Mirrors [StoreEntry.displayName]: Thaana when the reader is on Dhivehi
  /// AND the store wrote one, otherwise the Latin text. A blank Latin one is
  /// never papered over with the Thaana text — an English reader would get
  /// a script they did not ask for.
  String? displayDescription(bool dhivehi) {
    if (descriptionIsThaana(dhivehi)) {
      return descriptionDv!.trim();
    }
    final en = description?.trim();

    return en == null || en.isEmpty ? null : en;
  }

  /// Whether [displayDescription] answers with the Thaana text — which is
  /// what decides the paragraph's DIRECTION, not the reader's locale.
  ///
  /// A Dhivehi reader on a store that wrote only a Latin description gets
  /// that Latin text; laid out RTL it right-aligns English prose and throws
  /// its full stop to the wrong end. Same rule as [displayDescription], said
  /// once so the two can never disagree.
  bool descriptionIsThaana(bool dhivehi) {
    final dv = descriptionDv?.trim();

    return dhivehi && dv != null && dv.isNotEmpty;
  }
}

class StoreBranch {
  StoreBranch({
    required this.name,
    required this.address,
    required this.lat,
    required this.lng,
  });

  factory StoreBranch.fromJson(Map<String, dynamic> json) => StoreBranch(
        name: _s(json['name']),
        address: json['address'] as String?,
        lat: switch (json['lat']) { final num v => v.toDouble(), _ => null },
        lng: switch (json['lng']) { final num v => v.toDouble(), _ => null },
      );

  final String name;
  final String? address;

  /// Null when the merchant has not pinned this branch on the map.
  final double? lat;
  final double? lng;

  bool get pinned => lat != null && lng != null;
}

/// An island zone from GET /discover/zones — the location picker's unit.
class ZoneEntry {
  ZoneEntry({
    required this.id,
    required this.name,
    required this.nameDv,
    required this.storeCount,
  });

  factory ZoneEntry.fromJson(Map<String, dynamic> json) => ZoneEntry(
        id: json['id'] as int? ?? 0,
        name: _s(json['name']),
        nameDv: json['name_dv'] as String?,
        storeCount: json['store_count'] as int? ?? 0,
      );

  final int id;
  final String name;
  final String? nameDv;
  final int storeCount;

  String displayName(bool dhivehi) =>
      dhivehi && (nameDv?.isNotEmpty ?? false) ? nameDv! : name;
}

/// GET /api/discover/merchants — the searchable directory (offset-paged on
/// the server; small pages, alphabetical).
class DirectoryPage {
  DirectoryPage({
    required this.stores,
    required this.total,
    required this.page,
    required this.perPage,
  });

  factory DirectoryPage.fromJson(
    List<dynamic> data,
    Map<String, dynamic> meta,
  ) =>
      DirectoryPage(
        stores: [
          for (final item in data)
            StoreEntry.fromJson((item as Map).cast<String, dynamic>()),
        ],
        total: meta['total'] as int? ?? 0,
        page: meta['page'] as int? ?? 1,
        perPage: meta['per_page'] as int? ?? 25,
      );

  final List<StoreEntry> stores;
  final int total;
  final int page;
  final int perPage;

  bool get hasMore => page * perPage < total;
}

/// GET/PUT /customer/payout-account — where cashback is sent.
class PayoutAccount {
  PayoutAccount({
    required this.bankName,
    required this.accountNo,
    required this.accountName,
    required this.hasAccount,
  });

  factory PayoutAccount.fromJson(Map<String, dynamic> json) => PayoutAccount(
        bankName: json['bank_name'] as String?,
        accountNo: json['account_no'] as String?,
        accountName: json['account_name'] as String?,
        hasAccount: json['has_payout_account'] as bool? ?? false,
      );

  /// bml | mib — the app maps to a label and logo.
  final String? bankName;
  final String? accountNo;
  final String? accountName;
  final bool hasAccount;
}
