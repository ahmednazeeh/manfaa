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
    bool secondRow = false,
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
              secondRow: secondRow,
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

    // MR10: the liability is told ONCE, not five times. MR11 took the
    // ageing strip out of the card too, so the amount is on screen exactly
    // once and the accounting still sits behind "View breakdown".
    expect(find.text('MVR 27.50'), findsOneWidget);
    expect(find.text('1 transaction'), findsOneWidget);
    // The ageing columns are gone from the dashboard entirely — that story
    // lives in Settlements now.
    expect(find.text('0–5 days'), findsNothing);
    expect(find.text('Aging'), findsNothing);
    // Collapsed by default: the cashback/fee rows are not on screen yet.
    expect(find.text('MVR 20.00'), findsNothing);

    await tester.tap(find.text('View breakdown'));
    await tester.pumpAndSettle();
    expect(find.text('MVR 20.00'), findsOneWidget); // cashback row
    expect(find.text('MVR 7.50'), findsOneWidget); // fee row

    // The saving reads as an ACTION with its deadline, and names the fee
    // it applies to — clock_start 10 Aug + 15 days, server's own integer.
    expect(find.textContaining('Save MVR 0.38 by settling before'), findsOneWidget);
    expect(find.textContaining('25 Aug 2026'), findsOneWidget);
    expect(
      find.textContaining('prompt-payment discount on platform fees'),
      findsOneWidget,
    );

    // The takings half the owner asked for.
    expect(find.text('This month'), findsOneWidget);
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

  testWidgets('an insufficient wallet is not offered at all — bank pays', (
    tester,
  ) async {
    final api = await pumpMoney(tester, walletBalanceLaari: 0);
    await goSettlements(tester);

    // MR11 (owner report): a balance that cannot cover the amount is not a
    // payment option — the row is absent, not disabled with a chip.
    expect(find.text('Wallet balance'), findsNothing);
    expect(find.text('Insufficient'), findsNothing);
    // Bank transfer is still there, and it is what the CTA takes.
    expect(find.text('Bank transfer'), findsOneWidget);

    await tester.tap(find.text('Pay MVR 27.12'));
    await tester.pumpAndSettle();

    expect(find.text('Transfer exactly this amount'), findsWidgets);
    expect(api.walletSettles, isEmpty);
    expect(api.creates, isEmpty);
  });

  testWidgets('a wallet that covers the amount IS offered', (tester) async {
    await pumpMoney(tester, walletBalanceLaari: 500000);
    await goSettlements(tester);

    expect(find.text('Wallet balance'), findsOneWidget);
    expect(find.text('Insufficient'), findsNothing);
  });

  testWidgets(
    'MR11: Edit on Included transactions opens the picker, and the pick '
    're-prices on the SERVER as exactly those ids',
    (tester) async {
      final api = await pumpMoney(tester, secondRow: true);
      await goSettlements(tester);

      // Both payable sales are in the batch to start with.
      expect(find.text('INV-2107'), findsOneWidget);
      expect(find.text('INV-2108'), findsOneWidget);

      await tester.tap(find.byKey(const Key('included-edit')));
      await tester.pumpAndSettle();

      // The picker is open, and it LEADS with the re-price warning: the
      // owner's "which would remove discount".
      expect(find.text('Choose transactions'), findsOneWidget);
      expect(
        find.text('Changing the selection re-prices the batch'),
        findsOneWidget,
      );
      expect(
        find.textContaining('can lose the prompt-payment discount'),
        findsOneWidget,
      );
      expect(find.text('2 transactions selected'), findsOneWidget);

      // Drop the newer sale, keep the older one.
      await tester.tap(find.text('INV-2108'));
      await tester.pumpAndSettle();
      expect(find.text('1 transaction selected'), findsOneWidget);

      await tester.tap(find.byKey(const Key('picker-apply')));
      await tester.pumpAndSettle();

      // Back on the tab, and the SERVER priced the new selection — the app
      // sent the ids it was given and computed no money of its own.
      expect(find.text('Choose transactions'), findsNothing);
      expect(api.previews.last.settleAll, isFalse);
      expect(api.previews.last.ids, [61]);
      expect(find.text('INV-2108'), findsNothing);

      // …and the pay path carries that same selection, never settle_all.
      await tester.tap(find.text('Pay MVR 27.12'));
      await tester.pumpAndSettle();
      expect(api.previews.last.settleAll, isFalse);
      expect(api.previews.last.ids, [61]);
    },
  );

  testWidgets(
    'MR11: the picker opens on the batch actually being settled — a preset '
    'narrowing is not silently widened back to everything',
    (tester) async {
      final api = await pumpMoney(tester, secondRow: true);
      await goSettlements(tester);

      // Narrow the board with a preset chip: the priced batch is INV-2107.
      await tester.tap(find.text('Older than 5 days'));
      await tester.pumpAndSettle();
      expect(api.previews.last.ids, [61]);
      expect(find.text('INV-2108'), findsNothing);

      await tester.tap(find.byKey(const Key('included-edit')));
      await tester.pumpAndSettle();

      // Exactly the preset's membership is ticked — not the whole board.
      expect(find.text('1 transaction selected'), findsOneWidget);

      // And an untouched Apply keeps that batch: the amount the tab was
      // showing cannot grow behind the merchant's back.
      await tester.tap(find.byKey(const Key('picker-apply')));
      await tester.pumpAndSettle();
      expect(api.previews.last.settleAll, isFalse);
      expect(api.previews.last.ids, [61]);
      expect(find.text('INV-2108'), findsNothing);
    },
  );

  testWidgets(
    'MR11: ticking every row goes back as the race-proof settle_all MODE',
    (tester) async {
      final api = await pumpMoney(tester, secondRow: true);
      await goSettlements(tester);

      await tester.tap(find.byKey(const Key('included-edit')));
      await tester.pumpAndSettle();
      // Untick one, then tick it back: the whole board is the selection
      // again, so the batch must travel as the MODE, not a frozen list.
      await tester.tap(find.text('INV-2108'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('INV-2108'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('picker-apply')));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Pay MVR 27.12'));
      await tester.pumpAndSettle();
      expect(api.previews.last.settleAll, isTrue);
      expect(api.previews.last.ids, isNull);
    },
  );

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
    this.secondRow = false,
  });

  final List<String> permissions;
  final int walletBalanceLaari;
  final bool includeDiscount;

  /// A second payable sale on the board — what MR11's picker narrows from.
  final bool secondRow;

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
    'month': {
      'credit_count': 42,
      'eligible_laari': 1845000,
      'cashback_laari': 36900,
      'average_eligible_laari': 43928,
    },
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
        // Membership is the SERVER's: a subset preview flags which of the
        // payable rows this priced answer covers.
        'selected': ids.contains(61),
      },
      // The picker needs something to narrow FROM, so a second payable row
      // exists only for the tests that ask for it.
      if (secondRow)
        {
          'id': 62,
          'invoice_no': 'INV-2108',
          'occurred_at': '2026-08-16T10:00:00+05:00',
          'clock_start_at': '2026-08-16T00:00:00+00:00',
          'due_at': '2026-08-31T00:00:00+00:00',
          'age_days': 1,
          'overdue': false,
          'cashback_laari': 1000,
          'fee_laari': 375,
          'fee_gst_laari': 0,
          'due_laari': 1375,
          'selected': ids.contains(62),
        },
    ],
    'buckets': {
      'all': {
        'count': secondRow ? 2 : 1,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'due_laari': 2750,
        'transaction_ids': secondRow ? [61, 62] : [61],
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
      _preview(
        settleAll
            ? (secondRow ? [61, 62] : [61])
            : (transactionIds ?? const []),
      ),
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
