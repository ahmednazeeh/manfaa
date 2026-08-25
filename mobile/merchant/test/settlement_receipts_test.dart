import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/settlements/settlement_detail_screen.dart';
import 'package:manfaa_merchant/l10n/gen/app_localizations.dart';

/// The receipts listed on a settlement — THE SETTLEMENT RECORD where the
/// transferred figure appears (owner, 2026-08-25).
///
/// `amount_laari` on a payment is only the CLAIM the merchant typed on the
/// upload form. `received_laari` is what the bank actually credited, and it
/// is what funded the batch's lines. So the row leads with the bank's figure
/// once one exists — and, because a silently smaller number reads as a
/// fault, it says in one plain sentence why that is not the amount entered.
void main() {
  /// A batch with one bank receipt. `payment` overrides the receipt's own
  /// fields so each test states only what it is about.
  Map<String, dynamic> settlementJson({
    Map<String, dynamic> payment = const {},
  }) => {
    'id': 44,
    'reference': 'ST-2026-00042',
    'state': 'partially_settled',
    'funding_method': 'bank',
    'currency': 'MVR',
    'sale_total_laari': 100000,
    'cashback_total_laari': 2000,
    'fee_total_laari': 750,
    'fee_gst_total_laari': 0,
    'amount_due_laari': 2712,
    'amount_received_laari': 1000,
    'created_at': '2026-08-16T09:45:00+00:00',
    'payment_instructions': {
      'reference': 'ST-2026-00042',
      'amount_due_laari': 2712,
      'amount_due_mvr': '27.12',
      'bank_account': null,
      'bank_accounts': <Object>[],
      'needs_configuration': false,
    },
    'merchant_status': {
      'code': 'partially_settled',
      'message': 'Part of this settlement is paid.',
      'rejection': null,
    },
    'lines': <Object>[],
    'payments': [
      {
        'id': 12,
        'settlement_id': 44,
        // THE CLAIM: what the merchant typed.
        'amount_laari': 2712,
        'amount_mvr': '27.12',
        'received_laari': null,
        'received_mvr': null,
        'amount_differs': false,
        'currency': 'MVR',
        'method': 'bank',
        'bank_ref': 'FT12345',
        'has_slip': true,
        'slip_mime': 'image/jpeg',
        'slip_size_bytes': 204800,
        'state': 'pending',
        'matched_at': null,
        'rejected_at': null,
        'rejection_reason': null,
        'created_at': '2026-08-16T09:45:00+00:00',
        ...payment,
      },
    ],
  };

  Future<void> pumpDetail(
    WidgetTester tester,
    Map<String, dynamic> settlement,
  ) async {
    await tester.binding.setSurfaceSize(const Size(600, 2400));
    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          apiProvider.overrideWith(
            (ref) => _DetailApi(session: session, settlementJson: settlement),
          ),
        ],
        child: MaterialApp(
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          // `embedded` is the two-pane half: a plain Column with no back
          // button, so it needs no router above it.
          home: const Scaffold(
            body: SingleChildScrollView(
              child: SettlementDetailBody(id: 44, embedded: true),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  /// formatMoney puts a NON-BREAKING space after MVR; a plain one would
  /// silently never match.
  String mvr(int laari) => formatMoney(laari, dhivehi: false);

  testWidgets('a receipt still being verified shows the amount claimed, and '
      'claims no discrepancy', (tester) async {
    await pumpDetail(tester, settlementJson());

    expect(find.text('Payments'), findsOneWidget);
    expect(find.text(mvr(2712)), findsWidgets);
    expect(find.textContaining('Your bank sent'), findsNothing);
  });

  testWidgets('a matched receipt leads with what the BANK sent and says why '
      'it is not the amount entered', (tester) async {
    await pumpDetail(
      tester,
      settlementJson(
        payment: const {
          'state': 'matched',
          'received_laari': 1000,
          'received_mvr': '10.00',
          'amount_differs': true,
          'matched_at': '2026-08-25T16:20:00+00:00',
        },
      ),
    );

    // The money that funded the lines, not the number on the form.
    expect(find.text(mvr(1000)), findsWidgets);
    expect(
      find.textContaining(
        'Your bank sent ${mvr(1000)}, not the ${mvr(2712)} you entered',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a matched receipt whose figures agree says nothing extra', (
    tester,
  ) async {
    await pumpDetail(
      tester,
      settlementJson(
        payment: const {
          'state': 'matched',
          'received_laari': 2712,
          'received_mvr': '27.12',
          'amount_differs': false,
          'matched_at': '2026-08-25T16:20:00+00:00',
        },
      ),
    );

    expect(find.text(mvr(2712)), findsWidgets);
    expect(find.textContaining('Your bank sent'), findsNothing);
  });

  testWidgets('a receipt matched by hand with no bank figure keeps the '
      'claim, and announces no mismatch', (tester) async {
    await pumpDetail(
      tester,
      settlementJson(
        payment: const {
          'state': 'matched',
          'received_laari': null,
          'received_mvr': null,
          // Even set, the flag is refused: there is no second figure to name.
          'amount_differs': true,
          'matched_at': '2026-08-25T16:20:00+00:00',
        },
      ),
    );

    expect(find.text(mvr(2712)), findsWidgets);
    expect(find.textContaining('Your bank sent'), findsNothing);
  });
}

class _DetailApi extends MerchantApi {
  _DetailApi({required super.session, required this.settlementJson});

  // The guided setup is not live in this fixture, so the shell's chip and
  // the Dashboard's tour prompt draw nothing and every assertion below is
  // about the screen it is about. Overridden rather than inherited because
  // the base class would reach the NETWORK from a unit test.
  @override
  Future<MerchantOnboardingGuide> onboarding() async =>
      MerchantOnboardingGuide.hidden;

  final Map<String, dynamic> settlementJson;

  @override
  Future<MerchantSettlement> settlement(int id) async =>
      MerchantSettlement.fromJson(settlementJson);

  /// GET /merchant/fee-promotion. Defaults to NOTHING RUNNING — the state
  /// every shipped assertion and golden was written against — and is
  /// settable so a test can throw the switch the way a superadmin does.
  MerchantFeePromotion promotion = MerchantFeePromotion.none;

  @override
  Future<MerchantFeePromotion> feePromotion() async => promotion;
}
