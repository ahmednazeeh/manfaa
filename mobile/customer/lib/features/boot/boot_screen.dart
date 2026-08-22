import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';

/// Boot: session in, /config checked, THEN anything else.
///
/// The order is the contract (guide §1): `minimum_build` is the API's only
/// lever to pull a defective build out of service, so it is obeyed before
/// sign-in is even considered. Offline is not an error here — a customer in
/// a shop with no signal still gets their code, so a failed config fetch
/// routes by session and the gate is enforced on the next connected launch.
class BootScreen extends ConsumerStatefulWidget {
  const BootScreen({super.key});

  @override
  ConsumerState<BootScreen> createState() => _BootScreenState();
}

class _BootScreenState extends ConsumerState<BootScreen> {
  @override
  void initState() {
    super.initState();
    _boot();
  }

  Future<void> _boot() async {
    await ref.read(sessionInitProvider.future);

    final session = ref.read(sessionProvider);

    String next;
    try {
      final config = await ref.read(configProvider.future);
      final gate = resolveGate(
        config,
        platform: defaultTargetPlatform == TargetPlatform.iOS
            ? 'ios'
            : 'android',
        build: appBuildNumber,
      );

      if (gate.verdict == UpdateVerdict.required) {
        if (mounted) context.go('/update-required', extra: gate.storeUrl);
        return;
      }

      next = session.signedIn ? '/home' : '/signin';
    } on Exception {
      next = session.signedIn ? '/home' : '/signin';
    }

    if (mounted) context.go(next);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // A calm splash: the square mark on the canvas and a quiet spinner well
    // below — nothing competes with the brand.
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // The square mark, which carries the name itself — the
            // separate title Text below it was a second wordmark stacked
            // under the first.
            const BrandLogo(
              shape: BrandLogoShape.square,
              height: 96,
              semanticLabel: 'Manfaa',
            ),
            const SizedBox(height: Gap.huge),
            SizedBox(
              width: 24,
              height: 24,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The hard stop. No dismissal: a build below `minimum_build` is one the
/// platform has deliberately pulled — usually because it computes or shows
/// money wrongly — and letting it limp on is worse than the friction.
class UpdateRequiredScreen extends StatelessWidget {
  const UpdateRequiredScreen({super.key, required this.storeUrl});

  final String storeUrl;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Scaffold(
      body: Padding(
        padding: const EdgeInsets.all(Gap.huge),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Center(
              child: IconTile(
                Icons.system_update_alt_rounded,
                tint: ManfaaTint.coral,
                size: 64,
                iconSize: 30,
              ),
            ),
            const SizedBox(height: Gap.xxl),
            Text(
              l10n.updateRequiredTitle,
              style: theme.textTheme.headlineMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: Gap.md),
            Text(
              l10n.updateRequiredBody,
              style: theme.textTheme.bodyLarge?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
            if (storeUrl.isNotEmpty) ...[
              const SizedBox(height: Gap.xxl),
              // url_launcher lands with the release round; until then the
              // store link is at least visible and copyable. Links wear the
              // violet accent.
              SelectableText(
                storeUrl,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: ManfaaColors.violet,
                ),
                textAlign: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
