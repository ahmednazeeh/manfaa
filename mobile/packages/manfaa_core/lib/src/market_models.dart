/// Marketplace models (PLAN-marketplace.md §2, §3).
///
/// The shape that governs all of it: a shopper buys from a BRANCH, not a
/// merchant. Stock and fulfilment are physical, so a chain's two shops
/// genuinely differ, and a card reads brand + island.
library;

/// Everything the store card and the store header need, for ONE shop.
class MarketBranch {
  const MarketBranch({
    required this.branchId,
    required this.merchantId,
    required this.storeName,
    this.storeNameDv,
    required this.branchName,
    required this.slug,
    this.address,
    this.cashbackRatePercent,
    this.logoUrl,
    this.favourite = false,
    this.fulfilment,
    this.rating,
    required this.ratingCount,
    required this.delivery,
    required this.pickupOnly,
  });

  factory MarketBranch.fromJson(Map<String, dynamic> json) => MarketBranch(
        branchId: json['branch_id'] as int? ?? 0,
        merchantId: json['merchant_id'] as int? ?? 0,
        storeName: (json['store_name'] as String?) ?? '',
        storeNameDv: json['store_name_dv'] as String?,
        branchName: (json['branch_name'] as String?) ?? '',
        slug: (json['slug'] as String?) ?? '',
        address: json['address'] as String?,
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        logoUrl: json['logo_url'] as String?,
        favourite: json['favourite'] as bool? ?? false,
        fulfilment: json['fulfilment'] as String?,
        rating: (json['rating'] as num?)?.toDouble(),
        ratingCount: json['rating_count'] as int? ?? 0,
        delivery: DeliveryTerms.fromJson(
          (json['delivery'] as Map?)?.cast<String, dynamic>() ?? const {},
        ),
        pickupOnly: json['pickup_only'] as bool? ?? false,
      );

  final int branchId;
  final int merchantId;
  final String storeName;
  final String? storeNameDv;
  final String branchName;
  final String slug;
  final String? address;

  /// The store's standing rate, or null when it has none yet.
  final String? cashbackRatePercent;

  /// The shop's own mark, or null when it has not uploaded one.
  final String? logoUrl;

  /// Whether THIS shopper has hearted the shop. False for a signed-out
  /// visitor, which is right — browsing is public and has no favourites.
  final bool favourite;
  final String? fulfilment;

  /// Null until somebody has rated it. NEVER 0.0 — a new shop has no
  /// rating, and zero stars would libel it on its first day.
  final double? rating;
  final int ratingCount;

  final DeliveryTerms delivery;

  /// No branch of this shop delivers to the chosen address. Shown, not
  /// hidden: the customer may still collect.
  final bool pickupOnly;

  String displayName(bool dhivehi) =>
      dhivehi && (storeNameDv?.isNotEmpty ?? false) ? storeNameDv! : storeName;
}

/// What a branch will charge to bring a basket to ONE island.
///
/// Every delivery number a shopper reads comes from here — the chips on the
/// store page, the subcart's fee line, the "MVR 66 to minimum" bar — so the
/// three can never drift apart.
class DeliveryTerms {
  const DeliveryTerms({
    required this.delivers,
    required this.feeLaari,
    required this.feeWaived,
    this.freeDeliveryOverLaari,
    this.orderMinimumLaari,
    required this.minimumMet,
    required this.shortfallLaari,
    this.toFreeDeliveryLaari,
    this.etaMin,
    this.etaMax,
  });

  factory DeliveryTerms.fromJson(Map<String, dynamic> json) => DeliveryTerms(
        delivers: json['delivers'] as bool? ?? false,
        feeLaari: json['fee_laari'] as int? ?? 0,
        feeWaived: json['fee_waived'] as bool? ?? false,
        freeDeliveryOverLaari: json['free_delivery_over_laari'] as int?,
        orderMinimumLaari: json['order_minimum_laari'] as int?,
        minimumMet: json['minimum_met'] as bool? ?? true,
        shortfallLaari: json['shortfall_laari'] as int? ?? 0,
        toFreeDeliveryLaari: json['to_free_delivery_laari'] as int?,
        etaMin: json['eta_min'] as int?,
        etaMax: json['eta_max'] as int?,
      );

  final bool delivers;
  final int feeLaari;
  final bool feeWaived;
  final int? freeDeliveryOverLaari;
  final int? orderMinimumLaari;
  final bool minimumMet;

  /// How much more this shop needs before it will deliver. The number the
  /// cart's warning renders.
  final int shortfallLaari;

  /// How much more would make delivery free, or null when there is nothing
  /// to earn. Drives the progress bar.
  final int? toFreeDeliveryLaari;

  final int? etaMin;
  final int? etaMax;

  /// "30–60 min", or null when the shop has not said.
  String? get etaLabel {
    if (etaMin == null && etaMax == null) return null;
    if (etaMin == null) return '$etaMax min';
    if (etaMax == null || etaMax == etaMin) return '$etaMin min';
    return '$etaMin–$etaMax min';
  }
}

/// One line on a shelf: this shop's price for this product.
class MarketProduct {
  const MarketProduct({
    required this.branchProductId,
    required this.productId,
    required this.name,
    this.nameDv,
    this.description,
    required this.priceLaari,
    this.compareAtLaari,
    this.cashbackRatePercent,
    this.imageUrl,
    required this.inStock,
    this.category,
  });

  factory MarketProduct.fromJson(Map<String, dynamic> json) => MarketProduct(
        branchProductId: json['branch_product_id'] as int? ?? 0,
        productId: json['product_id'] as int? ?? 0,
        name: (json['name'] as String?) ?? '',
        nameDv: json['name_dv'] as String?,
        description: json['description'] as String?,
        priceLaari: json['price_laari'] as int? ?? 0,
        compareAtLaari: json['compare_at_laari'] as int?,
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        imageUrl: json['image_url'] as String?,
        inStock: json['in_stock'] as bool? ?? false,
        category: json['category'] as String?,
      );

  final int branchProductId;
  final int productId;
  final String name;
  final String? nameDv;
  final String? description;
  final int priceLaari;

  /// The struck-through "was" price. Only ever ABOVE the live one.
  final int? compareAtLaari;
  final String? cashbackRatePercent;
  final String? imageUrl;
  final bool inStock;
  final String? category;

  String displayName(bool dhivehi) =>
      dhivehi && (nameDv?.isNotEmpty ?? false) ? nameDv! : name;
}

/// One shop's page: its terms, its aisles, its shelves.
class MarketStore {
  const MarketStore({
    required this.branchId,
    required this.storeName,
    required this.branchName,
    this.address,
    this.rating,
    required this.ratingCount,
    required this.delivery,
    this.cashbackRatePercent,
    this.logoUrl,
    required this.categories,
    required this.products,
  });

  factory MarketStore.fromJson(Map<String, dynamic> json) => MarketStore(
        branchId: json['branch_id'] as int? ?? 0,
        storeName: (json['store_name'] as String?) ?? '',
        branchName: (json['branch_name'] as String?) ?? '',
        address: json['address'] as String?,
        rating: (json['rating'] as num?)?.toDouble(),
        ratingCount: json['rating_count'] as int? ?? 0,
        delivery: DeliveryTerms.fromJson(
          (json['delivery'] as Map?)?.cast<String, dynamic>() ?? const {},
        ),
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        logoUrl: json['logo_url'] as String?,
        categories: ((json['categories'] as List?) ?? const [])
            .map((row) =>
                MarketCategory.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
        products: ((json['products'] as List?) ?? const [])
            .map((row) =>
                MarketProduct.fromJson((row as Map).cast<String, dynamic>()))
            .toList(growable: false),
      );

  final int branchId;
  final String storeName;
  final String branchName;
  final String? address;
  final double? rating;
  final int ratingCount;
  final DeliveryTerms delivery;
  final String? cashbackRatePercent;
  final String? logoUrl;

  /// Only the aisles this shop actually stocks — an empty chip is a promise
  /// the shelf cannot keep.
  final List<MarketCategory> categories;
  final List<MarketProduct> products;
}

class MarketCategory {
  const MarketCategory({required this.slug, required this.nameEn, this.nameDv});

  factory MarketCategory.fromJson(Map<String, dynamic> json) => MarketCategory(
        slug: (json['slug'] as String?) ?? '',
        nameEn: (json['name_en'] as String?) ?? '',
        nameDv: json['name_dv'] as String?,
      );

  final String slug;
  final String nameEn;
  final String? nameDv;

  String label(bool dhivehi) =>
      dhivehi && (nameDv?.isNotEmpty ?? false) ? nameDv! : nameEn;
}


/// A product found ACROSS stores (`AI Product Search.png`).
///
/// The shop is an attribute of the result, not a step before it — a shopper
/// wants rice, and which shop has it is our problem rather than theirs.
class SearchHit {
  const SearchHit({
    required this.branchProductId,
    required this.productId,
    required this.name,
    required this.priceLaari,
    required this.inStock,
    required this.store,
    required this.delivery,
    this.nameDv,
    this.imageUrl,
    this.compareAtLaari,
    this.cashbackRatePercent,
  });

  factory SearchHit.fromJson(Map<String, dynamic> json) => SearchHit(
        branchProductId: json['branch_product_id'] as int? ?? 0,
        productId: json['product_id'] as int? ?? 0,
        name: (json['name'] as String?) ?? '',
        nameDv: json['name_dv'] as String?,
        imageUrl: json['image_url'] as String?,
        priceLaari: json['price_laari'] as int? ?? 0,
        compareAtLaari: json['compare_at_laari'] as int?,
        inStock: json['in_stock'] as bool? ?? true,
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        store: SearchStore.fromJson(
          ((json['store'] as Map?) ?? const {}).cast<String, dynamic>(),
        ),
        delivery: DeliveryTerms.fromJson(
          ((json['delivery'] as Map?) ?? const {}).cast<String, dynamic>(),
        ),
      );

  final int branchProductId;
  final int productId;
  final String name;
  final String? nameDv;
  final String? imageUrl;
  final int priceLaari;
  final int? compareAtLaari;
  final bool inStock;
  final String? cashbackRatePercent;
  final SearchStore store;
  final DeliveryTerms delivery;

  bool get discounted =>
      compareAtLaari != null && compareAtLaari! > priceLaari;
}

class SearchStore {
  const SearchStore({
    required this.branchId,
    required this.name,
    required this.branchName,
    required this.ratingCount,
    this.logoUrl,
    this.rating,
  });

  factory SearchStore.fromJson(Map<String, dynamic> json) => SearchStore(
        branchId: json['branch_id'] as int? ?? 0,
        name: (json['name'] as String?) ?? '',
        branchName: (json['branch_name'] as String?) ?? '',
        ratingCount: json['rating_count'] as int? ?? 0,
        logoUrl: json['logo_url'] as String?,
        rating: (json['rating'] as num?)?.toDouble(),
      );

  final int branchId;
  final String name;
  final String branchName;
  final int ratingCount;
  final String? logoUrl;
  final double? rating;
}

/// What the search understood, so the chips on screen are a readout of the
/// query rather than decoration.
class SearchFacet {
  const SearchFacet({required this.key, required this.label});

  factory SearchFacet.fromJson(Map<String, dynamic> json) => SearchFacet(
        key: (json['key'] as String?) ?? '',
        label: (json['label'] as String?) ?? '',
      );

  final String key;
  final String label;
}

class SearchResults {
  const SearchResults({
    required this.hits,
    required this.facets,
    required this.summary,
  });

  factory SearchResults.fromJson(Map<String, dynamic> json) {
    final meta = (json['meta'] as Map?)?.cast<String, dynamic>();

    return SearchResults(
      hits: ((json['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => SearchHit.fromJson(row.cast<String, dynamic>()))
          .toList(growable: false),
      facets: ((meta?['facets'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => SearchFacet.fromJson(row.cast<String, dynamic>()))
          .toList(growable: false),
      summary: (meta?['summary'] as String?) ?? '',
    );
  }

  final List<SearchHit> hits;
  final List<SearchFacet> facets;
  final String summary;
}


/// One product opened on its own. Everything [SearchHit] has, plus the
/// detail a shopper wants before committing — and the store block, because
/// deciding to buy is also deciding who to buy from.
class ProductDetail {
  const ProductDetail({
    required this.hit,
    required this.images,
    required this.allowSubstitutions,
    this.description,
    this.stockQty,
  });

  factory ProductDetail.fromJson(Map<String, dynamic> json) => ProductDetail(
        hit: SearchHit.fromJson(json),
        description: json['description'] as String?,
        allowSubstitutions: json['allow_substitutions'] as bool? ?? false,
        stockQty: json['stock_qty'] as int?,
        images: ((json['images'] as List?) ?? const [])
            .whereType<String>()
            .toList(growable: false),
      );

  final SearchHit hit;
  final String? description;
  final bool allowSubstitutions;
  final int? stockQty;
  final List<String> images;

  /// Worth warning about, and only when it is genuinely tight.
  bool get lowStock => stockQty != null && stockQty! > 0 && stockQty! <= 5;
}
