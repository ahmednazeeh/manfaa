import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/settlements/settlement_widgets.dart';

/// The pre-fundable wallet (owner, 2026-08-24): the wallet screen's claims
/// list, Top up CTA and auto-settle switch, and the receipt-first top-up
/// claim. The whole app pumped over a fake MerchantApi that records the
/// EXACT bodies the two wallet writes carry — the typed amount as integer
/// laari, the chosen platform account, the slip, the bank reference; and
/// the switch through PATCH /merchant/preferences, the one write path.
void main() {
  const walletPermissions = [
    'settlements.view',
    'settlements.create',
    'settlements.preview',
    'wallet.view',
    'wallet.settle',
    'wallet.top_up',
    'preferences.update',
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

  Future<_WalletApi> pumpWallet(
    WidgetTester tester, {
    List<String> permissions = walletPermissions,
    bool previewRefuses = false,
  }) async {
    await tester.binding.setSurfaceSize(const Size(600, 3200));
    final store = await seededStore(permissions);
    late _WalletApi api;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          apiProvider.overrideWith(
            (ref) => api = _WalletApi(
              session: ref.watch(sessionProvider),
              permissions: permissions,
              previewRefuses: previewRefuses,
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

  Future<void> goWallet(WidgetTester tester) async {
    await tester.tap(find.text('View movements'));
    await tester.pumpAndSettle();
  }

  Future<void> goTopUp(WidgetTester tester) async {
    await goWallet(tester);
    await tester.tap(find.text('Top up'));
    await tester.pumpAndSettle();
    expect(find.text('Top up your wallet'), findsOneWidget);
  }

  Future<void> attachSlip(WidgetTester tester) async {
    slipPicker = (camera) async => PickedSlip(
      name: 'slip.jpg',
      sizeBytes: _tinyPng.length,
      bytes: Uint8List.fromList(_tinyPng),
    );
    await tester.tap(find.text('Choose file'));
    await tester.pumpAndSettle();
  }

  String mvr(int laari) => formatMoney(laari, dhivehi: false);

  tearDown(() => slipPicker = defaultSlipPicker);

  testWidgets('the wallet lists the claims in flight, the CTA and the switch', (
    tester,
  ) async {
    await pumpWallet(tester);
    await goWallet(tester);

    // The hint that "our team records top-ups" is gone — the merchant does.
    expect(find.textContaining('recorded by our team'), findsNothing);
    expect(find.text('Top up'), findsOneWidget);

    // Money the merchant has sent that is not yet balance, each with the
    // bank it named and where it stands; a refusal carries the reason.
    expect(find.text('Top-ups in progress'), findsOneWidget);
    expect(find.text(mvr(50000)), findsOneWidget);
    expect(find.text('Verifying'), findsOneWidget);
    expect(find.textContaining('To BML'), findsWidgets);
    expect(find.text('Rejected'), findsOneWidget);
    expect(
      find.text('Reason: The amount on the slip did not match the transfer.'),
      findsOneWidget,
    );

    // The switch reads ON from the wallet payload, with its one-line why.
    expect(find.text('Auto-settle from wallet'), findsOneWidget);
    expect(find.textContaining('Every hour'), findsOneWidget);
    expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);

    // The ledger's types are still words, never codes.
    expect(find.text('Top-up'), findsOneWidget);
    expect(find.text('Spent on a settlement'), findsOneWidget);
  });

  testWidgets('the switch writes through preferences and re-reads the wallet', (
    tester,
  ) async {
    final api = await pumpWallet(tester);
    await goWallet(tester);

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    // ONE write, on the documented path, carrying only the switch.
    expect(api.autoSettleWrites, [false]);
    expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
    // The wallet was re-read so a return within the cache window agrees.
    expect(api.walletReads, greaterThanOrEqualTo(2));
  });

  testWidgets('no wallet.top_up hides the CTA; no preferences.update freezes '
      'the switch', (tester) async {
    await pumpWallet(
      tester,
      permissions: const [
        'settlements.view',
        'settlements.preview',
        'wallet.view',
        'credits.create',
        'transactions.view',
      ],
    );
    await goWallet(tester);

    expect(find.text('Top up'), findsNothing);
    // The state still shows; only the hand that may move it is withheld.
    expect(find.text('Auto-settle from wallet'), findsOneWidget);
    expect(tester.widget<Switch>(find.byType(Switch)).onChanged, isNull);
  });

  testWidgets('a claim carries the typed laari, the bank, the slip and the '
      'reference', (tester) async {
    final api = await pumpWallet(tester);
    await goTopUp(tester);

    // The floor the wallet payload reports, said next to the box.
    expect(find.text('Minimum ${mvr(10000)}'), findsOneWidget);
    // A top-up never gets a payment reference — and the card says so
    // rather than promising one after the receipt.
    expect(find.textContaining('No payment reference needed'), findsOneWidget);
    expect(find.textContaining('will generate one'), findsNothing);

    await tester.enterText(find.byKey(const Key('top-up-amount')), '1,250.50');
    await tester.enterText(find.byKey(const Key('top-up-bank-ref')), ' FT123 ');
    await tester.pumpAndSettle();
    // The instructions card prices from the typed figure, live.
    expect(find.text(mvr(125050)), findsOneWidget);

    await attachSlip(tester);
    await tester.tap(find.text('Submit slip'));
    await tester.pumpAndSettle();

    expect(api.topUps, hasLength(1));
    final claim = api.topUps.single;
    expect(claim.amountLaari, 125050); // string surgery, never a float
    // The single configured account was preselected and travelled.
    expect(claim.platformBankAccountId, 1);
    expect(claim.bankRef, ' FT123 '); // the client trims; the fake records raw
    expect(claim.filename, 'slip.jpg');

    // The success story is VERIFYING — nothing claims "topped up" until
    // the transfer matches.
    expect(find.text('Manfaa is verifying your transfer'), findsOneWidget);
    expect(find.textContaining('Your top-up of ${mvr(125050)}'), findsOneWidget);
    // The wallet re-read so the claim shows on its pending list.
    expect(api.walletReads, greaterThanOrEqualTo(2));
  });

  testWidgets('under the platform floor nothing is sent', (tester) async {
    final api = await pumpWallet(tester);
    await goTopUp(tester);

    await tester.enterText(find.byKey(const Key('top-up-amount')), '50');
    await attachSlip(tester);
    await tester.tap(find.text('Submit slip'));
    await tester.pumpAndSettle();

    expect(find.text('The minimum top-up is ${mvr(10000)}.'), findsWidgets);
    expect(api.topUps, isEmpty);
    expect(find.text('Manfaa is verifying your transfer'), findsNothing);

    // Fixing the figure clears the refusal on the spot.
    await tester.enterText(find.byKey(const Key('top-up-amount')), '100');
    await tester.pumpAndSettle();
    expect(find.text('The minimum top-up is ${mvr(10000)}.'), findsNothing);
  });

  testWidgets('the bank details come off the wallet payload — an empty '
      'board and no settlement history still get them', (tester) async {
    final api = await pumpWallet(tester, previewRefuses: true);
    await goTopUp(tester);

    // The preview would refuse (nothing to settle) and was never asked;
    // the wallet's own `bank_accounts` supplied the platform's accounts,
    // and no settlement's reference came along as if it were this claim's.
    expect(api.settlementReads, 0);
    expect(find.text('7730000123456'), findsOneWidget);
    expect(find.text('ST-2026-00041'), findsNothing);
    expect(find.textContaining("Couldn't load the bank details"), findsNothing);
  });
}

class _WalletApi extends MerchantApi {
  _WalletApi({
    required super.session,
    required this.permissions,
    this.previewRefuses = false,
  });

  final List<String> permissions;

  /// The settle-all preview answers 422 (nothing to settle) — the case a
  /// merchant pre-funding an idle store is in.
  final bool previewRefuses;

  /// What the merchant's switch currently says server-side.
  var autoSettle = true;
  var walletReads = 0;
  var settlementReads = 0;
  final autoSettleWrites = <bool>[];
  final topUps =
      <({
        int amountLaari,
        int platformBankAccountId,
        String? bankRef,
        String filename,
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
    'outstanding': {
      'total': {
        'count': 1,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'payable_laari': 2750,
      },
      'buckets': {
        for (final key in ['0_5', '6_10', '11_15', 'overdue'])
          key: {
            'count': key == '0_5' ? 1 : 0,
            'cashback_laari': key == '0_5' ? 2000 : 0,
            'fee_laari': key == '0_5' ? 750 : 0,
            'fee_gst_laari': 0,
            'payable_laari': key == '0_5' ? 2750 : 0,
          },
      },
      'pending_adjustments': {'count': 0, 'credit_laari': 0},
    },
    'open_settlement': null,
  });

  @override
  Future<MerchantWalletState> wallet() async {
    walletReads++;
    return MerchantWalletState.fromJson({
      'balance_laari': 8175,
      'currency': 'MVR',
      'top_up_min_laari': 10000,
      'auto_settle_from_wallet': autoSettle,
      'bank_accounts': _instructions['bank_accounts'],
      'pending_top_ups': [
        {
          'id': 3,
          'amount_laari': 50000,
          'amount_mvr': 'MVR 500.00',
          'bank_ref': 'FT99877',
          'bank': {
            'id': 1,
            'bank_name': 'bml',
            'account_no': '7730000123456',
            'account_name': 'Manfaa Pvt Ltd',
          },
          'state': 'pending',
          'created_at': '2026-08-16T04:30:00+00:00',
        },
        // Refused claims stay listed for a week, with the reason.
        {
          'id': 2,
          'amount_laari': 20000,
          'amount_mvr': 'MVR 200.00',
          'bank_ref': null,
          'bank': null,
          'state': 'rejected',
          'rejected_reason': 'The amount on the slip did not match the transfer.',
          'created_at': '2026-08-14T04:30:00+00:00',
        },
      ],
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
    });
  }

  static const _instructions = {
    'amount_due_laari': 2750,
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
  };

  @override
  Future<SettlementPreviewData> settlementPreview({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async {
    if (previewRefuses) {
      throw MobileApiException(
        code: ApiCode.validationFailed,
        message: 'Nothing to settle.',
        status: 422,
      );
    }
    return SettlementPreviewData.fromJson({
      'as_of': '2026-08-16T09:07:00+00:00',
      'transaction_ids': [61],
      'transaction_count': 1,
      'sale_total_laari': 100000,
      'cashback_total_laari': 2000,
      'fee_total_laari': 750,
      'fee_gst_total_laari': 0,
      'line_total_laari': 2750,
      'credit_applied_laari': 0,
      'discount_laari': 0,
      'amount_due_before_discount_laari': 2750,
      'amount_due_laari': 2750,
      'due_at': '2026-08-25T00:00:00+00:00',
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
      },
      'payment_instructions': _instructions,
    });
  }

  @override
  Future<SettlementPage> settlements({int page = 1}) async {
    settlementReads++;
    return SettlementPage.fromJson({
      'data': [
        {
          'id': 41,
          'reference': 'ST-2026-00041',
          'state': 'settled',
          'funding_method': 'bank',
          'currency': 'MVR',
          'sale_total_laari': 100000,
          'cashback_total_laari': 2000,
          'fee_total_laari': 750,
          'fee_gst_total_laari': 0,
          'discount_laari': 0,
          'amount_due_laari': 2750,
          'amount_received_laari': 2750,
          'due_at': null,
          'created_at': '2026-08-01T09:00:00+00:00',
          'payment_instructions': {
            'reference': 'ST-2026-00041',
            ..._instructions,
          },
          'merchant_status': {
            'code': 'settled',
            'message': 'Settled.',
            'rejection': null,
          },
        },
      ],
      'meta': const {'current_page': 1, 'last_page': 1, 'total': 1},
    });
  }

  @override
  Future<MerchantPreferences> updatePreferences({
    String? settlementMethod,
    int? minEligibleLaari,
    int? validationWindowDays,
    bool? autoSettleFromWallet,
  }) async {
    // The switch is the ONLY key this screen ever sends.
    expect(settlementMethod, isNull);
    expect(minEligibleLaari, isNull);
    expect(validationWindowDays, isNull);
    if (autoSettleFromWallet != null) {
      autoSettleWrites.add(autoSettleFromWallet);
      autoSettle = autoSettleFromWallet;
    }
    return MerchantPreferences.fromJson({
      'settlement_method': 'bank',
      'min_eligible_laari': 10000,
      'validation_window_days': 15,
      'validation_window_max_days': 30,
      'auto_settle_from_wallet': autoSettle,
    });
  }

  @override
  Future<WalletTopUpClaim> createWalletTopUp({
    required int amountLaari,
    required int platformBankAccountId,
    required Uint8List slipBytes,
    required String slipFilename,
    String? bankRef,
  }) async {
    topUps.add((
      amountLaari: amountLaari,
      platformBankAccountId: platformBankAccountId,
      bankRef: bankRef,
      filename: slipFilename,
    ));
    return WalletTopUpClaim.fromJson({
      'id': 4,
      'merchant_id': 7,
      'amount_laari': amountLaari,
      'currency': 'MVR',
      'bank_ref': bankRef?.trim(),
      'platform_bank_account_id': platformBankAccountId,
      'platform_bank_account': {
        'id': platformBankAccountId,
        'bank_name': 'bml',
        'account_no': '7730000123456',
        'account_name': 'Manfaa Pvt Ltd',
      },
      'state': 'pending',
      'has_slip': true,
      'created_at': '2026-08-16T09:45:00+00:00',
    });
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
