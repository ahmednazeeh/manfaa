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
/// signal is exactly where this screen gets opened. The QR sits on a HARD
/// white plate with hard-black modules whatever the theme: scanners need
/// dark modules on a light quiet zone, so those colours are never themed.
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
    ScreenBrightness.instance.resetApplicationScreenBrightness().catchError(
      (_) {},
    );
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final code = ref.watch(sessionProvider).customerCode ?? '';
    final qrSize = (MediaQuery.sizeOf(context).width * 0.62).clamp(
      180.0,
      320.0,
    );

    return Scaffold(
      // Themed AppBar: bold title, and the fullscreen-dialog route supplies
      // the close affordance.
      appBar: AppBar(title: Text(l10n.qrTitle)),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(
              Gap.xxl,
              Gap.md,
              Gap.xxl,
              Gap.huge,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const ManfaaWordmark(),
                const SizedBox(height: Gap.xxl),
                _QrPlate(code: code, qrSize: qrSize),
                const SizedBox(height: Gap.xxl),
                Text(
                  l10n.showAtTill,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: Gap.xs),
                Text(
                  l10n.qrHint,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The scannable surface: HARD white, generous quiet-zone padding, hard-black
/// modules, and the grouped code in hard ink — none of it themed, because a
/// scanner (and a squinting cashier) needs dark-on-white in every theme.
class _QrPlate extends StatelessWidget {
  const _QrPlate({required this.code, required this.qrSize});

  final String code;
  final double qrSize;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(Gap.xxl, Gap.xxl, Gap.xxl, Gap.xl),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(Corner.sheet),
        border: Border.all(color: ManfaaColors.line),
        boxShadow: const [
          BoxShadow(
            color: Color(0x141A1F36),
            blurRadius: 24,
            offset: Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          QrImageView(
            data: code,
            size: qrSize,
            padding: EdgeInsets.zero,
            backgroundColor: Colors.white,
            eyeStyle: const QrEyeStyle(
              eyeShape: QrEyeShape.square,
              color: Colors.black,
            ),
            dataModuleStyle: const QrDataModuleStyle(
              dataModuleShape: QrDataModuleShape.square,
              color: Colors.black,
            ),
          ),
          const SizedBox(height: Gap.xl),
          Text(
            code.length == 6
                ? '${code.substring(0, 3)} ${code.substring(3)}'
                : code,
            textDirection: TextDirection.ltr,
            style: const TextStyle(
              fontSize: 40,
              fontWeight: FontWeight.w800,
              letterSpacing: 6,
              color: ManfaaColors.ink,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}
