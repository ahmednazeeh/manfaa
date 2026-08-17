import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

void main() {
  test('themes build for every brightness and script combination', () {
    for (final brightness in Brightness.values) {
      for (final dhivehi in [true, false]) {
        final theme = manfaaTheme(brightness: brightness, dhivehi: dhivehi);
        expect(theme.colorScheme.brightness, brightness);
      }
    }
  });

  test('a money tone darkens instead of glowing in dark mode', () {
    final light = toneSurface(ToneSurface.pending, Brightness.light);
    final dark = toneSurface(ToneSurface.pending, Brightness.dark);

    expect(light.background.a, 1.0);
    expect(dark.background.a, lessThan(1.0));
    expect(dark.foreground, isNot(equals(light.foreground)));
  });

  testWidgets('the dv widgets delegate turns the tree RTL', (tester) async {
    // THE property the delegate exists for: Flutter ships no dv framework
    // strings, and falling back to English must not fall back to LTR.
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('dv'),
      supportedLocales: const [Locale('dv')],
      localizationsDelegates: dvFallbackDelegates,
      home: Builder(
        builder: (context) => Text(Directionality.of(context).name),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('rtl'), findsOneWidget);
  });
}
