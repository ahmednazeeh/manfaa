import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'rate_step.dart';
import 'setup_common.dart';

/// Step 6 — review & submit: every saved value as a Profile.png-style row
/// (icon tile, label, value, chevron), each one a tap back into its step.
/// Submit maps `setup_incomplete.missing[]` onto fix-this links.
class ReviewStep extends ConsumerStatefulWidget {
  const ReviewStep({
    super.key,
    required this.state,
    required this.onSubmitted,
    required this.onJump,
    required this.onBack,
  });

  final MerchantSetupState state;

  /// Submit landed: the fresh state carries `status: pending_review`.
  final void Function(MerchantSetupState state) onSubmitted;
  final void Function(int step) onJump;
  final VoidCallback onBack;

  @override
  ConsumerState<ReviewStep> createState() => _ReviewStepState();
}

class _ReviewStepState extends ConsumerState<ReviewStep> {
  var _busy = false;
  List<String> _missing = const [];
  String? _submitError;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _missing = const [];
      _submitError = null;
    });
    try {
      final fresh = await ref.read(apiProvider).submitSetup();
      if (mounted) widget.onSubmitted(fresh);
    } on MobileApiException catch (e) {
      if (!mounted) return;
      if (e.code == ApiCode.setupIncomplete &&
          e.missingRequirements.isNotEmpty) {
        setState(() => _missing = e.missingRequirements);
      } else {
        setState(() => _submitError = describeSetupError(context, e));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _submitError = describeSetupError(context, e));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final values = widget.state.values;

    final category = widget.state.categories
        .where((option) => option.slug == values.category)
        .firstOrNull;

    final channelLabel = switch (values.channel) {
      'online' => l10n.channelOnline,
      'both' => l10n.channelBoth,
      _ => l10n.channelInStore,
    };

    final branch = values.primaryBranch;
    final pin = widget.state.pinned
        ? '${branch!.lat!.toStringAsFixed(7)}, '
            '${branch.lng!.toStringAsFixed(7)}'
        : null;

    final rateBp = values.cashbackRatePercent == null
        ? null
        : parsePercentToBp(values.cashbackRatePercent!);
    final rateText = rateBp == null
        ? null
        : l10n.rateSummary(
            formatBp(rateBp),
            formatBp(staticFeeBp(rateBp)),
            formatBp(rateBp + staticFeeBp(rateBp)),
          );

    final terms = (values.eligibilityBasis ?? '').trim();
    final description = (values.description ?? '').trim();

    /// The wizard step that fixes a missing requirement key (web parity).
    const stepForMissing = {
      'category': 0,
      'channel': 0,
      'description': 0,
      'contact': 0,
      'rate': 3,
      'terms': 4,
    };
    // The wizard's own order, not the server's — the list reads top-to-bottom
    // the way the steps it links into do.
    const missingOrder = [
      'category',
      'channel',
      'description',
      'contact',
      'rate',
      'terms',
    ];

    String missingLabel(String key) => switch (key) {
          'category' => l10n.missingCategory,
          'channel' => l10n.missingChannel,
          'description' => l10n.missingDescription,
          'contact' => l10n.missingContact,
          'rate' => l10n.missingRate,
          'terms' => l10n.missingTerms,
          _ => key,
        };

    return SetupStepCard(
      title: l10n.reviewTitle,
      subtitle: l10n.reviewSubtitle,
      actionLabel: l10n.submitForReview,
      actionArrow: false,
      onAction: _submit,
      onBack: widget.onBack,
      busy: _busy,
      children: [
        _ReviewRow(
          icon: Icons.storefront_rounded,
          tint: ManfaaTint.violet,
          label: l10n.reviewName,
          value: values.name,
          onTap: () => widget.onJump(0),
        ),
        _ReviewRow(
          icon: Icons.sell_outlined,
          tint: ManfaaTint.violet,
          label: l10n.reviewCategory,
          value: category?.label(dhivehi: dhivehi),
          onTap: () => widget.onJump(0),
        ),
        _ReviewRow(
          icon: Icons.store_mall_directory_outlined,
          tint: ManfaaTint.blue,
          label: l10n.reviewChannel,
          value: channelLabel,
          onTap: () => widget.onJump(0),
        ),
        _ReviewRow(
          icon: Icons.notes_rounded,
          tint: ManfaaTint.blue,
          label: l10n.reviewDescription,
          value: description.isEmpty ? null : description,
          onTap: () => widget.onJump(0),
        ),
        _ReviewRow(
          icon: Icons.place_outlined,
          tint: ManfaaTint.coral,
          label: l10n.reviewLocation,
          value: pin,
          emptyLabel: l10n.noLocation,
          ltrValue: true,
          onTap: () => widget.onJump(1),
        ),
        _ReviewRow(
          icon: Icons.image_outlined,
          tint: ManfaaTint.amber,
          label: l10n.reviewLogo,
          value: values.logoUrl != null ? '' : null,
          emptyLabel: l10n.noLogo,
          trailing: values.logoUrl != null
              ? ClipOval(
                  child: Image.network(
                    values.logoUrl!,
                    width: 32,
                    height: 32,
                    fit: BoxFit.cover,
                    errorBuilder: (_, _, _) => Icon(
                      Icons.image_outlined,
                      size: 20,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                )
              : null,
          onTap: () => widget.onJump(2),
        ),
        _ReviewRow(
          icon: Icons.percent_rounded,
          tint: ManfaaTint.green,
          label: l10n.reviewRate,
          value: rateText,
          ltrValue: true,
          onTap: () => widget.onJump(3),
        ),
        _ReviewRow(
          icon: Icons.checklist_rounded,
          tint: ManfaaTint.ink,
          label: l10n.reviewTerms,
          value: terms.isEmpty ? null : terms,
          last: true,
          onTap: () => widget.onJump(4),
        ),
        if (_missing.isNotEmpty) ...[
          const SizedBox(height: Gap.lg),
          SetupNotice(
            title: l10n.missingTitle,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final key in missingOrder.where(_missing.contains))
                  InkWell(
                    onTap: () => widget.onJump(stepForMissing[key]!),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: Gap.xs),
                      child: Text(
                        missingLabel(key),
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.secondary,
                          fontWeight: FontWeight.w600,
                          decoration: TextDecoration.underline,
                          decorationColor: theme.colorScheme.secondary,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
        if (_submitError != null) ...[
          const SizedBox(height: Gap.lg),
          SetupNotice(
            title: _submitError!,
            tone: ToneSurface.attention,
            child: const SizedBox.shrink(),
          ),
        ],
      ],
    );
  }
}

/// One summary row in the Profile.png idiom: pastel icon tile, muted label,
/// the value on the end side, a chevron promising the tap-back.
class _ReviewRow extends StatelessWidget {
  const _ReviewRow({
    required this.icon,
    required this.tint,
    required this.label,
    required this.value,
    required this.onTap,
    this.emptyLabel,
    this.trailing,
    this.ltrValue = false,
    this.last = false,
  });

  final IconData icon;
  final ManfaaTint tint;
  final String label;

  /// Null renders the muted "not set" shape ([emptyLabel] or the shared
  /// notSet string).
  final String? value;
  final VoidCallback onTap;
  final String? emptyLabel;
  final Widget? trailing;
  final bool ltrValue;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final missing = value == null;

    return Column(
      children: [
        InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: Gap.md),
            child: Row(
              children: [
                IconTile(icon, tint: tint, size: 38, iconSize: 19),
                const SizedBox(width: Gap.md),
                Flexible(
                  child: Text(label,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium),
                ),
                const SizedBox(width: Gap.lg),
                Expanded(
                  child: trailing != null
                      ? Align(
                          alignment: AlignmentDirectional.centerEnd,
                          child: trailing,
                        )
                      : Text(
                        missing ? (emptyLabel ?? l10n.notSet) : value!,
                        textAlign: TextAlign.end,
                        textDirection: ltrValue ? TextDirection.ltr : null,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: missing ? null : FontWeight.w600,
                          color: missing ? muted : null,
                        ),
                      ),
                ),
                const SizedBox(width: Gap.sm),
                Icon(Icons.chevron_right_rounded, size: 20, color: muted),
              ],
            ),
          ),
        ),
        if (!last)
          Divider(height: 1, color: theme.colorScheme.outlineVariant),
      ],
    );
  }
}
