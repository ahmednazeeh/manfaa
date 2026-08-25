import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';

/// MR5 contract tests — the More estate's store-identity half:
///
///  - menu rows are DRAWN from the flat permission set (absent slug → row
///    absent; the server enforces regardless);
///  - the profile editor's "same as contact" tick is a COMPARISON and sends
///    the CONTACT value itself as `support_phone`;
///  - a rate change travels as the EXACT percent string typed.
void main() {
  MobileConfig config() => MobileConfig.fromJson({
    'apps': {
      'merchant': {
        'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
      },
    },
    'features': const {},
  });

  Future<(_FakeApi, WidgetTester)> boot(
    WidgetTester tester, {
    required List<String> permissions,
    Map<String, dynamic>? profileJson,
  }) async {
    // A tall surface so every form control is on screen — these tests are
    // about wire contracts, not scrolling.
    await tester.binding.setSurfaceSize(const Size(600, 2400));
    tester.view.physicalSize = const Size(600, 2400);
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
    final api = _FakeApi(
      session: session,
      permissions: permissions,
      profileJson: profileJson,
    );

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
    return (api, tester);
  }

  testWidgets(
    'More draws only the rows the role holds — absent slug, absent row',
    (tester) async {
      // A cashier: till permissions only. More is still reachable (it always
      // is — sign-out lives there), but the management rows do not exist.
      await boot(tester, permissions: const ['credits.create']);
      await tester.tap(find.text('More'));
      await tester.pumpAndSettle();

      expect(find.text('Log out'), findsOneWidget);
      expect(find.text('Profile'), findsNothing);
      expect(find.text('Manage Employees'), findsNothing);
      expect(find.text('Roles'), findsNothing);
      expect(find.text('Manage Branches'), findsNothing);
      expect(find.text('Cashback Settings'), findsNothing);
      expect(find.text('Promotions'), findsNothing);
      expect(find.text('View profile'), findsNothing);
    },
  );

  testWidgets('a single held slug earns exactly its door', (tester) async {
    // profile.view only → More IS the landing tab (no other tab is held).
    await boot(tester, permissions: const ['profile.view']);

    // MR12 (owner report): the profile has ONE door — the identity card's
    // "View profile". The duplicate menu row is gone, so holding the slug
    // must still open the screen, and nothing else may appear.
    expect(find.text('View profile'), findsOneWidget);
    expect(find.text('Profile'), findsNothing);
    expect(find.text('Manage Employees'), findsNothing);
    expect(find.text('Cashback Settings'), findsNothing);
  });

  testWidgets('preferences.update alone still earns the Cashback Settings row '
      '(the merged screen self-gates any-of)', (tester) async {
    await boot(tester, permissions: const ['preferences.update']);

    expect(find.text('Cashback Settings'), findsOneWidget);
  });

  testWidgets(
    'support-phone tick is BY COMPARISON and sends the contact value',
    (tester) async {
      final (api, _) = await boot(
        tester,
        permissions: const ['profile.view', 'profile.edit', 'setup.view'],
      );

      await tester.tap(find.text('View profile'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Edit'));
      await tester.pumpAndSettle();

      // support_phone equals contact_phone in the fixture → ticked, and no
      // separate support field is shown.
      final checkbox = tester.widget<Checkbox>(find.byType(Checkbox));
      expect(checkbox.value, isTrue);

      // Change the CONTACT number; the tick means "same", so the save must
      // carry the NEW contact value as support_phone — never null, never the
      // stale copy.
      await tester.enterText(find.byType(TextField).at(3), '+9607000000');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save profile'));
      await tester.pumpAndSettle();

      expect(api.profilePatches, hasLength(1));
      final patch = api.profilePatches.single;
      expect(patch['contact_phone'], '+9607000000');
      expect(patch['support_phone'], '+9607000000');
      // Category untouched → the PATCH carries NO category key at all.
      expect(patch.containsKey('category'), isFalse);
    },
  );

  testWidgets('the profile edit sends the description it was given', (
    tester,
  ) async {
    final (api, _) = await boot(
      tester,
      permissions: const ['profile.view', 'profile.edit', 'setup.view'],
    );

    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
    // The view screen prints the store's own words before anything is
    // edited — the field is READ here, not only written.
    expect(
      find.text('A neighbourhood grocery on Majeedhee Magu, open daily.'),
      findsOneWidget,
    );

    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    // 0 name, 1 Thaana name, 2 contact email, 3 contact phone, 4 website,
    // 5 description (the support field is hidden while the tick holds).
    final description = find.byType(TextField).at(5);
    expect(
      tester.widget<TextField>(description).controller!.text,
      'A neighbourhood grocery on Majeedhee Magu, open daily.',
    );

    await tester.enterText(
      description,
      'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save profile'));
    await tester.pumpAndSettle();

    expect(
      api.profilePatches.single['description'],
      'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
    );
  });

  testWidgets('181 words never leaves the profile editor', (tester) async {
    final (api, _) = await boot(
      tester,
      permissions: const ['profile.view', 'profile.edit', 'setup.view'],
    );

    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byType(TextField).at(5),
      List.filled(181, 'word').join(' '),
    );
    await tester.pumpAndSettle();
    expect(find.text('181 / 180 words'), findsOneWidget);

    await tester.tap(find.text('Save profile'));
    await tester.pumpAndSettle();

    expect(api.profilePatches, isEmpty);
    expect(
      find.text('That is over 180 words. Shorten it to continue.'),
      findsOneWidget,
    );
  });

  testWidgets('a DIFFERENT support number unticks the comparison box', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const ['profile.view', 'profile.edit', 'setup.view'],
      profileJson: {..._profileFixture, 'support_phone': '+9603334455'},
    );

    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    final checkbox = tester.widget<Checkbox>(find.byType(Checkbox));
    expect(checkbox.value, isFalse);
  });

  testWidgets('the rate change travels as the EXACT percent string typed', (
    tester,
  ) async {
    final (api, _) = await boot(
      tester,
      permissions: const ['rate.view', 'rate.update'],
    );

    await tester.tap(find.text('Cashback Settings'));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.edit_outlined));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).last, '2.50');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Change rate'));
    await tester.pumpAndSettle();

    expect(api.ratePosts, ['2.50']);
    // And the server's own timing answer is rendered — never re-derived.
    expect(find.text('Cashback rate is now 2.5%'), findsOneWidget);
  });
}

const _profileFixture = <String, dynamic>{
  'id': 7,
  'name': 'Tropical Mart',
  'name_dv': null,
  'slug': 'tropical-mart',
  'status': 'active',
  'category': 'grocery',
  'category_retired': false,
  'channel': 'in_store',
  'eligibility_basis': 'Everything except tobacco.',
  'description': 'A neighbourhood grocery on Majeedhee Magu, open daily.',
  'contact_email': 'hello@tropicalmart.mv',
  'contact_phone': '+9607781234',
  'support_phone': '+9607781234',
  'website_url': null,
};

class _FakeApi extends MerchantApi {
  _FakeApi({
    required super.session,
    required this.permissions,
    Map<String, dynamic>? profileJson,
  }) : profileJson = profileJson ?? _profileFixture;

  // The guided setup is not live in this fixture, so the shell's chip and
  // the Dashboard's tour prompt draw nothing and every assertion below is
  // about the screen it is about. Overridden rather than inherited because
  // the base class would reach the NETWORK from a unit test.
  @override
  Future<MerchantOnboardingGuide> onboarding() async =>
      MerchantOnboardingGuide.hidden;

  final List<String> permissions;
  final Map<String, dynamic> profileJson;

  /// Every PATCH /merchant/profile body, verbatim.
  final profilePatches = <Map<String, dynamic>>[];

  /// Every POST /merchant/rate percent string, verbatim.
  final ratePosts = <String>[];

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
  Future<MerchantProfile> profile() async =>
      MerchantProfile.fromJson(profileJson);

  @override
  Future<ProfileSaveResult> updateProfile({
    required String name,
    String? nameDv,
    required String channel,
    String? eligibilityBasis,
    String? description,
    String? contactEmail,
    String? contactPhone,
    String? supportPhone,
    String? websiteUrl,
    bool categoryChanged = false,
    String? category,
  }) async {
    profilePatches.add({
      'name': name,
      'name_dv': nameDv,
      'channel': channel,
      'eligibility_basis': eligibilityBasis,
      'description': description,
      'contact_email': contactEmail,
      'contact_phone': contactPhone,
      'support_phone': supportPhone,
      'website_url': websiteUrl,
      if (categoryChanged) 'category': category,
    });
    // The instant half only — this fixture's store queues nothing.
    return ProfileSaveResult(
      profile: MerchantProfile.fromJson({
        ...profileJson,
        'contact_phone': contactPhone,
        'support_phone': supportPhone,
      }),
    );
  }

  @override
  Future<MerchantSetupState> getSetup() async => MerchantSetupState.fromJson(
    const {
      'status': 'active',
      'steps': {'profile': true, 'location': true, 'logo': false, 'rate': true},
      'values': {
        'name': 'Tropical Mart',
        'slug': 'tropical-mart',
        'category': 'grocery',
        'channel': 'in_store',
        'eligibility_basis': 'Everything except tobacco.',
        'description': 'A neighbourhood grocery on Majeedhee Magu, open daily.',
        'contact_email': 'hello@tropicalmart.mv',
        'contact_phone': '+9607781234',
        'support_phone': null,
        'website_url': null,
        'primary_branch': null,
        'logo_url': null,
        'cashback_rate_percent': '2.00',
      },
      'rate_bounds': {'min_percent': '0.50', 'max_percent': '10.00'},
      'categories': [
        {
          'slug': 'grocery',
          'name_en': 'Grocery / Supermarket',
          'name_dv': null,
        },
        {'slug': 'dining', 'name_en': 'Dining & Cafés', 'name_dv': null},
      ],
      'submitted_at': null,
      'rejected_reason': null,
    },
  );

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
  Future<RateChangeResult> changeRate(String percent) async {
    ratePosts.add(percent);
    return RateChangeResult(
      rate: MerchantRate.fromJson({
        'current': {
          'cashback_rate_percent': percent,
          'platform_fee_percent': '0.75',
          'all_in_percent': '3.25',
          'effective_from': '2026-08-17T10:00:00+05:00',
          'effective_to': null,
        },
        'pending': null,
      }),
      change: RateChangeSummary.fromJson({
        'previous': {
          'cashback_rate_percent': '2.00',
          'platform_fee_percent': '0.75',
          'all_in_percent': '2.75',
        },
        'new': {
          'cashback_rate_percent': percent,
          'platform_fee_percent': '0.75',
          'all_in_percent': '3.25',
        },
        'effective_at': '2026-08-17T10:00:00+05:00',
        'applies': 'immediately',
        'tier_changed': false,
      }),
    );
  }

  @override
  Future<List<ProductCategory>> productCategories() async => const [];

  @override
  Future<MerchantPreferences> updatePreferences({
    String? settlementMethod,
    int? minEligibleLaari,
    int? validationWindowDays,
    bool? autoSettleFromWallet,
  }) async => MerchantPreferences.fromJson(const {
    'settlement_method': 'bank',
    'min_eligible_laari': 10000,
    'validation_window_days': 3,
    'validation_window_max_days': 3,
  });

  /// GET /merchant/fee-promotion. Defaults to NOTHING RUNNING — the state
  /// every shipped assertion and golden was written against — and is
  /// settable so a test can throw the switch the way a superadmin does.
  MerchantFeePromotion promotion = MerchantFeePromotion.none;

  @override
  Future<MerchantFeePromotion> feePromotion() async => promotion;
}
