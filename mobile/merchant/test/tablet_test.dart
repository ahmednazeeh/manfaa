import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';

/// MR7 contract tests — the estates half of the tablet round, at an
/// expanded (≥840dp content) window:
///
///  - Transactions splits into list | detail: selecting a sale fills the
///    pane, the corrections render there behind the SAME window + slug
///    gates, and the amend form lands the same wire call inline (no sheet);
///  - Settlements splits into overview | detail: selecting a recent
///    settlement renders its story in the pane without leaving the tab;
///  - Employees splits into list | editor: a staff row opens the SAME edit
///    form inline and a landed save travels the same wire.
///
/// Phones are untouched by all of this — the phone contract tests
/// (money/estate/more) run at compact/medium widths and still pass.
void main() {
  MobileConfig config() => MobileConfig.fromJson({
    'apps': {
      'merchant': {
        'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
      },
    },
    'features': const {},
  });

  Future<_FakeApi> boot(
    WidgetTester tester, {
    required List<String> permissions,
  }) async {
    // The MR7 slate: ≥840dp window ⇒ rail shell; the content that remains
    // is still ≥840dp ⇒ the two-pane screen layouts.
    await tester.binding.setSurfaceSize(const Size(1280, 800));
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1.0;

    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.saveSession(
      token: 't',
      userName: 'Aminath Waheedha',
      userEmail: 'a@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: permissions,
    );
    final api = _FakeApi(session: session, permissions: permissions);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          sessionProvider.overrideWithValue(session),
          apiProvider.overrideWith((ref) => api),
          configProvider.overrideWith((ref) async => config()),
        ],
        child: const MerchantApp(),
      ),
    );
    await tester.pumpAndSettle();
    return api;
  }

  testWidgets(
    'transactions: selecting a sale fills the pane; amend lands inline',
    (tester) async {
      final api = await boot(
        tester,
        permissions: const [
          'credits.create',
          'transactions.view',
          'transactions.amend',
          'transactions.cancel',
        ],
      );

      await tester.tap(find.text('Transactions').last);
      await tester.pumpAndSettle();

      // Empty selection: the quiet hint, not a blank.
      expect(find.text('Select a sale'), findsOneWidget);
      expect(find.text('Correct amount'), findsNothing);

      // Selecting the correctable sale surfaces the detail + actions.
      await tester.tap(find.text('INV-1004'));
      await tester.pumpAndSettle();
      expect(find.text('Select a sale'), findsNothing);
      expect(find.text('Correct amount'), findsOneWidget);
      expect(find.text('Cancel sale'), findsOneWidget);

      // A sale OUTSIDE the correction window shows no buttons — the exact
      // gate the phone tile draws from.
      await tester.tap(find.text('INV-1003'));
      await tester.pumpAndSettle();
      expect(find.text('Correct amount'), findsNothing);
      expect(find.text('Cancel sale'), findsNothing);

      // Back to the correctable one: the amend form opens INLINE (same
      // fields, no route pushed) and the save carries the same wire body.
      await tester.tap(find.text('INV-1004'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Correct amount'));
      await tester.pumpAndSettle();
      expect(find.text('Correct the amount'), findsOneWidget);

      await tester.enterText(find.byType(TextField).last, '900');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save correction'));
      await tester.pumpAndSettle();

      expect(api.amends, [
        {'id': 4, 'eligible_laari': 90000},
      ]);
      // The form closed back into the detail pane.
      expect(find.text('Correct the amount'), findsNothing);

      // Let the confirmation snackbar's timer run out before teardown.
      await tester.pump(const Duration(seconds: 5));
    },
  );

  testWidgets(
    'settlements: selecting a recent batch renders its story in the pane',
    (tester) async {
      await boot(
        tester,
        permissions: const [
          'settlements.view',
          'settlements.preview',
          'settlements.create',
          'wallet.view',
        ],
      );

      await tester.tap(find.text('Settlements').last);
      await tester.pumpAndSettle();

      expect(find.text('Select a settlement'), findsOneWidget);

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

      // The pane carries the detail's summary WITHOUT leaving the tab (the
      // overview's hero is still on screen — no pushed route, no back
      // button appeared).
      expect(find.text('Select a settlement'), findsNothing);
      expect(find.text('Summary'), findsOneWidget);
      expect(find.text('Amount due now'), findsOneWidget);
      expect(find.byIcon(Icons.arrow_back_rounded), findsNothing);
    },
  );

  testWidgets(
    'employees: a staff row opens the editor pane; the save travels',
    (tester) async {
      final api = await boot(
        tester,
        permissions: const [
          'credits.create',
          'staff.view',
          'staff.edit',
          'roles.view',
        ],
      );

      // The rail gates on the SAME kTabs slugs as the phone bar: this role
      // holds no transactions.view / settlements.view (the latter also
      // earns Dashboard), so the rail draws Credit and More and nothing
      // else at the expanded width.
      expect(find.text('Dashboard'), findsNothing);
      expect(find.text('Transactions'), findsNothing);
      expect(find.text('Settlements'), findsNothing);
      expect(find.text('Credit'), findsOneWidget);
      expect(find.text('More'), findsOneWidget);

      await tester.tap(find.text('More').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Manage Employees'));
      await tester.pumpAndSettle();

      expect(find.text('Select an employee'), findsOneWidget);

      await tester.tap(find.text('Mariyam Shifa'));
      await tester.pumpAndSettle();

      // The editor sits in the pane (Save visible with the list still on
      // screen), not in a modal route.
      expect(find.text('Select an employee'), findsNothing);
      expect(find.text('Save'), findsOneWidget);
      expect(find.text('Search employees'), findsOneWidget);

      // Deactivate and save — the same wire the phone sheet sends.
      await tester.tap(find.byType(Switch).last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(api.staffUpdates, [
        {'id': 2, 'merchant_role_id': null, 'is_active': false},
      ]);
      // The pane closed back to the hint.
      expect(find.text('Select an employee'), findsOneWidget);

      // Let the confirmation snackbar's timer run out before teardown.
      await tester.pump(const Duration(seconds: 5));
    },
  );
}

const _ownerRole = {
  'id': 1,
  'name': 'Owner',
  'name_dv': null,
  'is_owner': true,
};

const _cashierRole = {
  'id': 2,
  'name': 'Cashier',
  'name_dv': null,
  'is_owner': false,
};

Map<String, dynamic> _staffRow(
  int id,
  String name,
  String email,
  Map<String, dynamic> role,
) => {
  'id': id,
  'name': name,
  'email': email,
  'role': role,
  'is_active': true,
  'created_at': '2026-08-01T09:00:00+00:00',
};

class _FakeApi extends MerchantApi {
  _FakeApi({required super.session, required this.permissions});

  final List<String> permissions;

  /// Every amend body, verbatim.
  final amends = <Map<String, dynamic>>[];

  /// Every staff PATCH body, verbatim.
  final staffUpdates = <Map<String, dynamic>>[];

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
    'today': {'credit_count': 0, 'eligible_laari': 0, 'cashback_laari': 0},
    'outstanding': null,
    'open_settlement': null,
  });

  @override
  Future<MerchantWalletState> wallet() async => MerchantWalletState.fromJson(
    const {'balance_laari': 0, 'currency': 'MVR', 'transactions': <Object>[]},
  );

  Map<String, dynamic> _tx(
    int id,
    String invoice,
    String state, {
    int eligible = 100000,
    int cashback = 2000,
  }) => {
    'id': id,
    'origin': 'manual',
    'invoice_no': invoice,
    'state': state,
    'reason_code': null,
    'backdated': false,
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
    ],
    nextCursor: null,
    hasMore: false,
  );

  @override
  Future<MerchantTransaction> amendTransaction({
    required int id,
    required int eligibleLaari,
    int? saleLaari,
    List<CreditLine>? lines,
  }) async {
    amends.add({'id': id, 'eligible_laari': eligibleLaari});
    return MerchantTransaction.fromJson(
      _tx(id, 'INV-1004', 'awaiting_validation', eligible: eligibleLaari),
    );
  }

  Map<String, dynamic> _settlement(int id, String reference) => {
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
    'amount_due_laari': 2750,
    'amount_received_laari': 2750,
    'due_at': null,
    'created_at': '2026-08-01T09:00:00+00:00',
    'payment_instructions': {
      'reference': reference,
      'amount_due_laari': 2750,
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

  @override
  Future<SettlementPage> settlements({int page = 1}) async =>
      SettlementPage.fromJson({
        'data': [_settlement(41, 'ST-2026-00041')],
        'meta': const {'current_page': 1, 'last_page': 1, 'total': 1},
      });

  @override
  Future<MerchantSettlement> settlement(int id) async =>
      MerchantSettlement.fromJson(_settlement(id, 'ST-2026-000$id'));

  @override
  Future<SettlementPreviewData> settlementPreview({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async => SettlementPreviewData.fromJson({
    'as_of': '2026-08-16T09:07:00+00:00',
    'transaction_ids': const [61],
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
    'buckets': const {
      'all': {
        'count': 1,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'due_laari': 2750,
        'transaction_ids': [61],
      },
    },
    'payment_instructions': const {
      'reference_preview': 'ST-2026-00042',
      'reference_is_final': false,
      'amount_due_laari': 2750,
      'bank_account': {
        'bank_name': 'bml',
        'account_no': '7730000123456',
        'account_name': 'Manfaa Pvt Ltd',
      },
      'bank_accounts': <Object>[],
      'needs_configuration': false,
    },
  });

  @override
  Future<List<MerchantStaff>> staff() async => [
    for (final json in [
      _staffRow(1, 'Ahmed Nazeeh', 'nazeeh@tropicalmart.mv', _ownerRole),
      _staffRow(2, 'Mariyam Shifa', 'shifa@tropicalmart.mv', _cashierRole),
    ])
      MerchantStaff.fromJson(json),
  ];

  @override
  Future<MerchantStaff> updateStaff(
    int id, {
    String? name,
    String? email,
    int? merchantRoleId,
    bool? isActive,
  }) async {
    staffUpdates.add({
      'id': id,
      'merchant_role_id': merchantRoleId,
      'is_active': isActive,
    });
    return MerchantStaff.fromJson(
      _staffRow(id, 'Mariyam Shifa', 'shifa@tropicalmart.mv', _cashierRole),
    );
  }

  @override
  Future<List<MerchantRole>> roles() async => [
    MerchantRole.fromJson(const {
      'id': 1,
      'name': 'Owner',
      'name_dv': null,
      'slug': 'owner',
      'is_owner': true,
      'is_system': true,
      'permissions': <String>[],
      'staff_count': 1,
    }),
    MerchantRole.fromJson(const {
      'id': 2,
      'name': 'Cashier',
      'name_dv': null,
      'slug': 'staff',
      'is_owner': false,
      'is_system': true,
      'permissions': ['credits.create'],
      'staff_count': 1,
    }),
  ];
}
