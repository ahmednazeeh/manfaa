import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show FontLoader, rootBundle;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_customer/app/app.dart';
import 'package:manfaa_customer/app/providers.dart';

/// Not a test — a screenshot harness. `flutter test test/screenshot_test.dart`
/// writes real PNGs of the running screens so the UI can actually be LOOKED
/// at. Loads the bundled fonts so text renders as glyphs, not boxes.
Future<void> _loadFonts() async {
  final manifest = json.decode(
    await rootBundle.loadString('FontManifest.json'),
  ) as List<dynamic>;

  for (final family in manifest) {
    final loader = FontLoader(family['family'] as String);
    for (final asset in family['fonts'] as List<dynamic>) {
      loader.addFont(rootBundle.load(asset['asset'] as String));
    }
    await loader.load();
  }
}

class _ShotApi extends ManfaaApi {
  _ShotApi({required super.session});

  @override
  Future<HomeData> home() async => HomeData.fromJson(const {
        'customer': {'name': 'Aishath Ali', 'customer_code': '374230'},
        'balance': {
          'currency': 'MVR',
          'confirmed_laari': 128450,
          'pending_laari': 40000,
          'paid_this_month_laari': 25000,
        },
        'payout': {
          'minimum_laari': 10000,
          'next_window': {'starts_at': '2026-08-25', 'ends_at': '2026-08-31'},
          'has_account': false,
        },
      });

  @override
  Future<DiscoverFeed> discover({double? lat, double? lng, int? zone}) async =>
      DiscoverFeed.fromJson(const {
        'categories': [
          {'slug': 'grocery', 'name_en': 'Grocery', 'count': 12},
          {'slug': 'dining', 'name_en': 'Dining', 'count': 8},
          {'slug': 'fashion', 'name_en': 'Fashion', 'count': 5},
          {'slug': 'electronics', 'name_en': 'Electronics', 'count': 4},
          {'slug': 'health', 'name_en': 'Health', 'count': 6},
        ],
        'offers': [
          {
            'id': 1,
            'kind': 'text',
            'title': 'Island Mart',
            'blurb': 'Your one-stop shop for daily essentials and more.',
            'badge': 'Limited time',
            'merchant': {
              'name': 'Island Mart',
              'slug': 'island-mart',
              'category': 'Grocery',
              'channel': 'in_store',
              'cashback_rate_percent': '10',
              'standing_cashback_rate_percent': '5',
            },
          },
        ],
        'increased': [
          {
            'name': 'Ocean Café',
            'slug': 'ocean-cafe',
            'category': 'Dining',
            'channel': 'in_store',
            'cashback_rate_percent': '8',
            'standing_cashback_rate_percent': '5',
            'distance_m': 300,
          },
        ],
        'featured': [
          {
            'name': 'Style Hub',
            'slug': 'style-hub',
            'category': 'Fashion',
            'channel': 'in_store',
            'cashback_rate_percent': '5',
            'standing_cashback_rate_percent': '5',
            'distance_m': 600,
          },
          {
            'name': 'Tech Corner',
            'slug': 'tech-corner',
            'category': 'Electronics',
            'channel': 'both',
            'cashback_rate_percent': '3',
            'standing_cashback_rate_percent': '3',
            'distance_m': 800,
          },
        ],
        'nearby': [
          {
            'name': 'Fresh Basket',
            'slug': 'fresh-basket',
            'category': 'Grocery',
            'channel': 'in_store',
            'cashback_rate_percent': '2',
            'standing_cashback_rate_percent': '2',
            'distance_m': 200,
          },
          {
            'name': 'Brew Point',
            'slug': 'brew-point',
            'category': 'Dining',
            'channel': 'in_store',
            'cashback_rate_percent': '3',
            'standing_cashback_rate_percent': '3',
            'distance_m': 300,
          },
        ],
      });

  @override
  Future<CursorPage<TransactionEntry>> transactions({String? cursor}) async =>
      CursorPage(
        hasMore: false,
        items: [
          TransactionEntry.fromJson(const {
            'id': 1,
            'merchant': {'name': 'Ocean Café', 'slug': 'ocean-cafe'},
            'invoice_no': 'INV-1001',
            'eligible_laari': 50000,
            'cashback_laari': 5000,
            'status': 'pending',
            'status_reason': 'validation_window',
            'occurred_at': '2026-08-16',
          }),
          TransactionEntry.fromJson(const {
            'id': 2,
            'merchant': {'name': 'Island Mart', 'slug': 'island-mart'},
            'invoice_no': 'INV-1002',
            'eligible_laari': 120000,
            'cashback_laari': 12000,
            'status': 'confirmed',
            'occurred_at': '2026-08-15',
          }),
          TransactionEntry.fromJson(const {
            'id': 3,
            'merchant': {'name': 'Tech Corner', 'slug': 'tech-corner'},
            'invoice_no': 'INV-1003',
            'eligible_laari': 300000,
            'cashback_laari': 9000,
            'status': 'paid',
            'occurred_at': '2026-08-10',
          }),
        ],
      );

  @override
  Future<CursorPage<PayoutEntry>> payouts({String? cursor}) async => CursorPage(
        hasMore: false,
        items: [
          PayoutEntry.fromJson(const {
            'id': 1,
            'amount_laari': 25000,
            'status': 'paid',
            'bank': 'Bank of Maldives',
            'account_masked': '•••• 4821',
            'reference': 'PO-2026-08',
            'period_start': '2026-07-25',
            'period_end': '2026-07-31',
            'transaction_count': 4,
            'paid_at': '2026-08-01',
          }),
        ],
      );
}

void main() {
  setUpAll(_loadFonts);

  Future<void> shot(WidgetTester tester, String name, Brightness b,
      {IconData? tab, bool signedOut = false, String locale = 'en'}) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    tester.view.devicePixelRatio = 3.0;
    tester.view.physicalSize = const Size(390 * 3, 844 * 3);
    tester.platformDispatcher.platformBrightnessTestValue = b;

    final store = MemorySecretStore();
    final session = SessionStore(store);
    await session.init();
    // Theme mode is now a persisted CHOICE (default light), so a dark shot
    // must select dark explicitly rather than lean on platform brightness.
    await session.setThemeMode(b == Brightness.dark ? 'dark' : 'light');
    // The app ships in two scripts and only one was pinned, so Thaana layout
    // bugs — text overflowing its box, an amount splitting from its currency
    // word — reached the owner instead of a golden (2026-08-21).
    await session.setLocale(locale);
    if (!signedOut) {
      await session.saveSession(
        token: 't',
        customerCode: '374230',
        customerName: 'Aishath Ali',
      );
    }

    await tester.pumpWidget(ProviderScope(
      overrides: [
        secretStoreProvider.overrideWithValue(store),
        sessionProvider.overrideWithValue(session),
        apiProvider.overrideWith((ref) => _ShotApi(session: session)),
        configProvider.overrideWith((ref) async => MobileConfig.fromJson(const {
              'apps': {
                'customer': {
                  'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''}
                }
              },
              'features': {},
            })),
      ],
      child: const ManfaaApp(),
    ));
    // Let boot route to home.
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 120));
    }

    if (tab != null) {
      await tester.tap(find.byIcon(tab));
      for (var i = 0; i < 6; i++) {
        await tester.pump(const Duration(milliseconds: 120));
      }
    }

    // Bundled images (the platform's brand marks) decode ASYNCHRONOUSLY.
    // pumpAndSettle never waits for that, so without this the shot paints an
    // empty box where the logo belongs — which is exactly what these goldens
    // did the moment the wordmark stopped being drawn text. runAsync lets
    // the real decode finish. (The merchant harness has carried this since
    // its bank marks landed.)
    final images = find.byType(Image).evaluate().toList();
    if (images.isNotEmpty) {
      await tester.runAsync(() async {
        for (final element in images) {
          await precacheImage((element.widget as Image).image, element);
        }
      });
      await tester.pumpAndSettle();
    }

    await expectLater(
      find.byType(ManfaaApp),
      matchesGoldenFile('shots/$name.png'),
    );
  }

  testWidgets('signin light',
      (t) => shot(t, 'signin_light', Brightness.light, signedOut: true));
  testWidgets('signin dark',
      (t) => shot(t, 'signin_dark', Brightness.dark, signedOut: true));
  testWidgets('home light', (t) => shot(t, 'home_light', Brightness.light));
  testWidgets('home dhivehi',
      (t) => shot(t, 'home_dv', Brightness.light, locale: 'dv'));
  testWidgets('home dark', (t) => shot(t, 'home_dark', Brightness.dark));
  testWidgets('profile light',
      (t) => shot(t, 'profile_light', Brightness.light,
          tab: Icons.person_outline_rounded));
  testWidgets('profile dark',
      (t) => shot(t, 'profile_dark', Brightness.dark,
          tab: Icons.person_outline_rounded));
  testWidgets('discover light',
      (t) => shot(t, 'discover_light', Brightness.light,
          tab: Icons.explore_outlined));
  testWidgets('discover dark',
      (t) => shot(t, 'discover_dark', Brightness.dark,
          tab: Icons.explore_outlined));
  testWidgets('activity light',
      (t) => shot(t, 'activity_light', Brightness.light,
          tab: Icons.receipt_long_outlined));
  testWidgets('activity dark',
      (t) => shot(t, 'activity_dark', Brightness.dark,
          tab: Icons.receipt_long_outlined));
}
