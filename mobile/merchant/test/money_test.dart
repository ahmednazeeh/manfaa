import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/settlements/settlement_widgets.dart';

/// MR3 tests: the money screens. The whole app pumped over a fake
/// MerchantApi that records the EXACT selection bodies the money writes
/// carry — because "the preview's selection goes to the POST unchanged" is
/// the round's contract, not a styling detail.
void main() {
  const moneyPermissions = [
    'settlements.view',
    'settlements.create',
    'settlements.preview',
    'settlements.receipt_add',
    'wallet.view',
    'wallet.settle',
    'credits.create',
    'transactions.view',
  ];

  MobileConfig config() => MobileConfig.fromJson({
    'apps': {
      'merchant': {
        'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
      },
    },
    'features': const {},
  });

  Future<MemorySecretStore> seededStore(List<String> permissions) async {
    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.saveSession(
      token: 't-1',
      userName: 'Aminath Waheedha',
      userEmail: 'till@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: permissions,
    );
    return store;
  }

  Future<_MoneyApi> pumpMoney(
    WidgetTester tester, {
    List<String> permissions = moneyPermissions,
    int walletBalanceLaari = 0,
    bool includeDiscount = true,
  }) async {
    await tester.binding.setSurfaceSize(const Size(600, 3200));
    final store = await seededStore(permissions);
    late _MoneyApi api;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          apiProvider.overrideWith(
            (ref) => api = _MoneyApi(
              session: ref.watch(sessionProvider),
              permissions: permissions,
              walletBalanceLaari: walletBalanceLaari,
              includeDiscount: includeDiscount,
            ),
          ),
          configProvider.overrideWith((ref) async => config()),
        ],
        child: const MerchantApp(),
      ),
    );
    await tester.pumpAndSettle();
    return api;
  }

  Future<void> goSettlements(WidgetTester tester) async {
    await tester.tap(find.text('Settlements').last);
    await tester.pumpAndSettle();
  }

  tearDown(() => slipPicker = defaultSlipPicker);

  testWidgets('the dashboard renders the server laari untouched', (
    tester,
  ) async {
    await pumpMoney(tester);

    // Hero + 0–5 bucket + the breakdown chip all carry the SAME server
    // total — 2750 laari rendered, never recomputed.
    expect(find.text('MVR 27.50'), findsNWidgets(3));
    expect(find.text('1 transaction'), findsOneWidget);
    expect(find.text('MVR 20.00'), findsOneWidget); // cashback row
    expect(find.text('MVR 7.50'), findsOneWidget); // fee row
    // Empty buckets render the server's zeros, not blanks.
    expect(find.text('MVR 0.00'), findsWidgets);

    // The deadline banner: clock_start 10 Aug + 15 days, and the save
    // amount is the server's discount integer.
    expect(
      find.textContaining('stops earning the 5% prompt-payment discount '
          'on 25 Aug 2026'),
      findsOneWidget,
    );
    expect(find.textContaining('save MVR 0.38'), findsOneWidget);
  });

  testWidgets('permission-hidden blocks: no settlements.view, no money', (
    tester,
  ) async {
    await pumpMoney(
      tester,
      permissions: const ['credits.create', 'transactions.view'],
    );

    // Without settlements.view there is no Dashboard or Settlements tab at
    // all — the app lands on Credit and nothing money-shaped is drawn.
    expect(find.text('Outstanding to settle'), findsNothing);
    expect(find.text('Payable breakdown'), findsNothing);
    expect(find.text('Dashboard'), findsNothing);
    expect(find.text('Settlements'), findsNothing);
  });

  testWidgets('the wallet card exists only behind wallet.view', (
    tester,
  ) async {
    await pumpMoney(
      tester,
      permissions: const [
        'settlements.view',
        'settlements.preview',
        'credits.create',
      ],
    );

    // The money cards render (settlements.view)…
    expect(find.text('Outstanding to settle'), findsOneWidget);
    // …but the wallet block does not exist for this account.
    expect(find.text('Wallet'), findsNothing);
    expect(find.text('View movements'), findsNothing);
  });

  testWidgets('an insufficient wallet cannot be chosen — bank pays', (
    tester,
  ) async {
    final api = await pumpMoney(tester, walletBalanceLaari: 0);
    await goSettlements(tester);

    // The wallet option shows its balance and the Insufficient state.
    expect(find.text('Insufficient'), findsOneWidget);
    expect(find.text('MVR 0.00'), findsWidgets);

    // Tapping it does not select it: the pay CTA still takes the bank path.
    await tester.tap(find.text('Wallet balance'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pay MVR 27.12'));
    await tester.pumpAndSettle();

    expect(find.text('Transfer exactly this amount'), findsWidgets);
    expect(api.walletSettles, isEmpty);
    expect(api.creates, isEmpty);
  });

  testWidgets('wallet settle sends the settle_all MODE through unchanged', (
    tester,
  ) async {
    final api = await pumpMoney(tester, walletBalanceLaari: 500000);
    await goSettlements(tester);

    expect(find.text('Insufficient'), findsNothing);
    await tester.tap(find.text('Wallet balance'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pay MVR 27.12'));
    await tester.pumpAndSettle();

    // The confirm dialog names the exact amount to draw.
    expect(find.textContaining('MVR 27.12 will be drawn'), findsOneWidget);
    await tester.tap(find.text('Settle from wallet'));
    await tester.pumpAndSettle();

    expect(api.walletSettles, hasLength(1));
    expect(api.walletSettles.single.settleAll, isTrue);
    // The mode, never an id list — a sale landing between preview and
    // submit joins the batch instead of being left behind.
    expect(api.walletSettles.single.ids, isNull);
  });

  testWidgets('a preset narrows to the server ids and POSTs exactly those', (
    tester,
  ) async {
    final api = await pumpMoney(tester, walletBalanceLaari: 500000);
    await goSettlements(tester);

    await tester.tap(find.text('Older than 5 days'));
    await tester.pumpAndSettle();

    // The re-price asked for the BUCKET's own membership.
    expect(api.previews.last.settleAll, isFalse);
    expect(api.previews.last.ids, [61]);

    await tester.tap(find.text('Wallet balance'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pay MVR 27.12'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Settle from wallet'));
    await tester.pumpAndSettle();

    expect(api.walletSettles.single.settleAll, isFalse);
    expect(api.walletSettles.single.ids, [61]);
  });

  testWidgets('a slip over 5 MB is refused client-side, before any upload', (
    tester,
  ) async {
    final api = await pumpMoney(tester);
    await goSettlements(tester);
    await tester.tap(find.text('Pay now'));
    await tester.pumpAndSettle();

    // The pre-flight reads the SIZE, so the fake never allocates 6 MB.
    slipPicker = (camera) async => PickedSlip(
      name: 'slip.jpg',
      sizeBytes: 6 * 1024 * 1024,
      bytes: Uint8List.fromList(const [1, 2, 3]),
    );
    await tester.tap(find.text('Choose file'));
    await tester.pumpAndSettle();

    expect(find.textContaining('over 5 MB'), findsOneWidget);

    // Nothing was accepted: the refusal stays on screen, submitting goes
    // nowhere, and no POST ever left the phone.
    await tester.tap(find.text('Submit slip'));
    await tester.pumpAndSettle();
    expect(find.textContaining('over 5 MB'), findsOneWidget);
    expect(find.text('Manfaa is verifying your transfer'), findsNothing);
    expect(api.creates, isEmpty);
  });

  testWidgets('the bank path POSTs the slip with settle_all unchanged', (
    tester,
  ) async {
    final api = await pumpMoney(tester);
    await goSettlements(tester);
    await tester.tap(find.text('Pay now'));
    await tester.pumpAndSettle();

    slipPicker = (camera) async => PickedSlip(
      name: 'slip.jpg',
      sizeBytes: _tinyPng.length,
      bytes: Uint8List.fromList(_tinyPng),
    );
    await tester.tap(find.text('Choose file'));
    await tester.pumpAndSettle();
    expect(find.textContaining('over 5 MB'), findsNothing);

    await tester.tap(find.text('Submit slip'));
    await tester.pumpAndSettle();

    expect(api.creates, hasLength(1));
    final create = api.creates.single;
    expect(create.settleAll, isTrue);
    expect(create.ids, isNull);
    // The prefilled amount is the preview's amount_due integer, untouched.
    expect(create.amountLaari, 2712);
    expect(create.filename, 'slip.jpg');
    // The single configured account rode along for reconciliation.
    expect(create.platformBankAccountId, 1);

    // The success story is VERIFYING — nothing claims "settled" until the
    // slip matches.
    expect(find.text('Manfaa is verifying your transfer'), findsOneWidget);
  });

  testWidgets('no discount block from the server means no banner, nowhere', (
    tester,
  ) async {
    await pumpMoney(tester, includeDiscount: false);

    expect(find.textContaining('prompt-payment discount'), findsNothing);

    await goSettlements(tester);
    expect(find.textContaining('prompt-payment discount'), findsNothing);
    // The board still prices and pays without one — at the undiscounted due.
    expect(find.text('MVR 27.50'), findsWidgets);
    expect(find.text('Pay MVR 27.50'), findsOneWidget);
  });
}

/// The selection a money call carried, exactly as the API method got it.
typedef _Selection = ({bool settleAll, List<int>? ids});

class _MoneyApi extends MerchantApi {
  _MoneyApi({
    required super.session,
    required this.permissions,
    required this.walletBalanceLaari,
    required this.includeDiscount,
  });

  final List<String> permissions;
  final int walletBalanceLaari;
  final bool includeDiscount;

  final previews = <_Selection>[];
  final walletSettles = <_Selection>[];
  final creates =
      <({
        bool settleAll,
        List<int>? ids,
        int amountLaari,
        String filename,
        int? platformBankAccountId,
      })>[];

  @override
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson({
      'user': {'id': 1, 'name': 'Aminath Waheedha', 'email': 'a@tropical.mv'},
      'merchant': {
        'id': 7,
        'name': 'Tropical Mart',
        'slug': 'tropical-mart',
        'status': 'active',
      },
      'permissions': permissions,
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

  /// The server truth this fake mirrors: `outstanding` is WITHHELD (null)
  /// without settlements.view — the UI draws nothing rather than deciding.
  @override
  Future<MerchantHome> home() async => MerchantHome.fromJson({
    'merchant': {'name': 'Tropical Mart', 'status': 'active'},
    'today': {'credit_count': 4, 'eligible_laari': 235000, 'cashback_laari': 11750},
    'outstanding': permissions.contains('settlements.view')
        ? {
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
          }
        : null,
    'open_settlement': null,
  });

  @override
  Future<MerchantWalletState> wallet() async => MerchantWalletState.fromJson({
    'balance_laari': walletBalanceLaari,
    'currency': 'MVR',
    'transactions': const <Object>[],
  });

  Map<String, dynamic> _preview(List<int> ids) => {
    'as_of': '2026-08-16T09:07:00+00:00',
    'transaction_ids': ids,
    'transaction_count': ids.length,
    'sale_total_laari': 100000,
    'cashback_total_laari': 2000,
    'fee_total_laari': 750,
    'fee_gst_total_laari': 0,
    'line_total_laari': 2750,
    'credit_applied_laari': 0,
    'discount_laari': includeDiscount ? 38 : 0,
    'amount_due_before_discount_laari': 2750,
    'amount_due_laari': includeDiscount ? 2712 : 2750,
    'due_at': '2026-08-25T00:00:00+00:00',
    if (includeDiscount)
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

  @override
  Future<SettlementPreviewData> settlementPreview({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async {
    previews.add((settleAll: settleAll, ids: transactionIds));
    return SettlementPreviewData.fromJson(
      _preview(settleAll ? [61] : (transactionIds ?? const [])),
    );
  }

  Map<String, dynamic> _settlement(int id, {String state = 'settled'}) => {
    'id': id,
    'reference': 'ST-2026-000$id',
    'state': state,
    'funding_method': 'wallet',
    'currency': 'MVR',
    'sale_total_laari': 100000,
    'cashback_total_laari': 2000,
    'fee_total_laari': 750,
    'fee_gst_total_laari': 0,
    'discount_laari': 38,
    'discount_rate_percent': '5.00',
    'discount_reason': 'eligible',
    'amount_due_laari': 2712,
    'amount_received_laari': 2712,
    'due_at': null,
    'created_at': '2026-08-16T09:45:00+00:00',
    'payment_instructions': {
      'reference': 'ST-2026-000$id',
      'amount_due_laari': 2712,
      'bank_account': null,
      'bank_accounts': <Object>[],
      'needs_configuration': true,
    },
    'merchant_status': {
      'code': state == 'settled' ? 'settled' : 'verifying',
      'message': 'Settled — the rewards on this batch are confirmed.',
      'rejection': null,
    },
  };

  @override
  Future<SettlementPage> settlements({int page = 1}) async =>
      SettlementPage.fromJson({
        'data': const <Object>[],
        'meta': const {'current_page': 1, 'last_page': 1, 'total': 0},
      });

  @override
  Future<MerchantSettlement> settlement(int id) async =>
      MerchantSettlement.fromJson(_settlement(id));

  @override
  Future<MerchantSettlement> walletSettle({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async {
    walletSettles.add((settleAll: settleAll, ids: transactionIds));
    return MerchantSettlement.fromJson(_settlement(44));
  }

  @override
  Future<MerchantSettlement> createSettlement({
    bool settleAll = false,
    List<int>? transactionIds,
    required int amountLaari,
    String? bankRef,
    required Uint8List slipBytes,
    required String slipFilename,
    int? platformBankAccountId,
  }) async {
    creates.add((
      settleAll: settleAll,
      ids: transactionIds,
      amountLaari: amountLaari,
      filename: slipFilename,
      platformBankAccountId: platformBankAccountId,
    ));
    return MerchantSettlement.fromJson(
      _settlement(45, state: 'payment_review'),
    );
  }

  // The Credit tab loads these when it is the landing tab.
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
  Future<List<ProductCategory>> productCategories() async => const [];

  @override
  Future<CustomerLookup> lookupCustomer(String code) async =>
      CustomerLookup(valid: false);

  @override
  Future<CursorPage<MerchantTransaction>> transactions({
    String? cursor,
    String? state,
    int? perPage,
  }) async => CursorPage(items: const [], nextCursor: null, hasMore: false);
}

/// A 1×1 transparent PNG — a slip the image decoder accepts.
const _tinyPng = [
  0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, //
  0x00, 0x00, 0x00, 0x0D, 0x49, 0x48, 0x44, 0x52, //
  0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01, //
  0x08, 0x06, 0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4, //
  0x89, 0x00, 0x00, 0x00, 0x0D, 0x49, 0x44, 0x41, //
  0x54, 0x78, 0x9C, 0x62, 0x00, 0x01, 0x00, 0x00, //
  0x05, 0x00, 0x01, 0x0D, 0x0A, 0x2D, 0xB4, 0x00, //
  0x00, 0x00, 0x00, 0x49, 0x45, 0x4E, 0x44, 0xAE, //
  0x42, 0x60, 0x82,
];
