import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/push/push_registrar.dart';

/// MR4 push wiring. Three angles:
///
/// 1. The routing map is a PURE function of the API's `template` data key —
///    tested key by key, because a wrong route is a cashier yanked somewhere
///    surprising mid-credit.
/// 2. The registrar's guards: every entry point must no-op (never throw)
///    where Firebase does not exist — which is every test run, every desktop
///    dev run, and any device that refuses the permission or cannot reach
///    FCM — getToken() fails the same guarded way.
/// 3. The app boots green with the full wiring in app.dart — push failing
///    must never touch the till's real work.
void main() {
  group('routeForPushData', () {
    test('every deadline moment lands on the Settlements tab', () {
      for (final key in const [
        'settlement_due',
        'prompt_discount_expiring',
        'settlement_due_soon',
        'reminder_day10',
        'urgent_day13',
        'due_day15',
      ]) {
        expect(routeForPushData({'template': key}), '/settlements',
            reason: key);
      }
    });

    test('a settlement verdict opens the named settlement', () {
      expect(
        routeForPushData(
            {'template': 'settlement_accepted', 'settlement_id': '44'}),
        '/settlements/44',
      );
      // FCM data values are strings on the wire, but the map is dynamic —
      // an int must route identically.
      expect(
        routeForPushData(
            {'template': 'settlement_rejected', 'settlement_id': 44}),
        '/settlements/44',
      );
    });

    test('a verdict without an id falls back to the Settlements list', () {
      // The live payload today: SendPushNotification sends {template} only.
      expect(routeForPushData({'template': 'settlement_accepted'}),
          '/settlements');
      expect(routeForPushData({'template': 'settlement_rejected'}),
          '/settlements');
      // A garbled id is the same as none — never a /settlements/0 detail.
      expect(
        routeForPushData(
            {'template': 'settlement_accepted', 'settlement_id': 'oops'}),
        '/settlements',
      );
    });

    test('either top-up verdict lands on the wallet, where the claim is', () {
      // The transfer view says we notify you the moment it is confirmed —
      // and `wallet_top_up_received` is that notification. The refusal is
      // the same flow's other verdict, and the wallet is where the refused
      // claim and its reason are listed; both settlement verdicts are
      // routed for exactly this reason.
      expect(
        routeForPushData({'template': 'wallet_top_up_received'}),
        '/wallet',
      );
      expect(
        routeForPushData({'template': 'wallet_top_up_rejected'}),
        '/wallet',
      );
      // The third verdict: credited, for a figure that is not the one they
      // typed. The one a merchant most needs to open.
      expect(
        routeForPushData({'template': 'wallet_top_up_amount_differs'}),
        '/wallet',
      );
    });

    test('a part-paid settlement opens the batch that is still owed', () {
      // The transfer was matched for less than the batch needed. The store
      // still owes, and the settlement's own screen is the only one that
      // says how much.
      expect(
        routeForPushData(
            {'template': 'settlement_partially_paid', 'settlement_id': '7'}),
        '/settlements/7',
      );
      expect(
        routeForPushData({'template': 'settlement_partially_paid'}),
        '/settlements',
      );
    });

    test('a store-change verdict lands on the estate hub (MR9)', () {
      // Profile and Manage Branches both live one tap from /more, and the
      // push carries no kind key to choose between them.
      expect(routeForPushData({'template': 'store_change_approved'}), '/more');
      expect(routeForPushData({'template': 'store_change_rejected'}), '/more');
    });

    test('an unknown or missing template navigates nowhere', () {
      expect(routeForPushData({'template': 'party_time'}), isNull);
      expect(routeForPushData({'template': ''}), isNull);
      expect(routeForPushData(const {}), isNull);
      // A customer-app key leaking to the wrong audience routes nowhere
      // rather than guessing.
      expect(routeForPushData({'template': 'cashback_earned'}), isNull);
    });
  });

  group('PushRegistrar guards', () {
    test('every entry point no-ops without Firebase — nothing throws', () async {
      final container = ProviderContainer(overrides: [
        secretStoreProvider.overrideWithValue(MemorySecretStore()),
      ]);
      addTearDown(container.dispose);

      final registrar = container.read(pushRegistrarProvider);
      final router = GoRouter(routes: [
        GoRoute(path: '/', builder: (_, _) => const SizedBox()),
      ]);
      addTearDown(router.dispose);
      final messenger = GlobalKey<ScaffoldMessengerState>();

      // No Firebase app exists under `flutter test`; each call must return
      // quietly instead of crashing the till. Twice: idempotence is part of
      // the contract (app.dart wires on every MerchantApp build).
      await registrar.ensureRegistered();
      await registrar.ensureRegistered();
      registrar.wireTapRouting(router);
      registrar.wireTapRouting(router);
      registrar.wireForeground(messenger, router);
      registrar.wireForeground(messenger, router);
    });
  });

  group('boot with placeholder Firebase config', () {
    testWidgets('the app boots green — no Firebase, full push wiring',
        (tester) async {
      await tester.pumpWidget(ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(MemorySecretStore()),
          configProvider.overrideWith(
            (ref) async => MobileConfig.fromJson({
              'apps': {
                'merchant': {
                  'android': {
                    'minimum_build': 1,
                    'latest_build': 1,
                    'store_url': '',
                  },
                  'ios': {
                    'minimum_build': 1,
                    'latest_build': 1,
                    'store_url': '',
                  },
                },
              },
              'features': const {},
            }),
          ),
        ],
        child: const MerchantApp(),
      ));
      await tester.pumpAndSettle();

      // Landed on login with the push wiring having run (app.dart calls
      // wireTapRouting + wireForeground on build) and thrown nothing.
      expect(find.text('Merchant login'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
