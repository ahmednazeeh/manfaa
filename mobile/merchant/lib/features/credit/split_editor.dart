import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../setup/rate_step.dart'
    show estimateLaariAtBp, parsePercentToBp, staticFeeBp;

/// The split-by-category editor, reworked to the owner's MR8 spec: INLINE
/// ROWS instead of the add-category popup. Each row is a searchable
/// category dropdown (type-to-filter over the served categories, the
/// Everything-else bucket included) beside an MVR amount field and a
/// per-row delete; "Add row" appends.
///
/// The card OWNS its draft rows (controllers and all) and reports upward
/// only what the wire needs: the COMPLETE rows as [SplitRow]s plus a
/// completeness flag. Since MR8 the lines total IS the eligible amount —
/// computed in the background and sent as `eligible_amount` — so the old
/// sum-mismatch error path is impossible from this UI by construction.
///
/// Everything priced here is a display ESTIMATE mirroring the §4 per-line
/// ceiling math — the server prices authoritatively at the sale time.

/// One composed line. `category` is a slug, or null for Everything else.
class SplitRow {
  SplitRow({this.category, required this.amountLaari});

  final String? category;
  final int amountLaari;
}

/// A display estimate for the whole split at the sale's base terms.
///
/// [baseFeeBp] is the §4 TIER fee for lines with no category rate of their
/// own; [promotion] is applied PER LINE (`min(promotion, tier)`), because a
/// category rate gives its line its own tier fee and the server takes that
/// minimum at each priced unit, not once over the sale.
({int cashback, int fee, int gst})? estimateSplit(
  List<SplitRow> rows,
  List<ProductCategory> categories, {
  required int? baseRateBp,
  required int? baseFeeBp,
  MerchantTaxTerms tax = const MerchantTaxTerms(),
  MerchantFeePromotion promotion = MerchantFeePromotion.none,
}) {
  var cashback = 0;
  var fee = 0;
  var gst = 0;
  // GST is applied PER LINE and the header is the SUM of the line integers
  // (§4: round at the line, then sum) — splitting the aggregate instead
  // would round differently and disagree with the recorded receipt.
  final gstRateBp = parsePercentToBp(tax.gstRatePercent) ?? 0;
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
    final tierFeeBp = categoryBp != null
        ? staticFeeBp(categoryBp)
        : (baseFeeBp ?? staticFeeBp(rateBp));
    // Never null here: tierFeeBp is a concrete int by construction.
    final feeBp = promotion.chargedFeeBp(tierFeeBp) ?? tierFeeBp;
    cashback += estimateLaariAtBp(row.amountLaari, rateBp);

    final lineFee = estimateLaariAtBp(row.amountLaari, feeBp);
    final (net, lineGst) = tax.split(lineFee, gstRateBp);
    // Printed GROSS under `inclusive` — the quoted rate produces that
    // figure, and the tax is disclosed as a component of it.
    fee += tax.inclusive ? net + lineGst : net;
    gst += lineGst;
  }
  return (cashback: cashback, fee: fee, gst: gst);
}

/// Sentinel key for the Everything-else bucket inside the editor.
const kSplitElseKey = '__everything_else__';

/// The rotating chip palette, index-stable within the active list so a
/// category keeps its colour while the screen lives.
const _chipTints = [
  ManfaaTint.green,
  ManfaaTint.amber,
  ManfaaTint.blue,
  ManfaaTint.coral,
  ManfaaTint.violet,
];

/// One draft line: a picked (or not-yet-picked) category key and the raw
/// amount text. The controllers live exactly as long as the row.
class _DraftRow {
  _DraftRow({String label = '', String amount = ''})
      : search = TextEditingController(text: label),
        amount = TextEditingController(text: amount);

  /// Assigned when the row's search field resolves to a category (null is
  /// the Everything-else bucket, which is a deliberate value, not "unset").
  String? key;
  final TextEditingController search;
  final TextEditingController amount;
  final FocusNode focus = FocusNode();

  int? get laari {
    final parsed = parseMvrToLaari(amount.text);
    return parsed != null && parsed >= 1 ? parsed : null;
  }

  bool get amountInvalid =>
      amount.text.trim().isNotEmpty && laari == null;

  void dispose() {
    search.dispose();
    amount.dispose();
    focus.dispose();
  }
}

class SplitEditorCard extends StatefulWidget {
  const SplitEditorCard({
    super.key,
    required this.categories,
    required this.onChanged,
  });

  /// ACTIVE categories only.
  final List<ProductCategory> categories;

  /// Reports the COMPLETE rows (category picked, valid amount) and whether
  /// EVERY row is complete — the parent derives the eligible amount from
  /// the rows' sum and gates submit on the flag.
  final void Function(List<SplitRow> rows, bool complete) onChanged;

  @override
  State<SplitEditorCard> createState() => _SplitEditorCardState();
}

class _SplitEditorCardState extends State<SplitEditorCard> {
  // One empty row from the start: turning the split on means composing a
  // line, not hunting for an Add button first.
  late final List<_DraftRow> _rows = [_DraftRow()];

  @override
  void dispose() {
    for (final row in _rows) {
      row.dispose();
    }
    super.dispose();
  }

  bool get _canAdd =>
      _rows.length < widget.categories.length + 1; // every slug + else

  void _emit() {
    final complete =
        _rows.isNotEmpty &&
        _rows.every((row) => row.key != null && row.laari != null);
    widget.onChanged([
      for (final row in _rows)
        if (row.key != null && row.laari != null)
          SplitRow(
            category: row.key == kSplitElseKey ? null : row.key,
            amountLaari: row.laari!,
          ),
    ], complete);
  }

  void _addRow() {
    setState(() => _rows.add(_DraftRow()));
    _emit();
  }

  void _removeRow(int index) {
    final row = _rows.removeAt(index);
    // Dispose after the frame — the field is still in the closing tree.
    WidgetsBinding.instance.addPostFrameCallback((_) => row.dispose());
    setState(() {});
    _emit();
  }

  /// The keys this row may still pick: the else-bucket plus every active
  /// slug, minus what OTHER rows already hold.
  List<String> _optionsFor(_DraftRow row) {
    final used = {
      for (final other in _rows)
        if (!identical(other, row) && other.key != null) other.key!,
    };
    return [
      if (!used.contains(kSplitElseKey)) kSplitElseKey,
      for (final category in widget.categories)
        if (!used.contains(category.slug)) category.slug,
    ];
  }

  String _labelFor(String key, {required bool dhivehi}) {
    if (key == kSplitElseKey) return context.l10n.splitEverythingElse;
    return widget.categories
            .where((c) => c.slug == key)
            .firstOrNull
            ?.label(dhivehi: dhivehi) ??
        key;
  }

  ManfaaTint _tintFor(String? key) {
    if (key == null) return ManfaaTint.neutral;
    if (key == kSplitElseKey) return ManfaaTint.violet;
    final index = widget.categories.indexWhere((c) => c.slug == key);
    return index < 0 ? ManfaaTint.neutral : _chipTints[index % _chipTints.length];
  }

  bool _excluded(String? key) =>
      widget.categories.where((c) => c.slug == key).firstOrNull?.excluded ??
      false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final muted = theme.colorScheme.onSurfaceVariant;

    final sum = _rows.fold<int>(0, (acc, row) => acc + (row.laari ?? 0));

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
                onPressed: _canAdd ? _addRow : null,
                icon: const Icon(Icons.add_rounded, size: 18),
                label: Text(l10n.splitAddRow),
                style: TextButton.styleFrom(
                  foregroundColor: theme.colorScheme.secondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 2),
          // Fix 4's contract, said out loud: the lines ARE the eligible
          // amount — there is no second field to disagree with.
          Text(
            l10n.splitSumIsEligible,
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: Gap.md),
          for (final (index, row) in _rows.indexed) ...[
            if (index > 0) const SizedBox(height: Gap.sm),
            _buildRow(context, index, row, dhivehi: dhivehi),
            if (_excluded(row.key))
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(
                  l10n.splitExcludedNote,
                  style: theme.textTheme.labelSmall?.copyWith(color: muted),
                ),
              ),
          ],
          const SizedBox(height: Gap.md),
          Row(
            children: [
              Expanded(
                child: Text(
                  l10n.splitLinesTotal,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
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
      ),
    );
  }

  Widget _buildRow(
    BuildContext context,
    int index,
    _DraftRow row, {
    required bool dhivehi,
  }) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final tint = _tintFor(row.key);
    final colors = tintColors(tint, theme.brightness);

    // A SETTLED row shows its category as a chip, not as raw field text:
    // the box is narrower than a long name ("Everything else"), and a
    // TextField cannot ellipsize — it scrolls, so the row ended up reading
    // "…ing else" instead of naming its category. Tapping the chip hands
    // the field back for a change of mind.
    if (row.key != null && !row.focus.hasFocus) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: InkWell(
              borderRadius: BorderRadius.circular(Corner.control),
              onTap: () {
                row.search.clear();
                setState(() => row.key = null);
                row.focus.requestFocus();
                _emit();
              },
              child: Container(
                height: 56,
                padding: const EdgeInsets.symmetric(horizontal: Gap.sm + 2),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                child: Row(
                  children: [
                    Icon(Icons.circle_rounded, size: 14, color: colors.fg),
                    const SizedBox(width: Gap.sm),
                    Expanded(
                      child: Text(
                        _labelFor(row.key!, dhivehi: dhivehi),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(width: Gap.sm),
          _amountField(context, row),
          _deleteButton(context, index),
        ],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: RawAutocomplete<String>(
            textEditingController: row.search,
            focusNode: row.focus,
            optionsBuilder: (value) {
              final options = _optionsFor(row);
              final query = value.text.trim().toLowerCase();
              // A settled pick shows the whole menu again on refocus —
              // that is how "change my mind" works without clearing first.
              final settled =
                  row.key != null &&
                  value.text == _labelFor(row.key!, dhivehi: dhivehi);
              if (query.isEmpty || settled) return options;
              return [
                for (final key in options)
                  if (_labelFor(key, dhivehi: dhivehi)
                      .toLowerCase()
                      .contains(query))
                    key,
              ];
            },
            displayStringForOption: (key) =>
                _labelFor(key, dhivehi: dhivehi),
            onSelected: (key) {
              setState(() => row.key = key);
              // Park the caret at the START of the settled label: the field
              // is narrower than a long category name ("Everything else"),
              // and a caret left at the end scrolls the text so the row
              // reads "…ing else" instead of naming its category.
              row.search.selection = const TextSelection.collapsed(offset: 0);
              _emit();
            },
            fieldViewBuilder:
                (context, controller, focusNode, onFieldSubmitted) =>
                    TextField(
              controller: controller,
              focusNode: focusNode,
              onSubmitted: (_) => onFieldSubmitted(),
              onChanged: (text) {
                // Typing past the settled label un-picks the row until a
                // fresh option is chosen — a half-edited name must never
                // silently keep the old slug on the wire.
                if (row.key != null &&
                    text != _labelFor(row.key!, dhivehi: dhivehi)) {
                  setState(() => row.key = null);
                  _emit();
                }
              },
              decoration: InputDecoration(
                hintText: l10n.splitSearchHint,
                // The ref's colour chip, kept as the picked row's dot.
                prefixIcon: Icon(
                  row.key == null
                      ? Icons.search_rounded
                      : Icons.circle_rounded,
                  size: row.key == null ? 20 : 14,
                  color: row.key == null
                      ? theme.colorScheme.onSurfaceVariant
                      : colors.fg,
                ),
              ),
            ),
            optionsViewBuilder: (context, onSelected, options) =>
                Align(
              alignment: AlignmentDirectional.topStart,
              child: Material(
                elevation: 4,
                borderRadius: BorderRadius.circular(Corner.tile),
                clipBehavior: Clip.antiAlias,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(
                    maxHeight: 224,
                    maxWidth: 320,
                  ),
                  child: ListView(
                    padding: const EdgeInsets.symmetric(vertical: Gap.xs),
                    shrinkWrap: true,
                    children: [
                      for (final key in options)
                        InkWell(
                          onTap: () => onSelected(key),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: Gap.md,
                              vertical: Gap.sm + 2,
                            ),
                            child: Row(
                              children: [
                                Icon(
                                  Icons.circle_rounded,
                                  size: 12,
                                  color: tintColors(
                                    _tintFor(key),
                                    theme.brightness,
                                  ).fg,
                                ),
                                const SizedBox(width: Gap.sm),
                                Expanded(
                                  child: Text(
                                    _labelFor(key, dhivehi: dhivehi),
                                    style: theme.textTheme.bodyMedium,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(width: Gap.sm),
        _amountField(context, row),
        _deleteButton(context, index),
      ],
    );
  }

  /// The row's money box — shared by the settled (chip) and picking
  /// (autocomplete) halves so the two states line up to the pixel.
  Widget _amountField(BuildContext context, _DraftRow row) {
    final theme = Theme.of(context);
    return SizedBox(
      width: 116,
      child: TextField(
        controller: row.amount,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        textDirection: TextDirection.ltr,
        onChanged: (_) {
          setState(() {});
          _emit();
        },
        style: theme.textTheme.bodyLarge?.copyWith(
          fontFeatures: const [FontFeature.tabularFigures()],
        ),
        decoration: InputDecoration(
          hintText: '0.00',
          prefixText: 'MVR ',
          prefixStyle: theme.textTheme.labelLarge?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
          errorText: row.amountInvalid ? '' : null,
          errorStyle: const TextStyle(height: 0, fontSize: 0),
        ),
      ),
    );
  }

  Widget _deleteButton(BuildContext context, int index) {
    final theme = Theme.of(context);
    return IconButton(
      onPressed: () => _removeRow(index),
      tooltip: context.l10n.splitRemoveLine,
      icon: const Icon(Icons.delete_outline_rounded, size: 20),
      color: theme.colorScheme.onSurfaceVariant,
      padding: const EdgeInsets.only(top: 14),
      visualDensity: VisualDensity.compact,
    );
  }
}
