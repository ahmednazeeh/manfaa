import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../setup/rate_step.dart'
    show estimateLaariAtBp, parsePercentToBp, staticFeeBp;

/// The With-Category ref's "Category breakdown" editor: colour-chipped rows
/// with amount + edit/delete, an Add-category action, the Everything-else
/// bucket, and the client-side sum check against the eligible amount.
///
/// Everything priced here is a display ESTIMATE mirroring the §4 per-line
/// ceiling math — the server prices authoritatively at the sale time. Lines
/// on the wire must sum EXACTLY to the eligible amount
/// (`lines_sum_mismatch` otherwise), which is the mismatch gate below.

/// One composed line. `category` is a slug, or null for Everything else.
class SplitRow {
  SplitRow({this.category, required this.amountLaari});

  final String? category;
  final int amountLaari;
}

/// A display estimate for the whole split at the sale's base terms.
({int cashback, int fee})? estimateSplit(
  List<SplitRow> rows,
  List<ProductCategory> categories, {
  required int? baseRateBp,
  required int? baseFeeBp,
}) {
  var cashback = 0;
  var fee = 0;
  for (final row in rows) {
    final category = categories
        .where((c) => c.slug == row.category)
        .firstOrNull;
    if (row.category != null && category == null) return null;
    if (category != null && category.excluded) continue;

    final categoryBp = category?.cashbackRatePercent == null
        ? null
        : parsePercentToBp(category!.cashbackRatePercent!);
    final rateBp = categoryBp ?? baseRateBp;
    if (rateBp == null) return null;
    final feeBp = categoryBp != null
        ? staticFeeBp(categoryBp)
        : (baseFeeBp ?? staticFeeBp(rateBp));
    cashback += estimateLaariAtBp(row.amountLaari, rateBp);
    fee += estimateLaariAtBp(row.amountLaari, feeBp);
  }
  return (cashback: cashback, fee: fee);
}

/// The rotating chip palette, index-stable within the active list so a
/// category keeps its colour while the screen lives.
const _chipTints = [
  ManfaaTint.green,
  ManfaaTint.amber,
  ManfaaTint.blue,
  ManfaaTint.coral,
  ManfaaTint.violet,
];

class SplitEditorCard extends StatelessWidget {
  const SplitEditorCard({
    super.key,
    required this.rows,
    required this.categories,
    required this.eligibleLaari,
    required this.onRowsChanged,
  });

  final List<SplitRow> rows;

  /// ACTIVE categories only.
  final List<ProductCategory> categories;

  /// The typed eligible amount the lines must sum to; null while invalid.
  final int? eligibleLaari;
  final ValueChanged<List<SplitRow>> onRowsChanged;

  bool get _canAdd =>
      rows.length < categories.length + 1; // every slug + the else-bucket

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final sum = rows.fold<int>(0, (acc, row) => acc + row.amountLaari);
    final mismatch =
        rows.isNotEmpty && eligibleLaari != null && sum != eligibleLaari;

    String rowLabel(SplitRow row) {
      // The chip wears the ref's short "Other"; the dialog keeps the full
      // "Everything else" where there is room to explain.
      if (row.category == null) return l10n.splitOtherChip;
      final category = categories
          .where((c) => c.slug == row.category)
          .firstOrNull;
      return category?.label(dhivehi: dhivehi) ?? row.category!;
    }

    ManfaaTint rowTint(SplitRow row) {
      if (row.category == null) return ManfaaTint.violet;
      final index = categories.indexWhere((c) => c.slug == row.category);
      return index < 0
          ? ManfaaTint.neutral
          : _chipTints[index % _chipTints.length];
    }

    bool rowExcluded(SplitRow row) =>
        categories.where((c) => c.slug == row.category).firstOrNull?.excluded ??
        false;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  l10n.splitBreakdownTitle,
                  style: theme.textTheme.titleMedium,
                ),
              ),
              TextButton.icon(
                onPressed: _canAdd ? () => _editRow(context) : null,
                icon: const Icon(Icons.add_rounded, size: 18),
                label: Text(l10n.splitAddCategory),
                style: TextButton.styleFrom(
                  foregroundColor: theme.colorScheme.secondary,
                ),
              ),
            ],
          ),
          if (rows.isEmpty) ...[
            const SizedBox(height: Gap.sm),
            Text(
              l10n.splitEmptyHint,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
          for (final (index, row) in rows.indexed) ...[
            if (index > 0)
              Divider(height: 1, color: theme.colorScheme.outlineVariant),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: Gap.sm),
              child: Row(
                children: [
                  Flexible(
                    child: _CategoryChip(
                      label: rowLabel(row),
                      tint: rowTint(row),
                    ),
                  ),
                  if (rowExcluded(row)) ...[
                    const SizedBox(width: Gap.sm),
                    Expanded(
                      child: Text(
                        l10n.splitExcludedNote,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ] else
                    const Spacer(),
                  MoneyText(row.amountLaari, style: theme.textTheme.titleSmall),
                  IconButton(
                    onPressed: () => _editRow(context, index: index),
                    tooltip: l10n.splitEditLine,
                    icon: const Icon(Icons.edit_outlined, size: 19),
                    color: theme.colorScheme.onSurfaceVariant,
                    visualDensity: VisualDensity.compact,
                  ),
                  IconButton(
                    onPressed: () => onRowsChanged([...rows]..removeAt(index)),
                    tooltip: l10n.splitRemoveLine,
                    icon: const Icon(Icons.delete_outline_rounded, size: 19),
                    color: theme.colorScheme.onSurfaceVariant,
                    visualDensity: VisualDensity.compact,
                  ),
                ],
              ),
            ),
          ],
          if (rows.isNotEmpty) ...[
            const SizedBox(height: Gap.sm),
            Row(
              children: [
                Expanded(
                  child: Text(
                    l10n.splitLinesTotal,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                MoneyText(
                  sum,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ],
          if (mismatch) ...[
            const SizedBox(height: Gap.md),
            _MismatchNotice(
              difference: formatMoney(
                (eligibleLaari! - sum).abs(),
                dhivehi: dhivehi,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _editRow(BuildContext context, {int? index}) async {
    final editing = index == null ? null : rows[index];

    // The categories still free for this row: all active slugs plus the
    // else-bucket, minus the ones other rows already hold.
    final used = {
      for (final (i, row) in rows.indexed)
        if (i != index) row.category ?? kSplitElseKey,
    };
    final options = [
      if (!used.contains(kSplitElseKey)) kSplitElseKey,
      for (final category in categories)
        if (!used.contains(category.slug)) category.slug,
    ];
    if (options.isEmpty) return;

    final row = await showDialog<SplitRow>(
      context: context,
      builder: (_) => _SplitRowDialog(
        categories: categories,
        options: options,
        initialKey: editing == null
            ? options.first
            : (editing.category ?? kSplitElseKey),
        initialAmount: editing == null
            ? ''
            : formatRufiyaa(editing.amountLaari),
        editing: editing != null,
      ),
    );
    if (row == null) return;

    final next = [...rows];
    if (index == null) {
      next.add(row);
    } else {
      next[index] = row;
    }
    onRowsChanged(next);
  }
}

/// Sentinel key for the Everything-else bucket inside the row dialog.
const kSplitElseKey = '__everything_else__';

/// The add/edit dialog OWNS its controller so disposal follows the route's
/// real teardown (an inline dispose fires while the close animation still
/// rebuilds the field).
class _SplitRowDialog extends StatefulWidget {
  const _SplitRowDialog({
    required this.categories,
    required this.options,
    required this.initialKey,
    required this.initialAmount,
    required this.editing,
  });

  final List<ProductCategory> categories;
  final List<String> options;
  final String initialKey;
  final String initialAmount;
  final bool editing;

  @override
  State<_SplitRowDialog> createState() => _SplitRowDialogState();
}

class _SplitRowDialogState extends State<_SplitRowDialog> {
  late String _selected = widget.initialKey;
  late final TextEditingController _amount = TextEditingController(
    text: widget.initialAmount,
  );

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final parsed = parseMvrToLaari(_amount.text);
    final invalid =
        _amount.text.trim().isNotEmpty && (parsed == null || parsed < 1);

    String optionLabel(String key) {
      if (key == kSplitElseKey) return l10n.splitEverythingElse;
      return widget.categories
              .where((c) => c.slug == key)
              .firstOrNull
              ?.label(dhivehi: dhivehi) ??
          key;
    }

    return AlertDialog(
      title: Text(
        widget.editing ? l10n.splitDialogTitleEdit : l10n.splitDialogTitleAdd,
      ),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            DropdownButtonFormField<String>(
              initialValue: _selected,
              decoration: InputDecoration(labelText: l10n.splitCategoryLabel),
              items: [
                for (final key in widget.options)
                  DropdownMenuItem(value: key, child: Text(optionLabel(key))),
              ],
              onChanged: (value) =>
                  setState(() => _selected = value ?? _selected),
            ),
            const SizedBox(height: Gap.lg),
            TextField(
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              textDirection: TextDirection.ltr,
              autofocus: true,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                labelText: l10n.splitAmountLabel,
                prefixText: 'MVR ',
                errorText: invalid ? l10n.splitAmountInvalid : null,
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(l10n.cancel),
        ),
        FilledButton(
          style: FilledButton.styleFrom(
            minimumSize: const Size(0, 44),
            padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
          ),
          onPressed: parsed != null && parsed >= 1
              ? () => Navigator.of(context).pop(
                  SplitRow(
                    category: _selected == kSplitElseKey ? null : _selected,
                    amountLaari: parsed,
                  ),
                )
              : null,
          child: Text(l10n.save),
        ),
      ],
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({required this.label, required this.tint});

  final String label;
  final ManfaaTint tint;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = tintColors(tint, theme.brightness);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: colors.bg,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.labelMedium?.copyWith(
          color: colors.fg,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _MismatchNotice extends StatelessWidget {
  const _MismatchNotice({required this.difference});

  final String difference;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final colors = toneSurface(ToneSurface.pending, theme.brightness);

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.splitMismatchTitle,
            style: theme.textTheme.titleSmall?.copyWith(
              color: colors.foreground,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            l10n.splitMismatchBody(difference),
            style: theme.textTheme.bodySmall?.copyWith(
              color: colors.foreground,
            ),
          ),
        ],
      ),
    );
  }
}
