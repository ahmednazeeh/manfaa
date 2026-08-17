import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';

/// Foundation tests. Providers are overridden at the seams the foundation
/// built for exactly this: MemorySecretStore instead of the keystore, a
/// canned config instead of the network, a fake MerchantApi driving the
/// real session store.
void main() {
  MobileConfig config() => MobileConfig.fromJson({
        'apps': {
          'merchant': {
            'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
            'ios': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
          },
        },
        'features': const {},
      });

  Widget app({
    _FakeApi Function(MerchantSession)? makeApi,
    MobileConfig Function()? configFactory,
  }) =>
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(MemorySecretStore()),
          if (makeApi != null)
            apiProvider
                .overrideWith((ref) => makeApi(ref.watch(sessionProvider))),
          configProvider.overrideWith(
            (ref) async => (configFactory ?? config)(),
          ),
        ],
        child: const MerchantApp(),
      );

  Future<void> signIn(WidgetTester tester, {String password = 'secret'}) async {
    await tester.enterText(
        find.byType(TextField).first, 'aminath@tropical.mv');
    await tester.enterText(find.byType(TextField).last, password);
    await tester.tap(find.text('Log in'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('boots to login when no session exists', (tester) async {
    await tester.pumpWidget(app());
    await tester.pumpAndSettle();

    expect(find.text('Merchant login'), findsOneWidget);
    expect(find.text('Log in'), findsOneWidget);
    // No shell chrome on the login screen.
    expect(find.text('Dashboard'), findsNothing);
    // The mock's dead chrome stays omitted.
    expect(find.textContaining('Google'), findsNothing);
    expect(find.textContaining('Forgot'), findsNothing);
  });

  testWidgets('signs in → the shell draws only the tabs the role holds',
      (tester) async {
    await tester.pumpWidget(app(
      makeApi: (s) => _FakeApi(
        session: s,
        permissions: const ['credits.create', 'transactions.view'],
      ),
    ));
    await tester.pumpAndSettle();
    await signIn(tester);

    // No settlements.view → no Dashboard, no Settlements; first allowed
    // tab is Credit, whose placeholder renders.
    expect(find.text('Credit'), findsOneWidget);
    expect(find.text('Transactions'), findsOneWidget);
    expect(find.text('More'), findsOneWidget);
    expect(find.text('Dashboard'), findsNothing);
    expect(find.text('Settlements'), findsNothing);
    expect(find.text('On its way'), findsOneWidget);
  });

  testWidgets('a full role lands on Dashboard with all five tabs',
      (tester) async {
    await tester.pumpWidget(app(makeApi: _FakeApi.new_));
    await tester.pumpAndSettle();
    await signIn(tester);

    for (final label in const [
      'Dashboard', 'Credit', 'Transactions', 'Settlements', 'More',
    ]) {
      expect(find.text(label), findsOneWidget);
    }
  });

  testWidgets('wrong credentials render the localized sentence, not the code',
      (tester) async {
    await tester.pumpWidget(app(makeApi: _FakeApi.new_));
    await tester.pumpAndSettle();
    await signIn(tester, password: 'wrong');

    expect(
      find.text("That email or password isn't right. Check them and try again."),
      findsOneWidget,
    );
    expect(find.textContaining('validation_failed'), findsNothing);
    expect(find.text('Merchant login'), findsOneWidget);
  });

  testWidgets('a store under review is routed to the status screen, not the shell',
      (tester) async {
    await tester.pumpWidget(app(
      makeApi: (s) => _FakeApi(session: s, status: 'pending_review'),
    ));
    await tester.pumpAndSettle();
    await signIn(tester);

    expect(find.text('Your store is under review'), findsOneWidget);
    expect(find.text('Sign out'), findsOneWidget);
    expect(find.text('Dashboard'), findsNothing);
  });

  testWidgets('a build below minimum_build is hard-blocked before sign-in',
      (tester) async {
    await tester.pumpWidget(app(
      configFactory: () => MobileConfig.fromJson({
        'apps': {
          'merchant': {
            'android': {
              'minimum_build': 99,
              'latest_build': 99,
              'store_url': 'https://manfaa.app/app',
            },
            'ios': {
              'minimum_build': 99,
              'latest_build': 99,
              'store_url': 'https://manfaa.app/app',
            },
          },
        },
        'features': const {},
      }),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Update needed'), findsOneWidget);
    expect(find.text('Merchant login'), findsNothing);
  });
}

/// Fake API driving the REAL session store — Dart methods are virtual, so
/// the fake overrides the network calls and nothing else.
class _FakeApi extends MerchantApi {
  _FakeApi({
    required super.session,
    this.permissions = const [
      'settlements.view',
      'settlements.create',
      'credits.create',
      'transactions.view',
    ],
    this.status = 'active',
  });

  // Tear-off-friendly factory with the defaults.
  // ignore: prefer_constructors_over_static_methods
  static _FakeApi new_(MerchantSession session) => _FakeApi(session: session);

  final List<String> permissions;
  final String status;

  @override
  Future<void> signIn({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    if (password != 'secret') {
      throw MobileApiException(
        code: ApiCode.validationFailed,
        message: 'raw server prose',
        status: 422,
      );
    }
    // Like the real payload: no merchant status at sign-in.
    await session.saveSession(
      token: 't-1',
      userName: 'Aminath Waheedha',
      userEmail: email,
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      permissions: permissions,
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
        'status': status,
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
}
