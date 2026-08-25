import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/credit/split_editor.dart'
    show SplitRow, estimateSplit;
import 'package:manfaa_merchant/features/fee_promotion/fee_promotion_banner.dart';
import 'package:manfaa_merchant/l10n/gen/app_localizations.dart';

/// THE PLATFORM FEE PROMOTION ON THE TILL (owner, 2026-08-25).
///
/// Three things are under test and they are deliberately separate:
///
///   1. the BANNER draws the server's sentence, in the reader's language,
///      and refuses to draw anything it does not fully understand;
///   2. the COST PREVIEW quotes the promotional rate — `min(promotion,
///      tier)`, the server's own rule, taken PER LINE on a split sale;
///   3. the SCREENS that quote a fee carry the banner, and stop carrying it
///      the moment the window closes.
void main() {
  const allPermissions = [
    'settlements.view',
    'settlements.create',
    'settlements.preview',
    'wallet.view',
    'credits.create',
    'transactions.view',
    'rate.view',
    'product_categories.view',
  ];

  /// The server's answer while a promotion is running.
  Map<String, dynamic> offer({
    String kind = 'introductory',
    String? fee = '0.00',
    String? endsAt = '2099-01-01T00:00:00+00:00',
    int? daysRemaining = 13,
  }) => {
    'active': true,
    'kind': kind,
    'kind_label': 'Introductory offer',
    'platform_fee_percent': fee,
    'ends_at': endsAt,
    'days_remaining': daysRemaining,
    'banner_en': 'No platform fee for your first 60 days on Manfaa.',
    'banner_dv': 'މަންފާގައި ފުރަތަމަ 60 ދުވަހު ފީއެއް ނުނަގާނެއެވެ.',
  };

  MerchantFeePromotion promotion({
    String kind = 'introductory',
    String? fee = '0.00',
    String? endsAt = '2099-01-01T00:00:00+00:00',
    int? daysRemaining = 13,
  }) => MerchantFeePromotion.fromJson(
    offer(kind: kind, fee: fee, endsAt: endsAt, daysRemaining: daysRemaining),
  );

  // ---------------------------------------------------------- the banner

  /// The banner alone, so the copy and the refusals are tested without the
  /// whole app in the way.
  Widget bannerHost(MerchantFeePromotion promo, {String language = 'en'}) =>
      MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('dv')],
        localizationsDelegates: const [
          AppLocalizations.delegate,
          ...dvFallbackDelegates,
          GlobalMaterialLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
        ],
        home: Scaffold(body: FeePromotionBannerBody(promotion: promo)),
      );

  group('the banner', () {
    testWidgets('says the kind, the rate, the days and the SERVER\'s words', (
      tester,
    ) async {
      await tester.pumpWidget(bannerHost(promotion()));
      await tester.pumpAndSettle();

      expect(find.byKey(FeePromotionBanner.bannerKey), findsOneWidget);
      expect(find.text('Introductory offer'), findsOneWidget);
      expect(find.text('Platform fee 0%'), findsOneWidget);
      expect(find.text('13 days left'), findsOneWidget);
      expect(
        find.text('No platform fee for your first 60 days on Manfaa.'),
        findsOneWidget,
      );
    });

    testWidgets('reads in Dhivehi: our chrome AND the server copy', (
      tester,
    ) async {
      await tester.pumpWidget(bannerHost(promotion(), language: 'dv'));
      await tester.pumpAndSettle();

      // The server's own dv sentence, not the English one.
      expect(
        find.text('މަންފާގައި ފުރަތަމަ 60 ދުވަހު ފީއެއް ނުނަގާނެއެވެ.'),
        findsOneWidget,
      );
      expect(find.text('No platform fee for your first 60 days on Manfaa.'),
          findsNothing);
      // The heading is OURS, translated — never the server's English
      // `kind_label`, which would put English inside a Thaana banner.
      expect(find.text('Introductory offer'), findsNothing);
      expect(find.text('ފެށުމުގެ ޚާއްޞަ ފީ'), findsOneWidget);
    });

    testWidgets('names the platform-wide kind by its own heading', (
      tester,
    ) async {
      await tester.pumpWidget(
        bannerHost(promotion(kind: 'platform_wide', fee: '0.25')),
      );
      await tester.pumpAndSettle();

      expect(find.text('Platform-wide offer'), findsOneWidget);
      expect(find.text('Platform fee 0.25%'), findsOneWidget);
    });

    testWidgets('draws NOTHING when nothing is running', (tester) async {
      await tester.pumpWidget(bannerHost(MerchantFeePromotion.none));
      await tester.pumpAndSettle();

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
    });

    testWidgets('draws NOTHING for a kind this build has never seen', (
      tester,
    ) async {
      await tester.pumpWidget(bannerHost(promotion(kind: 'seasonal_2027')));
      await tester.pumpAndSettle();

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('draws NOTHING once the window has closed', (tester) async {
      await tester.pumpWidget(
        bannerHost(promotion(endsAt: '2020-01-01T00:00:00+00:00')),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
    });

    testWidgets('a campaign with no end still draws, with no day count', (
      tester,
    ) async {
      await tester.pumpWidget(
        bannerHost(
          promotion(
            kind: 'platform_wide',
            endsAt: null,
            daysRemaining: null,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(FeePromotionBanner.bannerKey), findsOneWidget);
      expect(find.textContaining('days left'), findsNothing);
    });

    testWidgets('the last day reads as the last day, not as zero left', (
      tester,
    ) async {
      await tester.pumpWidget(bannerHost(promotion(daysRemaining: 0)));
      await tester.pumpAndSettle();

      expect(find.text('Last day'), findsOneWidget);
    });
  });

  // --------------------------------------------------- the split estimate

  group('the split estimate', () {
    List<ProductCategory> categories() => [
      ProductCategory.fromJson(const {
        'id': 1,
        'slug': 'fruits',
        'name_en': 'Fruits',
        'name_dv': null,
        'mode': 'rate',
        'cashback_rate_percent': '5.00',
        'active': true,
        'sort': 0,
      }),
    ];

    // One line at a CATEGORY rate (5.00% → the §4 band's 1.00% fee) and one
    // at the store's base rate (2.00% → 0.75%), MVR 1,000.00 each.
    final rows = [
      SplitRow(category: 'fruits', amountLaari: 100000),
      SplitRow(category: null, amountLaari: 100000),
    ];

    test('prices exactly as before when nothing is running', () {
      final estimate = estimateSplit(
        rows,
        categories(),
        baseRateBp: 200,
        baseFeeBp: 75,
      );

      expect(estimate!.fee, 1000 + 750);
    });

    test('a zero-fee promotion zeroes every line', () {
      final estimate = estimateSplit(
        rows,
        categories(),
        baseRateBp: 200,
        baseFeeBp: 75,
        promotion: promotion(),
      );

      expect(estimate!.fee, 0);
      // The customer's reward is untouched — this feature lowers OUR cut.
      expect(estimate.cashback, 5000 + 2000);
    });

    // THE POINT OF THE PARAMETER: the minimum is taken at each priced unit,
    // exactly as TermsResolver does. A sale-level minimum would charge the
    // base line 0.80% too and come out at 1,600 laari.
    test('the minimum is taken PER LINE, not once over the sale', () {
      final estimate = estimateSplit(
        rows,
        categories(),
        baseRateBp: 200,
        baseFeeBp: 75,
        promotion: promotion(fee: '0.80'),
      );

      expect(estimate!.fee, 800 + 750);
    });

    test('a finished promotion prices at the tier again', () {
      final estimate = estimateSplit(
        rows,
        categories(),
        baseRateBp: 200,
        baseFeeBp: 75,
        promotion: promotion(endsAt: '2020-01-01T00:00:00+00:00'),
      );

      expect(estimate!.fee, 1000 + 750);
    });
  });

  // ------------------------------------------------------- the whole app

  Future<MemorySecretStore> seededStore() async {
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
      permissions: allPermissions,
    );
    return store;
  }

  Future<_PromoApi> pumpApp(
    WidgetTester tester, {
    Map<String, dynamic>? promotionJson,
    bool promotionFails = false,
  }) async {
    await tester.binding.setSurfaceSize(const Size(600, 3200));
    final store = await seededStore();
    late _PromoApi api;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          apiProvider.overrideWith(
            (ref) => api = _PromoApi(
              session: ref.watch(sessionProvider),
              permissions: allPermissions,
              promotionJson: promotionJson,
              promotionFails: promotionFails,
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
              'features': <String, dynamic>{},
            }),
          ),
        ],
        child: const MerchantApp(),
      ),
    );
    await tester.pumpAndSettle();
    return api;
  }

  Future<void> goCredit(WidgetTester tester) async {
    await tester.tap(find.text('Credit').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(2), '1,000.00');
    await tester.pumpAndSettle();
  }

  group('the screens that quote a fee', () {
    testWidgets('the Dashboard carries the banner', (tester) async {
      await pumpApp(tester, promotionJson: offer());

      expect(find.byKey(FeePromotionBanner.bannerKey), findsOneWidget);
      expect(find.text('Platform fee 0%'), findsOneWidget);
    });

    testWidgets('the Settlements board carries it too', (tester) async {
      await pumpApp(tester, promotionJson: offer());
      await tester.tap(find.text('Settlements').last);
      await tester.pumpAndSettle();

      expect(find.byKey(FeePromotionBanner.bannerKey), findsOneWidget);
    });

    testWidgets('the till quotes the PROMOTIONAL fee in the cost preview', (
      tester,
    ) async {
      await pumpApp(tester, promotionJson: offer());
      await goCredit(tester);

      // The banner sits above the preview whose fee row it explains.
      expect(find.byKey(FeePromotionBanner.bannerKey), findsOneWidget);
      // min(0.00, 0.75) = 0.00 — the rate AND the money, on the same row.
      expect(find.text('Platform fee (0%)'), findsOneWidget);
      expect(find.text('Platform fee (0.75%)'), findsNothing);
      expect(find.text(_mvr('0.00')), findsOneWidget);
      // All-in drops to the cashback rate alone.
      expect(find.text('You pay (2%)'), findsOneWidget);
      expect(find.text(_mvr('20.00')), findsWidgets);
    });

    testWidgets('THE MERCHANT WINS — a cheaper tier is left alone', (
      tester,
    ) async {
      await pumpApp(tester, promotionJson: offer(fee: '1.00'));
      await goCredit(tester);

      // The banner still says what the campaign offers…
      expect(find.text('Platform fee 1%'), findsOneWidget);
      // …and this store still pays its own, cheaper, tier fee.
      expect(find.text('Platform fee (0.75%)'), findsOneWidget);
      expect(find.text('You pay (2.75%)'), findsOneWidget);
    });

    testWidgets('nothing running changes nothing on the till', (tester) async {
      await pumpApp(tester);
      await goCredit(tester);

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
      expect(find.text('Platform fee (0.75%)'), findsOneWidget);
      expect(find.text('You pay (2.75%)'), findsOneWidget);
    });

    testWidgets('a kind from the future is ignored, quote and all', (
      tester,
    ) async {
      await pumpApp(tester, promotionJson: offer(kind: 'seasonal_2027'));
      await goCredit(tester);

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
      expect(find.text('Platform fee (0.75%)'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a closed window disappears without waiting for the server', (
      tester,
    ) async {
      // The server is still answering `active: true` — a cached 60-second
      // answer, or a till that has not asked again — but the window it
      // describes ended. The till stops advertising it AND stops quoting it.
      await pumpApp(
        tester,
        promotionJson: offer(endsAt: '2020-01-01T00:00:00+00:00'),
      );
      await goCredit(tester);

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
      expect(find.text('Platform fee (0.75%)'), findsOneWidget);
    });

    testWidgets('a failed read is silent, never an error on a till screen', (
      tester,
    ) async {
      await pumpApp(tester, promotionFails: true);
      await goCredit(tester);

      expect(find.byKey(FeePromotionBanner.bannerKey), findsNothing);
      // And the quote falls back to the tier fee — the figure the server
      // would have charged anyway.
      expect(find.text('Platform fee (0.75%)'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the Dashboard banner reads in Dhivehi', (tester) async {
      await pumpApp(tester, promotionJson: offer());
      // Switched through the app's own control, not by pumping a different
      // widget — the banner has to follow the language the merchant picked.
      final container = ProviderScope.containerOf(
        tester.element(find.byType(MerchantApp)),
      );
      await container.read(localeProvider.notifier).set('dv');
      await tester.pumpAndSettle();

      expect(
        find.text('މަންފާގައި ފުރަތަމަ 60 ދުވަހު ފީއެއް ނުނަގާނެއެވެ.'),
        findsOneWidget,
      );
      expect(find.text('ފެށުމުގެ ޚާއްޞަ ފީ'), findsOneWidget);
    });
  });
}

/// Money on screen carries a NON-BREAKING space between the currency word
/// and the figure (manfaa_core's `moneyGap`) — they are one typographic
/// unit and must never break across lines.
String _mvr(String amount) => 'MVR\u00A0$amount';

/// The till's server, with one switch: what GET /merchant/fee-promotion
/// answers. Everything else is the ordinary fixture — rate 2.00% on the
/// §4 0.75% tier, so "the promotion won" and "the tier won" are both
/// visible in the same preview.
class _PromoApi extends MerchantApi {
  _PromoApi({
    required super.session,
    required this.permissions,
    this.promotionJson,
    this.promotionFails = false,
  });

  final List<String> permissions;

  /// Null = the server says nothing is running.
  final Map<String, dynamic>? promotionJson;

  /// The read fails outright — a dead network, a 500, a 403 from a server
  /// that has not mounted the route yet.
  final bool promotionFails;

  @override
  Future<MerchantFeePromotion> feePromotion() async {
    if (promotionFails) throw MobileApiException.network();
    return MerchantFeePromotion.fromJson(
      promotionJson ??
          const {
            'active': false,
            'kind': null,
            'kind_label': null,
            'platform_fee_percent': null,
            'ends_at': null,
            'days_remaining': null,
            'banner_en': null,
            'banner_dv': null,
          },
    );
  }

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
  Future<MerchantHome> home() async => MerchantHome.fromJson(const {
    'merchant': {'name': 'Tropical Mart', 'status': 'active'},
    'today': {'credit_count': 0, 'eligible_laari': 0, 'cashback_laari': 0},
    'outstanding': null,
    'open_settlement': null,
  });

  @override
  Future<MerchantRate> merchantRate() async => MerchantRate.fromJson(const {
    // The §4 TIER fee, which the server keeps promotions OUT of on
    // purpose — the till applies min(promotion, tier) itself.
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
      const <ProductCategory>[];

  @override
  Future<CustomerLookup> lookupCustomer(String code) async =>
      CustomerLookup(valid: false);

  /// An empty board: the domain's "nothing to settle" 422, which the tab
  /// renders as its empty state. The banner sits above the board either way.
  @override
  Future<SettlementPreviewData> settlementPreview({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async => throw MobileApiException(
    code: ApiCode.validationFailed,
    message: 'Nothing to settle.',
    status: 422,
  );

  @override
  Future<SettlementPage> settlements({int page = 1}) async =>
      SettlementPage.fromJson(const {
        'data': <Object>[],
        'meta': {'current_page': 1, 'last_page': 1, 'total': 0},
      });

  @override
  Future<MerchantWalletState> wallet() async =>
      MerchantWalletState.fromJson(const {
        'balance_laari': 0,
        'currency': 'MVR',
        'transactions': <Object>[],
      });
}
