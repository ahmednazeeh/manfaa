import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';

/// The not-trading-yet screen: draft, pending_review and rejected all land
/// here until MR1 ships the real signup + setup wizard (resume the wizard,
/// show the rejection reason, resubmit). For MR0 it is a clean status shell:
/// the hourglass copy for a store under review, and a way out (sign out).
///
/// The router owns entry AND exit: /merchant/me refreshes the status on
/// every launch, and the moment it flips to active the same revision bump
/// walks the merchant into the shell — nothing here needs a refresh button.
class SetupPendingScreen extends ConsumerWidget {
  const SetupPendingScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Align(
                alignment: AlignmentDirectional.centerStart,
                child: MerchantWordmark(),
              ),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const IconTile(Icons.hourglass_top_rounded,
                        tint: ManfaaTint.amber, size: 72, iconSize: 34),
                    const SizedBox(height: Gap.xxl),
                    if ((session.merchantName ?? '').isNotEmpty) ...[
                      Text(
                        session.merchantName!,
                        style: theme.textTheme.titleMedium?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: Gap.sm),
                    ],
                    Text(l10n.setupPendingTitle,
                        style: theme.textTheme.headlineSmall,
                        textAlign: TextAlign.center),
                    const SizedBox(height: Gap.md),
                    Text(
                      l10n.setupPendingBody,
                      style: theme.textTheme.bodyLarge?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
              OutlinedButton(
                onPressed: () => ref.read(apiProvider).signOut(),
                child: Text(l10n.signOut),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
