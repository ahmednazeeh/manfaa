import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The platform logo, in whichever form the surface needs.
///
/// Reads from [BrandAssetCache] synchronously and falls back to the bundled
/// asset, so it draws on the first frame — no loading state, no placeholder,
/// and no flash of one mark replaced by another. When a superadmin uploads a
/// new logo the cache bumps its version and every [BrandLogo] on screen
/// repaints itself.
///
/// The cache is optional: omit it and the widget uses
/// [BrandAssetCache.instance], which the app sets at boot. Before that — a
/// first launch, or a widget test — it renders the bundled mark, which is
/// exactly what a phone with no cache yet would show.
class BrandLogo extends StatelessWidget {
  const BrandLogo({
    super.key,
    this.cache,
    this.shape = BrandLogoShape.landscape,
    this.height,
    this.semanticLabel,
  });

  final BrandAssetCache? cache;

  /// Landscape for headers; square where a wordmark would be too wide — the
  /// boot screen, and anywhere the mark stands alone above a title.
  final BrandLogoShape shape;

  /// Marks are sized by HEIGHT and keep their own aspect. An uploaded logo's
  /// proportions are not ours to choose, so constraining width would either
  /// squash it or leave it floating in a box.
  final double? height;

  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final slot = switch ((shape, dark)) {
      (BrandLogoShape.landscape, false) => BrandSlot.landscapeLight,
      (BrandLogoShape.landscape, true) => BrandSlot.landscapeDark,
      (BrandLogoShape.square, false) => BrandSlot.squareLight,
      (BrandLogoShape.square, true) => BrandSlot.squareDark,
    };

    // Falls back to the process-wide cache so a header does not have to be
    // handed one; null means a first launch, and the bundled mark answers.
    final source = cache ?? BrandAssetCache.instance;

    if (source == null) return _image(slot, null);

    return ValueListenableBuilder<int>(
      valueListenable: source.version,
      builder: (context, _, _) => _image(slot, source.bytesFor(slot)),
    );
  }

  Widget _image(BrandSlot slot, Uint8List? bytes) {
    // gaplessPlayback keeps the previous frame on screen while replacement
    // bytes decode, so a refresh mid-session never blinks.
    if (bytes != null) {
      return Image.memory(
        bytes,
        height: height,
        fit: BoxFit.contain,
        gaplessPlayback: true,
        semanticLabel: semanticLabel,
        // A corrupt cache entry must not put a broken-image glyph in the
        // header; fall through to the mark we shipped.
        errorBuilder: (context, _, _) => _bundled(slot),
      );
    }

    return _bundled(slot);
  }

  Widget _bundled(BrandSlot slot) => Image.asset(
        slot.assetPath,
        height: height,
        fit: BoxFit.contain,
        semanticLabel: semanticLabel,
      );
}

enum BrandLogoShape { landscape, square }
