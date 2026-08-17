import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart' show debugDisableShadows;
import 'dart:typed_data';

import 'package:flutter/services.dart' show FontLoader, rootBundle;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_customer/app/app.dart';
import 'package:manfaa_customer/app/providers.dart';

/// Not a test — the MARKETING renderer. Produces the real Home screen as the
/// landing page's hero phone (manfaa.app). Unlike the golden harness, it
/// also merges a monochrome emoji face into the Manrope family (path via
/// MARKETING_EMOJI_FONT) so the greeting's wave renders as a glyph instead
/// of a tofu box. Run:
///   MARKETING_EMOJI_FONT=/path/NotoEmoji.ttf \
///   flutter test test/marketing_shot_test.dart --update-goldens
Future<void> _loadFonts() async {
  final manifest = json.decode(
    await rootBundle.loadString('FontManifest.json'),
  ) as List<dynamic>;

  final emojiPath = Platform.environment['MARKETING_EMOJI_FONT'];

  for (final family in manifest) {
    final loader = FontLoader(family['family'] as String);
    for (final asset in family['fonts'] as List<dynamic>) {
      loader.addFont(rootBundle.load(asset['asset'] as String));
    }
    // Fallback CHAIN, not a merge into Manrope (a variable emoji face
    // appended there hijacked the Latin glyphs): the theme's
    // fontFamilyFallback is ['Faruma'], so appending the emoji face to the
    // FARUMA family makes the wave resolve Manrope → Faruma → emoji while
    // Latin stays pure Manrope.
    if (emojiPath != null && family['family'] == 'Faruma') {
      final bytes = File(emojiPath).readAsBytesSync();
      loader.addFont(Future.value(ByteData.view(bytes.buffer)));
    }
    await loader.load();
  }
}

class _MarketingApi extends ManfaaApi {
  _MarketingApi({required super.session});

  @override
  Future<HomeData> home() async => HomeData.fromJson(const {
        'customer': {'name': 'Aishath', 'customer_code': '374230'},
        'balance': {
          'currency': 'MVR',
          'confirmed_laari': 128450,
          'pending_laari': 40000,
          'paid_this_month_laari': 96000,
        },
        'payout': {
          'minimum_laari': 10000,
          'next_window': {'starts_at': '2026-08-25', 'ends_at': '2026-08-31'},
          // No nag card on the marketing shot — the account is set up.
          'has_account': true,
        },
      });
}

void main() {
  setUpAll(_loadFonts);

  Future<void> shot(WidgetTester tester, String name, Brightness b) async {
    // Golden runs disable real shadows and draw solid grey boxes instead —
    // the exact halo the owner flagged on the landing hero. Marketing shots
    // must look like the device, so real soft shadows are restored here.
    debugDisableShadows = false;
    await tester.binding.setSurfaceSize(const Size(390, 844));
    tester.view.devicePixelRatio = 3.0;
    tester.view.physicalSize = const Size(390 * 3, 844 * 3);
    tester.platformDispatcher.platformBrightnessTestValue = b;

    final store = MemorySecretStore();
    final session = SessionStore(store);
    await session.init();
    await session.setThemeMode(b == Brightness.dark ? 'dark' : 'light');
    await session.saveSession(
      token: 't',
      customerCode: '374230',
      customerName: 'Aishath',
    );

    await tester.pumpWidget(ProviderScope(
      overrides: [
        secretStoreProvider.overrideWithValue(store),
        sessionProvider.overrideWithValue(session),
        apiProvider.overrideWith((ref) => _MarketingApi(session: session)),
        configProvider.overrideWith((ref) async => MobileConfig.fromJson(const {
              'apps': {
                'customer': {
                  'android': {
                    'minimum_build': 1,
                    'latest_build': 1,
                    'store_url': ''
                  }
                }
              },
              'features': {},
            })),
      ],
      child: const ManfaaApp(),
    ));
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 120));
    }

    await expectLater(
      find.byType(ManfaaApp),
      matchesGoldenFile('shots/$name.png'),
    );

    // Restored INSIDE the body: the framework asserts painting debug vars
    // before tearDowns run.
    debugDisableShadows = true;
  }

  testWidgets('marketing home light',
      (t) => shot(t, 'marketing_home_light', Brightness.light));
  testWidgets('marketing home dark',
      (t) => shot(t, 'marketing_home_dark', Brightness.dark));
}
