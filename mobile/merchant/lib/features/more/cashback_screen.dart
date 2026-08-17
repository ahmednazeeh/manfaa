import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';
import '../../widgets/tx_format.dart';
import '../settlements/settlement_widgets.dart' show ToneBanner;
import '../setup/rate_step.dart'
    show bpToPercentString, formatBp, parsePercentToBp;
import '../setup/setup_common.dart' show SelectRow;
import 'more_providers.dart';

/// Cashback Settings (MR5), drawn to Cashback Settings.png — the web's
/// merged screen (rate + category rules + earning knobs) on mobile.
///
/// Each section stands on its own permission, exactly as the web page does:
/// `rate.view` draws the standing rate (edit behind `rate.update`),
/// `product_categories.view` the rules (writes behind their own slugs),
/// `preferences.update` the earning card and the Save CTA.
///
/// §7 timing law: what the pre-submit hints SAY is the web's copy, but when
/// a change actually takes hold is rendered from the SERVER's answer
/// (`change.applies` / `change.effective_at`) — never re-derived here.
class CashbackSettingsScreen extends ConsumerStatefulWidget {
  const CashbackSettingsScreen({super.key});

  @override
  ConsumerState<CashbackSettingsScreen> createState() =>
      _CashbackSettingsScreenState();
}

class _CashbackSettingsScreenState
    extends ConsumerState<CashbackSettingsScreen> {
  /// The server's summary of the last rate change this visit — the §7
  /// timing answer, shown until the screen is left.
  RateChangeSummary? _lastChange;

  @override
  Widget build(BuildContext context) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final showRate = session.can('rate.view');
    final showRules = session.can('product_categories.view');
    final showEarning = session.can('preferences.update');

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            Gap.xl,
            Gap.sm,
            Gap.xl,
            Gap.navClearance,
          ),
          children: [
            MerchantDetailTopBar(initials: initials),
            const SizedBox(height: Gap.md),
            Text(
              l10n.cashbackTitle,
              style: theme.textTheme.headlineMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.cashbackSubtitle,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.lg),
            if (showRate) ...[
              _RateSection(
                canEdit: session.can('rate.update'),
                lastChange: _lastChange,
                onChanged: (change) => setState(() => _lastChange = change),
              ),
              const SizedBox(height: Gap.md),
            ],
            if (showRules) ...[
              _RulesSection(
                canCreate: session.can('product_categories.create'),
                canEdit: session.can('product_categories.edit'),
              ),
              const SizedBox(height: Gap.md),
            ],
            if (showEarning) const _EarningSection(),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------- rate

class _RateSection extends ConsumerWidget {
  const _RateSection({
    required this.canEdit,
    required this.lastChange,
    required this.onChanged,
  });

  final bool canEdit;
  final RateChangeSummary? lastChange;
  final void Function(RateChangeSummary? change) onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final rate = ref.watch(rateSettingsProvider);

    return rate.when(
      loading: () => const SkeletonBox(height: 120, radius: Corner.card),
      error: (error, _) => _SectionError(
        message: error is MobileApiException
            ? error.message
            : l10n.errorGeneric,
        onRetry: () => ref.invalidate(rateSettingsProvider),
      ),
      data: (rate) {
        final current = rate.current;
        final change = lastChange;

        return ManfaaCard(
          padding: const EdgeInsets.all(Gap.xl),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const IconTile(
                    Icons.percent_rounded,
                    tint: ManfaaTint.violet,
                    size: 48,
                    iconSize: 24,
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          l10n.generalRateTitle,
                          style: theme.textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: Gap.xs),
                        Text(
                          current == null
                              ? l10n.noRateYet
                              : '${current.cashbackRatePercent}%',
                          textDirection: TextDirection.ltr,
                          style: current == null
                              ? theme.textTheme.bodyMedium?.copyWith(
                                  color: theme.colorScheme.onSurfaceVariant,
                                )
                              : theme.textTheme.headlineMedium?.copyWith(
                                  fontWeight: FontWeight.w800,
                                ),
                        ),
                        const SizedBox(height: Gap.xs),
                        Text(
                          l10n.generalRateHint,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (canEdit) ...[
                    const SizedBox(width: Gap.sm),
                    _SoftIconButton(
                      icon: Icons.edit_outlined,
                      onTap: () => _openEditor(context, ref, rate),
                    ),
                  ],
                ],
              ),
              // The §7 scheduled decrease on file — the server's window,
              // verbatim.
              if (rate.pending != null) ...[
                const SizedBox(height: Gap.md),
                ToneBanner(
                  tone: ToneSurface.info,
                  icon: Icons.schedule_rounded,
                  title: l10n.scheduledChangeTitle(
                    trimRatePercent(rate.pending!.cashbackRatePercent),
                  ),
                  body: l10n.scheduledChangeBody(
                    formatIsoDisplay(rate.pending!.effectiveFrom),
                  ),
                ),
              ],
              // The server's own answer on the change just made.
              if (change != null) ...[
                const SizedBox(height: Gap.md),
                ToneBanner(
                  tone: ToneSurface.confirmed,
                  icon: Icons.check_circle_outline_rounded,
                  title: change.applies == 'immediately'
                      ? l10n.rateChangedNowTitle(
                          trimRatePercent(change.next.cashbackRatePercent),
                        )
                      : l10n.rateChangedLaterTitle(
                          trimRatePercent(change.next.cashbackRatePercent),
                        ),
                  body: change.applies == 'immediately'
                      ? (change.next.platformFeePercent != null &&
                                change.next.allInPercent != null
                            ? l10n.rateChangedNowBody(
                                trimRatePercent(
                                  change.next.platformFeePercent!,
                                ),
                                trimRatePercent(change.next.allInPercent!),
                              )
                            : null)
                      : l10n.rateChangedLaterBody(
                          formatIsoDisplay(change.effectiveAt),
                        ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }

  Future<void> _openEditor(
    BuildContext context,
    WidgetRef ref,
    MerchantRate rate,
  ) async {
    final result = await showModalBottomSheet<RateChangeResult>(
      context: context,
      // ROOT navigator: the shell's floating nav bar overlays the branch
      // navigator's overlay (extendBody), and a sheet under the nav bar is
      // a sheet whose CTA cannot be pressed.
      useRootNavigator: true,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _RateEditSheet(rate: rate),
    );
    if (result == null) return;
    ref.invalidate(rateSettingsProvider);
    onChanged(result.change);
  }
}

/// The change-the-rate sheet. Bounds are the §4 structural window in
/// integer bp (percent strings become ints once — never doubles); the
/// pre-submit direction hints carry the web's §7 copy; the SUBMIT sends the
/// exact string and the server's answer travels back to the section banner.
class _RateEditSheet extends ConsumerStatefulWidget {
  const _RateEditSheet({required this.rate});

  final MerchantRate rate;

  @override
  ConsumerState<_RateEditSheet> createState() => _RateEditSheetState();
}

class _RateEditSheetState extends ConsumerState<_RateEditSheet> {
  static const _minBp = 50;
  static const _maxBp = 2000;

  final _input = TextEditingController();
  var _busy = false;
  String? _serverError;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _submit(int bp) async {
    final l10n = context.l10n;
    setState(() {
      _busy = true;
      _serverError = null;
    });
    try {
      final result = await ref
          .read(apiProvider)
          .changeRate(bpToPercentString(bp));
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.rateUpdatedToast)));
      Navigator.of(context).pop(result);
    } on MobileApiException catch (e) {
      if (mounted) setState(() => _serverError = e.message);
    } catch (_) {
      if (mounted) setState(() => _serverError = l10n.errorGeneric);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    final current = widget.rate.current;
    final currentBp = current == null
        ? null
        : parsePercentToBp(current.cashbackRatePercent);

    final trimmed = _input.text.trim();
    final bp = trimmed.isEmpty ? null : parsePercentToBp(trimmed);
    final inputError = trimmed.isEmpty
        ? null
        : bp == null
        ? l10n.customRateFormat
        : bp < _minBp
        ? l10n.errRateMin(formatBp(_minBp))
        : bp > _maxBp
        ? l10n.errRateMax(formatBp(_maxBp))
        : null;
    final validBp = inputError == null ? bp : null;
    final sameAsCurrent =
        validBp != null && currentBp != null && validBp == currentBp;
    // A same-rate submit is a no-op — unless a change is scheduled, where
    // the server treats it as "keep my rate" and cancels the pending row.
    final cancelsPending = sameAsCurrent && widget.rate.pending != null;
    final canSubmit =
        validBp != null && (!sameAsCurrent || cancelsPending) && !_busy;

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.sm,
        bottom: MediaQuery.viewInsetsOf(context).bottom + Gap.xl,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              current == null ? l10n.setRateCta : l10n.editRateTitle,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.newRateLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _input,
              autofocus: true,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              textDirection: TextDirection.ltr,
              onChanged: (_) => setState(() => _serverError = null),
              decoration: InputDecoration(
                hintText: current == null
                    ? '5.00'
                    : current.cashbackRatePercent,
                suffixText: '%',
                errorText: inputError,
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.rateBoundsHint(formatBp(_minBp), formatBp(_maxBp)),
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            if (sameAsCurrent && !cancelsPending) ...[
              const SizedBox(height: Gap.sm),
              Text(
                l10n.rateSameAsCurrent,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ],
            if (cancelsPending) ...[
              const SizedBox(height: Gap.sm),
              Text(
                l10n.rateCancelsPending,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ],
            // Direction advisory (web copy). The applied timing itself comes
            // back from the server after submit.
            if (validBp != null && currentBp != null && !sameAsCurrent) ...[
              const SizedBox(height: Gap.md),
              ToneBanner(
                tone: ToneSurface.info,
                icon: Icons.info_outline_rounded,
                title: validBp > currentBp
                    ? l10n.rateIncreaseNote
                    : l10n.rateDecreaseNote,
              ),
            ],
            if (_serverError != null) ...[
              const SizedBox(height: Gap.md),
              ToneBanner(
                tone: ToneSurface.attention,
                icon: Icons.error_outline_rounded,
                title: l10n.rateNotPricedTitle,
                body: _serverError,
              ),
            ],
            const SizedBox(height: Gap.lg),
            FilledButton(
              onPressed: canSubmit ? () => _submit(validBp) : null,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(
                      current == null
                          ? l10n.setRateCta
                          : cancelsPending
                          ? l10n.cancelPendingCta
                          : l10n.changeRateCta,
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

// ------------------------------------------------------------- rules

class _RulesSection extends ConsumerWidget {
  const _RulesSection({required this.canCreate, required this.canEdit});

  final bool canCreate;
  final bool canEdit;

  static const _tints = [
    ManfaaTint.green,
    ManfaaTint.blue,
    ManfaaTint.amber,
    ManfaaTint.violet,
    ManfaaTint.coral,
  ];
  static const _icons = [
    Icons.shopping_cart_outlined,
    Icons.home_outlined,
    Icons.eco_outlined,
    Icons.desktop_windows_outlined,
    Icons.sell_outlined,
  ];

  Future<void> _openRule(
    BuildContext context,
    WidgetRef ref, {
    ProductCategory? existing,
  }) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      useRootNavigator: true, // over the floating nav bar — see the rate sheet
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _RuleSheet(existing: existing),
    );
    if (saved ?? false) ref.invalidate(categoryRulesProvider);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final rules = ref.watch(categoryRulesProvider);

    return rules.when(
      loading: () => const SkeletonBox(height: 160, radius: Corner.card),
      error: (error, _) => _SectionError(
        message: error is MobileApiException
            ? error.message
            : l10n.errorGeneric,
        onRetry: () => ref.invalidate(categoryRulesProvider),
      ),
      data: (rules) => ManfaaCard(
        padding: const EdgeInsets.all(Gap.xl),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const IconTile(
                  Icons.sell_outlined,
                  tint: ManfaaTint.violet,
                  size: 48,
                  iconSize: 24,
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              l10n.categoryRulesTitle,
                              style: theme.textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                          StatusChip(
                            label: l10n.optionalBadge,
                            tone: StatusTone.paid,
                          ),
                        ],
                      ),
                      const SizedBox(height: Gap.xs),
                      Text(
                        l10n.categoryRulesHint,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: Gap.sm),
            for (final (i, rule) in rules.indexed) ...[
              if (i > 0)
                Divider(height: 1, color: theme.colorScheme.outlineVariant),
              _RuleRow(
                rule: rule,
                icon: _icons[i % _icons.length],
                tint: _tints[i % _tints.length],
                label: rule.label(dhivehi: dhivehi),
                onTap: canEdit
                    ? () => _openRule(context, ref, existing: rule)
                    : null,
              ),
            ],
            if (canCreate) ...[
              if (rules.isNotEmpty)
                Divider(height: 1, color: theme.colorScheme.outlineVariant),
              InkWell(
                borderRadius: BorderRadius.circular(Corner.tile),
                onTap: () => _openRule(context, ref),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: Gap.md),
                  child: Row(
                    children: [
                      Icon(
                        Icons.add_circle_outline_rounded,
                        color: theme.brightness == Brightness.dark
                            ? const Color(0xFFB79CF6)
                            : ManfaaColors.violet,
                      ),
                      const SizedBox(width: Gap.md),
                      Text(
                        l10n.addCategoryRule,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: theme.brightness == Brightness.dark
                              ? const Color(0xFFB79CF6)
                              : ManfaaColors.violetDeep,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _RuleRow extends StatelessWidget {
  const _RuleRow({
    required this.rule,
    required this.icon,
    required this.tint,
    required this.label,
    this.onTap,
  });

  final ProductCategory rule;
  final IconData icon;
  final ManfaaTint tint;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.md),
        child: Row(
          children: [
            IconTile(icon, tint: tint, size: 40, iconSize: 20),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Text(
                label,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: rule.active
                      ? null
                      : theme.colorScheme.onSurfaceVariant,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            const SizedBox(width: Gap.sm),
            if (!rule.active)
              StatusChip(label: l10n.inactiveBadge, tone: StatusTone.closed)
            else if (rule.excluded)
              StatusChip(label: l10n.excludedBadge, tone: StatusTone.closed)
            else
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: dark
                      ? ManfaaColors.violet.withValues(alpha: 0.22)
                      : ManfaaColors.violetSoft,
                  borderRadius: BorderRadius.circular(Corner.bar),
                ),
                child: Text(
                  '${rule.cashbackRatePercent}%',
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.labelMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: dark
                        ? const Color(0xFFB79CF6)
                        : ManfaaColors.violetDeep,
                  ),
                ),
              ),
            if (onTap != null)
              Icon(
                Icons.chevron_right_rounded,
                color: theme.colorScheme.onSurfaceVariant,
              ),
          ],
        ),
      ),
    );
  }
}

/// Add/edit one category rule. Mode is the server's own pair — `rate` (its
/// own percent) or `excluded` (never earns, even in promotions); the Dhivehi
/// name is REQUIRED because it is what a Dhivehi customer reads on their
/// receipt. Pops `true` after a landed save.
class _RuleSheet extends ConsumerStatefulWidget {
  const _RuleSheet({this.existing});

  final ProductCategory? existing;

  @override
  ConsumerState<_RuleSheet> createState() => _RuleSheetState();
}

class _RuleSheetState extends ConsumerState<_RuleSheet> {
  static const _minBp = 50;
  static const _maxBp = 2000;

  late final _nameEn = TextEditingController(
    text: widget.existing?.nameEn ?? '',
  );
  late final _nameDv = TextEditingController(
    text: widget.existing?.nameDv ?? '',
  );
  late String _mode = widget.existing?.mode ?? 'rate';
  late final _rate = TextEditingController(
    text: widget.existing?.cashbackRatePercent ?? '',
  );
  late bool _active = widget.existing?.active ?? true;
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _nameEn.dispose();
    _nameDv.dispose();
    _rate.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final l10n = context.l10n;
    final nameEn = _nameEn.text.trim();
    final nameDv = _nameDv.text.trim();
    if (nameEn.isEmpty || nameDv.isEmpty) {
      setState(() => _error = l10n.ruleNamesRequired);
      return;
    }

    String? percent;
    if (_mode == 'rate') {
      final bp = parsePercentToBp(_rate.text.trim());
      if (bp == null || bp < _minBp || bp > _maxBp) {
        setState(
          () =>
              _error = l10n.rateBoundsHint(formatBp(_minBp), formatBp(_maxBp)),
        );
        return;
      }
      percent = bpToPercentString(bp);
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ref.read(apiProvider);
      final existing = widget.existing;
      if (existing == null) {
        await api.createProductCategory(
          nameEn: nameEn,
          nameDv: nameDv,
          mode: _mode,
          cashbackRatePercent: percent,
        );
      } else {
        await api.updateProductCategory(
          existing.id,
          nameEn: nameEn,
          nameDv: nameDv,
          mode: _mode,
          cashbackRatePercent: percent,
          active: _active,
        );
      }
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.categorySaved)));
      Navigator.of(context).pop(true);
    } on MobileApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.categorySaveFailed);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final editing = widget.existing != null;

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.sm,
        bottom: MediaQuery.viewInsetsOf(context).bottom + Gap.xl,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              editing ? l10n.editRuleTitle : l10n.addCategoryRule,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.ruleNameEnLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _nameEn,
              textDirection: TextDirection.ltr,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.sell_outlined),
              ),
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.ruleNameDvLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _nameDv,
              textDirection: TextDirection.rtl,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.translate_rounded),
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.ruleNameDvHint,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.xl),
            Text(l10n.ruleModeLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.md),
            SelectRow(
              label: l10n.modeRateOption,
              hint: l10n.modeRateOptionHint,
              selected: _mode == 'rate',
              onTap: () => setState(() => _mode = 'rate'),
            ),
            const SizedBox(height: Gap.sm),
            SelectRow(
              label: l10n.modeExcludedOption,
              hint: l10n.modeExcludedOptionHint,
              selected: _mode == 'excluded',
              onTap: () => setState(() => _mode = 'excluded'),
            ),
            if (_mode == 'rate') ...[
              const SizedBox(height: Gap.lg),
              Text(l10n.ruleRateLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _rate,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                textDirection: TextDirection.ltr,
                decoration: const InputDecoration(suffixText: '%'),
              ),
            ],
            if (editing) ...[
              const SizedBox(height: Gap.lg),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      l10n.ruleActiveLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                  ),
                  Switch(
                    value: _active,
                    activeThumbColor: Colors.white,
                    activeTrackColor: ManfaaColors.violet,
                    onChanged: (value) => setState(() => _active = value),
                  ),
                ],
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: Gap.md),
              Text(
                _error!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
            const SizedBox(height: Gap.lg),
            FilledButton(
              onPressed: _busy ? null : _save,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(l10n.save),
            ),
          ],
        ),
      ),
    );
  }
}

// ----------------------------------------------------------- earning

/// Earning & settlement (Cashback Settings.png bottom card): the two
/// preference knobs as inputs, the static customer-visibility explainer,
/// and the ref's full-width violet Save CTA. The "Pending" row is
/// information, not a control — customer-side visibility has no merchant
/// switch, so none is drawn.
class _EarningSection extends ConsumerStatefulWidget {
  const _EarningSection();

  @override
  ConsumerState<_EarningSection> createState() => _EarningSectionState();
}

class _EarningSectionState extends ConsumerState<_EarningSection> {
  static const _maxEligibleLaari = 100000;

  final _minEligible = TextEditingController();
  final _windowDays = TextEditingController();
  var _seeded = false;
  var _busy = false;

  @override
  void dispose() {
    _minEligible.dispose();
    _windowDays.dispose();
    super.dispose();
  }

  void _seed(MerchantPreferences prefs) {
    if (_seeded) return;
    _seeded = true;
    _minEligible.text = formatRufiyaa(prefs.minEligibleLaari);
    _windowDays.text = '${prefs.validationWindowDays}';
  }

  int? _laari() => parseMvrToLaari(_minEligible.text);

  int? _days() {
    final text = _windowDays.text.trim();
    if (!RegExp(r'^\d{1,2}$').hasMatch(text)) return null;
    return int.parse(text);
  }

  Future<void> _save(int maxDays) async {
    final l10n = context.l10n;
    final laari = _laari();
    final days = _days();
    if (laari == null || days == null) return;

    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .updatePreferences(
            minEligibleLaari: laari,
            validationWindowDays: days,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.preferencesSaved)));
      _seeded = false;
      ref.invalidate(preferencesProvider);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              e is MobileApiException ? e.message : l10n.preferencesSaveFailed,
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final prefs = ref.watch(preferencesProvider);

    return prefs.when(
      loading: () => const SkeletonBox(height: 220, radius: Corner.card),
      error: (error, _) => _SectionError(
        message: error is MobileApiException
            ? error.message
            : l10n.errorGeneric,
        onRetry: () => ref.invalidate(preferencesProvider),
      ),
      data: (prefs) {
        _seed(prefs);
        final maxDays = prefs.validationWindowMaxDays;

        final laari = _laari();
        final laariInvalid = laari == null || laari > _maxEligibleLaari;
        final days = _days();
        final daysInvalid = days == null || days > maxDays;
        final canSave = !laariInvalid && !daysInvalid && !_busy;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ManfaaCard(
              padding: const EdgeInsets.all(Gap.xl),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const IconTile(
                        Icons.savings_outlined,
                        tint: ManfaaTint.violet,
                        size: 40,
                        iconSize: 20,
                      ),
                      const SizedBox(width: Gap.md),
                      Expanded(
                        child: Text(
                          l10n.earningTitle,
                          style: theme.textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: Gap.lg),
                  Text(
                    l10n.minEligibleTitle,
                    style: theme.textTheme.labelLarge,
                  ),
                  const SizedBox(height: Gap.xs),
                  Text(
                    l10n.minEligibleHint,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: Gap.sm),
                  TextField(
                    controller: _minEligible,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    textDirection: TextDirection.ltr,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      prefixText: 'MVR ',
                      errorText:
                          _minEligible.text.trim().isNotEmpty && laariInvalid
                          ? l10n.minEligibleInvalid
                          : null,
                    ),
                  ),
                  const SizedBox(height: Gap.lg),
                  Text(
                    l10n.validationWindowTitle,
                    style: theme.textTheme.labelLarge,
                  ),
                  const SizedBox(height: Gap.xs),
                  Text(
                    l10n.validationWindowHint,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: Gap.sm),
                  TextField(
                    controller: _windowDays,
                    keyboardType: TextInputType.number,
                    textDirection: TextDirection.ltr,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      suffixText: l10n.daysSuffix,
                      errorText:
                          _windowDays.text.trim().isNotEmpty && daysInvalid
                          ? l10n.validationWindowInvalid(maxDays)
                          : null,
                    ),
                  ),
                  const SizedBox(height: Gap.lg),
                  Divider(height: 1, color: theme.colorScheme.outlineVariant),
                  const SizedBox(height: Gap.md),
                  // Static explainer, not a control (ref reconciliation).
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const IconTile(
                        Icons.info_outline_rounded,
                        tint: ManfaaTint.amber,
                        size: 36,
                        iconSize: 18,
                      ),
                      const SizedBox(width: Gap.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              l10n.visibilityTitle,
                              style: theme.textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              l10n.visibilityHint,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: muted,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: Gap.sm),
                      Text(
                        l10n.visibilityValue,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: Gap.lg),
            // The ref's violet CTA — saves the two knobs above.
            FilledButton(
              onPressed: canSave ? () => _save(maxDays) : null,
              style: FilledButton.styleFrom(
                backgroundColor: ManfaaColors.violet,
                foregroundColor: Colors.white,
                disabledBackgroundColor: ManfaaColors.violet.withValues(
                  alpha: 0.35,
                ),
                disabledForegroundColor: Colors.white70,
              ),
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(l10n.saveChanges),
            ),
          ],
        );
      },
    );
  }
}

// ------------------------------------------------------------ shared

class _SectionError extends StatelessWidget {
  const _SectionError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return ManfaaCard(
      child: Column(
        children: [
          Text(message, textAlign: TextAlign.center),
          const SizedBox(height: Gap.md),
          OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
        ],
      ),
    );
  }
}

class _SoftIconButton extends StatelessWidget {
  const _SoftIconButton({required this.icon, required this.onTap});

  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.tile),
      onTap: onTap,
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          color: dark
              ? ManfaaColors.violet.withValues(alpha: 0.22)
              : ManfaaColors.violetSoft,
          borderRadius: BorderRadius.circular(Corner.tile),
        ),
        child: Icon(
          icon,
          size: 20,
          color: dark ? const Color(0xFFB79CF6) : ManfaaColors.violet,
        ),
      ),
    );
  }
}
