import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/app/shell.dart' show kFloatingNavBarKey;
import 'package:manfaa_merchant/features/credit/credit_screen.dart'
    show kCreditCtaFlowKey, kCreditCtaTopKey;
import 'package:manfaa_merchant/features/credit/credit_widgets.dart'
    show PendingNote;

/// MR2 tests: the till. The whole app pumped with a fake MerchantApi over
/// the REAL session store and the REAL offline queue — the tests drive the
/// same seams the shipping wiring uses.
void main() {
  const tillPermissions = [
    'credits.create',
    'credits.custom_rate',
    'transactions.view',
    'transactions.amend',
    'transactions.cancel',
  ];

  MobileConfig config() => MobileConfig.fromJson({
    'apps': {
      'merchant': {
        'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
        'ios': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
      },
    },
    'features': const {},
  });

  Future<MemorySecretStore> seededStore(List<String> permissions) async {
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
      permissions: permissions,
    );
    return store;
  }

  Widget app(
    MemorySecretStore store,
    _TillApi Function(MerchantSession) makeApi,
  ) => ProviderScope(
    overrides: [
      secretStoreProvider.overrideWithValue(store),
      apiProvider.overrideWith((ref) => makeApi(ref.watch(sessionProvider))),
      configProvider.overrideWith((ref) async => config()),
    ],
    child: const MerchantApp(),
  );

  /// Pump the whole app, signed in, on a tall surface (the credit form is a
  /// long scroll), and let boot route. Without settlements.view the first
  /// allowed tab IS Credit, so the tests land straight on the till.
  Future<_TillApi> pumpTill(
    WidgetTester tester, {
    List<String> permissions = tillPermissions,
    Size surface = const Size(600, 3000),
    double textScale = 1.0,
    bool gst = false,
  }) async {
    await tester.binding.setSurfaceSize(surface);
    tester.platformDispatcher.textScaleFactorTestValue = textScale;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    final store = await seededStore(permissions);
    late _TillApi api;
    await tester.pumpWidget(
      app(
        store,
        (s) => api = _TillApi(
          session: s,
          permissions: permissions,
          gst: gst,
        ),
      ),
    );
    await tester.pumpAndSettle();
    return api;
  }

  Future<void> enterCode(WidgetTester tester, String code) async {
    await tester.enterText(find.byType(TextField).first, code);
    await tester.pumpAndSettle();
  }

  /// The invoice / eligible / sale fields by tree order (0 is the code
  /// field's invisible TextField).
  Finder field(int index) => find.byType(TextField).at(index);

  FilledButton submitButton(WidgetTester tester) => tester.widget<FilledButton>(
    find.ancestor(
      of: find.text('Credit customer').last,
      matching: find.byType(FilledButton),
    ),
  );

  Future<void> tapSubmit(WidgetTester tester) async {
    await tester.tap(
      find.ancestor(
        of: find.text('Credit customer').last,
        matching: find.byType(FilledButton),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('a complete code looks the customer up and renders the name', (
    tester,
  ) async {
    await pumpTill(tester);

    expect(find.text('Credit customer'), findsWidgets);
    await enterCode(tester, '374230');

    expect(find.text('Ahmed Nazeeh'), findsOneWidget);
    expect(find.text('Verified'), findsOneWidget);

    // An unknown code renders the clean not-found state — and no name.
    await enterCode(tester, '000000');
    expect(find.text("We don't recognise this code"), findsOneWidget);
    expect(find.text('Ahmed Nazeeh'), findsNothing);
  });

  testWidgets('the custom-rate toggle is drawn only with credits.custom_rate', (
    tester,
  ) async {
    await pumpTill(
      tester,
      permissions: const ['credits.create', 'transactions.view'],
    );

    expect(find.text('Custom cashback for this sale'), findsNothing);
    // The split toggle is not permission-gated.
    expect(find.text('Split by category'), findsOneWidget);
  });

  testWidgets('with the permission, a custom rate is raise-only client-side', (
    tester,
  ) async {
    await pumpTill(tester);
    expect(find.text('Custom cashback for this sale'), findsOneWidget);

    // The custom-rate Switch is the first toggle on the form.
    await tester.tap(find.byType(Switch).first);
    await tester.pumpAndSettle();

    // Below the advertised 2% → the too-low hint renders. The override
    // field is the 5th TextField (code, invoice, eligible, sale, override).
    await tester.enterText(field(4), '1.5');
    await tester.pumpAndSettle();
    expect(find.textContaining('This sale already earns 2%'), findsOneWidget);
  });

  testWidgets('split-on hides the eligible field and the rows BECOME the '
      'eligible amount — the sum can no longer disagree (MR8)', (tester) async {
    final api = await pumpTill(tester);

    await enterCode(tester, '374230');
    await tester.enterText(field(1), 'INV-1001');
    await tester.enterText(field(2), '100');
    await tester.pumpAndSettle();

    // Turn the split on (its Switch is the last one on the form).
    await tester.tap(find.byType(Switch).last);
    await tester.pumpAndSettle();
    expect(find.text('Category breakdown'), findsOneWidget);

    // The owner's fix: with the split on there is NO eligible-amount field
    // left to drift from the lines — the rows are the amount.
    expect(find.text('Eligible amount'), findsNothing);

    // Rows are addressed by their own fields, not by form-wide index: the
    // search box carries the search hint, the amount box the MVR prefix.
    Finder searchFields() => find.byWidgetPredicate(
      (w) => w is TextField && w.decoration?.hintText == 'Search categories',
    );
    Finder amountFields() => find.byWidgetPredicate(
      (w) => w is TextField && w.decoration?.prefixText == 'MVR ',
    );

    // Row 1 starts present: pick a category by typing, then tap the option.
    await tester.enterText(searchFields().first, 'fru');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Fruits').last);
    await tester.pumpAndSettle();
    await tester.enterText(amountFields().first, '60');
    await tester.pumpAndSettle();

    // A second row on the Everything-else bucket — every row names its
    // category explicitly, the bucket included, so nothing is implicit.
    await tester.tap(find.text('Add row'));
    await tester.pumpAndSettle();
    await tester.enterText(searchFields().last, 'every');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Everything else').last);
    await tester.pumpAndSettle();
    await tester.enterText(amountFields().last, '40');
    await tester.pumpAndSettle();

    expect(submitButton(tester).onPressed, isNotNull);
    await tapSubmit(tester);

    expect(api.credits, hasLength(1));
    final lines = api.credits.single['lines'] as List<CreditLine>;
    expect(lines, hasLength(2));
    expect(lines.first.category, 'fruits');
    expect(lines.first.amountLaari, 6000);
    expect(lines.last.category, isNull); // the Everything-else bucket
    expect(lines.last.amountLaari, 4000);
    // Derived in the background from the rows, never typed twice.
    expect(api.credits.single['eligible'], 10000);
  });

  testWidgets('an old sale time demands the explicit confirm before '
      'backdated_acknowledged is ever sent', (tester) async {
    final api = await pumpTill(tester);

    await enterCode(tester, '374230');
    await tester.enterText(field(1), 'INV-9');
    await tester.enterText(field(2), '250');
    await tester.pumpAndSettle();

    // Pick a date a month back — certainly beyond window + grace.
    await tester.tap(find.byIcon(Icons.edit_calendar_outlined));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Previous month'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('15').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    // The time picker follows; the default time stands.
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();

    // The warning is up and the credit is NOT one tap away.
    expect(find.text('Backdated sale — this credit is final'), findsOneWidget);
    expect(submitButton(tester).onPressed, isNull);
    expect(api.credits, isEmpty);

    // Tick the confirmation — only now can it be recorded.
    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    expect(submitButton(tester).onPressed, isNotNull);

    await tapSubmit(tester);
    expect(api.credits, hasLength(1));
    expect(api.credits.single['backdated_acknowledged'], isTrue);
    expect(api.credits.single['occurred_at'], endsWith('+05:00'));
  });

  testWidgets(
    'an untouched sale time sends no occurred_at and no acknowledgement',
    (tester) async {
      final api = await pumpTill(tester);

      await enterCode(tester, '374230');
      await tester.enterText(field(1), 'INV-2');
      await tester.enterText(field(2), '1,000.00');
      await tester.pumpAndSettle();
      await tapSubmit(tester);

      expect(api.credits, hasLength(1));
      expect(api.credits.single['occurred_at'], isNull);
      expect(api.credits.single['backdated_acknowledged'], isFalse);
      expect(api.credits.single['eligible'], 100000);
      // The result card is up with the priced figures.
      expect(find.text('Cashback recorded'), findsOneWidget);
    },
  );

  // ---- MR7: hardware keyboard / barcode gun ------------------------------

  testWidgets(
    'scanner-gun Enter walks the till from field to field, and NEVER '
    'finalises the credit',
    (tester) async {
      final api = await pumpTill(tester);

      // The gun types six digits — the lookup fires on the sixth — then
      // sends Enter, which must carry focus on to the invoice field.
      await enterCode(tester, '374230');
      expect(find.text('Ahmed Nazeeh'), findsOneWidget);
      final lookupsAfterCode = api.lookups;

      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(api.lookups, lookupsAfterCode); // verified — no redundant call
      expect(
        tester.widget<TextField>(field(1)).focusNode?.hasFocus,
        isTrue,
      );

      // An INCOMPLETE form stays quiet on Enter — nothing is sent.
      await tester.enterText(field(1), 'INV-77');
      await tester.pumpAndSettle();
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(api.credits, isEmpty);

      // And a COMPLETE form stays quiet too. This is the reversal of MR7
      // (owner report 2026-08-20): on a touch keyboard the return key is the
      // tick in the corner, where a thumb goes to dismiss the keyboard, and
      // cashiers were crediting customers by accident mid-entry.
      await tester.enterText(field(2), '250');
      await tester.pumpAndSettle();
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(
        api.credits,
        isEmpty,
        reason: 'the keyboard must never spend money',
      );

      // It takes the deliberate press.
      await tester.tap(find.text('Credit now').last);
      await tester.pumpAndSettle();
      expect(api.credits, hasLength(1));
      expect(api.credits.single['invoice_no'], 'INV-77');
      expect(find.text('Cashback recorded'), findsOneWidget);
    },
  );

  testWidgets(
    'the return key walks invoice -> eligible -> full sale, then just puts '
    'the keyboard away',
    (tester) async {
      await pumpTill(tester);
      await enterCode(tester, '374230');
      await tester.pumpAndSettle();

      // Enter off the code lands on invoice.
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(tester.widget<TextField>(field(1)).focusNode?.hasFocus, isTrue);

      // invoice -> eligible
      await tester.testTextInput.receiveAction(TextInputAction.next);
      await tester.pumpAndSettle();
      expect(tester.widget<TextField>(field(2)).focusNode?.hasFocus, isTrue);

      // eligible -> full sale
      await tester.testTextInput.receiveAction(TextInputAction.next);
      await tester.pumpAndSettle();
      expect(tester.widget<TextField>(field(3)).focusNode?.hasFocus, isTrue);

      // full sale -> nothing focused, keyboard dismissed
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(tester.widget<TextField>(field(3)).focusNode?.hasFocus, isFalse);
    },
  );

  testWidgets(
    'Enter in the code entry retries a failed lookup; a short code stays '
    'quiet',
    (tester) async {
      final api = await pumpTill(tester);

      // Five digits + Enter: no lookup fires (a gun misfire, a fat finger).
      await enterCode(tester, '37423');
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(api.lookups, 0);

      // The network eats the sixth-digit lookup → the failed notice.
      api.failLookups = true;
      await enterCode(tester, '374230');
      expect(api.lookups, 1);
      expect(find.text('Ahmed Nazeeh'), findsNothing);

      // Enter — the gun scans again — refires the lookup and lands it.
      api.failLookups = false;
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(api.lookups, 2);
      expect(find.text('Ahmed Nazeeh'), findsOneWidget);
    },
  );

  testWidgets(
    'MR11: the heading button and the flow CTA are ONE action — one '
    'disabled state, one submit path',
    (tester) async {
      final api = await pumpTill(tester);

      FilledButton top() =>
          tester.widget<FilledButton>(find.byKey(kCreditCtaTopKey));
      FilledButton flow() =>
          tester.widget<FilledButton>(find.byKey(kCreditCtaFlowKey));

      // Both exist, and an empty till disables BOTH.
      expect(find.byKey(kCreditCtaTopKey), findsOneWidget);
      expect(find.byKey(kCreditCtaFlowKey), findsOneWidget);
      expect(top().onPressed, isNull);
      expect(flow().onPressed, isNull);

      // A half-filled sale still disables both — the gate is shared, not
      // copied.
      await enterCode(tester, '374230');
      await tester.enterText(field(1), 'INV-9001');
      await tester.pumpAndSettle();
      expect(top().onPressed, isNull);
      expect(flow().onPressed, isNull);

      // Completed: both go live together.
      await tester.enterText(field(2), '250');
      await tester.pumpAndSettle();
      expect(top().onPressed, isNotNull);
      expect(flow().onPressed, isNotNull);

      // And the HEADING button submits the same credit the flow one would.
      await tester.tap(find.byKey(kCreditCtaTopKey));
      await tester.pumpAndSettle();
      expect(api.credits, hasLength(1));
      expect(api.credits.single['invoice_no'], 'INV-9001');
    },
  );

  testWidgets('the top bar survives a large accessibility text scale on a '
      'narrow phone — the wordmark shrinks instead of overflowing', (
    tester,
  ) async {
    // Pre-existing before MR11 and only visible at scale: the wordmark grows
    // with the reader's text size and the row had no give, so it overflowed
    // by ~69px at 1.3 on a 390dp frame.
    await pumpTill(tester, surface: const Size(390, 844), textScale: 1.3);

    // A RenderFlex overflow throws in tests — no exception IS the assertion.
    expect(tester.takeException(), isNull);

    // The lockup is still drawn. "Manfaa" is the uploaded landscape mark
    // now rather than a Text, so the image is what proves it, and
    // "Merchant" is the one word of it that is still type.
    expect(find.byType(BrandLogo), findsWidgets);
    expect(find.text('Merchant'), findsWidgets);
  });

  testWidgets(
    'MR11: nothing is pinned — the flow CTA scrolls clear of the floating '
    'nav instead of under it',
    (tester) async {
      // The shipped phone frame, where the old pinned bar overlapped.
      await tester.binding.setSurfaceSize(const Size(390, 844));
      // Surface size leaks between tests unless it is handed back.
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final store = await seededStore(tillPermissions);
      await tester.pumpWidget(
        app(store, (s) => _TillApi(session: s, permissions: tillPermissions)),
      );
      await tester.pumpAndSettle();

      final scrollable = find.byType(Scrollable).first;
      final position = tester.state<ScrollableState>(scrollable).position;
      position.jumpTo(position.maxScrollExtent);
      await tester.pumpAndSettle();

      final ctaBottom = tester.getRect(find.byKey(kCreditCtaFlowKey)).bottom;
      final navTop = tester.getRect(find.byKey(kFloatingNavBarKey)).top;

      // The bar floats over the branch (extendBody). With the list's
      // bottomClearanceOf padding the CTA ends ABOVE it — every pixel of
      // the button is reachable. (740 vs 762 on the 390×844 frame.)
      expect(ctaBottom, lessThanOrEqualTo(navTop));
      // …and it really is the end of the scroll being measured, not some
      // button parked halfway up the page.
      expect(ctaBottom, greaterThan(navTop - 120));
      // …and the pinned bar itself is gone: the CTA lives in the scroll,
      // below the violet pending note.
      expect(
        tester.getRect(find.byType(PendingNote)).bottom,
        lessThanOrEqualTo(tester.getRect(find.byKey(kCreditCtaFlowKey)).top),
      );
    },
  );

  testWidgets('the till holds at 1.3 text scale (no overflow)', (
    tester,
  ) async {
    tester.platformDispatcher.textScaleFactorTestValue = 1.3;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    await pumpTill(tester);

    await enterCode(tester, '374230');
    await tester.enterText(field(1), 'INV-1001');
    await tester.enterText(field(2), '1,000.00');
    await tester.pumpAndSettle();
    // Any RenderFlex overflow would have failed the test by here; the form
    // still stands and the CTA still submits.
    expect(find.text('Ahmed Nazeeh'), findsOneWidget);
  });

  testWidgets(
    'offline, a credit queues with a visible banner; the drain clears it '
    'and replays the ORIGINAL key',
    (tester) async {
      final api = await pumpTill(tester);
      api.offline = true;

      await enterCode(tester, '374230');
      await tester.enterText(field(1), 'INV-77');
      await tester.enterText(field(2), '500');
      await tester.pumpAndSettle();
      await tapSubmit(tester);

      // Queued, not recorded: the pending-sync banner carries the count.
      expect(find.text('1 sale waiting to sync'), findsOneWidget);
      expect(api.credits, isEmpty);
      expect(api.creditKeys, hasLength(1));

      // Connectivity comes back; the drain replays with the SAME key.
      api.offline = false;
      final container = ProviderScope.containerOf(
        tester.element(find.byType(MerchantApp)),
      );
      await container.read(creditQueueProvider.notifier).drain();
      await tester.pumpAndSettle();

      expect(find.text('1 sale waiting to sync'), findsNothing);
      expect(api.credits, hasLength(1));
      expect(api.creditKeys, hasLength(2));
      expect(api.creditKeys.first, api.creditKeys.last);
    },
  );

  testWidgets('amend/cancel actions exist only while awaiting_validation '
      'and never on backdated rows', (tester) async {
    await pumpTill(tester);

    // Walk over to the Transactions tab.
    await tester.tap(find.text('Transactions').last);
    await tester.pumpAndSettle();

    expect(find.text('INV-OPEN'), findsOneWidget);
    expect(find.text('INV-BACKDATED'), findsOneWidget);
    expect(find.text('INV-CONFIRMED'), findsOneWidget);

    // Exactly ONE row (the open, non-backdated one) offers the actions.
    expect(find.text('Correct amount'), findsOneWidget);
    expect(find.text('Cancel sale'), findsOneWidget);
    // The backdated row is labelled instead.
    expect(find.text('Backdated'), findsOneWidget);
  });

  testWidgets('staff without the amend/cancel slugs see no actions at all', (
    tester,
  ) async {
    await pumpTill(
      tester,
      permissions: const ['credits.create', 'transactions.view'],
    );
    await tester.tap(find.text('Transactions').last);
    await tester.pumpAndSettle();

    expect(find.text('INV-OPEN'), findsOneWidget);
    expect(find.text('Correct amount'), findsNothing);
    expect(find.text('Cancel sale'), findsNothing);
  });

  // ---- GST on a recorded sale --------------------------------------------
  //
  // "You pay" has ALWAYS included the tax on Manfaa's fee; until now it
  // never said so. The recorded-credit card names the fee and the tax as
  // two lines, at the rate STAMPED on the row — and shows no tax line at
  // all on a sale that carries none.

  Future<void> recordASale(WidgetTester tester) async {
    await enterCode(tester, '374230');
    await tester.enterText(field(1), 'INV-GST');
    await tester.enterText(field(2), '1,000.00');
    await tester.pumpAndSettle();
    await tapSubmit(tester);
  }

  testWidgets('the recorded sale names the fee and its GST separately', (
    tester,
  ) async {
    await pumpTill(tester, gst: true);
    await recordASale(tester);

    expect(find.text('Cashback recorded'), findsOneWidget);
    // Manfaa's own charge, and the tax on it at the rate the row was
    // stamped with — two rows, never one blended number.
    expect(find.text('Platform fee (0.75%)'), findsOneWidget);
    expect(find.text('MVR\u00A07.50'), findsOneWidget);
    expect(find.text('GST on fee (8%)'), findsOneWidget);
    expect(find.text('MVR\u00A00.60'), findsOneWidget);
    // And the total the till has always shown: 20.00 + 7.50 + 0.60.
    expect(find.text('MVR\u00A028.10'), findsOneWidget);
  });

  testWidgets('a GST-free sale shows no tax line on the recorded card', (
    tester,
  ) async {
    await pumpTill(tester);
    await recordASale(tester);

    expect(find.text('Cashback recorded'), findsOneWidget);
    expect(find.textContaining('GST on fee'), findsNothing);
  });
}

/// Fake MerchantApi over the real session store: the till endpoints answer
/// canned data, createCredit records the exact composition it was handed,
/// and [offline] simulates the dead-network path the queue exists for.
class _TillApi extends MerchantApi {
  _TillApi({
    required super.session,
    required this.permissions,
    this.gst = false,
  });

  final List<String> permissions;

  /// GST switched on at the platform, 8% on top of the fee: a 1,000.00 sale
  /// prices cashback 20.00 and fee 7.50, so the tax is
  /// ceil(750 × 800 / 10000) = 60 laari and the merchant owes 28.10.
  final bool gst;

  var offline = false;
  final credits = <Map<String, Object?>>[];
  final creditKeys = <String>[];

  /// MR7 keyboard tests: how many lookups fired, and whether the next one
  /// should fail like a dead network.
  var lookups = 0;
  var failLookups = false;

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
  Future<CustomerLookup> lookupCustomer(String code) async {
    lookups++;
    if (failLookups) throw MobileApiException.network();
    return code == '374230'
        ? CustomerLookup(valid: true, name: 'Ahmed Nazeeh')
        : CustomerLookup(valid: false);
  }

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
  Future<List<ProductCategory>> productCategories() async => [
    for (final (i, spec) in const [
      ('fruits', 'Fruits', 'rate', '5.00', true),
      ('veggies', 'Veggies', 'rate', '2.50', true),
      ('tobacco', 'Tobacco', 'excluded', null, true),
      ('legacy', 'Legacy', 'rate', '1.00', false),
    ].indexed)
      ProductCategory.fromJson({
        'id': i + 1,
        'slug': spec.$1,
        'name_en': spec.$2,
        'name_dv': null,
        'mode': spec.$3,
        'cashback_rate_percent': spec.$4,
        'active': spec.$5,
        'sort': i,
      }),
  ];

  @override
  Future<MerchantCreditResult> createCredit({
    required String idempotencyKey,
    required String customerCode,
    required String invoiceNo,
    required int eligibleLaari,
    int? saleLaari,
    String? occurredAt,
    String? cashbackRatePercent,
    List<CreditLine>? lines,
    bool backdatedAcknowledged = false,
  }) async {
    creditKeys.add(idempotencyKey);
    if (offline) throw MobileApiException.network();
    credits.add({
      'key': idempotencyKey,
      'customer_code': customerCode,
      'invoice_no': invoiceNo,
      'eligible': eligibleLaari,
      'sale': saleLaari,
      'occurred_at': occurredAt,
      'rate': cashbackRatePercent,
      'lines': lines ?? const <CreditLine>[],
      'backdated_acknowledged': backdatedAcknowledged,
    });
    return MerchantCreditResult(
      transaction: MerchantTransaction.fromJson({
        'id': credits.length,
        'origin': 'manual',
        'invoice_no': invoiceNo,
        'state': 'awaiting_validation',
        'reason_code': null,
        'backdated': false,
        'currency': 'MVR',
        'eligible_laari': eligibleLaari,
        'sale_laari': saleLaari,
        'cashback_rate_percent': cashbackRatePercent ?? '2.00',
        'platform_fee_percent': '0.75',
        'effective_cashback_rate_percent': cashbackRatePercent ?? '2.00',
        'effective_platform_fee_percent': '0.75',
        'cashback_laari': (eligibleLaari * 2 + 99) ~/ 100 ~/ 100 * 100,
        'fee_laari': gst ? 750 : 0,
        'fee_gst_laari': gst ? 60 : 0,
        'fee_gst_percent': gst ? '8.00' : '0.00',
        'fee_treatment': 'on_top',
        'occurred_at': occurredAt ?? '2026-08-17T10:00:00+05:00',
        'received_at': '2026-08-17T10:00:01+05:00',
      }),
      replayed: false,
    );
  }

  Map<String, dynamic> _tx(
    int id,
    String invoice,
    String state, {
    bool backdated = false,
    String? reason,
  }) => {
    'id': id,
    'origin': 'manual',
    'invoice_no': invoice,
    'state': state,
    'reason_code': reason,
    'backdated': backdated,
    'currency': 'MVR',
    'eligible_laari': 100000,
    'sale_laari': null,
    'cashback_rate_percent': '2.00',
    'platform_fee_percent': '0.75',
    'effective_cashback_rate_percent': '2.00',
    'effective_platform_fee_percent': '0.75',
    'cashback_laari': 2000,
    'fee_laari': 750,
    'fee_gst_laari': 60,
    'occurred_at': '2026-08-16T14:00:00+05:00',
    'received_at': '2026-08-16T14:00:01+05:00',
  };

  @override
  Future<CursorPage<MerchantTransaction>> transactions({
    String? cursor,
    String? state,
    int? perPage,
  }) async {
    final rows = [
      MerchantTransaction.fromJson(_tx(1, 'INV-OPEN', 'awaiting_validation')),
      MerchantTransaction.fromJson(
        _tx(
          2,
          'INV-BACKDATED',
          'awaiting_validation',
          backdated: true,
          reason: 'backdated_final',
        ),
      ),
      MerchantTransaction.fromJson(_tx(3, 'INV-CONFIRMED', 'confirmed')),
    ];
    return CursorPage(
      items: [
        for (final row in rows)
          if (state == null || row.state == state) row,
      ],
      nextCursor: null,
      hasMore: false,
    );
  }
}
