import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_customer/app/app.dart';
import 'package:manfaa_customer/app/providers.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

/// Foundation tests. Providers are overridden at the seams the foundation
/// built for exactly this: MemorySecretStore instead of the keystore, a
/// canned config instead of the network.
void main() {
  MobileConfig config() => MobileConfig.fromJson({
        'apps': {
          'customer': {
            'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
            'ios': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
          },
        },
        'features': {'customer_claims': false},
      });

  Widget app({MobileConfig Function()? configFactory}) => ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(MemorySecretStore()),
          configProvider.overrideWith(
            (ref) async => (configFactory ?? config)(),
          ),
        ],
        child: const ManfaaApp(),
      );

  testWidgets('boots to sign-in when no session exists', (tester) async {
    await tester.pumpWidget(app());
    await tester.pumpAndSettle();

    expect(find.text('Phone number'), findsOneWidget);
    // The web-port failure mode: no five-tab bar on the sign-in screen.
    expect(find.byType(NavigationBar), findsNothing);
  });

  testWidgets('a build below minimum_build is hard-blocked before sign-in',
      (tester) async {
    await tester.pumpWidget(app(
      configFactory: () => MobileConfig.fromJson({
        'apps': {
          'customer': {
            'android': {
              'minimum_build': 99,
              'latest_build': 99,
              'store_url': 'https://play.example/manfaa',
            },
            'ios': {
              'minimum_build': 99,
              'latest_build': 99,
              'store_url': 'https://apps.example/manfaa',
            },
          },
        },
        'features': const {},
      }),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Update needed'), findsOneWidget);
    expect(find.text('Phone number'), findsNothing);
  });

  onboardingTests();
  activityTests();
  discoverTests();
  devicesTests();
  otpErrorTests();
  payoutAccountTests();
  avatarTests();
  polishTests();

  testWidgets('MoneyText renders laari as the canonical MVR string',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: Scaffold(body: MoneyText(123456)),
    ));

    expect(find.text('MVR 1,234.56'), findsOneWidget);
  });
}

/// Fake API for the onboarding flow — Dart methods are virtual, so the fake
/// simply overrides the network calls and drives the real session store.
class _FakeApi extends ManfaaApi {
  _FakeApi({required super.session});

  var otpRequests = 0;

  @override
  Future<void> requestOtp(String phone) async => otpRequests++;

  MobileApiException? nextVerifyError;
  MobileApiException? nextRegisterError;

  @override
  Future<OtpVerifyOutcome> verifyOtp({
    required String phone,
    required String code,
    required String deviceName,
  }) async {
    if (nextVerifyError != null) {
      final e = nextVerifyError!;
      nextVerifyError = null;
      throw e;
    }
    if (code == '111111') {
      await session.saveSession(
        token: 't-1',
        customerCode: '374230',
        customerName: 'Ahmed',
      );
      return OtpVerifyOutcome.signedIn();
    }
    if (code == '222222') {
      return OtpVerifyOutcome.registrationRequired('signup-tok');
    }
    throw MobileApiException(
      code: 'otp_invalid',
      message: 'server prose',
      status: 422,
    );
  }

  @override
  Future<void> registerWithOtp({
    required String signupToken,
    required String name,
    required String deviceName,
  }) async {
    if (nextRegisterError != null) {
      final e = nextRegisterError!;
      nextRegisterError = null;
      throw e;
    }
    await session.saveSession(
      token: 't-2',
      customerCode: '482917',
      customerName: name,
    );
  }

  @override
  Future<CursorPage<TransactionEntry>> transactions({String? cursor}) async {
    // Two pages: proves the cursor walk and the footer loader.
    if (cursor == null) {
      return CursorPage(
        items: [
          TransactionEntry.fromJson(const {
            'id': 2,
            'merchant': {'name': 'Ocean Café', 'slug': 'ocean-cafe'},
            'invoice_no': 'INV-2',
            'eligible_laari': 100000,
            'cashback_laari': 5000,
            'status': 'pending',
            'status_reason': 'validation_window',
            'occurred_at': '2026-08-16T10:00:00Z',
          }),
        ],
        nextCursor: 'page-2',
        hasMore: true,
      );
    }
    return CursorPage(
      items: [
        TransactionEntry.fromJson(const {
          'id': 1,
          'merchant': {'name': 'Island Mart', 'slug': 'island-mart'},
          'invoice_no': 'INV-1',
          'eligible_laari': 50000,
          'cashback_laari': 2500,
          'status': 'confirmed',
          'status_reason': null,
          'occurred_at': '2026-08-10T10:00:00Z',
        }),
      ],
      hasMore: false,
    );
  }

  @override
  Future<CursorPage<PayoutEntry>> payouts({String? cursor}) async =>
      CursorPage(
        items: [
          PayoutEntry.fromJson(const {
            'id': 9,
            'amount_laari': 12500,
            'status': 'paid',
            'bank': 'BML',
            'account_masked': '•••• 4821',
            'reference': 'PB-20260731',
            'period_start': '2026-07-01',
            'period_end': '2026-07-31',
            'transaction_count': 3,
            'paid_at': '2026-08-02T09:00:00Z',
          }),
        ],
        hasMore: false,
      );

  @override
  Future<PayoutDetail> payoutDetail(int id) async => PayoutDetail.fromJson(const {
        'id': 9,
        'amount_laari': 12500,
        'status': 'paid',
        'bank': 'BML',
        'account_masked': '•••• 4821',
        'reference': 'PB-20260731',
        'period_start': '2026-07-01',
        'period_end': '2026-07-31',
        'paid_at': '2026-08-02T09:00:00Z',
        'transactions': [
          {
            'id': 1,
            'invoice_no': 'INV-1',
            'occurred_at': '2026-07-05T10:00:00Z',
            'eligible_laari': 50000,
            'cashback_laari': 12500,
            'merchant': {'name': 'Island Mart', 'name_dv': null, 'slug': 'island-mart'},
          },
        ],
      });

  @override
  Future<List<DeviceEntry>> devices() async => [
        DeviceEntry.fromJson(const {
          'id': 1,
          'device_name': "Ahmed's iPhone",
          'signed_in_at': '2026-08-01T09:00:00Z',
          'last_used_at': '2026-08-17T08:00:00Z',
          'is_current_device': true,
        }),
        DeviceEntry.fromJson(const {
          'id': 2,
          'device_name': 'Old Android',
          'signed_in_at': '2026-06-01T09:00:00Z',
          'last_used_at': '',
          'is_current_device': false,
        }),
      ];

  var revoked = <int>[];

  @override
  Future<void> revokeDevice(int id) async => revoked.add(id);

  var avatarUploads = 0;
  var avatarRemovals = 0;

  @override
  Future<String?> uploadAvatar({
    required Uint8List bytes,
    required String filename,
  }) async {
    avatarUploads++;
    const url = 'https://cdn.example/avatar.png';
    await session.setAvatarUrl(url);
    return url;
  }

  @override
  Future<void> removeAvatar() async {
    avatarRemovals++;
    await session.setAvatarUrl(null);
  }

  PayoutAccount payout = PayoutAccount.fromJson(const {
    'bank_name': null,
    'account_no': null,
    'account_name': null,
    'has_payout_account': false,
  });
  var payoutOtpRequests = 0;
  Map<String, dynamic>? lastPayoutUpdate;
  var pushDeletes = 0;
  var pushRegisters = 0;

  @override
  Future<void> registerPushToken({
    required String token,
    required String platform,
    int? appBuild,
    String? locale,
  }) async => pushRegisters++;

  @override
  Future<void> deletePushToken(String token) async => pushDeletes++;

  @override
  Future<PayoutAccount> payoutAccount() async => payout;

  @override
  Future<void> requestPayoutAccountOtp() async => payoutOtpRequests++;

  @override
  Future<PayoutAccount> updatePayoutAccount({
    required String bankName,
    required String accountNo,
    required String accountName,
    required String otpCode,
  }) async {
    lastPayoutUpdate = {
      'bank': bankName,
      'account_no': accountNo,
      'otp': otpCode,
    };
    payout = PayoutAccount.fromJson({
      'bank_name': bankName,
      'account_no': accountNo,
      'account_name': accountName,
      'has_payout_account': true,
    });
    return payout;
  }

  @override
  Future<DiscoverFeed> discover({double? lat, double? lng, int? zone}) async =>
      DiscoverFeed.fromJson(const {
        'increased': [
          {
            'name': 'Ocean Café',
            'name_dv': null,
            'slug': 'ocean-cafe',
            'category': 'dining',
            'logo_url': null,
            'channel': 'in_store',
            'cashback_rate_percent': '8.00',
            'standing_cashback_rate_percent': '5.00',
            'promo_ends_at': '2026-08-30T00:00:00Z',
            'distance_m': null,
          },
        ],
        'featured': [
          {
            'name': 'Island Mart',
            'name_dv': null,
            'slug': 'island-mart',
            'category': 'grocery',
            'logo_url': null,
            'channel': 'both',
            'cashback_rate_percent': '5.00',
            'standing_cashback_rate_percent': '5.00',
            'promo_ends_at': null,
            'distance_m': null,
          },
        ],
        'categories': [
          {'slug': 'grocery', 'name_en': 'Grocery', 'name_dv': null, 'icon_url': null, 'count': 4},
        ],
        'offers': [
          {
            'id': 1,
            'kind': 'text',
            'title': 'Fresh week',
            'title_dv': null,
            'blurb': 'Daily essentials, boosted.',
            'blurb_dv': null,
            'badge': 'Limited time',
            'badge_dv': null,
            'image_url': null,
            'ends_at': null,
            'merchant': {
              'name': 'Island Mart',
              'name_dv': null,
              'slug': 'island-mart',
              'category': 'grocery',
              'logo_url': null,
              'channel': 'both',
              'cashback_rate_percent': '10.00',
              'standing_cashback_rate_percent': '5.00',
              'promo_ends_at': null,
            },
          },
        ],
      });

  /// The keys here are the ones DiscoveryService::buildStore() really emits
  /// (`cashback_basis`, `category_rates`) — the fixture is the wire, not the
  /// merchant-side spelling of the same columns.
  ///
  /// Two stores on purpose: Ocean Café has written a description, Island
  /// Mart is one of the stores that predate the field and sends the key not
  /// at all — the case the page must render as absent, not as an empty box.
  @override
  Future<StorePage> store(String slug) async => StorePage.fromJson(
        slug == 'island-mart'
            ? const {
                'name': 'Island Mart',
                'name_dv': null,
                'slug': 'island-mart',
                'category': 'grocery',
                'logo_url': null,
                'channel': 'both',
                'cashback_rate_percent': '5.00',
                'standing_cashback_rate_percent': '5.00',
                'promotion': null,
                'cashback_basis': null,
                'branches': <Map<String, dynamic>>[],
                'category_rates': <Map<String, dynamic>>[],
                'contact_phone': null,
                'website_url': null,
              }
            : const {
                'name': 'Ocean Café',
                'name_dv': null,
                'slug': 'ocean-cafe',
                'category': 'dining',
                'logo_url': null,
                'channel': 'in_store',
                'cashback_rate_percent': '8.00',
                'standing_cashback_rate_percent': '5.00',
                'promo_ends_at': null,
                'description':
                    'A harbour-front café pulling espresso since 2011.',
                'description_dv': null,
                'cashback_basis': 'Food and drinks; excludes tobacco.',
                'branches': [
                  {'name': 'Malé main', 'address': 'Majeedhee Magu'},
                ],
                'category_rates': <Map<String, dynamic>>[],
                'contact_phone': '+9603312345',
                'website_url': null,
              },
      );

  @override
  Future<HomeData> home() async => HomeData.fromJson({
        // Reads the session the sign-in path just wrote, exactly as the real
        // endpoint reflects the authenticated account.
        'customer': {
          'name': session.customerName ?? '',
          'customer_code': session.customerCode ?? '',
        },
        'balance': {
          'currency': 'MVR',
          'confirmed_laari': 0,
          'pending_laari': 0,
          'paid_this_month_laari': 0,
        },
        'payout': {
          'minimum_laari': 10000,
          'next_window': {'starts_at': '2026-08-25', 'ends_at': '2026-08-31'},
          'has_account': true,
        },
      });
}

Widget onboardingApp(ManfaaApi Function(SessionStore) makeApi) {
  final store = MemorySecretStore();

  return ProviderScope(
    overrides: [
      secretStoreProvider.overrideWithValue(store),
      apiProvider.overrideWith((ref) => makeApi(ref.watch(sessionProvider))),
      configProvider.overrideWith(
        (ref) async => MobileConfig.fromJson({
          'apps': {
            'customer': {
              'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
              'ios': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
            },
          },
          'features': const {},
        }),
      ),
    ],
    child: const ManfaaApp(),
  );
}

void onboardingTests() {
  testWidgets('signs an existing customer in: phone → code → home',
      (tester) async {
    await tester.pumpWidget(onboardingApp((s) => _FakeApi(session: s)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('Enter the code'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    // Explicit pumps: the resend countdown holds a live periodic timer, so
    // pumpAndSettle would chase it for its full 60 seconds.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    // Home: the code hero renders from the fake /home payload.
    expect(find.text('374 230'), findsWidgets);
  });

  testWidgets('registers a new customer: phone → code → name → home',
      (tester) async {
    await tester.pumpWidget(onboardingApp((s) => _FakeApi(session: s)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '7799999');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    await tester.enterText(find.byType(TextField).first, '222222');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('What should we call you?'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'Aishath');
    await tester.tap(find.text('Create my account'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('Hi Aishath'), findsOneWidget);
  });

  testWidgets('a wrong code shows the LOCALIZED sentence, not the raw code',
      (tester) async {
    await tester.pumpWidget(onboardingApp((s) => _FakeApi(session: s)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    await tester.enterText(find.byType(TextField).first, '999999');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    // The KNOWN code maps to the app's own string — never snake_case, and
    // not even the server's fallback prose when a translation exists.
    expect(
      find.text("That code isn't right or has expired. Check it, or request a fresh one."),
      findsOneWidget,
    );
    expect(find.textContaining('otp_invalid'), findsNothing);
  });
}


void activityTests() {
  Future<void> signInToHome(WidgetTester tester) async {
    await tester.pumpWidget(onboardingApp((s) => _FakeApi(session: s)));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('activity renders reasons as sentences, never keys',
      (tester) async {
    await signInToHome(tester);

    await tester.tap(find.text('Activity'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('Ocean Café'), findsOneWidget);
    // The KEY became the localized sentence with the merchant filled in.
    expect(
      find.text("You'll earn this once Ocean Café confirms."),
      findsOneWidget,
    );
    expect(find.textContaining('validation_window'), findsNothing);
    expect(find.text('MVR 50.00'), findsOneWidget);
  });

  testWidgets(
      'the balance hints live in tooltips, not as body text under the figures',
      (tester) async {
    await signInToHome(tester);

    const confirmed = 'Confirmed and ready for payout';
    const pending = 'Waiting for store confirmation';

    // Owner, 2026-08-21: both sentences came out of the card and became a
    // long-press hint on the heading instead. They must not render as body
    // text any more...
    expect(find.text(confirmed), findsNothing);
    expect(find.text(pending), findsNothing);

    // ...but they must stay REACHABLE, or the app no longer explains
    // anywhere what "pending" means.
    final messages = tester
        .widgetList<Tooltip>(find.byType(Tooltip))
        .map((t) => t.message)
        .toList();
    expect(messages, contains(confirmed));

    // The pending column — and so its hint — exists only when there IS
    // something pending, and this fixture has none. The home_light and
    // home_dv goldens cover the two-column case.
    expect(messages, isNot(contains(pending)));
  });


  testWidgets('the paid segment lists payouts and opens the detail',
      (tester) async {
    await signInToHome(tester);

    await tester.tap(find.text('Activity'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('Paid out'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('MVR 125.00'), findsOneWidget);

    await tester.tap(find.text('MVR 125.00'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('PB-20260731'), findsOneWidget);
    expect(find.text('1 purchase in this payout'), findsOneWidget);
    expect(find.text('BML •••• 4821'), findsOneWidget);
  });
}


void discoverTests() {
  Future<void> signInAndOpenDiscover(WidgetTester tester) async {
    // The redesigned Discover carries a header, search and featured chrome
    // above the shelves — a taller surface keeps the shelves in the lazy
    // ListView's build window.
    await tester.binding.setSurfaceSize(const Size(430, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(onboardingApp((s) => _FakeApi(session: s)));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('Discover'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('discover renders shelves with the boosted comparison',
      (tester) async {
    await signInAndOpenDiscover(tester);

    expect(find.text('Boosted right now'), findsOneWidget);
    // The boost only lands when the old rate stands beside the new one.
    expect(find.text('8.00%'), findsOneWidget);
    expect(find.text('usually 5.00%'), findsOneWidget);
    // The Featured hero leads with the store itself — its name, and a CTA
    // straight through to the store (owner mockup, 2026-08-23).
    expect(find.text('Featured'), findsWidgets);
    expect(find.text('Island Mart'), findsWidgets);
    expect(find.text('View store'), findsOneWidget);
  });

  testWidgets('the store page shows the eligibility terms — the §11 guard',
      (tester) async {
    await signInAndOpenDiscover(tester);

    await tester.tap(find.text('Ocean Café').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('What earns cashback'), findsOneWidget);
    expect(find.text('Food and drinks; excludes tobacco.'), findsOneWidget);
    expect(find.text('Malé main'), findsOneWidget);
  });

  testWidgets('the store page leads with the store\'s own description',
      (tester) async {
    await signInAndOpenDiscover(tester);

    await tester.tap(find.text('Ocean Café').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('About'), findsOneWidget);
    expect(
      find.text('A harbour-front café pulling espresso since 2011.'),
      findsOneWidget,
    );

    // It reads BEFORE the commercial detail — the store introducing itself
    // sits above the terms card, not below the branches.
    final about = tester.getTopLeft(find.text('About')).dy;
    final terms = tester.getTopLeft(find.text('What earns cashback')).dy;
    expect(about, lessThan(terms));
  });

  testWidgets('a store with no description gets no block at all',
      (tester) async {
    await signInAndOpenDiscover(tester);

    await tester.tap(find.text('Island Mart').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    // The store renders; the About card simply is not there. An empty card
    // would read as a store with nothing to say about itself.
    expect(find.text('Island Mart'), findsWidgets);
    expect(find.text('About'), findsNothing);
  });
}


void devicesTests() {
  testWidgets('the devices screen lists phones and marks this one',
      (tester) async {
    final api = <_FakeApi>[];
    await tester.pumpWidget(onboardingApp((s) {
      final a = _FakeApi(session: s);
      api.add(a);
      return a;
    }));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('Profile'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    // The Appearance card pushed the devices tile past the list's lazy-build
    // extent, so scroll it into existence before tapping.
    await tester.scrollUntilVisible(
      find.text('Signed-in devices'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pump();
    await tester.tap(find.text('Signed-in devices'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text("Ahmed's iPhone"), findsOneWidget);
    expect(find.text('Old Android'), findsOneWidget);
    expect(find.text('This phone'), findsOneWidget);

    // The whole row is the revoke affordance now.
    await tester.tap(find.text('Old Android'));
    await tester.pumpAndSettle();

    // Confirm — scoped to the dialog (the row tooltip shares the label).
    await tester.tap(find.descendant(
      of: find.byType(AlertDialog),
      matching: find.widgetWithText(FilledButton, 'Sign out device'),
    ));
    await tester.pumpAndSettle();

    expect(api.single.revoked, [2]);
    expect(find.text('Sign out everywhere'), findsOneWidget);
  });
}


void otpErrorTests() {
  Future<_FakeApi> toCodeStep(WidgetTester tester) async {
    late _FakeApi api;
    await tester.pumpWidget(onboardingApp((s) {
      api = _FakeApi(session: s);
      return api;
    }));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    return api;
  }

  Future<void> pump(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  // Every KNOWN verify code maps to its own localized sentence — string-arm
  // switches the compiler cannot check, so a deleted arm falls silently to
  // English. One assertion per arm.
  for (final (code, expected) in [
    ('otp_attempts_exceeded',
        'Too many wrong codes. Request a fresh one and try again.'),
    ('account_unavailable',
        "This account can't be signed in right now. Contact Manfaa support."),
    ('rate_limited', 'Too many attempts. Please wait a moment and try again.'),
  ]) {
    testWidgets('verify code $code renders its localized string', (tester) async {
      final api = await toCodeStep(tester);
      api.nextVerifyError =
          MobileApiException(code: code, message: 'raw', status: 422);

      await tester.enterText(find.byType(TextField).first, '000000');
      await tester.tap(find.text('Verify'));
      await pump(tester);

      expect(find.text(expected), findsOneWidget);
      expect(find.textContaining(code), findsNothing);
    });
  }

  testWidgets('a dead signup token bounces the name step back to phone',
      (tester) async {
    final api = await toCodeStep(tester);

    // 222222 → registration_required, so we reach the name step.
    await tester.enterText(find.byType(TextField).first, '222222');
    await tester.tap(find.text('Verify'));
    await pump(tester);
    expect(find.text('What should we call you?'), findsOneWidget);

    api.nextRegisterError = MobileApiException(
        code: 'signup_token_invalid', message: 'raw', status: 422);

    await tester.enterText(find.byType(TextField).first, 'Aishath');
    await tester.tap(find.text('Create my account'));
    await pump(tester);

    // The message shows AND the flow is back at the phone step — not trapped
    // re-tapping Finish against a dead token (finding 1).
    expect(find.text('That verification expired. Start again with your phone number.'),
        findsOneWidget);
    expect(find.text('Phone number'), findsOneWidget);
  });
}


void payoutAccountTests() {
  testWidgets('changing the payout account requires the code — the gate',
      (tester) async {
    // Taller surface so the settings list and the payout form both build
    // fully — the redesign's larger controls push content past 600px.
    await tester.binding.setSurfaceSize(const Size(430, 1800));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    late _FakeApi api;
    await tester.pumpWidget(onboardingApp((s) {
      api = _FakeApi(session: s);
      return api;
    }));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('Profile'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.tap(find.text('Payout account'));
    await tester.pumpAndSettle();

    // Fill the form. The confirm-code field does NOT exist yet — the change
    // cannot be made without proving the phone.
    await tester.enterText(
        find.widgetWithText(TextField, 'Account number'), '7730000012894821');
    await tester.enterText(
        find.widgetWithText(TextField, 'Account holder name'), 'Aishath');
    await tester.pump();
    expect(find.text('Save account'), findsNothing);

    // Send the code → the second step appears.
    await tester.tap(find.text('Send confirmation code'));
    await tester.pumpAndSettle();
    expect(api.payoutOtpRequests, 1);

    // The confirm-code field appeared — the gate is in place.
    expect(find.text('Enter the code we sent to your phone to confirm this change.'),
        findsOneWidget);

    // Enter the code and save. Scroll first: the Save button sits below the
    // fold in the test viewport, and a lazy ListView won't build it until
    // it scrolls near.
    await tester.enterText(find.byType(TextField).last, '123456');
    await tester.pump();
    // Drag the form's own ListView up so its bottom button builds (several
    // Scrollables exist, so scrollUntilVisible can't pick one).
    await tester.drag(
      find.text('Enter the code we sent to your phone to confirm this change.'),
      const Offset(0, -400),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save account'));
    await tester.pumpAndSettle();

    expect(api.lastPayoutUpdate?['otp'], '123456');
    expect(api.lastPayoutUpdate?['account_no'], '7730000012894821');
  });
}


void avatarTests() {
  testWidgets('the profile photo sheet offers remove only once a photo exists',
      (tester) async {
    late _FakeApi api;
    await tester.pumpWidget(onboardingApp((s) {
      api = _FakeApi(session: s);
      return api;
    }));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('Profile'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    // The identity avatar is the tap target; the pencil badge marks it.
    await tester.tap(find.byIcon(Icons.edit_rounded));
    await tester.pumpAndSettle();

    // No photo yet: choose is offered, remove is not.
    expect(find.text('Choose photo'), findsOneWidget);
    expect(find.text('Remove photo'), findsNothing);

    // Dismiss via the barrier.
    await tester.tapAt(const Offset(10, 100));
    await tester.pumpAndSettle();

    // A photo appears (as if uploaded from another surface) — the session
    // bump repaints, and the sheet now offers remove.
    await api.session.setAvatarUrl('https://cdn.example/avatar.png');
    await tester.pump();

    await tester.tap(find.byIcon(Icons.edit_rounded));
    await tester.pumpAndSettle();
    expect(find.text('Remove photo'), findsOneWidget);

    await tester.tap(find.text('Remove photo'));
    await tester.pumpAndSettle();

    expect(api.avatarRemovals, 1);
    expect(api.session.avatarUrl, isNull);
    expect(find.text('Profile picture removed.'), findsOneWidget);
  });
}

Widget themedApp(Locale locale, Brightness brightness) {
  final store = MemorySecretStore();
  return ProviderScope(
    overrides: [
      secretStoreProvider.overrideWithValue(store),
      apiProvider.overrideWith((ref) => _FakeApi(session: ref.watch(sessionProvider))),
      configProvider.overrideWith((ref) async => MobileConfig.fromJson({
            'apps': {'customer': {'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''}, 'ios': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''}}},
            'features': const {},
          })),
      localeProvider.overrideWith(() => _FixedLocale(locale)),
    ],
    child: const ManfaaApp(),
  );
}

class _FixedLocale extends LocaleController {
  _FixedLocale(this._locale);
  final Locale _locale;
  @override
  Locale build() => _locale;
}

void polishTests() {
  for (final locale in const [Locale('en'), Locale('dv')]) {
    for (final brightness in Brightness.values) {
      testWidgets('sign-in renders: ${locale.languageCode} / ${brightness.name}',
          (tester) async {
        tester.platformDispatcher.platformBrightnessTestValue = brightness;
        addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

        await tester.pumpWidget(themedApp(locale, brightness));
        await tester.pumpAndSettle();

        expect(find.byType(TextField), findsWidgets);
        final dir =
            Directionality.of(tester.element(find.byType(TextField).first));
        expect(dir,
            locale.languageCode == 'dv' ? TextDirection.rtl : TextDirection.ltr);
      });
    }
  }

  testWidgets('large text scale does not overflow the sign-in screen',
      (tester) async {
    tester.platformDispatcher.textScaleFactorTestValue = 1.6;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

    await tester.pumpWidget(themedApp(const Locale('en'), Brightness.light));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
  });

  testWidgets('the notifications toggle flips the push preference',
      (tester) async {
    // Taller surface so the settings list builds and taps land without
    // fighting a lazy ListView.
    await tester.binding.setSurfaceSize(const Size(430, 1800));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    late _FakeApi api;
    await tester.pumpWidget(onboardingApp((s) {
      api = _FakeApi(session: s);
      return api;
    }));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField).first, '111111');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('Profile'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(api.session.pushEnabled, isTrue);
    // The Appearance card pushed the toggle below the fold on the test
    // viewport — bring it into view before tapping.
    await tester.ensureVisible(find.byType(Switch));
    await tester.pump();
    await tester.tap(find.byType(Switch));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(api.session.pushEnabled, isFalse);
  });
}
