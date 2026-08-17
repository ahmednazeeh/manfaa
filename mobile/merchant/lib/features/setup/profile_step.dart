import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'setup_common.dart';

/// Step 1 — store profile: the curated category CHIPS (from the setup
/// payload itself), the channel choice as selection rows, contacts, and the
/// website. Saved server-side on Continue; "same as contact" stores the
/// support phone as NULL rather than a copy (web parity — a copy would go
/// silently stale the day the contact number changes).
class ProfileStep extends ConsumerStatefulWidget {
  const ProfileStep({super.key, required this.state, required this.onSaved});

  final MerchantSetupState state;
  final void Function(MerchantSetupState state) onSaved;

  @override
  ConsumerState<ProfileStep> createState() => _ProfileStepState();
}

class _ProfileStepState extends ConsumerState<ProfileStep> {
  late String? _category = widget.state.values.category;
  late String _channel = widget.state.values.channel;
  late final _contactEmail =
      TextEditingController(text: widget.state.values.contactEmail ?? '');
  late final _contactPhone =
      TextEditingController(text: widget.state.values.contactPhone ?? '');
  late final _supportPhone =
      TextEditingController(text: widget.state.values.supportPhone ?? '');
  late final _website =
      TextEditingController(text: widget.state.values.websiteUrl ?? '');

  /// Ticked when the store has no distinct support line — the state a fresh
  /// signup starts in.
  late bool _supportSame = (widget.state.values.supportPhone ?? '').isEmpty;

  var _busy = false;
  var _categoryError = false;

  @override
  void dispose() {
    _contactEmail.dispose();
    _contactPhone.dispose();
    _supportPhone.dispose();
    _website.dispose();
    super.dispose();
  }

  String? _clean(TextEditingController controller) {
    final text = controller.text.trim();
    return text.isEmpty ? null : text;
  }

  Future<void> _save() async {
    final category = _category;
    if (category == null) {
      setState(() => _categoryError = true);
      return;
    }

    setState(() => _busy = true);
    try {
      final fresh = await ref.read(apiProvider).saveSetupProfile(
            category: category,
            channel: _channel,
            contactEmail: _clean(_contactEmail),
            contactPhone: _clean(_contactPhone),
            supportPhone: _supportSame ? null : _clean(_supportPhone),
            websiteUrl: _clean(_website),
          );
      if (mounted) widget.onSaved(fresh);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(describeSetupError(context, e))));
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
    final muted = theme.colorScheme.onSurfaceVariant;

    return SetupStepCard(
      title: l10n.profileTitle,
      actionLabel: l10n.continueLabel,
      onAction: _save,
      busy: _busy,
      children: [
        Text(l10n.categoryLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.xs),
        Text(l10n.categoryHint,
            style: theme.textTheme.bodySmall?.copyWith(color: muted)),
        const SizedBox(height: Gap.md),
        Wrap(
          spacing: Gap.sm,
          runSpacing: Gap.sm,
          children: [
            for (final option in widget.state.categories)
              _CategoryChip(
                label: option.label(dhivehi: dhivehi),
                selected: option.slug == _category,
                onTap: () => setState(() {
                  _category = option.slug;
                  _categoryError = false;
                }),
              ),
          ],
        ),
        if (_categoryError) ...[
          const SizedBox(height: Gap.sm),
          Text(
            l10n.categoryRequired,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.error),
          ),
        ],
        const SizedBox(height: Gap.xl),
        Text(l10n.channelLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.md),
        for (final (channel, label, hint) in [
          ('in_store', l10n.channelInStore, l10n.channelInStoreHint),
          ('online', l10n.channelOnline, l10n.channelOnlineHint),
          ('both', l10n.channelBoth, l10n.channelBothHint),
        ]) ...[
          _ChannelRow(
            label: label,
            hint: hint,
            selected: _channel == channel,
            onTap: () => setState(() => _channel = channel),
          ),
          const SizedBox(height: Gap.sm),
        ],
        const SizedBox(height: Gap.md),
        Text(l10n.contactEmailLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _contactEmail,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          textDirection: TextDirection.ltr,
          decoration:
              const InputDecoration(prefixIcon: Icon(Icons.mail_outline_rounded)),
        ),
        const SizedBox(height: Gap.lg),
        Text(l10n.contactPhoneLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _contactPhone,
          keyboardType: TextInputType.phone,
          textDirection: TextDirection.ltr,
          decoration:
              const InputDecoration(prefixIcon: Icon(Icons.call_outlined)),
        ),
        const SizedBox(height: Gap.lg),
        Text(l10n.supportPhoneLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.xs),
        InkWell(
          borderRadius: BorderRadius.circular(Corner.tile),
          onTap: () => setState(() => _supportSame = !_supportSame),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: Gap.xs),
            child: Row(
              children: [
                SizedBox(
                  width: 24,
                  height: 24,
                  child: Checkbox(
                    value: _supportSame,
                    activeColor: ManfaaColors.violet,
                    onChanged: (checked) =>
                        setState(() => _supportSame = checked ?? true),
                  ),
                ),
                const SizedBox(width: Gap.sm),
                Expanded(
                  child: Text(
                    l10n.supportSameAsContact,
                    style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                  ),
                ),
              ],
            ),
          ),
        ),
        if (!_supportSame) ...[
          const SizedBox(height: Gap.sm),
          TextField(
            controller: _supportPhone,
            keyboardType: TextInputType.phone,
            textDirection: TextDirection.ltr,
            decoration: const InputDecoration(
                prefixIcon: Icon(Icons.support_agent_rounded)),
          ),
        ],
        const SizedBox(height: Gap.lg),
        Text(l10n.websiteLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _website,
          keyboardType: TextInputType.url,
          autocorrect: false,
          textDirection: TextDirection.ltr,
          decoration: const InputDecoration(
            hintText: 'teaplus.mv',
            prefixIcon: Icon(Icons.language_rounded),
          ),
        ),
      ],
    );
  }
}

/// A curated-category pill — violet when chosen, quiet outline otherwise.
class _CategoryChip extends StatelessWidget {
  const _CategoryChip({
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

/// One channel option as a selection row: radio dot, label, hint.
class _ChannelRow extends StatelessWidget {
  const _ChannelRow({
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
