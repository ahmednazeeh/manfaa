import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';

/// The post-submit lifecycle screens (MR1):
///  - pending_review — the hourglass, the submitted date, and a way out
///    (sign out). No refresh button: /merchant/me re-checks the status on
///    every launch/resume and the router walks the moment it flips.
///  - rejected — the admin's reason VERBATIM in a blockquote, plus "Edit
///    and resubmit", which reopens the wizard.
///
/// Details (submitted_at, rejected_reason) come from GET /merchant/setup,
/// which is readable in every status; the screen renders its basic copy
/// even when that fetch fails — status truth is already in the session.
final setupStateProvider = FutureProvider.autoDispose<MerchantSetupState>(
  (ref) => ref.watch(apiProvider).getSetup(),
);

class SetupPendingScreen extends ConsumerWidget {
  const SetupPendingScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final rejected = session.merchantStatus == 'rejected';

    // The wizard details need setup.view; a staff account without it still
    // deserves the correct headline — just without date or reason.
    final canRead = session.can('setup.view');
    final state =
        canRead ? ref.watch(setupStateProvider).valueOrNull : null;

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
                child: ListView(
                  children: [
                    const SizedBox(height: Gap.huge),
                    Center(
                      child: IconTile(
                        rejected
                            ? Icons.edit_note_rounded
                            : Icons.hourglass_top_rounded,
                        tint: ManfaaTint.amber,
                        size: 72,
                        iconSize: 34,
                      ),
                    ),
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
                    Text(
                      rejected ? l10n.rejectedTitle : l10n.setupPendingTitle,
                      style: theme.textTheme.headlineSmall,
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: Gap.md),
                    Text(
                      rejected ? l10n.rejectedBody : l10n.setupPendingBody,
                      style: theme.textTheme.bodyLarge?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant),
                      textAlign: TextAlign.center,
                    ),
                    if (rejected && state?.rejectedReason != null) ...[
                      const SizedBox(height: Gap.xl),
                      // The admin's words, unedited — a paraphrase would
                      // only send the owner fixing the wrong thing.
                      Container(
                        padding: const EdgeInsets.all(Gap.lg),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.surfaceContainerLow,
                          borderRadius: BorderRadius.circular(Corner.card),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            // The blockquote's amber cue bar.
                            Container(
                              width: 3,
                              height: 40,
                              decoration: BoxDecoration(
                                color: ManfaaColors.amber,
                                borderRadius: BorderRadius.circular(2),
                              ),
                            ),
                            const SizedBox(width: Gap.md),
                            Expanded(
                              child: Text(
                                state!.rejectedReason!,
                                style: theme.textTheme.bodyMedium,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    if (!rejected && state?.submittedAt != null) ...[
                      const SizedBox(height: Gap.xl),
                      Text(
                        l10n.pendingSubmittedAt(
                            _formatSubmitted(state!.submittedAt!)),
                        style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant),
                        textAlign: TextAlign.center,
                      ),
                    ],
                    if (rejected) ...[
                      const SizedBox(height: Gap.xxl),
                      FilledButton.icon(
                        onPressed: () => context.go('/setup'),
                        icon: const Icon(Icons.edit_rounded, size: 18),
                        label: Text(l10n.editAndResubmit),
                      ),
                    ],
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

  /// "17 Aug 2026, 14:05" in the DEVICE's local time — the ISO instant is
  /// UTC on the wire.
  String _formatSubmitted(String iso) {
    final parsed = DateTime.tryParse(iso);
    if (parsed == null) return iso;
    return DateFormat('d MMM yyyy, HH:mm').format(parsed.toLocal());
  }
}
