import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'package:manfaa_customer/features/market/cart_screen.dart';
import 'package:manfaa_customer/features/market/floating_cart.dart';

/// The basket foot was reported as "covers half the screen, and still no
/// next". Both halves of that are measurable, so they are measured here
/// rather than eyeballed.
void main() {
  Cart cartWith({required bool needsAddress, required bool canCheckout}) => Cart(
        subcarts: const [],
        itemsLaari: 38400,
        deliveryLaari: 2500,
        totalPayableLaari: 38400,
        cashbackLaari: 1198,
        storeCount: 3,
        canCheckout: canCheckout,
        needsAddress: needsAddress,
        addressId: needsAddress ? null : 1,
      );

  Widget harness(Cart cart) => MaterialApp(
        theme: manfaaTheme(brightness: Brightness.light, dhivehi: false),
        home: Scaffold(bottomNavigationBar: CheckoutBar(cart: cart)),
      );

  testWidgets('the bar stays a bar on a small phone', (tester) async {
    // A deliberately cramped device: 360x640 is the floor we support.
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      harness(cartWith(needsAddress: false, canCheckout: true)),
    );

    final height = tester.getSize(find.byType(CheckoutBar)).height;

    // It used to be reported as covering half the screen. A quarter of a
    // short phone is a generous ceiling for two lines and a button.
    expect(height, lessThan(640 * 0.25));
  });

  testWidgets('a basket with no address offers the way to add one',
      (tester) async {
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      harness(cartWith(needsAddress: true, canCheckout: false)),
    );

    // The dead end this replaced: Checkout disabled, and the address form
    // reachable only THROUGH checkout.
    final button = find.widgetWithText(FilledButton, 'Add delivery address');
    expect(button, findsOneWidget);
    expect(tester.widget<FilledButton>(button).onPressed, isNotNull);
  });

  testWidgets('an unmet minimum says so instead of going dead quietly',
      (tester) async {
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      harness(cartWith(needsAddress: false, canCheckout: false)),
    );

    expect(find.text('Add more to meet a shop minimum'), findsOneWidget);
  });

  floatingCartTests();

  testWidgets('nothing overflows at a large text scale', (tester) async {
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(textScaler: TextScaler.linear(1.6)),
        child: harness(cartWith(needsAddress: false, canCheckout: true)),
      ),
    );

    expect(tester.takeException(), isNull);
  });
}

/// The black floating bar was reported wrapping "1 letter per line" — the
/// signature of an [Expanded] starved to a few pixels by fixed-width
/// siblings. A per-character wrap makes the bar tall, so HEIGHT is the
/// measurement that catches it.
void floatingCartTests() {
  DeliveryTerms shortOf() => DeliveryTerms(
        delivers: true,
        feeLaari: 2500,
        feeWaived: false,
        freeDeliveryOverLaari: null,
        orderMinimumLaari: 20000,
        minimumMet: false,
        shortfallLaari: 6600,
        toFreeDeliveryLaari: null,
        etaMin: 30,
        etaMax: 60,
      );

  // A SizedBox gives TIGHT width, so the test measures how the bar behaves
  // at a given width rather than how a Scaffold happens to constrain it.
  Widget harness({DeliveryTerms? terms, double width = 360}) => MaterialApp(
        theme: manfaaTheme(brightness: Brightness.light, dhivehi: false),
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: width,
              child: FloatingCartBar(
                count: 3,
                totalLaari: 13400,
                earnLaari: 268,
                terms: terms,
                onTap: () {},
              ),
            ),
          ),
        ),
      );

  for (final width in [320.0, 360.0, 411.0]) {
    testWidgets('the black bar does not wrap per letter at ${width}dp',
        (tester) async {
      await tester.pumpWidget(harness(terms: shortOf(), width: width));

      expect(tester.takeException(), isNull);

      // Two text lines, a button and a progress track. Anything approaching
      // a per-character wrap blows well past this.
      final height = tester.getSize(find.byType(FloatingCartBar)).height;
      expect(height, lessThan(160), reason: 'bar grew tall at ${width}dp');

      // And the figures must still be one line each.
      expect(find.textContaining('3 items'), findsOneWidget);
      expect(find.textContaining('Earn'), findsOneWidget);
    });
  }

  testWidgets('the black bar survives a large text scale', (tester) async {
    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(
          size: Size(320, 720),
          textScaler: TextScaler.linear(1.5),
        ),
        child: harness(terms: shortOf(), width: 320),
      ),
    );

    expect(tester.takeException(), isNull);
  });

  testWidgets('with no minimum the bar is a single line of content',
      (tester) async {
    await tester.pumpWidget(harness());

    expect(find.byType(LinearProgressIndicator), findsNothing);
  });
}
