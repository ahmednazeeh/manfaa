import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';

/// The wizard's step order — web parity (setup-wizard.tsx STEPS). This list
/// is the ONE place a step's position is written down; every jump (resume,
/// review-row edits, fix-this links) resolves through indices of it.
const kSetupSteps = ['profile', 'location', 'logo', 'rate', 'terms', 'review'];

/// The store description's ceiling: 180 WORDS, not characters — the exact
/// `App\Rules\MaxWords(180)` the server validates with. A word ceiling
/// refuses neither a long Dhivehi word nor a short English one unfairly,
/// which is the whole reason the server counts words.
const kDescriptionMaxWords = 180;

/// Words the way the SERVER counts them: any run of whitespace separates
/// words, in every script we serve (`preg_split('/\s+/u', trim($value))`).
/// Counting differently here would refuse text the server accepts — or,
/// worse, wave through text it will refuse.
int countWords(String text) =>
    text.trim().split(RegExp(r'\s+')).where((word) => word.isNotEmpty).length;

/// Resume at the first step whose REQUIRED value is still missing — the
/// exact web rule (firstIncompleteStep). Logo is never required; location
/// only for a store customers can walk into, read from the channel the
/// SERVER holds.
int firstIncompleteStep(MerchantSetupState state) {
  // The description is a SUBMIT requirement saved on the profile step, so a
  // store that filled that step before the field existed resumes there
  // rather than discovering it at the submit button (the terms rule below,
  // applied to the step that owns the field).
  if (!state.steps.profile ||
      state.values.category == null ||
      (state.values.description ?? '').trim().isEmpty) {
    return 0;
  }
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

/// A curated-category pill — violet when chosen, quiet outline otherwise.
/// Shared by the setup profile step and the MR5 profile editor: the two
/// surfaces must stay the same control, because they save the same field.
class SelectChip extends StatelessWidget {
  const SelectChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.bar),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected
              ? (dark
                  ? ManfaaColors.violet.withValues(alpha: 0.22)
                  : ManfaaColors.violetSoft)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(Corner.bar),
          border: Border.all(
            color: selected ? ManfaaColors.violet : theme.colorScheme.outline,
          ),
        ),
        child: Text(
          label,
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected
                ? (dark ? const Color(0xFFB79CF6) : ManfaaColors.violetDeep)
                : theme.colorScheme.onSurface,
          ),
        ),
      ),
    );
  }
}

/// The store-description control: label, a multi-line field, and the line
/// underneath carrying BOTH the guidance (which names the 180-word ceiling)
/// and a live word counter that turns to the error colour once the ceiling
/// is passed.
///
/// Shared by the wizard's profile step and the MR5 profile editor, for the
/// same reason [SelectChip] and [SelectRow] are: the two surfaces save the
/// SAME field, so the ceiling must be drawn in one place or the two will
/// drift the first time the number moves.
///
/// The counter repaints from the controller itself — the owner has to see
/// the count move while typing, not after the next validation.
class DescriptionField extends StatefulWidget {
  const DescriptionField({
    super.key,
    required this.controller,
    this.errorText,
    this.onChanged,
  });

  final TextEditingController controller;

  /// The refusal to print in place of the guidance: empty, over the
  /// ceiling, or the server's own 422 sentence for `description`.
  final String? errorText;

  /// Fired on every keystroke so the screen can clear a stale refusal.
  final ValueChanged<String>? onChanged;

  @override
  State<DescriptionField> createState() => _DescriptionFieldState();
}

class _DescriptionFieldState extends State<DescriptionField> {
  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_repaint);
  }

  @override
  void didUpdateWidget(DescriptionField old) {
    super.didUpdateWidget(old);
    // A screen that swaps controllers keeps its counter: the State outlives
    // the widget, so the listener has to follow.
    if (old.controller != widget.controller) {
      old.controller.removeListener(_repaint);
      widget.controller.addListener(_repaint);
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(_repaint);
    super.dispose();
  }

  void _repaint() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final words = countWords(widget.controller.text);
    final overCeiling = words > kDescriptionMaxWords;
    final error = widget.errorText;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(l10n.descriptionLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: widget.controller,
          minLines: 4,
          maxLines: 8,
          keyboardType: TextInputType.multiline,
          textInputAction: TextInputAction.newline,
          onChanged: widget.onChanged,
          decoration: InputDecoration(hintText: l10n.descriptionPlaceholder),
        ),
        const SizedBox(height: Gap.xs),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Text(
                error ?? l10n.descriptionHint,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: error != null
                      ? theme.colorScheme.error
                      : theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
            const SizedBox(width: Gap.sm),
            Text(
              l10n.descriptionWordCount(words, kDescriptionMaxWords),
              // Latin digits keep their order inside dv.
              textDirection: TextDirection.ltr,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: overCeiling ? FontWeight.w700 : null,
                color: overCeiling
                    ? theme.colorScheme.error
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// One radio-style selection row: dot, label, hint — the channel picker's
/// control, shared with the MR5 profile editor and the category-rule mode
/// picker for the same reason as [SelectChip].
class SelectRow extends StatelessWidget {
  const SelectRow({
    super.key,
    required this.label,
    required this.hint,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final String hint;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.control),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(Gap.md),
        decoration: BoxDecoration(
          color: selected
              ? (dark
                  ? ManfaaColors.violet.withValues(alpha: 0.12)
                  : const Color(0xFFF4F1FE))
              : Colors.transparent,
          borderRadius: BorderRadius.circular(Corner.control),
          border: Border.all(
            color: selected
                ? ManfaaColors.violet
                : theme.colorScheme.outlineVariant,
          ),
        ),
        child: Row(
          children: [
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_off_rounded,
              size: 20,
              color: selected
                  ? ManfaaColors.violet
                  : theme.colorScheme.onSurfaceVariant,
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label,
                      style: theme.textTheme.titleSmall
                          ?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 2),
                  Text(
                    hint,
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ],
              ),
            ),
          ],
        ),
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
