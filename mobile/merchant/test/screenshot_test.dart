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
  // The owner's setup estate (MR1) — the wizard shots need the gates.
  'setup.view',
  'setup.edit',
  'setup.submit',
  'branding.update',
];

/// A believable wizard state per shot: fresh (the profile step), complete
/// (the review step), pending (the waiting screen).
Map<String, dynamic> _setupFixture({
  required String status,
  bool complete = false,
  String? submittedAt,
}) =>
    {
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
        {'slug': 'grocery', 'name_en': 'Grocery / Supermarket', 'name_dv': 'ފިހާރަ'},
        {'slug': 'dining', 'name_en': 'Dining & Cafés', 'name_dv': 'ކެފޭ'},
        {'slug': 'fashion', 'name_en': 'Fashion', 'name_dv': null},
        {'slug': 'electronics', 'name_en': 'Electronics', 'name_dv': null},
        {'slug': 'health', 'name_en': 'Health & Beauty', 'name_dv': null},
        {'slug': 'services', 'name_en': 'Services', 'name_dv': null},
      ],
      'submitted_at': submittedAt,
      'rejected_reason': null,
    };

class _ShotApi extends MerchantApi {
  _ShotApi({required super.session, this.status = 'active', this.setup});

  final String status;
  final Map<String, dynamic>? setup;

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

  @override
  Future<void> requestSignupOtp(String phone) async {}

  @override
  Future<String> verifySignupOtp({
    required String phone,
    required String code,
  }) async =>
      'shot-signup-token';

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

  Future<void> shot(WidgetTester tester, String name, Brightness b,
      {bool signedOut = false,
      String status = 'active',
      Map<String, dynamic>? setup,
      Future<void> Function(WidgetTester tester)? drive}) async {
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
        merchantStatus: status,
        permissions: _permissions,
      );
    }

    await tester.pumpWidget(ProviderScope(
      overrides: [
        secretStoreProvider.overrideWithValue(store),
        sessionProvider.overrideWithValue(session),
        apiProvider.overrideWith(
            (ref) => _ShotApi(session: session, status: status, setup: setup)),
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
    await tester.scrollUntilVisible(find.text('Register your store'), 120,
        scrollable: find.byType(Scrollable).first);
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

  testWidgets('login light',
      (t) => shot(t, 'login_light', Brightness.light, signedOut: true));
  testWidgets('login dark',
      (t) => shot(t, 'login_dark', Brightness.dark, signedOut: true));
  testWidgets('shell light', (t) => shot(t, 'shell_light', Brightness.light));
  testWidgets('shell dark', (t) => shot(t, 'shell_dark', Brightness.dark));

  // ---- MR1: signup + wizard + status --------------------------------------
  testWidgets(
      'signup details light',
      (t) => shot(t, 'signup_details', Brightness.light,
          signedOut: true, drive: driveToSignupDetails));
  testWidgets(
      'wizard profile light',
      (t) => shot(t, 'wizard_profile_light', Brightness.light,
          status: 'draft', setup: _setupFixture(status: 'draft')));
  testWidgets(
      'wizard profile dark',
      (t) => shot(t, 'wizard_profile_dark', Brightness.dark,
          status: 'draft', setup: _setupFixture(status: 'draft')));
  testWidgets(
      'wizard review light',
      (t) => shot(t, 'wizard_review', Brightness.light,
          status: 'draft',
          setup: _setupFixture(status: 'draft', complete: true)));
  testWidgets(
      'status pending light',
      (t) => shot(t, 'status_pending', Brightness.light,
          status: 'pending_review',
          setup: _setupFixture(
              status: 'pending_review',
              complete: true,
              submittedAt: '2026-08-17T09:41:00Z')));
}
