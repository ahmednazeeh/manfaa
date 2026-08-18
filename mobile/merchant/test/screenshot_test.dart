import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show FontLoader, rootBundle;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/credit/credit_screen.dart'
    show creditClock;

/// Not a test — a screenshot harness. `flutter test test/screenshot_test.dart
/// --update-goldens` writes real PNGs of the running screens so the UI can
/// actually be LOOKED at. Loads the bundled fonts so text renders as glyphs,
/// not boxes.
Future<void> _loadFonts() async {
  final manifest =
      json.decode(await rootBundle.loadString('FontManifest.json'))
          as List<dynamic>;

  for (final family in manifest) {
    final loader = FontLoader(family['family'] as String);
    for (final asset in family['fonts'] as List<dynamic>) {
      loader.addFont(rootBundle.load(asset['asset'] as String));
    }
    await loader.load();
  }
}

const _permissions = [
  'settlements.view',
  'settlements.create',
  'settlements.preview',
  'settlements.receipt_add',
  'wallet.view',
  'wallet.settle',
  'credits.create',
  'credits.custom_rate',
  'transactions.view',
  'transactions.amend',
  'transactions.cancel',
  // The owner's setup estate (MR1) — the wizard shots need the gates.
  'setup.view',
  'setup.edit',
  'setup.submit',
  'branding.update',
  // The More estate (MR5) — the owner's full hand, so every menu row and
  // section draws in the shots.
  'profile.view',
  'profile.edit',
  'rate.view',
  'rate.update',
  'product_categories.view',
  'product_categories.create',
  'product_categories.edit',
  'preferences.update',
  'staff.view',
  'staff.invite',
  'staff.edit',
  'roles.view',
  'roles.manage',
  'branches.view',
  'branches.create',
  'branches.edit',
  'branches.delete',
  'promotions.view',
  'promotions.create',
  'promotions.publish',
  'promotions.cancel',
];

// ---- MR3 fixtures: the ref's own numbers, server-shaped ---------------------
//
// Dashboard.png / Settlements.png: one outstanding sale of MVR 1,000 —
// cashback 20.00, fee 7.50, GST 0 → payable 27.50, in the 0–5 bucket, with
// the 5% prompt-payment discount worth 0.38 (ceiling of 5% × 7.50) and the
// oldest clock starting 10 Aug → deadline 25 Aug 2026. All instants are
// FIXED so the goldens render the same bytes on every run.

const _shotOutstanding = {
  'as_of': '2026-08-16T14:07:00+05:00',
  'total': {
    'count': 1,
    'cashback_laari': 2000,
    'fee_laari': 750,
    'fee_gst_laari': 0,
    'payable_laari': 2750,
  },
  'buckets': {
    '0_5': {
      'count': 1,
      'cashback_laari': 2000,
      'fee_laari': 750,
      'fee_gst_laari': 0,
      'payable_laari': 2750,
    },
    '6_10': {
      'count': 0,
      'cashback_laari': 0,
      'fee_laari': 0,
      'fee_gst_laari': 0,
      'payable_laari': 0,
    },
    '11_15': {
      'count': 0,
      'cashback_laari': 0,
      'fee_laari': 0,
      'fee_gst_laari': 0,
      'payable_laari': 0,
    },
    'overdue': {
      'count': 0,
      'cashback_laari': 0,
      'fee_laari': 0,
      'fee_gst_laari': 0,
      'payable_laari': 0,
    },
  },
  'pending_adjustments': {'count': 0, 'credit_laari': 0},
};

const _shotPreview = {
  'as_of': '2026-08-16T09:07:00+00:00',
  'transaction_ids': [61],
  'transaction_count': 1,
  'sale_total_laari': 100000,
  'cashback_total_laari': 2000,
  'fee_total_laari': 750,
  'fee_gst_total_laari': 0,
  'line_total_laari': 2750,
  'credit_applied_laari': 0,
  'discount_laari': 38,
  'amount_due_before_discount_laari': 2750,
  'amount_due_laari': 2712,
  'due_at': '2026-08-25T00:00:00+00:00',
  'discount': {
    'eligible': true,
    'reason_code': 'eligible',
    'rate_percent': '5.00',
    'max_age_days': 15,
    'discount_laari': 38,
    'fee_discount_laari': 38,
    'gst_relief_laari': 0,
  },
  'transactions': [
    {
      'id': 61,
      'invoice_no': 'INV-2107',
      'occurred_at': '2026-08-15T10:00:00+05:00',
      'clock_start_at': '2026-08-10T00:00:00+00:00',
      'due_at': '2026-08-25T00:00:00+00:00',
      'age_days': 6,
      'overdue': false,
      'cashback_laari': 2000,
      'fee_laari': 750,
      'fee_gst_laari': 0,
      'due_laari': 2750,
      'selected': true,
    },
  ],
  'buckets': {
    'all': {
      'count': 1,
      'cashback_laari': 2000,
      'fee_laari': 750,
      'fee_gst_laari': 0,
      'due_laari': 2750,
      'transaction_ids': [61],
    },
    'older_than_5': {
      'count': 1,
      'cashback_laari': 2000,
      'fee_laari': 750,
      'fee_gst_laari': 0,
      'due_laari': 2750,
      'transaction_ids': [61],
    },
    'older_than_10': {
      'count': 0,
      'cashback_laari': 0,
      'fee_laari': 0,
      'fee_gst_laari': 0,
      'due_laari': 0,
      'transaction_ids': <int>[],
    },
    'overdue': {
      'count': 0,
      'cashback_laari': 0,
      'fee_laari': 0,
      'fee_gst_laari': 0,
      'due_laari': 0,
      'transaction_ids': <int>[],
    },
  },
  'payment_instructions': {
    'amount_due_laari': 2712,
    'bank_account': {
      'bank_name': 'bml',
      'account_no': '7730000123456',
      'account_name': 'Manfaa Pvt Ltd',
    },
    'bank_accounts': [
      {
        'id': 1,
        'bank_name': 'bml',
        'account_no': '7730000123456',
        'account_name': 'Manfaa Pvt Ltd',
        'currency': 'MVR',
        'is_primary': true,
      },
    ],
    'needs_configuration': false,
  },
};

Map<String, dynamic> _shotSettlement(
  int id,
  String reference,
  int dueLaari,
  String createdAt,
) => {
  'id': id,
  'reference': reference,
  'state': 'settled',
  'funding_method': 'bank',
  'currency': 'MVR',
  'sale_total_laari': 100000,
  'cashback_total_laari': 2000,
  'fee_total_laari': 750,
  'fee_gst_total_laari': 0,
  'discount_laari': 0,
  'discount_rate_percent': null,
  'discount_reason': null,
  'amount_due_laari': dueLaari,
  'amount_received_laari': dueLaari,
  'due_at': null,
  'created_at': createdAt,
  'payment_instructions': {
    'reference': reference,
    'amount_due_laari': dueLaari,
    'bank_account': {
      'bank_name': 'bml',
      'account_no': '7730000123456',
      'account_name': 'Manfaa Pvt Ltd',
    },
    'bank_accounts': <Object>[],
    'needs_configuration': false,
  },
  'merchant_status': {
    'code': 'settled',
    'message': 'Settled — the rewards on this batch are confirmed.',
    'rejection': null,
  },
};

/// A believable wizard state per shot: fresh (the profile step), complete
/// (the review step), pending (the waiting screen).
Map<String, dynamic> _setupFixture({
  required String status,
  bool complete = false,
  String? submittedAt,
}) => {
  'status': status,
  'steps': {
    'profile': complete,
    'location': complete,
    'logo': false,
    'rate': complete,
  },
  'values': {
    'name': 'Tropical Mart',
    'slug': 'tropical-mart',
    'category': complete ? 'grocery' : null,
    'channel': 'in_store',
    'eligibility_basis': complete
        ? 'Everything in store except tobacco, phone top-ups and gift cards.'
        : null,
    'contact_email': complete ? 'hello@tropicalmart.mv' : null,
    'contact_phone': complete ? '+9607781234' : null,
    'support_phone': null,
    'website_url': null,
    'primary_branch': complete
        ? {
            'id': 3,
            'name': 'Tropical Mart',
            'address': 'Majeedhee Magu, Malé',
            'lat': 4.1755354,
            'lng': 73.5093474,
          }
        : null,
    'logo_url': null,
    'cashback_rate_percent': complete ? '2.00' : null,
  },
  'rate_bounds': {'min_percent': '0.50', 'max_percent': '10.00'},
  'categories': [
    {
      'slug': 'grocery',
      'name_en': 'Grocery / Supermarket',
      'name_dv': 'ފިހާރަ',
    },
    {'slug': 'dining', 'name_en': 'Dining & Cafés', 'name_dv': 'ކެފޭ'},
    {'slug': 'fashion', 'name_en': 'Fashion', 'name_dv': null},
    {'slug': 'electronics', 'name_en': 'Electronics', 'name_dv': null},
    {'slug': 'health', 'name_en': 'Health & Beauty', 'name_dv': null},
    {'slug': 'services', 'name_en': 'Services', 'name_dv': null},
  ],
  'submitted_at': submittedAt,
  'rejected_reason': null,
};

// ---- MR9 fixtures: what is waiting on a reviewer ---------------------------
//
// Verbatim MerchantChangeRequestResource shapes (captured off the real PHP
// answering the mobile mount), with fixed instants so the goldens render the
// same bytes on every run.

const _shotPendingProfile = <String, dynamic>{
  'id': 21,
  'merchant_id': 7,
  'kind': 'profile',
  'kind_label': 'store profile change',
  'status': 'pending',
  'branch_id': null,
  'branch_name': 'Tropical Mart',
  'changes': [
    {'field': 'name', 'from': 'Tropical Mart', 'to': 'Tropical Fresh Market'},
    {'field': 'category', 'from': 'grocery', 'to': 'dining'},
    {'field': 'logo', 'from': null, 'to': 'https://manfaa.app/logo?side=1'},
  ],
  'proposed': {
    'name': 'Tropical Fresh Market',
    'category': 'dining',
    'logo_url': 'https://manfaa.app/logo?side=1',
  },
  'current': {
    'name': 'Tropical Mart',
    'category': 'grocery',
    'logo_url': null,
  },
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

const _shotPendingBranchCreate = <String, dynamic>{
  'id': 22,
  'merchant_id': 7,
  'kind': 'branch_create',
  'kind_label': 'new branch',
  'status': 'pending',
  'branch_id': null,
  'branch_name': null,
  'changes': [
    {'field': 'name', 'from': null, 'to': 'Tropical Mart — Addu'},
    {'field': 'address', 'from': null, 'to': 'Link Road, Hithadhoo'},
    {'field': 'lat', 'from': null, 'to': -0.6006},
    {'field': 'lng', 'from': null, 'to': 73.0836},
  ],
  'proposed': {
    'name': 'Tropical Mart — Addu',
    'address': 'Link Road, Hithadhoo',
    'lat': -0.6006,
    'lng': 73.0836,
  },
  'current': {'name': null, 'address': null, 'lat': null, 'lng': null},
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

const _shotPendingBranchUpdate = <String, dynamic>{
  'id': 23,
  'merchant_id': 7,
  'kind': 'branch_update',
  'kind_label': 'branch update',
  'status': 'pending',
  'branch_id': 4,
  'branch_name': 'Tropical Mart — Hulhumalé',
  'changes': [
    {
      'field': 'name',
      'from': 'Tropical Mart — Hulhumalé',
      'to': 'Tropical Mart — Hulhumalé Phase 2',
    },
  ],
  'proposed': {'name': 'Tropical Mart — Hulhumalé Phase 2'},
  'current': {'id': 4, 'name': 'Tropical Mart — Hulhumalé'},
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

class _ShotApi extends MerchantApi {
  _ShotApi({
    required super.session,
    this.status = 'active',
    this.setup,
    this.walletJson,
    this.categoriesJson,
    this.pendingProfileJson,
    this.pendingChanges,
  });

  final String status;
  final Map<String, dynamic>? setup;

  /// MR9 — the profile change waiting on a reviewer (the `pending_change`
  /// key of MerchantProfileResource), and the branch queue the branches
  /// index publishes under `meta.pending_changes`. Null on every other shot,
  /// so the shipped goldens render byte-identically.
  final Map<String, dynamic>? pendingProfileJson;
  final List<MerchantChangeRequest>? pendingChanges;

  /// Per-shot category vocabulary — the MR5 cashback shot wants the ref's
  /// four rules; the credit shots keep the split-editor trio.
  final List<Map<String, dynamic>>? categoriesJson;

  /// The wallet fixture per shot: the money shots want the ref's MVR 0.00
  /// (which is also what draws the Insufficient state), the wallet shot a
  /// balance with movements.
  final Map<String, dynamic>? walletJson;

  // ---- MR3: money ---------------------------------------------------------

  @override
  Future<MerchantOutstanding> outstanding() async =>
      MerchantOutstanding.fromJson(_shotOutstanding);

  @override
  Future<MerchantWalletState> wallet() async => MerchantWalletState.fromJson(
    walletJson ??
        const {
          'balance_laari': 0,
          'currency': 'MVR',
          'transactions': <Object>[],
        },
  );

  @override
  Future<SettlementPreviewData> settlementPreview({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async => SettlementPreviewData.fromJson(_shotPreview);

  @override
  Future<SettlementPage> settlements({
    int page = 1,
  }) async => SettlementPage.fromJson({
    'data': [
      _shotSettlement(41, 'ST-2026-00041', 2750, '2026-08-01T09:00:00+00:00'),
      _shotSettlement(38, 'ST-2026-00038', 3210, '2026-07-18T09:00:00+00:00'),
    ],
    'meta': const {'current_page': 1, 'last_page': 1, 'total': 2},
  });

  @override
  Future<MerchantSettlement> settlement(int id) async =>
      MerchantSettlement.fromJson(
        _shotSettlement(
          id,
          'ST-2026-000$id',
          2750,
          '2026-08-01T09:00:00+00:00',
        ),
      );

  @override
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson({
      'user': {'id': 1, 'name': 'Aminath Waheedha', 'email': 'a@tropical.mv'},
      'merchant': {
        'id': 7,
        'name': 'Tropical Mart',
        'slug': 'tropical-mart',
        'status': status,
      },
      'permissions': _permissions,
    });
    await session.saveProfile(
      userName: me.user.name,
      userEmail: me.user.email,
      merchantId: me.merchant.id,
      merchantName: me.merchant.name,
      merchantSlug: me.merchant.slug,
      merchantStatus: me.merchant.status,
      permissions: me.permissions,
    );
    return me;
  }

  @override
  Future<MerchantHome> home() async => MerchantHome.fromJson(const {
    'merchant': {'name': 'Tropical Mart', 'status': 'active'},
    'today': {
      'credit_count': 4,
      'eligible_laari': 235000,
      'cashback_laari': 11750,
    },
    // MR10's takings card: a believable month for a busy corner shop —
    // 42 credits, MVR 18,450 of eligible sales, MVR 369 of cashback, and
    // the server's own floored average (439.28 → 43928 laari).
    'month': {
      'credit_count': 42,
      'eligible_laari': 1845000,
      'cashback_laari': 36900,
      'average_eligible_laari': 43928,
    },
    'outstanding': {
      'total': {
        'count': 1,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'payable_laari': 2750,
      },
      'buckets': {
        '0_5': {
          'count': 1,
          'cashback_laari': 2000,
          'fee_laari': 750,
          'fee_gst_laari': 0,
          'payable_laari': 2750,
        },
        '6_10': {
          'count': 0,
          'cashback_laari': 0,
          'fee_laari': 0,
          'fee_gst_laari': 0,
          'payable_laari': 0,
        },
        '11_15': {
          'count': 0,
          'cashback_laari': 0,
          'fee_laari': 0,
          'fee_gst_laari': 0,
          'payable_laari': 0,
        },
        'overdue': {
          'count': 0,
          'cashback_laari': 0,
          'fee_laari': 0,
          'fee_gst_laari': 0,
          'payable_laari': 0,
        },
      },
      'pending_adjustments': {'count': 0, 'credit_laari': 0},
    },
    'open_settlement': null,
  });

  @override
  Future<MerchantSetupState> getSetup() async =>
      MerchantSetupState.fromJson(setup ?? _setupFixture(status: status));

  // ---- MR2: the till ------------------------------------------------------

  @override
  Future<CustomerLookup> lookupCustomer(String code) async => code == '374230'
      ? CustomerLookup(valid: true, name: 'Ahmed Nazeeh')
      : CustomerLookup(valid: false);

  @override
  Future<MerchantRate> merchantRate() async => MerchantRate.fromJson(const {
    'current': {
      'cashback_rate_percent': '2.00',
      'platform_fee_percent': '0.75',
      'all_in_percent': '2.75',
      'effective_from': '2026-08-01T00:00:00+05:00',
      'effective_to': null,
    },
    'pending': null,
  });

  @override
  Future<List<ProductCategory>> productCategories() async =>
      categoriesJson != null
      ? [for (final json in categoriesJson!) ProductCategory.fromJson(json)]
      : [
          for (final (i, spec) in const [
            ('fruits', 'Fruits', 'rate', '2.00'),
            ('veggies', 'Veggies', 'rate', '2.00'),
            ('tobacco', 'Tobacco', 'excluded', null),
          ].indexed)
            ProductCategory.fromJson({
              'id': i + 1,
              'slug': spec.$1,
              'name_en': spec.$2,
              'name_dv': null,
              'mode': spec.$3,
              'cashback_rate_percent': spec.$4,
              'active': true,
              'sort': i,
            }),
        ];

  // ---- MR5: the More estate ------------------------------------------------

  /// Profile.png's own values, server-shaped (MerchantProfileResource).
  /// `eligibility_basis` is ONE free-text string — the ref's chips render
  /// as this text.
  @override
  Future<MerchantProfile> profile() async => MerchantProfile.fromJson({
    'id': 7,
    'name': 'Tropical Mart',
    'name_dv': 'ޓްރޮޕިކަލް މާޓް',
    'slug': 'tropical-mart',
    'status': 'active',
    'category': 'grocery',
    'category_retired': false,
    'channel': 'in_store',
    'eligibility_basis':
        'Groceries, household essentials and selected daily items.',
    'contact_email': 'hello@tropicalmart.mv',
    'contact_phone': '+960 778 1234',
    'support_phone': '+960 333 4455',
    'website_url': 'https://www.tropicalmart.mv',
    'pending_change': pendingProfileJson,
  });

  /// The preferences READ (an empty PATCH server-side) — the ref's MVR
  /// 100.00 minimum and 15-day window.
  @override
  Future<MerchantPreferences> updatePreferences({
    String? settlementMethod,
    int? minEligibleLaari,
    int? validationWindowDays,
  }) async => MerchantPreferences.fromJson(const {
    'settlement_method': 'bank',
    'min_eligible_laari': 10000,
    'validation_window_days': 15,
    'validation_window_max_days': 30,
  });

  // ---- MR5: the management estate (agent B) -------------------------------

  static Map<String, dynamic> _staffRow(
    int id,
    String name,
    String email,
    Map<String, dynamic> role, {
    bool active = true,
  }) => {
    'id': id,
    'name': name,
    'email': email,
    'role': role,
    'is_active': active,
    'created_at': '2026-08-01T09:00:00+00:00',
  };

  static const _ownerRoleSummary = {
    'id': 1,
    'name': 'Owner',
    'name_dv': null,
    'is_owner': true,
  };
  static const _managerRoleSummary = {
    'id': 2,
    'name': 'Manager',
    'name_dv': null,
    'is_owner': false,
  };
  static const _cashierRoleSummary = {
    'id': 3,
    'name': 'Cashier',
    'name_dv': null,
    'is_owner': false,
  };
  static const _supportRoleSummary = {
    'id': 4,
    'name': 'Customer Support',
    'name_dv': null,
    'is_owner': false,
  };

  /// Manage Employees.png's names, server-shaped: the subtitle is the
  /// EMAIL (staff have no branch link), and there is no invite state — the
  /// ref's "Pending Invite" row renders Active, its "Accountant" stands on
  /// a real role.
  @override
  Future<List<MerchantStaff>> staff() async => [
    for (final json in [
      _staffRow(1, 'Ahmed Nazeeh', 'nazeeh@tropicalmart.mv', _ownerRoleSummary),
      _staffRow(
        2,
        'Mariyam Shifa',
        'shifa@tropicalmart.mv',
        _cashierRoleSummary,
      ),
      _staffRow(3, 'Ali Sameeh', 'sameeh@tropicalmart.mv', _managerRoleSummary),
      _staffRow(
        4,
        'Fathimath Aisha',
        'aisha@tropicalmart.mv',
        _supportRoleSummary,
      ),
      _staffRow(
        5,
        'Hassan Rameez',
        'hassan@tropicalmart.mv',
        _cashierRoleSummary,
        active: false,
      ),
    ])
      MerchantStaff.fromJson(json),
  ];

  /// Roles.png's card list, violet-restyled: Owner = the ref's Full
  /// Access treatment; the rest carry their permission counts.
  @override
  Future<List<MerchantRole>> roles() async => [
    for (final json in [
      {
        'id': 1,
        'name': 'Owner',
        'name_dv': null,
        'slug': 'owner',
        'is_owner': true,
        'is_system': true,
        'permissions': <String>[],
        'staff_count': 1,
      },
      {
        'id': 2,
        'name': 'Manager',
        'name_dv': null,
        'slug': 'manager',
        'is_owner': false,
        'is_system': true,
        'permissions': [
          'credits.create',
          'credits.custom_rate',
          'customers.lookup',
          'transactions.view',
          'transactions.amend',
          'transactions.cancel',
          'rate.view',
          'promotions.view',
          'product_categories.view',
          'settlements.view',
          'settlements.preview',
          'settlements.create',
          'settlements.receipt_add',
          'wallet.view',
          'profile.view',
          'branches.view',
          'branches.edit',
          'staff.view',
        ],
        'staff_count': 1,
      },
      {
        'id': 3,
        'name': 'Cashier',
        'name_dv': null,
        'slug': 'staff',
        'is_owner': false,
        'is_system': true,
        'permissions': [
          'credits.create',
          'customers.lookup',
          'transactions.view',
          'rate.view',
          'promotions.view',
          'product_categories.view',
          'profile.view',
          'branches.view',
        ],
        'staff_count': 2,
      },
      {
        'id': 4,
        'name': 'Customer Support',
        'name_dv': null,
        'slug': 'customer-support',
        'is_owner': false,
        'is_system': false,
        'permissions': [
          'customers.lookup',
          'transactions.view',
          'rate.view',
          'promotions.view',
        ],
        'staff_count': 1,
      },
    ])
      MerchantRole.fromJson(json),
  ];

  @override
  Future<PermissionCatalogue> permissionCatalogue() async =>
      PermissionCatalogue.fromJson(const {
        'groups': [
          {
            'slug': 'till',
            'label': 'Till',
            'permissions': [
              {
                'slug': 'credits.create',
                'label': 'Credit a customer',
                'group': 'till',
              },
              {
                'slug': 'credits.custom_rate',
                'label': 'Set a custom cashback rate on a sale',
                'group': 'till',
              },
              {
                'slug': 'customers.lookup',
                'label': 'Look up a customer',
                'group': 'till',
              },
              {
                'slug': 'transactions.view',
                'label': 'View transactions',
                'group': 'till',
              },
            ],
          },
          {
            'slug': 'money',
            'label': 'Money',
            'permissions': [
              {
                'slug': 'settlements.view',
                'label': 'View settlements',
                'group': 'money',
              },
              {
                'slug': 'wallet.view',
                'label': 'View the wallet',
                'group': 'money',
              },
            ],
          },
          {
            'slug': 'account',
            'label': 'Account',
            'permissions': [
              {
                'slug': 'staff.view',
                'label': 'View staff accounts',
                'group': 'account',
              },
              {
                'slug': 'roles.manage',
                'label': 'Create and edit roles',
                'group': 'account',
              },
            ],
          },
        ],
      });

  /// Branches.png's five locations minus the invented chrome — no photos,
  /// hours, phone or active flag; the pin split carries the ref's 4-and-1.
  @override
  Future<MerchantBranchEstate> branches() async => MerchantBranchEstate(
    pendingChanges: pendingChanges ?? const [],
    branches: [
    for (final json in [
      {
        'id': 3,
        'name': 'Tropical Mart — Main Branch',
        'address': 'Boduthakurufaanu Magu, Malé',
        'lat': 4.1755354,
        'lng': 73.5093474,
      },
      {
        'id': 4,
        'name': 'Tropical Mart — Hulhumalé',
        'address': 'Nirolhu Magu, Hulhumalé',
        'lat': 4.2105091,
        'lng': 73.5407121,
      },
      {
        'id': 5,
        'name': 'Tropical Mart — Villimalé',
        'address': 'Ameenee Magu, Villimalé',
        'lat': 4.1725071,
        'lng': 73.4882079,
      },
      {
        'id': 6,
        'name': 'Tropical Mart — Velana Airport',
        'address': 'Velana International Airport, Hulhulé',
        'lat': 4.1917701,
        'lng': 73.5290022,
      },
      {
        'id': 7,
        'name': 'Tropical Mart — Maamigili',
        'address': 'Maamigili, Raa Atoll',
        'lat': null,
        'lng': null,
      },
    ])
      MerchantBranch.fromJson(json),
    ],
  );

  /// One of each lifecycle state the list distinguishes.
  @override
  Future<List<Promotion>> promotions({String? status}) async => [
    for (final json in [
      {
        'cashback_rate_percent': '7.50',
        'platform_fee_percent': '1.25',
        'all_in_percent': '8.75',
        'id': 12,
        'merchant_id': 7,
        'branch_id': null,
        'status': 'draft',
        'is_live': false,
        'starts_at': '2026-08-20T10:00:00+05:00',
        'ends_at': '2026-08-22T22:00:00+05:00',
        'min_purchase_laari': 10000,
        'max_cashback_per_customer_laari': 50000,
        'published_at': null,
        'cancelled_at': null,
      },
      {
        'cashback_rate_percent': '5.00',
        'platform_fee_percent': '1.00',
        'all_in_percent': '6.00',
        'id': 11,
        'merchant_id': 7,
        'branch_id': 3,
        'status': 'published',
        'is_live': true,
        'starts_at': '2026-08-15T00:00:00+05:00',
        'ends_at': '2026-08-18T23:59:00+05:00',
        'min_purchase_laari': null,
        'max_cashback_per_customer_laari': null,
        'published_at': '2026-08-14T15:00:00+05:00',
        'cancelled_at': null,
      },
      {
        'cashback_rate_percent': '4.00',
        'platform_fee_percent': '1.00',
        'all_in_percent': '5.00',
        'id': 9,
        'merchant_id': 7,
        'branch_id': null,
        'status': 'ended',
        'is_live': false,
        'starts_at': '2026-07-25T00:00:00+05:00',
        'ends_at': '2026-07-28T23:59:00+05:00',
        'min_purchase_laari': 5000,
        'max_cashback_per_customer_laari': null,
        'published_at': '2026-07-24T12:00:00+05:00',
        'cancelled_at': null,
      },
    ])
      Promotion.fromJson(json),
  ];

  Map<String, dynamic> _tx(
    int id,
    String invoice,
    String state, {
    bool backdated = false,
    String? reason,
    int eligible = 100000,
    int cashback = 2000,
  }) => {
    'id': id,
    'origin': 'manual',
    'invoice_no': invoice,
    'state': state,
    'reason_code': reason,
    'backdated': backdated,
    'currency': 'MVR',
    'eligible_laari': eligible,
    'sale_laari': null,
    'cashback_rate_percent': '2.00',
    'platform_fee_percent': '0.75',
    'effective_cashback_rate_percent': '2.00',
    'effective_platform_fee_percent': '0.75',
    'cashback_laari': cashback,
    'fee_laari': 750,
    'fee_gst_laari': 60,
    'occurred_at': '2026-08-16T14:07:00+05:00',
    'received_at': '2026-08-16T14:07:01+05:00',
  };

  @override
  Future<CursorPage<MerchantTransaction>> transactions({
    String? cursor,
    String? state,
    int? perPage,
  }) async => CursorPage(
    items: [
      MerchantTransaction.fromJson(_tx(4, 'INV-1004', 'awaiting_validation')),
      MerchantTransaction.fromJson(
        _tx(3, 'INV-1003', 'confirmed', eligible: 45000, cashback: 900),
      ),
      MerchantTransaction.fromJson(
        _tx(
          2,
          'INV-1002',
          'awaiting_validation',
          backdated: true,
          reason: 'backdated_final',
        ),
      ),
      MerchantTransaction.fromJson(
        _tx(
          1,
          'INV-1001',
          'reversed',
          reason: 'below_minimum',
          eligible: 2500,
          cashback: 0,
        ),
      ),
    ],
    nextCursor: null,
    hasMore: false,
  );

  @override
  Future<void> requestSignupOtp(String phone) async {}

  @override
  Future<String> verifySignupOtp({
    required String phone,
    required String code,
  }) async => 'shot-signup-token';

  @override
  Future<void> registerMerchant({
    required String signupToken,
    required String businessName,
    String? businessNameDv,
    required String email,
    required String password,
    required String deviceName,
  }) async {}
}

void main() {
  setUpAll(_loadFonts);
  // A stable wall clock: the sale-time row renders the same golden bytes on
  // every run (the ref's own date, for the eye-check too).
  setUp(() => creditClock = () => DateTime(2026, 8, 16, 14, 7));

  Future<void> shot(
    WidgetTester tester,
    String name,
    Brightness b, {
    bool signedOut = false,
    String status = 'active',
    Map<String, dynamic>? setup,
    Map<String, dynamic>? walletJson,
    List<Map<String, dynamic>>? categoriesJson,
    Map<String, dynamic>? pendingProfileJson,
    List<MerchantChangeRequest>? pendingChanges,
    Future<void> Function(WidgetTester tester)? drive,
    // The phone frame the app shipped on; tabletShot passes the MR7 slate.
    Size size = const Size(390, 844),
    double dpr = 3.0,
  }) async {
    await tester.binding.setSurfaceSize(size);
    tester.view.devicePixelRatio = dpr;
    tester.view.physicalSize = size * dpr;
    tester.platformDispatcher.platformBrightnessTestValue = b;

    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    // Theme mode is a persisted CHOICE (default light), so a dark shot must
    // select dark explicitly rather than lean on platform brightness.
    await session.setThemeMode(b == Brightness.dark ? 'dark' : 'light');
    if (!signedOut) {
      await session.saveSession(
        token: 't',
        userName: 'Aminath Waheedha',
        userEmail: 'a@tropical.mv',
        merchantId: 7,
        merchantName: 'Tropical Mart',
        merchantSlug: 'tropical-mart',
        merchantStatus: status,
        permissions: _permissions,
      );
    }

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          sessionProvider.overrideWithValue(session),
          apiProvider.overrideWith(
            (ref) => _ShotApi(
              session: session,
              status: status,
              setup: setup,
              walletJson: walletJson,
              categoriesJson: categoriesJson,
              pendingProfileJson: pendingProfileJson,
              pendingChanges: pendingChanges,
            ),
          ),
          configProvider.overrideWith(
            (ref) async => MobileConfig.fromJson(const {
              'apps': {
                'merchant': {
                  'android': {
                    'minimum_build': 1,
                    'latest_build': 1,
                    'store_url': '',
                  },
                },
              },
              'features': {},
            }),
          ),
        ],
        child: const MerchantApp(),
      ),
    );
    // Let boot route.
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 120));
    }

    if (drive != null) {
      await drive(tester);
    }

    await expectLater(
      find.byType(MerchantApp),
      matchesGoldenFile('shots/$name.png'),
    );
  }

  /// Walk the signup flow to the DETAILS step (business name, Thaana name,
  /// email, password) — the screen the golden captures.
  Future<void> driveToSignupDetails(WidgetTester tester) async {
    await tester.scrollUntilVisible(
      find.text('Register your store'),
      120,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('Register your store'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '7781234');
    await tester.tap(find.text('Continue'));
    await tester.pump(const Duration(milliseconds: 60));
    await tester.enterText(find.byType(TextField), '123456');
    await tester.tap(find.text('Verify'));
    await tester.pump(const Duration(milliseconds: 60));
    await tester.pump(const Duration(milliseconds: 60));
  }

  testWidgets(
    'login light',
    (t) => shot(t, 'login_light', Brightness.light, signedOut: true),
  );
  testWidgets(
    'login dark',
    (t) => shot(t, 'login_dark', Brightness.dark, signedOut: true),
  );
  // ---- MR3: the money screens ---------------------------------------------
  // (These supersede the MR0 shell placeholders — the Dashboard IS the
  // shell's landing screen now.)

  Future<void> driveSettlements(WidgetTester tester) async {
    await tester.tap(find.text('Settlements'));
    await tester.pumpAndSettle();
  }

  Future<void> drivePayScreen(WidgetTester tester) async {
    await driveSettlements(tester);
    await tester.tap(find.text('Pay now'));
    await tester.pumpAndSettle();
  }

  Future<void> driveWallet(WidgetTester tester) async {
    await tester.scrollUntilVisible(
      find.text('View movements'),
      160,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('View movements'));
    await tester.pumpAndSettle();
  }

  testWidgets(
    'dashboard light',
    (t) => shot(t, 'dashboard_light', Brightness.light),
  );
  testWidgets(
    'dashboard dark',
    (t) => shot(t, 'dashboard_dark', Brightness.dark),
  );

  // ---- WL marketing renderer ------------------------------------------------
  // Not review goldens: the merchant.manfaa.app landing's phone mockup — the
  // REAL Dashboard, same fixture as above. Goldens normally run with shadows
  // disabled, which paints solid grey boxes where every card's soft shadow
  // belongs (the halo the owner flagged on the manfaa.app hero); marketing
  // shots must look like the device, so real shadows are restored here and
  // put back INSIDE the body — the framework asserts painting debug vars
  // before tearDowns run.
  Future<void> marketingShot(
    WidgetTester tester,
    String name,
    Brightness b,
  ) async {
    debugDisableShadows = false;
    await shot(tester, name, b);
    debugDisableShadows = true;
  }

  testWidgets(
    'marketing dashboard light',
    (t) => marketingShot(t, 'marketing_dashboard_light', Brightness.light),
  );
  testWidgets(
    'marketing dashboard dark',
    (t) => marketingShot(t, 'marketing_dashboard_dark', Brightness.dark),
  );
  testWidgets(
    'settlements light',
    (t) =>
        shot(t, 'settlements_light', Brightness.light, drive: driveSettlements),
  );
  testWidgets(
    'settlements dark',
    (t) =>
        shot(t, 'settlements_dark', Brightness.dark, drive: driveSettlements),
  );
  testWidgets(
    'settlement preview light',
    (t) => shot(
      t,
      'settlement_preview_light',
      Brightness.light,
      drive: drivePayScreen,
    ),
  );
  testWidgets(
    'wallet light',
    (t) => shot(
      t,
      'wallet_light',
      Brightness.light,
      walletJson: const {
        'balance_laari': 8175,
        'currency': 'MVR',
        'transactions': [
          {
            'id': 9,
            'amount_laari': -11825,
            'balance_after_laari': 8175,
            'type': 'settlement',
            'reference_type': 'settlement',
            'reference_id': 41,
            'description': 'Settlement ST-2026-00041',
            'created_at': '2026-08-15T14:12:00+05:00',
          },
          {
            'id': 8,
            'amount_laari': 20000,
            'balance_after_laari': 20000,
            'type': 'top_up',
            'reference_type': null,
            'reference_id': null,
            'description': 'Bank top-up (FT99231)',
            'created_at': '2026-08-10T10:00:00+05:00',
          },
        ],
      },
      drive: driveWallet,
    ),
  );

  // ---- MR1: signup + wizard + status --------------------------------------
  testWidgets(
    'signup details light',
    (t) => shot(
      t,
      'signup_details',
      Brightness.light,
      signedOut: true,
      drive: driveToSignupDetails,
    ),
  );
  testWidgets(
    'wizard profile light',
    (t) => shot(
      t,
      'wizard_profile_light',
      Brightness.light,
      status: 'draft',
      setup: _setupFixture(status: 'draft'),
    ),
  );
  testWidgets(
    'wizard profile dark',
    (t) => shot(
      t,
      'wizard_profile_dark',
      Brightness.dark,
      status: 'draft',
      setup: _setupFixture(status: 'draft'),
    ),
  );
  testWidgets(
    'wizard review light',
    (t) => shot(
      t,
      'wizard_review',
      Brightness.light,
      status: 'draft',
      setup: _setupFixture(status: 'draft', complete: true),
    ),
  );
  testWidgets(
    'status pending light',
    (t) => shot(
      t,
      'status_pending',
      Brightness.light,
      status: 'pending_review',
      setup: _setupFixture(
        status: 'pending_review',
        complete: true,
        submittedAt: '2026-08-17T09:41:00Z',
      ),
    ),
  );

  // ---- MR2: the till ------------------------------------------------------

  /// Walk the Credit tab into the ref's state: verified customer, invoice,
  /// amounts filled — the Credit Customer.png frame.
  Future<void> driveCredit(WidgetTester tester) async {
    await tester.tap(find.text('Credit'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '374230');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(1), 'INV-1001');
    await tester.enterText(find.byType(TextField).at(2), '1,000.00');
    await tester.enterText(find.byType(TextField).at(3), '1,000.00');
    await tester.pumpAndSettle();
    // Entering text scrolls to the focused field — put the frame back at
    // the top of the screen for the shot.
    await tester.dragFrom(const Offset(195, 300), const Offset(0, 900));
    await tester.pumpAndSettle();
  }

  /// Fill one split ROW (MR8: inline rows, no dialog) — search the
  /// category, tap the option, type the amount. The first row already
  /// exists; later ones are appended with "Add row" by the caller.
  Future<void> fillRow(
    WidgetTester tester,
    String category,
    String amount,
  ) async {
    Finder searchFields() => find.byWidgetPredicate(
      (w) => w is TextField && w.decoration?.hintText == 'Search categories',
    );
    Finder amountFields() => find.byWidgetPredicate(
      (w) => w is TextField && w.decoration?.prefixText == 'MVR ',
    );

    await tester.enterText(searchFields().last, category);
    await tester.pumpAndSettle();
    await tester.tap(find.text(category).last);
    await tester.pumpAndSettle();
    await tester.enterText(amountFields().last, amount);
    await tester.pumpAndSettle();
  }

  /// The With-Category ref: split on, Fruits 300 / Veggies 250 / Other 450,
  /// scrolled so the breakdown card leads the frame.
  Future<void> driveCreditSplit(WidgetTester tester) async {
    await driveCredit(tester);
    await tester.scrollUntilVisible(
      find.text('Split by category'),
      160,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Switch).last);
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Add row'),
      120,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await fillRow(tester, 'Fruits', '300');
    await tester.tap(find.text('Add row'));
    await tester.pumpAndSettle();
    await fillRow(tester, 'Veggies', '250');
    await tester.tap(find.text('Add row'));
    await tester.pumpAndSettle();
    await fillRow(tester, 'Everything else', '450');
    await tester.scrollUntilVisible(
      find.text('Cost preview'),
      120,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
  }

  Future<void> driveTransactions(WidgetTester tester) async {
    // The nav destination by its ICON, not by the word: MR10's month card
    // carries a "Transactions" figure too, and text order differs between
    // the phone bar and the tablet rail.
    await tester.tap(find.byIcon(Icons.receipt_long_outlined).last);
    await tester.pumpAndSettle();
  }

  testWidgets(
    'credit light',
    (t) => shot(t, 'credit_light', Brightness.light, drive: driveCredit),
  );
  testWidgets(
    'credit dark',
    (t) => shot(t, 'credit_dark', Brightness.dark, drive: driveCredit),
  );
  testWidgets(
    'credit split light',
    (t) => shot(
      t,
      'credit_split_light',
      Brightness.light,
      drive: driveCreditSplit,
    ),
  );
  testWidgets(
    'transactions light',
    (t) => shot(
      t,
      'transactions_light',
      Brightness.light,
      drive: driveTransactions,
    ),
  );

  // ---- MR5: the More estate (store-identity half) --------------------------

  /// The ref's own four category rules (Cashback Settings.png).
  const cashbackShotCategories = [
    {
      'id': 1,
      'slug': 'groceries',
      'name_en': 'Groceries',
      'name_dv': null,
      'mode': 'rate',
      'cashback_rate_percent': '2.00',
      'active': true,
      'sort': 0,
    },
    {
      'id': 2,
      'slug': 'household-essentials',
      'name_en': 'Household Essentials',
      'name_dv': null,
      'mode': 'rate',
      'cashback_rate_percent': '1.50',
      'active': true,
      'sort': 1,
    },
    {
      'id': 3,
      'slug': 'fresh-produce',
      'name_en': 'Fresh Produce',
      'name_dv': null,
      'mode': 'rate',
      'cashback_rate_percent': '3.00',
      'active': true,
      'sort': 2,
    },
    {
      'id': 4,
      'slug': 'electronics',
      'name_en': 'Electronics',
      'name_dv': null,
      'mode': 'rate',
      'cashback_rate_percent': '0.50',
      'active': true,
      'sort': 3,
    },
  ];

  Future<void> driveMore(WidgetTester tester) async {
    await tester.tap(find.text('More'));
    await tester.pumpAndSettle();
  }

  Future<void> driveProfile(WidgetTester tester) async {
    await driveMore(tester);
    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
  }

  Future<void> driveCashback(WidgetTester tester) async {
    await driveMore(tester);
    await tester.tap(find.text('Cashback Settings'));
    await tester.pumpAndSettle();
  }

  Future<void> driveEmployees(WidgetTester tester) async {
    await driveMore(tester);
    await tester.tap(find.text('Manage Employees'));
    await tester.pumpAndSettle();
  }

  Future<void> driveRoles(WidgetTester tester) async {
    await driveMore(tester);
    await tester.tap(find.text('Roles'));
    await tester.pumpAndSettle();
  }

  Future<void> driveBranches(WidgetTester tester) async {
    await driveMore(tester);
    await tester.tap(find.text('Manage Branches'));
    await tester.pumpAndSettle();
  }

  Future<void> drivePromotions(WidgetTester tester) async {
    await driveMore(tester);
    await tester.tap(find.text('Promotions'));
    await tester.pumpAndSettle();
  }

  testWidgets(
    'more light',
    (t) => shot(t, 'more_light', Brightness.light, drive: driveMore),
  );
  testWidgets(
    'more dark',
    (t) => shot(t, 'more_dark', Brightness.dark, drive: driveMore),
  );
  testWidgets(
    'profile light',
    (t) => shot(t, 'profile_light', Brightness.light, drive: driveProfile),
  );
  testWidgets(
    'cashback settings light',
    (t) => shot(
      t,
      'cashback_settings_light',
      Brightness.light,
      categoriesJson: cashbackShotCategories,
      drive: driveCashback,
    ),
  );
  testWidgets(
    'cashback settings earning light',
    (t) => shot(
      t,
      'cashback_settings_earning_light',
      Brightness.light,
      categoriesJson: cashbackShotCategories,
      drive: (tester) async {
        await driveCashback(tester);
        await tester.scrollUntilVisible(
          find.text('Save changes'),
          160,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.pumpAndSettle();
      },
    ),
  );

  // ---- MR5: the More estate (management half, agent B) ----------------------
  testWidgets(
    'employees light',
    (t) => shot(t, 'employees_light', Brightness.light, drive: driveEmployees),
  );
  testWidgets(
    'employees dark',
    (t) => shot(t, 'employees_dark', Brightness.dark, drive: driveEmployees),
  );
  testWidgets(
    'roles light',
    (t) => shot(t, 'roles_light', Brightness.light, drive: driveRoles),
  );
  testWidgets(
    'branches light',
    (t) => shot(t, 'branches_light', Brightness.light, drive: driveBranches),
  );

  // ---- MR9: waiting for Manfaa's review ------------------------------------
  // The gated states are their OWN goldens: the shipped shots above keep an
  // empty queue and must render byte-identically.
  testWidgets(
    'profile pending light',
    (t) => shot(
      t,
      'profile_pending_light',
      Brightness.light,
      pendingProfileJson: _shotPendingProfile,
      drive: driveProfile,
    ),
  );
  testWidgets(
    'branches pending light',
    (t) => shot(
      t,
      'branches_pending_light',
      Brightness.light,
      pendingChanges: [
        MerchantChangeRequest.fromJson(_shotPendingBranchCreate),
        MerchantChangeRequest.fromJson(_shotPendingBranchUpdate),
      ],
      drive: driveBranches,
    ),
  );
  testWidgets(
    'promotions light',
    (t) =>
        shot(t, 'promotions_light', Brightness.light, drive: drivePromotions),
  );

  // ---- MR7: the tablet surface ---------------------------------------------
  // A 1280×800 @2x landscape slate (the common 10" Android footprint):
  // ≥840dp window ⇒ the left rail shell; ≥840dp content ⇒ the Dashboard and
  // Credit two-column layouts. No refs exist for tablet — these goldens ARE
  // the eye-check surface.

  Future<void> tabletShot(
    WidgetTester tester,
    String name,
    Brightness b, {
    Future<void> Function(WidgetTester tester)? drive,
    List<MerchantChangeRequest>? pendingChanges,
  }) => shot(
    tester,
    name,
    b,
    size: const Size(1280, 800),
    dpr: 2.0,
    pendingChanges: pendingChanges,
    drive: drive,
  );

  testWidgets(
    'tablet dashboard light',
    (t) => tabletShot(t, 'tablet_dashboard_light', Brightness.light),
  );
  testWidgets(
    'tablet credit light',
    (t) => tabletShot(
      t,
      'tablet_credit_light',
      Brightness.light,
      drive: driveCredit,
    ),
  );
  testWidgets(
    'tablet more light',
    (t) =>
        tabletShot(t, 'tablet_more_light', Brightness.light, drive: driveMore),
  );

  // ---- MR7: the two-pane estates (agent B) ----------------------------------
  // Selection taps run AT the tablet size, so they exercise the expanded
  // split (right pane, not sheet). Branches stays on its hint pane — the
  // editor's map tiles would need the network.

  testWidgets(
    'tablet transactions light',
    (t) => tabletShot(
      t,
      'tablet_transactions_light',
      Brightness.light,
      drive: (tester) async {
        await driveTransactions(tester);
        await tester.tap(find.text('INV-1004'));
        await tester.pumpAndSettle();
      },
    ),
  );
  testWidgets(
    'tablet settlements light',
    (t) => tabletShot(
      t,
      'tablet_settlements_light',
      Brightness.light,
      drive: (tester) async {
        await driveSettlements(tester);
        // The recent list sits below the fold on the 800dp slate — reach
        // it, select, then jump back so the shot leads with the hero and
        // the pane's status story side by side.
        await tester.scrollUntilVisible(
          find.text('ST-2026-00041'),
          160,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('ST-2026-00041').first);
        await tester.pumpAndSettle();
        tester
            .state<ScrollableState>(find.byType(Scrollable).first)
            .position
            .jumpTo(0);
        await tester.pumpAndSettle();
      },
    ),
  );
  testWidgets(
    'tablet employees light',
    (t) => tabletShot(
      t,
      'tablet_employees_light',
      Brightness.light,
      drive: (tester) async {
        await driveEmployees(tester);
        await tester.tap(find.text('Mariyam Shifa'));
        await tester.pumpAndSettle();
      },
    ),
  );
  testWidgets(
    'tablet roles light',
    (t) => tabletShot(
      t,
      'tablet_roles_light',
      Brightness.light,
      drive: (tester) async {
        await driveRoles(tester);
        await tester.tap(find.text('Add role'));
        await tester.pumpAndSettle();
      },
    ),
  );
  testWidgets(
    'tablet branches light',
    (t) => tabletShot(
      t,
      'tablet_branches_light',
      Brightness.light,
      drive: driveBranches,
    ),
  );
  // MR9 on the slate: the pending cards live in the LIST column, so the
  // editor pane beside them is untouched — this golden is what proves it.
  testWidgets(
    'tablet branches pending light',
    (t) => tabletShot(
      t,
      'tablet_branches_pending_light',
      Brightness.light,
      pendingChanges: [
        MerchantChangeRequest.fromJson(_shotPendingBranchCreate),
        MerchantChangeRequest.fromJson(_shotPendingBranchUpdate),
      ],
      drive: driveBranches,
    ),
  );
  testWidgets(
    'tablet promotions light',
    // The hint pane, NOT the open builder: the builder's Starts row renders
    // DateTime.now(), which would make the golden's bytes drift every run.
    // The builder pane itself was eye-checked when this shot was built.
    (t) => tabletShot(
      t,
      'tablet_promotions_light',
      Brightness.light,
      drive: drivePromotions,
    ),
  );
}
