import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:screen_brightness/screen_brightness.dart';

import '../../app/app.dart';
import '../../app/providers.dart';

/// The till moment, fullscreen.
///
/// Reads the code from the SESSION CACHE, not the network — a shop with no
/// signal is exactly where this screen gets opened. Always white behind the
/// QR whatever the theme: scanners need dark modules on a light plate.
///
/// Brightness is forced UP while this screen shows and restored on the way
/// out (R2): a till scanner reads a dim screen slowly or not at all, and
/// nobody should be fumbling with brightness controls while a queue waits.
/// Guarded throughout — tests and desktop dev runs have no brightness
/// channel, and the QR must render regardless.
class QrScreen extends ConsumerStatefulWidget {
  const QrScreen({super.key});

  @override
  ConsumerState<QrScreen> createState() => _QrScreenState();
}

class _QrScreenState extends ConsumerState<QrScreen> {
  @override
  void initState() {
    super.initState();
    ScreenBrightness.instance
        .setApplicationScreenBrightness(1.0)
        .catchError((_) {});
  }

  @override
  void dispose() {
    ScreenBrightness.instance
        .resetApplicationScreenBrightness()
        .catchError((_) {});
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final code = ref.watch(sessionProvider).customerCode ?? '';
    final size = MediaQuery.sizeOf(context).width * 0.72;

    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: Colors.white,
        foregroundColor: ManfaaColors.stone900,
        title: Text(l10n.qrTitle),
      ),
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            QrImageView(
              data: code,
              size: size,
              backgroundColor: Colors.white,
            ),
            const SizedBox(height: Gap.huge),
            Text(
              code.length == 6
                  ? '${code.substring(0, 3)} ${code.substring(3)}'
                  : code,
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                fontSize: 44,
                fontWeight: FontWeight.w800,
                letterSpacing: 6,
                color: ManfaaColors.stone900,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
            const SizedBox(height: Gap.md),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: Gap.huge),
              child: Text(
                l10n.qrHint,
                textAlign: TextAlign.center,
                style: const TextStyle(color: ManfaaColors.stone500),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
