import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

/// What the logo falls back to, and when.
///
/// Lives in the APP rather than beside the widget in manfaa_ui, and that is
/// the point: the bundled marks are declared in this app's pubspec, so only
/// here does `Image.asset` actually resolve one. A copy of this in the
/// package would pass against an empty bundle and prove nothing.
///
/// The bundled marks are the reason a first launch — or a phone that has
/// never reached the network — shows a brand instead of an empty box.
void main() {
  setUp(() => BrandAssetCache.instance = null);
  tearDown(() => BrandAssetCache.instance = null);

  Future<void> pump(
    WidgetTester tester, {
    required Brightness brightness,
    BrandLogoShape shape = BrandLogoShape.landscape,
    BrandAssetCache? cache,
  }) =>
      tester.pumpWidget(
        MaterialApp(
          theme: ThemeData(brightness: brightness),
          home: Scaffold(body: BrandLogo(cache: cache, shape: shape)),
        ),
      );

  String assetOf(WidgetTester tester) {
    final image = tester.widget<Image>(find.byType(Image));
    return (image.image as AssetImage).assetName;
  }

  testWidgets('falls back to the bundled mark when nothing is cached',
      (tester) async {
    await pump(tester, brightness: Brightness.light);

    expect(assetOf(tester), 'assets/brand/landscape_light.png');
  });

  testWidgets('picks the dark mark on a dark theme', (tester) async {
    await pump(tester, brightness: Brightness.dark);

    expect(assetOf(tester), 'assets/brand/landscape_dark.png');
  });

  // Two tests rather than two pumps in one: re-pumping the same widget type
  // reuses the element, and the second assertion read the first pump's
  // image rather than the rebuilt one.
  testWidgets('picks the square mark for the boot screen', (tester) async {
    await pump(
      tester,
      brightness: Brightness.light,
      shape: BrandLogoShape.square,
    );

    expect(assetOf(tester), 'assets/brand/square_light.png');
  });

  testWidgets('picks the dark square mark on a dark boot screen',
      (tester) async {
    await pump(
      tester,
      brightness: Brightness.dark,
      shape: BrandLogoShape.square,
    );

    expect(assetOf(tester), 'assets/brand/square_dark.png');
  });

  testWidgets('prefers the downloaded mark once one is cached', (tester) async {
    final cache = _StubCache({
      BrandSlot.landscapeLight: Uint8List.fromList(const [1, 2, 3]),
    });

    await pump(tester, brightness: Brightness.light, cache: cache);

    // Downloaded bytes, not the bundled asset.
    expect(find.byType(Image), findsOneWidget);
    expect(tester.widget<Image>(find.byType(Image)).image,
        isA<MemoryImage>());
  });

  testWidgets('falls back per-slot: a cached light mark does not serve dark',
      (tester) async {
    // Only ONE slot downloaded — the other must still find its bundled mark
    // rather than render nothing.
    final cache = _StubCache({
      BrandSlot.landscapeLight: Uint8List.fromList(const [1, 2, 3]),
    });

    await pump(tester, brightness: Brightness.dark, cache: cache);

    expect(assetOf(tester), 'assets/brand/landscape_dark.png');
  });

  testWidgets('uses the process-wide cache when none is passed',
      (tester) async {
    BrandAssetCache.instance = _StubCache({
      BrandSlot.landscapeLight: Uint8List.fromList(const [9, 9, 9]),
    });

    await pump(tester, brightness: Brightness.light);

    expect(tester.widget<Image>(find.byType(Image)).image, isA<MemoryImage>());
  });
}

/// A cache with fixed contents and no network of any kind.
class _StubCache implements BrandAssetCache {
  _StubCache(this._bytes);

  final Map<BrandSlot, Uint8List> _bytes;

  @override
  final ValueNotifier<int> version = ValueNotifier(1);

  @override
  Uint8List? bytesFor(BrandSlot slot) => _bytes[slot];

  @override
  noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
