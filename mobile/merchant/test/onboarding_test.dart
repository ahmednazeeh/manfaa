import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show FontLoader, rootBundle;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/onboarding/guide_chip.dart';
import 'package:manfaa_merchant/features/onboarding/onboarding_providers.dart';
import 'package:manfaa_merchant/features/settlements/settlements_screen.dart';

/// The guided setup on the till (owner, 2026-08-25) — the contracts, not the
/// styling:
///
///  * the tasklist is CHROME, not a Dashboard card, so a cashier (who has no
///    Dashboard tab at all) still gets it;
///  * it is narrowed to what this person may actually do, and the counts
///    describe the rows on screen;
///  * `show` is the whole answer — there is no local dismissed flag, and
///    skipping is permanent, immediate and shared with the website;
///  * every task is real state, so nothing here can be ticked;
///  * the tour points at REAL widgets, skips a step whose target is absent,
///    and can be left at any moment — including with the system back
///    gesture, which deliberately does NOT count as an answer.
/// The bundled fonts, loaded for real.
///
/// Without this every glyph is a full-em square from the test font, which
/// makes Thaana render about three times its true width and turns any dv
/// screen into a false overflow failure. Manrope and Faruma are what the app
/// actually ships, so they are what a layout test has to measure.
Future<void> _loadFonts() async {
  final manifest =
      json.decode(await rootBundle.loadString('FontManifest.json'))
          as List<dynamic>;

  for (final family in manifest) {
    final loader = FontLoader(family['family'] as String);
    for (final asset in family['fonts'] as List<dynamic>) {
      loader.addFont(rootBundle.load(asset['asset'] as String));
    }
    await loader.load();
  }
}

void main() {
  setUpAll(_loadFonts);

  const ownerPermissions = [
    'settlements.view',
    'settlements.create',
    'credits.create',
    'transactions.view',
    'staff.view',
    'staff.invite',
    'setup.submit',
    'bank_account.update',
  ];

  MobileConfig config() => MobileConfig.fromJson({
    'apps': {
      'merchant': {
        'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
      },
    },
    'features': const {},
  });

  /// The server's own tasklist, verbatim from OnboardingGuide::tasks, with
  /// only `finish_setup` done — a store on its second day.
  Map<String, dynamic> guideJson({
    bool show = true,
    bool skipped = false,
    bool expired = false,
    bool tourCompleted = false,
    int daysRemaining = 3,
  }) => {
    'show': show,
    'skipped': skipped,
    'expired': expired,
    'tour_completed': tourCompleted,
    'started_at': '2026-08-23T06:00:00+00:00',
    'expires_at': '2026-08-28T06:00:00+00:00',
    'days_remaining': daysRemaining,
    'window_days': 5,
    'title_en': 'Getting started',
    'title_dv': 'ފެށުމުގެ ފިޔަވަޅުތައް',
    'tasks_done': 1,
    'tasks_total': 5,
    'all_done': false,
    'tasks': show
        ? [
            {
              'key': 'finish_setup',
              'label_en': 'Finish setup and submit your store',
              'label_dv': 'ސެޓަޕް ފުރިހަމަކުރައްވާ',
              'help_en': 'Fill in your store details and submit for review.',
              'help_dv': 'ތަފްޞީލުތައް ފުރިހަމަކުރައްވާ.',
              'done': true,
              'permission': 'setup.submit',
              'target': 'setup',
              'web_path': '/setup',
            },
            {
              'key': 'bank_account',
              'label_en': 'Add your bank account',
              'label_dv': 'ބޭންކް އެކައުންޓް އިތުރުކުރައްވާ',
              'help_en': 'Settlement transfers are matched against it.',
              'help_dv': 'ސެޓްލްމަންޓް ދިމާކުރަނީ މި އެކައުންޓާއެވެ.',
              'done': false,
              'permission': 'bank_account.update',
              'target': 'bank_account',
              'web_path': '/settings/bank-account',
            },
            {
              'key': 'credit_customer',
              'label_en': 'Credit your first customer',
              'label_dv': 'ފުރަތަމަ ކަސްޓަމަރަށް ކްރެޑިޓްކުރައްވާ',
              'help_en': 'Open Credit customer and key in what they spent.',
              'help_dv': 'ކްރެޑިޓް ހުޅުއްވައި އަދަދު ލިޔުއްވާ.',
              'done': false,
              'permission': 'credits.create',
              'target': 'credit',
              'web_path': '/credit',
            },
            {
              'key': 'settle_bill',
              'label_en': 'Settle your first bill',
              'label_dv': 'ފުރަތަމަ ބިލް ސެޓްލްކުރައްވާ',
              'help_en':
                  'Open Settlements, transfer the amount and upload '
                  'the receipt.',
              'help_dv': 'ސެޓްލްމަންޓް ހުޅުއްވައި ރަސީދު އަޕްލޯޑްކުރައްވާ.',
              'done': false,
              'permission': 'settlements.create',
              'target': 'settlements',
              'web_path': '/settlements',
            },
            {
              'key': 'add_staff',
              'label_en': 'Add the people who work your till',
              'label_dv': 'މުވައްޒަފުން އިތުރުކުރައްވާ',
              'help_en': 'Everyone at the counter needs their own account.',
              'help_dv': 'ކޮންމެ ބޭފުޅަކަށް ވަކި އެކައުންޓެއް ބޭނުންވާނެއެވެ.',
              'done': false,
              'permission': 'staff.invite',
              'target': 'staff',
              'web_path': '/settings/staff',
            },
          ]
        : const [],
  };

  Future<_GuideApi> pump(
    WidgetTester tester, {
    List<String> permissions = ownerPermissions,
    Map<String, dynamic>? guide,
    Size size = const Size(390, 844),
    String locale = 'en',
  }) async {
    await tester.binding.setSurfaceSize(size);
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.setLocale(locale);
    await session.saveSession(
      token: 't-1',
      userName: 'Aminath Waheedha',
      userEmail: 'owner@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: permissions,
    );

    late _GuideApi api;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          sessionProvider.overrideWithValue(session),
          apiProvider.overrideWith(
            (ref) => api = _GuideApi(
              session: session,
              permissions: permissions,
              guide: guide ?? guideJson(),
            ),
          ),
          configProvider.overrideWith((ref) async => config()),
        ],
        child: const MerchantApp(),
      ),
    );
    await tester.pumpAndSettle();
    return api;
  }

  Future<void> openSheet(WidgetTester tester) async {
    await tester.tap(find.byKey(kGuideChipKey));
    await tester.pumpAndSettle();
  }

  // ---- the tasklist -------------------------------------------------------

  testWidgets('nothing is drawn once the guide is over', (tester) async {
    final api = await pump(
      tester,
      guide: guideJson(show: false, expired: true),
    );

    expect(find.byKey(kGuideChipKey), findsNothing);
    expect(find.text('New to Manfaa?'), findsNothing);
    // Asked once. `show: false` cannot become true again, so the app never
    // asks again for the rest of the process — which is what makes this
    // read safe to hang off every resume.
    expect(api.reads, 1);
  });

  testWidgets('the chip counts only the rows this person may act on', (
    tester,
  ) async {
    await pump(tester);

    // Five tasks served, five held permissions → five rows, one done.
    expect(find.byKey(kGuideChipKey), findsOneWidget);
    expect(find.text('1/5'), findsOneWidget);

    await openSheet(tester);
    expect(find.text('Getting started'), findsOneWidget);
    expect(find.text('3 days left'), findsOneWidget);
    expect(find.text('1 of 5 done'), findsOneWidget);
    expect(find.textContaining('shows for your first 5 days'), findsOneWidget);
    // The done row collapses to a ticked line; the rest keep the
    // instructional prose, because that prose IS the guidance.
    expect(find.text('Done'), findsOneWidget);
    expect(
      find.text('Open Credit customer and key in what they spent.'),
      findsOneWidget,
    );
  });

  testWidgets('a cashier is told how to credit and nothing about the shop', (
    tester,
  ) async {
    // No Dashboard tab for this person at all — which is exactly why the
    // tasklist lives in the shell chrome and not in a Dashboard card.
    await pump(tester, permissions: const ['credits.create']);

    expect(find.text('Dashboard'), findsNothing);
    expect(find.byKey(kGuideChipKey), findsOneWidget);
    expect(find.text('0/1'), findsOneWidget);

    await openSheet(tester);
    expect(find.text('Credit your first customer'), findsOneWidget);
    expect(find.text('Add your bank account'), findsNothing);
    expect(find.text('Settle your first bill'), findsNothing);
    expect(find.text('0 of 1 done'), findsOneWidget);
  });

  testWidgets('a person with nothing on the list is shown nothing', (
    tester,
  ) async {
    // Read-only staff: every task belongs to somebody else. An empty box
    // would be worse than no box.
    await pump(tester, permissions: const ['transactions.view']);

    expect(find.byKey(kGuideChipKey), findsNothing);
  });

  testWidgets('skipping is permanent, immediate, and needs no re-read', (
    tester,
  ) async {
    final api = await pump(tester);
    await openSheet(tester);

    // Five tasks and their prose do not fit one sheet on a 390×844 phone —
    // Skip lives under the list, where it cannot be pressed by accident.
    await tester.drag(find.byKey(kGuideSheetKey), const Offset(0, -400));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hide this guide'));
    await tester.pumpAndSettle();
    expect(find.text('Hide the setup guide?'), findsOneWidget);
    // The confirmation says out loud that it reaches the website too.
    expect(find.textContaining('not on the Manfaa website'), findsOneWidget);

    await tester.tap(find.text('Hide it'));
    await tester.pumpAndSettle();

    expect(api.skips, 1);
    expect(find.byKey(kGuideChipKey), findsNothing);
    expect(find.byKey(kGuideSheetKey), findsNothing);
    // The POST answered the full state, so nothing followed it.
    expect(api.reads, 1);
  });

  testWidgets('a task with no door in the app offers the website', (
    tester,
  ) async {
    await pump(tester);
    await openSheet(tester);

    // There is no bank-account editor in the till app; the row is not a
    // dead end, it opens the panel page the server named in `web_path`.
    expect(find.text('Add your bank account'), findsOneWidget);
    expect(find.text('Open on the website'), findsOneWidget);
  });

  testWidgets('tapping a task closes the sheet and lands on its screen', (
    tester,
  ) async {
    await pump(tester);
    await openSheet(tester);

    await tester.tap(find.text('Settle your first bill'));
    await tester.pumpAndSettle();

    expect(find.byKey(kGuideSheetKey), findsNothing);
    expect(find.byType(SettlementsScreen), findsOneWidget);
    // ...and the chip is still there, on the tab it just moved to.
    expect(find.byKey(kGuideChipKey), findsOneWidget);
  });

  test('a second person on the same phone does not inherit the first '
      "one's guide", () async {
    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.saveSession(
      token: 't-1',
      userName: 'Aminath Waheedha',
      userEmail: 'owner@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: ownerPermissions,
    );

    late _GuideApi api;
    final container = ProviderContainer(
      overrides: [
        secretStoreProvider.overrideWithValue(store),
        sessionProvider.overrideWithValue(session),
        apiProvider.overrideWith(
          (ref) => api = _GuideApi(
            session: session,
            permissions: ownerPermissions,
            // The owner put it away for good — the state a second person
            // must NOT inherit.
            guide: guideJson(show: false, skipped: true),
          ),
        ),
      ],
    );
    addTearDown(container.dispose);
    final sub = container.listen(onboardingGuideProvider, (_, _) {});
    addTearDown(sub.close);

    await container.read(onboardingGuideProvider.future);
    expect(api.reads, 1);

    // A routine /merchant/me for the SAME person (a role narrowed, say) is
    // not a reason to ask again: the identity is unchanged.
    await session.saveProfile(
      userName: 'Aminath Waheedha',
      userEmail: 'owner@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: const ['credits.create'],
    );
    await container.read(onboardingGuideProvider.future);
    expect(api.reads, 1);

    // A DIFFERENT person signs in. Their five days are their own, and so is
    // the "this is over" — asking again is the only correct answer.
    await session.wipe();
    await session.saveSession(
      token: 't-2',
      userName: 'Hassan Ali',
      userEmail: 'cashier@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: const ['credits.create'],
    );
    await container.read(onboardingGuideProvider.future);
    expect(api.reads, greaterThan(1));
  });

  // ---- the walkthrough ----------------------------------------------------

  testWidgets('the tour walks both journeys and reports itself finished', (
    tester,
  ) async {
    final api = await pump(tester);

    expect(find.text('New to Manfaa?'), findsOneWidget);
    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();

    // Journey one: the Credit tab, then the Dashboard's own way in.
    expect(find.text('Step 1 of 4'), findsOneWidget);
    expect(find.text('Every sale starts here'), findsOneWidget);

    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    expect(find.text('Step 2 of 4'), findsOneWidget);
    expect(find.text('The same door from here'), findsOneWidget);

    // Back really goes back — a walkthrough that only moves forwards is a
    // walkthrough you cannot re-read.
    await tester.tap(find.text('Back'));
    await tester.pumpAndSettle();
    expect(find.text('Step 1 of 4'), findsOneWidget);

    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    // Journey two: what is owed, and where it gets paid.
    expect(find.text('Step 3 of 4'), findsOneWidget);
    expect(find.text('What you owe Manfaa'), findsOneWidget);

    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    expect(find.text('Step 4 of 4'), findsOneWidget);
    expect(find.text('Settle your due bills here'), findsOneWidget);

    await tester.tap(find.text('Got it'));
    await tester.pumpAndSettle();

    expect(find.text('Step 4 of 4'), findsNothing);
    expect(api.tours, 1);
    // Watching the tour is NOT skipping the tasklist: the chip stays.
    expect(find.byKey(kGuideChipKey), findsOneWidget);
    expect(find.text('New to Manfaa?'), findsNothing);
  });

  testWidgets('skipping the tour closes it and stops the offer', (
    tester,
  ) async {
    final api = await pump(tester);
    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Skip tour'));
    await tester.pumpAndSettle();

    expect(find.text('Step 1 of 4'), findsNothing);
    expect(api.tours, 1);
    expect(find.byKey(kGuideChipKey), findsOneWidget);
  });

  testWidgets('the system back gesture leaves the tour and answers nothing', (
    tester,
  ) async {
    final api = await pump(tester);
    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();
    expect(find.text('Step 1 of 4'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();

    // Out — never a trap. But a stray swipe must not silently spend the one
    // offer this person gets, so the state is untouched and the prompt is
    // still there.
    expect(find.text('Step 1 of 4'), findsNothing);
    expect(api.tours, 0);
    expect(find.text('New to Manfaa?'), findsOneWidget);
  });

  testWidgets('a step whose target is absent is skipped, and the count says '
      'so', (tester) async {
    // A manager who may see the money but not work the till: no Credit tab
    // and no Credit card, so the two credit steps have nothing to point at.
    await pump(
      tester,
      permissions: const [
        'settlements.view',
        'settlements.create',
        'transactions.view',
      ],
    );

    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();

    // Two steps, numbered over what is actually there — never "Step 3 of 4"
    // on the first thing a person sees.
    expect(find.text('Step 1 of 2'), findsOneWidget);
    expect(find.text('What you owe Manfaa'), findsOneWidget);
    expect(find.text('Every sale starts here'), findsNothing);

    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    expect(find.text('Step 2 of 2'), findsOneWidget);
    expect(find.text('Settle your due bills here'), findsOneWidget);
  });

  testWidgets('the tour behaves on a small phone', (tester) async {
    // 360×640 — the small Android the shipped app targets. Any overflow in
    // the bubble fails this test on its own.
    //
    // NOT 320: the merchant wordmark in MerchantTopBar already overflows a
    // 320dp header by 16px on every tab screen, which predates this round
    // and is flagged rather than papered over here.
    await pump(tester, size: const Size(360, 640));

    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();

    expect(find.text('Step 1 of 4'), findsOneWidget);
    expect(find.text('Next'), findsOneWidget);
    expect(find.text('Skip tour'), findsOneWidget);

    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    expect(find.text('Step 2 of 4'), findsOneWidget);
  });

  testWidgets('the rail shell puts the chip where the owner asked — bottom '
      'of the left rail', (tester) async {
    // On the ≥840dp slate the app DOES have a sidebar, and there the chip
    // goes literally where it was asked for.
    await pump(tester, size: const Size(1024, 768));

    expect(find.byKey(kGuideChipKey), findsOneWidget);
    final rail = tester.getCenter(find.byKey(kGuideChipKey));
    final screen = tester.getSize(find.byType(MaterialApp));
    // Leading edge, lower half.
    expect(rail.dx, lessThan(screen.width / 4));
    expect(rail.dy, greaterThan(screen.height / 2));

    await openSheet(tester);
    expect(find.text('Getting started'), findsOneWidget);
  });

  testWidgets('in Dhivehi every word is Dhivehi', (tester) async {
    await pump(tester, locale: 'dv');

    // The chip's own label is this app's string; the tasklist's title and
    // its instructional prose are the SERVER's dv, so the two surfaces say
    // the same sentence.
    expect(find.text('ސެޓަޕް ގައިޑް'), findsOneWidget);

    await openSheet(tester);
    expect(find.text('ފެށުމުގެ ފިޔަވަޅުތައް'), findsOneWidget);
    expect(find.text('3 ދުވަސް ބާކީ'), findsOneWidget);
    expect(find.text('ފުރަތަމަ ބިލް ސެޓްލްކުރައްވާ'), findsOneWidget);
    expect(
      find.text('ސެޓްލްމަންޓް ހުޅުއްވައި ރަސީދު އަޕްލޯޑްކުރައްވާ.'),
      findsOneWidget,
    );
    // No English leaked into a dv screen.
    expect(find.text('Getting started'), findsNothing);
    expect(find.text('Settle your first bill'), findsNothing);
  });

  testWidgets('the tour speaks Dhivehi too', (tester) async {
    await pump(tester, locale: 'dv');

    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();

    expect(find.text('ކޮންމެ ވިޔަފާރިއެއް ފަށަނީ މިތަނުން'), findsOneWidget);
    expect(find.text('4 ފިޔަވަޅުން 1 ވަނަ ފިޔަވަޅު'), findsOneWidget);
    expect(find.text('ދެން'), findsOneWidget);
    expect(find.text('ތަޢާރަފް ހުއްޓާލާ'), findsOneWidget);
  });

  testWidgets('"Not now" stops the offer without touching the tasklist', (
    tester,
  ) async {
    final api = await pump(tester);

    await tester.tap(find.byKey(const Key('tour-prompt-dismiss')));
    await tester.pumpAndSettle();

    expect(api.tours, 1);
    expect(api.skips, 0);
    expect(find.text('New to Manfaa?'), findsNothing);
    expect(find.byKey(kGuideChipKey), findsOneWidget);
  });

  testWidgets('the walkthrough stays available from the tasklist afterwards', (
    tester,
  ) async {
    await pump(tester, guide: guideJson(tourCompleted: true));

    // Watched already: no unsolicited prompt...
    expect(find.text('New to Manfaa?'), findsNothing);
    // ...but "Show me how" is still one tap away, forever.
    await openSheet(tester);
    expect(find.text('Show me how'), findsOneWidget);

    await tester.tap(find.text('Show me how'));
    await tester.pumpAndSettle();
    expect(find.text('Step 1 of 4'), findsOneWidget);
  });

  testWidgets('every step keeps its bubble on the screen at an '
      'accessibility text size', (tester) async {
    // 320×568 at 1.6× system text: the ring is round a card that is now
    // much taller, so the room beside it falls under the 120dp the bubble's
    // height budget used to be floored at. Anchored hard to the ring with a
    // budget bigger than the space that existed, the bubble was laid out
    // past the top of the screen and the Stack clipped it — the step's own
    // words, Back, Next and the one labelled way out all went with it.
    tester.platformDispatcher.textScaleFactorTestValue = 1.6;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

    await pump(tester, size: const Size(320, 568));
    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();

    // Walk the whole tour, whatever it resolves to on this screen.
    for (var guard = 0; guard < 8; guard++) {
      expect(find.textContaining('Step '), findsOneWidget);
      // The step is readable and the way out is reachable, on screen.
      final skip = tester.getRect(find.text('Skip tour'));
      expect(skip.top, greaterThanOrEqualTo(0));
      expect(skip.bottom, lessThanOrEqualTo(568));
      // An overflow is an exception, and an exception here is the bug this
      // test exists for.
      expect(tester.takeException(), isNull);

      if (find.text('Next').evaluate().isEmpty) break;
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
    }
  });

  testWidgets('rotating mid-tour re-measures instead of pointing at where '
      'the card used to be', (tester) async {
    await pump(tester, size: const Size(390, 844));
    await tester.tap(find.byKey(const Key('tour-prompt-start')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();

    // The same phone, turned over. The app locks no orientation and Android
    // does not recreate the activity, so this callback is the only notice
    // the tour gets that every anchor has moved.
    const landscape = Size(844, 390);
    await tester.binding.setSurfaceSize(landscape);
    tester.view.physicalSize = landscape;
    await tester.pumpAndSettle();

    // Measured once and never again, the bubble kept its portrait y and sat
    // entirely below a 390dp-tall screen: a dimmed app, a ring in the wrong
    // place, and no visible way out of either.
    final skip = tester.getRect(find.text('Skip tour'));
    expect(skip.top, greaterThanOrEqualTo(0));
    expect(skip.bottom, lessThanOrEqualTo(390));
    // 844×390 is also the width that grows the rail, and a rail that
    // overflows a short window clips its own last tabs.
    expect(tester.takeException(), isNull);
  });

  testWidgets('no walkthrough is offered to an account no step can point at', (
    tester,
  ) async {
    // Staff-only: no Credit tab, no Settlements tab, no Dashboard — so
    // every one of the four steps has nothing to point at, and the engine
    // would answer "dismissed" the moment it opened. The button would close
    // the sheet and do nothing, which reads as broken.
    await pump(tester, permissions: const ['staff.invite', 'staff.view']);

    expect(find.byKey(kGuideChipKey), findsOneWidget);
    await openSheet(tester);

    expect(find.text('Add the people who work your till'), findsOneWidget);
    expect(find.text('Show me how'), findsNothing);
  });
}

/// A fake MerchantApi that serves ONE mutable guide and counts every call —
/// the seam for "the POST answers the full state, so nothing follows it".
class _GuideApi extends MerchantApi {
  _GuideApi({
    required super.session,
    required this.permissions,
    required this.guide,
  });

  final List<String> permissions;

  /// Mutated by the two POSTs below, exactly as the server's own state is —
  /// which is what lets a test prove the write answered the full state.
  Map<String, dynamic> guide;

  var reads = 0;
  var skips = 0;
  var tours = 0;

  @override
  Future<MerchantOnboardingGuide> onboarding() async {
    reads++;
    return MerchantOnboardingGuide.fromJson(guide);
  }

  @override
  Future<MerchantOnboardingGuide> skipOnboarding() async {
    skips++;
    guide = {
      ...guide,
      'show': false,
      'skipped': true,
      'tasks': const <Map<String, dynamic>>[],
    };
    return MerchantOnboardingGuide.fromJson(guide);
  }

  @override
  Future<MerchantOnboardingGuide> completeOnboardingTour() async {
    tours++;
    guide = {...guide, 'tour_completed': true};
    return MerchantOnboardingGuide.fromJson(guide);
  }

  @override
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson({
      'user': {'id': 1, 'name': 'Aminath Waheedha', 'email': 'o@tropical.mv'},
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

  /// `outstanding` is WITHHELD without settlements.view, exactly as the
  /// server withholds it — which is what makes the outstanding card (and so
  /// the tour's third step) genuinely absent for some roles.
  @override
  Future<MerchantHome> home() async => MerchantHome.fromJson({
    'merchant': {'name': 'Tropical Mart', 'status': 'active'},
    'today': {
      'credit_count': 4,
      'eligible_laari': 235000,
      'cashback_laari': 11750,
    },
    'month': {
      'credit_count': 42,
      'eligible_laari': 1845000,
      'cashback_laari': 36900,
      'average_eligible_laari': 43928,
    },
    'outstanding': permissions.contains('settlements.view')
        ? {
            'total': {
              'count': 1,
              'cashback_laari': 2000,
              'fee_laari': 750,
              'fee_gst_laari': 0,
              'payable_laari': 2750,
            },
            'buckets': const <String, dynamic>{},
            'pending_adjustments': {'count': 0, 'credit_laari': 0},
          }
        : null,
    'open_settlement': null,
  });

  @override
  Future<MerchantFeePromotion> feePromotion() async =>
      MerchantFeePromotion.none;

  @override
  Future<SettlementPage> settlements({int page = 1}) async =>
      SettlementPage.fromJson(const {
        'data': <Map<String, dynamic>>[],
        'meta': {'next_cursor': null, 'has_more': false},
      });
}
