import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'package:manfaa_customer/features/market/cart_screen.dart';

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
