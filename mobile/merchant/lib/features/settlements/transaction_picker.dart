import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../widgets/tx_format.dart';
import '../money/money_providers.dart';
import 'settlement_widgets.dart' show ToneBanner;

/// The till's transaction picker (MR11) — the phone's answer to the web
/// panel's `components/settlement/transaction-picker.tsx`, decision for
/// decision:
///
///  * **Membership is the SERVER's.** A preset filters by the bucket's own
///    `transaction_ids`, never by an age rule re-evaluated here, so the
///    picker, the dashboard and the PLAN §1 discount window can never
///    disagree about whether a sale is "10 days old".
///  * **Every figure on a row is a stored integer** the preview carried.
///    The running total is an exact addition of those `due_laari` integers
///    to keep a tick instant — no rate is applied, nothing is divided, and
///    the amount the merchant actually transfers is the one the SERVER
///    prices after the selection lands.
///  * **Ticking everything is settle-all.** Web parity (`applySelection`):
///    a pick that covers every payable row goes back as the race-proof
///    `settle_all` MODE instead of a frozen id list, so a sale landing
///    between the pick and the submit joins the batch.
///
/// Re-pricing is the warning this screen leads with: a narrower batch is a
/// different batch, and PLAN §1's prompt-payment discount can be lost with
/// it. The server decides that, on the next preview — this screen only
/// changes which ids get asked about.
class TransactionPickerScreen extends ConsumerStatefulWidget {
  const TransactionPickerScreen({super.key, required this.catalogue});

  /// The settle-all preview: every payable row, the preset buckets and the
  /// discount verdict for settling everything.
  final SettlementPreviewData catalogue;

  @override
  ConsumerState<TransactionPickerScreen> createState() =>
      _TransactionPickerScreenState();
}

class _TransactionPickerScreenState
    extends ConsumerState<TransactionPickerScreen> {
  /// The preset whose rows are on screen — a FILTER over the catalogue,
  /// not the selection itself.
  var _preset = 'all';

  /// Every id currently in the batch.
  late Set<int> _selected;

  @override
  void initState() {
    super.initState();
    final custom = ref.read(settlementCustomSelectionProvider);
    final preset = ref.read(settlementsPresetProvider);
    final rows = widget.catalogue.transactions;
    // A preset narrows the board to the SERVER's own membership ids, and
    // that narrowed batch is what the tab is pricing. The picker has to
    // open on it: seeded from the whole catalogue instead, an untouched
    // Apply would silently widen a narrowed batch back to everything.
    final presetIds = preset == 'all'
        ? null
        : widget.catalogue.buckets[preset]?.transactionIds;
    _selected = {
      if (custom != null && custom.isNotEmpty)
        ...custom
      else if (presetIds != null && presetIds.isNotEmpty)
        ...presetIds
      // Neither a hand-pick nor a narrowing preset: the board as the server
      // left it — the preview's own `selected` flags.
      else
        for (final row in rows)
          if (row.selected) row.id,
    };
  }

  List<SettlementPickerRow> get _visible {
    if (_preset == 'all') return widget.catalogue.transactions;
    final inPreset = {
      ...?widget.catalogue.buckets[_preset]?.transactionIds,
    };
    return [
      for (final row in widget.catalogue.transactions)
        if (inPreset.contains(row.id)) row,
    ];
  }

  /// PLAN §1's age window, so a row past it can say WHY the batch may not
  /// earn the discount. 0 when the incentive is switched off platform-wide.
  int get _discountMaxAgeDays {
    final discount = widget.catalogue.discount;
    if (discount == null || discount.disabled) return 0;
    return discount.maxAgeDays;
  }

  void _choosePreset(String key) {
    setState(() {
      _preset = key;
      // Web parity: picking a preset SELECTS it — 'all' takes the whole
      // board, a bucket takes exactly the ids the server put in it.
      _selected = key == 'all'
          ? {for (final row in widget.catalogue.transactions) row.id}
          : {...?widget.catalogue.buckets[key]?.transactionIds};
    });
  }

  void _apply() {
    final rows = widget.catalogue.transactions;
    final ids = [
      for (final row in rows)
        if (_selected.contains(row.id)) row.id,
    ];
    if (ids.isEmpty) return;

    // Everything ticked is settle-all, not a frozen list (web
    // `applySelection`): the race-proof mode keeps a sale that lands in the
    // next second inside the batch.
    if (ids.length == rows.length) {
      ref.read(settlementCustomSelectionProvider.notifier).state = null;
      ref.read(settlementsPresetProvider.notifier).state = 'all';
    } else {
      ref.read(settlementCustomSelectionProvider.notifier).state = ids;
    }
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;
    final rows = _visible;
    final selectedRows = [
      for (final row in widget.catalogue.transactions)
        if (_selected.contains(row.id)) row,
    ];
    // An exact addition of the server's per-row integers — see the
    // docblock. The figure to transfer still comes from the re-price.
    final selectedTotalLaari = selectedRows.fold<int>(
      0,
      (total, row) => total + row.dueLaari,
    );
    final pageTicked =
        rows.isNotEmpty && rows.every((row) => _selected.contains(row.id));
    // Half the list ticked is its own state: an empty box would claim none
    // of these sales is in the batch, which is not what the merchant chose
    // (web parity — the picker's indeterminate header checkbox).
    final bool? pageState = pageTicked
        ? true
        : (rows.any((row) => _selected.contains(row.id)) ? null : false);

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.pickerTitle),
        titleTextStyle: theme.textTheme.titleMedium,
      ),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.sm,
                  Gap.xl,
                  Gap.lg,
                ),
                children: [
                  // The whole point of the warning: the batch is priced
                  // again, by the server, and the discount can go with it.
                  ToneBanner(
                    tone: ToneSurface.pending,
                    icon: Icons.published_with_changes_rounded,
                    title: l10n.pickerRepriceTitle,
                    body: l10n.pickerRepriceBody,
                  ),
                  const SizedBox(height: Gap.md),
                  Text(
                    l10n.pickerLead,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: Gap.md),
                  _PresetRow(
                    catalogue: widget.catalogue,
                    preset: _preset,
                    onChoose: _choosePreset,
                  ),
                  const SizedBox(height: Gap.md),
                  ManfaaCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        InkWell(
                          borderRadius: BorderRadius.circular(Corner.tile),
                          onTap: rows.isEmpty
                              ? null
                              : () => setState(() {
                                  if (pageTicked) {
                                    _selected.removeAll(rows.map((r) => r.id));
                                  } else {
                                    _selected.addAll(rows.map((r) => r.id));
                                  }
                                }),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              vertical: Gap.xs,
                            ),
                            child: Row(
                              children: [
                                Checkbox(
                                  value: pageState,
                                  tristate: true,
                                  onChanged: rows.isEmpty
                                      ? null
                                      : (_) => setState(() {
                                          if (pageTicked) {
                                            _selected.removeAll(
                                              rows.map((r) => r.id),
                                            );
                                          } else {
                                            _selected.addAll(
                                              rows.map((r) => r.id),
                                            );
                                          }
                                        }),
                                ),
                                const SizedBox(width: Gap.xs),
                                Expanded(
                                  child: Text(
                                    l10n.pickerSelectAll,
                                    style: theme.textTheme.labelLarge,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                        for (final (index, row) in rows.indexed) ...[
                          if (index > 0) const Divider(height: 1),
                          _PickerRow(
                            row: row,
                            selected: _selected.contains(row.id),
                            pastWindow:
                                _discountMaxAgeDays > 0 &&
                                row.ageDays >= _discountMaxAgeDays,
                            onToggle: () => setState(() {
                              if (!_selected.remove(row.id)) {
                                _selected.add(row.id);
                              }
                            }),
                          ),
                        ],
                        if (rows.isEmpty)
                          Padding(
                            padding: const EdgeInsets.symmetric(
                              vertical: Gap.lg,
                            ),
                            child: Text(
                              l10n.pickerNoneInFilter,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: muted,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            // The running tally + the one button that commits the pick.
            Container(
              width: double.infinity,
              color: theme.colorScheme.surfaceContainerLowest,
              padding: const EdgeInsets.fromLTRB(
                Gap.xl,
                Gap.md,
                Gap.xl,
                Gap.md,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          l10n.pickerSelectedCount(selectedRows.length),
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: muted,
                          ),
                        ),
                      ),
                      MoneyText(
                        selectedTotalLaari,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    l10n.pickerTotalHint,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: Gap.md),
                  FilledButton(
                    key: const Key('picker-apply'),
                    onPressed: selectedRows.isEmpty ? null : _apply,
                    child: Text(l10n.pickerApplyCta),
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

/// The age filters, each carrying its bucket's server count and total. An
/// empty bucket is unpickable, exactly as the web draws it.
class _PresetRow extends StatelessWidget {
  const _PresetRow({
    required this.catalogue,
    required this.preset,
    required this.onChoose,
  });

  final SettlementPreviewData catalogue;
  final String preset;
  final ValueChanged<String> onChoose;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    Widget chip(String key, String label) {
      final bucket = catalogue.buckets[key];
      final count = bucket?.count ?? 0;
      final empty = key != 'all' && count == 0;
      final selected = preset == key;

      return Padding(
        padding: const EdgeInsetsDirectional.only(end: Gap.sm),
        child: ChoiceChip(
          label: Text('$label · $count'),
          selected: selected,
          onSelected: empty ? null : (_) => onChoose(key),
          labelStyle: theme.textTheme.labelLarge?.copyWith(
            color: selected
                ? theme.colorScheme.onSecondaryContainer
                : theme.colorScheme.onSurfaceVariant,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
          ),
          selectedColor: theme.colorScheme.secondaryContainer,
          showCheckmark: false,
        ),
      );
    }

    return SizedBox(
      height: 40,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          chip('all', l10n.presetAllLabel),
          chip('older_than_5', l10n.presetOlder5),
          chip('older_than_10', l10n.presetOlder10),
          chip('overdue', l10n.bucketOverdue),
        ],
      ),
    );
  }
}

/// One payable sale: its invoice, its date, its age with the badge that
/// explains a lost discount, and the server's own due integer.
class _PickerRow extends StatelessWidget {
  const _PickerRow({
    required this.row,
    required this.selected,
    required this.pastWindow,
    required this.onToggle,
  });

  final SettlementPickerRow row;
  final bool selected;
  final bool pastWindow;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.tile),
      onTap: onToggle,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.xs),
        child: Row(
          children: [
            Checkbox(value: selected, onChanged: (_) => onToggle()),
            const SizedBox(width: Gap.xs),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    row.invoiceNo,
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.titleSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    row.occurredAt == null
                        ? '—'
                        : formatBusinessDate(row.occurredAt!),
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: Gap.xs,
                    runSpacing: Gap.xs,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        l10n.pickerAgeDays(row.ageDays),
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: muted,
                        ),
                      ),
                      if (row.overdue)
                        StatusChip(
                          label: l10n.bucketOverdue,
                          tone: StatusTone.attention,
                        )
                      else if (pastWindow)
                        StatusChip(
                          label: l10n.pickerPastWindow,
                          tone: StatusTone.pending,
                        ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(width: Gap.sm),
            MoneyText(
              row.dueLaari,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
