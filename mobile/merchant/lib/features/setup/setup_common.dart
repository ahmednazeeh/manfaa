import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';

/// The wizard's step order — web parity (setup-wizard.tsx STEPS). This list
/// is the ONE place a step's position is written down; every jump (resume,
/// review-row edits, fix-this links) resolves through indices of it.
const kSetupSteps = ['profile', 'location', 'logo', 'rate', 'terms', 'review'];

/// Resume at the first step whose REQUIRED value is still missing — the
/// exact web rule (firstIncompleteStep). Logo is never required; location
/// only for a store customers can walk into, read from the channel the
/// SERVER holds.
int firstIncompleteStep(MerchantSetupState state) {
  if (!state.steps.profile || state.values.category == null) return 0;
  if (state.locationRequired && !state.pinned) return 1;
  if (state.values.cashbackRatePercent == null) {
    return kSetupSteps.indexOf('rate');
  }
  if ((state.values.eligibilityBasis ?? '').trim().isEmpty) {
    return kSetupSteps.indexOf('terms');
  }
  return kSetupSteps.length - 1;
}

/// The contract's error rule for every wizard write: KNOWN codes render
/// localized sentences, an unknown code renders the server's own prose —
/// never raw snake_case.
String describeSetupError(BuildContext context, Object error) {
  final l10n = context.l10n;

  if (error is! MobileApiException) return l10n.errorGeneric;

  return switch (error.code) {
    ApiCode.setupNotEditable => l10n.errSetupNotEditable,
    ApiCode.rateLimited => l10n.errTooManyTries,
    _ => error.message,
  };
}

/// One wizard step's card: title, optional subtitle, the step's fields, and
/// the footer row — ghost Back on the start side, the ink primary action
/// (arrow included) on the end side. Matches the login card's language.
class SetupStepCard extends StatelessWidget {
  const SetupStepCard({
    super.key,
    required this.title,
    this.subtitle,
    required this.children,
    required this.actionLabel,
    required this.onAction,
    this.onBack,
    this.busy = false,
    this.actionArrow = true,
    this.secondary,
  });

  final String title;
  final String? subtitle;
  final List<Widget> children;
  final String actionLabel;
  final VoidCallback? onAction;
  final VoidCallback? onBack;
  final bool busy;
  final bool actionArrow;

  /// An optional extra footer action between Back and the primary (the
  /// location step's "Skip for now").
  final Widget? secondary;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return ManfaaCard(
      padding: const EdgeInsets.all(Gap.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: theme.textTheme.titleMedium),
          if (subtitle != null) ...[
            const SizedBox(height: Gap.xs),
            Text(
              subtitle!,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
          const SizedBox(height: Gap.lg),
          ...children,
          const SizedBox(height: Gap.xl),
          Row(
            children: [
              if (onBack != null)
                TextButton(
                  onPressed: busy ? null : onBack,
                  child: Text(l10n.back),
                ),
              Expanded(
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    // Loose so a long label shrinks instead of overflowing
                    // a narrow phone.
                    Flexible(
                      child: FilledButton(
                        onPressed: busy ? null : onAction,
                        // The theme's minimumSize is full-width (for the
                        // stacked auth CTAs); inside this footer Row that
                        // would be an infinite-width constraint — bound it.
                        style: FilledButton.styleFrom(
                          minimumSize: const Size(0, 50),
                          padding:
                              const EdgeInsets.symmetric(horizontal: Gap.xl),
                        ),
                        child: busy
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2),
                              )
                            : Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Flexible(
                                    child: Text(actionLabel,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis),
                                  ),
                                  if (actionArrow) ...[
                                    const SizedBox(width: Gap.sm),
                                    const Icon(Icons.arrow_forward_rounded,
                                        size: 20),
                                  ],
                                ],
                              ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (secondary != null) ...[
            const SizedBox(height: Gap.sm),
            Align(alignment: AlignmentDirectional.center, child: secondary),
          ],
        ],
      ),
    );
  }
}

/// A soft warning surface (the review step's missing-list, the rejected
/// banner) — amber tone, icon tile, free-form body.
class SetupNotice extends StatelessWidget {
  const SetupNotice({
    super.key,
    required this.title,
    required this.child,
    this.tone = ToneSurface.pending,
    this.icon = Icons.error_outline_rounded,
  });

  final String title;
  final Widget child;
  final ToneSurface tone;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = toneSurface(tone, theme.brightness);

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: colors.foreground),
              const SizedBox(width: Gap.sm),
              Expanded(
                child: Text(
                  title,
                  style: theme.textTheme.titleSmall
                      ?.copyWith(color: colors.foreground),
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          child,
        ],
      ),
    );
  }
}
