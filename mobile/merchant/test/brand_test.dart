import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_merchant/widgets/merchant_brand.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

/// The merchant lockup reads "Manfaa Merchant" across two different things:
/// a logo IMAGE carrying the word "Manfaa", and a Text carrying "Merchant".
/// A Row centres the image BOX against the text, but the landscape mark's
/// emblem is taller than the word beside it and hangs lower, so the word sits
/// low in its own box — and "Merchant" floated above it (owner, 2026-08-20).
///
/// These pin the correction, because it is invisible to `flutter analyze` and
/// the kind of thing a later refactor drops without noticing.
Future<void> _pump(WidgetTester tester, Brightness brightness) async {
  await tester.pumpWidget(
    MaterialApp(
      // An explicit Theme, not MaterialApp's themeMode: under the test binding
      // MaterialApp keeps resolving against the platform brightness and hands
      // `home` the light theme whatever themeMode says.
      home: Theme(
        data: brightness == Brightness.dark
            ? ThemeData.dark()
            : ThemeData.light(),
        child: Scaffold(
          body: Center(child: MerchantWordmark(key: ValueKey(brightness))),
        ),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  const size = 22.0;
  const logoHeight = size * 1.8;

  testWidgets('"Merchant" sits on the wordmark\'s line, not the box centre',
      (tester) async {
    await _pump(tester, Brightness.light);

    final logo = tester.getCenter(find.byType(BrandLogo));
    final word = tester.getCenter(find.text('Merchant'));

    expect(
      word.dy,
      greaterThan(logo.dy),
      reason: 'centring on the image box floats "Merchant" above "Manfaa"',
    );
    expect(word.dy - logo.dy, closeTo(logoHeight * 0.068, 0.6));
  });

  testWidgets('the dark mark needs a smaller nudge than the light one',
      (tester) async {
    await _pump(tester, Brightness.light);
    final light = tester.getCenter(find.text('Merchant')).dy -
        tester.getCenter(find.byType(BrandLogo)).dy;

    await _pump(tester, Brightness.dark);
    final dark = tester.getCenter(find.text('Merchant')).dy -
        tester.getCenter(find.byType(BrandLogo)).dy;

    // Not a style choice: the light and dark exports carry different internal
    // padding, so the wordmark sits at a different height in each.
    expect(dark, lessThan(light));
    expect(dark, closeTo(logoHeight * 0.040, 0.6));
  });

  testWidgets('the nudge does not make the lockup taller', (tester) async {
    await _pump(tester, Brightness.light);

    // Transform.translate paints the offset without taking layout space, so a
    // header row keeps the logo's height.
    expect(
      tester.getSize(find.byType(MerchantWordmark)).height,
      closeTo(logoHeight, 0.5),
    );
  });
}
