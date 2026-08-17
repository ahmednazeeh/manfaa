import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show FontLoader, rootBundle;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';

/// Not a test — a screenshot harness. `flutter test test/screenshot_test.dart
/// --update-goldens` writes real PNGs of the running screens so the UI can
/// actually be LOOKED at. Loads the bundled fonts so text renders as glyphs,
/// not boxes.
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

const _permissions = [
  'settlements.view',
  'settlements.create',
  'credits.create',
  'credits.custom_rate',
  'transactions.view',
];

class _ShotApi extends MerchantApi {
  _ShotApi({required super.session});

  @override
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson(const {
      'user': {'id': 1, 'name': 'Aminath Waheedha', 'email': 'a@tropical.mv'},
      'merchant': {
        'id': 7,
        'name': 'Tropical Mart',
        'slug': 'tropical-mart',
        'status': 'active',
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
}

void main() {
  setUpAll(_loadFonts);

  Future<void> shot(WidgetTester tester, String name, Brightness b,
      {bool signedOut = false}) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    tester.view.devicePixelRatio = 3.0;
    tester.view.physicalSize = const Size(390 * 3, 844 * 3);
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
        merchantStatus: 'active',
        permissions: _permissions,
      );
    }

    await tester.pumpWidget(ProviderScope(
      overrides: [
        secretStoreProvider.overrideWithValue(store),
        sessionProvider.overrideWithValue(session),
        apiProvider.overrideWith((ref) => _ShotApi(session: session)),
        configProvider.overrideWith((ref) async => MobileConfig.fromJson(const {
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
            })),
      ],
      child: const MerchantApp(),
    ));
    // Let boot route.
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 120));
    }

    await expectLater(
      find.byType(MerchantApp),
      matchesGoldenFile('shots/$name.png'),
    );
  }

  testWidgets('login light',
      (t) => shot(t, 'login_light', Brightness.light, signedOut: true));
  testWidgets('login dark',
      (t) => shot(t, 'login_dark', Brightness.dark, signedOut: true));
  testWidgets('shell light', (t) => shot(t, 'shell_light', Brightness.light));
  testWidgets('shell dark', (t) => shot(t, 'shell_dark', Brightness.dark));
}
