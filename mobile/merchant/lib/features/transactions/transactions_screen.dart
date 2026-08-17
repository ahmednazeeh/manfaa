import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/merchant_brand.dart';
import '../../widgets/tx_format.dart';
import 'paged.dart';

/// The Transactions tab (MR2): every credited sale, cursor-paged newest
/// first, in the ss-era card language — invoice + date, amounts through
/// MoneyText, a StatusChip per state, and the state-filter chips.
///
/// Row actions exist ONLY while the sale is `awaiting_validation` and not
/// backdated — the exact window the API enforces. Outside it the buttons are
/// absent, not greyed: there is nothing the cashier can do about a sale the
/// platform now owns.

/// The §6 state vocabulary, in filter order (TransactionStateSchema).
const kTransactionStates = [
  'tracked',
  'awaiting_validation',
  'payable_unfunded',
  'on_hold',
  'confirmed',
  'paid',
  'reversed',
  'written_off',
];

final txStateFilterProvider = StateProvider<String?>((_) => null);

final txPagerProvider =
    StateNotifierProvider.autoDispose<
      Pager<MerchantTransaction>,
      PagedState<MerchantTransaction>
    >((ref) {
      final state = ref.watch(txStateFilterProvider);
      return Pager(
        ({cursor}) =>
            ref.read(apiProvider).transactions(cursor: cursor, state: state),
      );
    });

class TransactionsScreen extends ConsumerWidget {
  const TransactionsScreen({super.key});

  List<Widget> _header(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final session = ref.watch(sessionProvider);
    final filter = ref.watch(txStateFilterProvider);
    final name = session.merchantName ?? session.userName ?? 'M';

    return [
      MerchantTopBar(
        initials: name.isEmpty ? 'M' : name.characters.first.toUpperCase(),
      ),
      const SizedBox(height: Gap.lg),
      Text(
        l10n.transactionsTitle,
        style: theme.textTheme.headlineMedium?.copyWith(
          fontWeight: FontWeight.w800,
        ),
      ),
      const SizedBox(height: Gap.md),
      SizedBox(
        height: 38,
        child: ListView(
          scrollDirection: Axis.horizontal,
          children: [
            _FilterChip(
              label: l10n.filterAll,
              selected: filter == null,
              onTap: () =>
                  ref.read(txStateFilterProvider.notifier).state = null,
            ),
            for (final state in kTransactionStates)
              _FilterChip(
                label: transactionStateLabel(l10n, state),
                selected: filter == state,
                onTap: () =>
                    ref.read(txStateFilterProvider.notifier).state = state,
              ),
          ],
        ),
      ),
      const SizedBox(height: Gap.md),
    ];
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final l10n = context.l10n;
    final state = ref.watch(txPagerProvider);
    final filter = ref.watch(txStateFilterProvider);
    final header = _header(context, ref);

    Widget body;
    if (!state.loaded) {
      body = ListView(
        padding: _pagePadding,
        children: [
          ...header,
          for (var i = 0; i < 5; i++) ...[
            const SkeletonBox(height: 96, radius: Corner.card),
            const SizedBox(height: Gap.md),
          ],
        ],
      );
    } else if (state.error != null) {
      body = ListView(
        padding: _pagePadding,
        children: [
          ...header,
          const SizedBox(height: Gap.xl),
          Center(
            child: Column(
              children: [
                const IconTile(
                  Icons.wifi_off_rounded,
                  tint: ManfaaTint.neutral,
                  size: 56,
                  iconSize: 26,
                ),
                const SizedBox(height: Gap.md),
                Text(
                  state.error!.isEmpty ? l10n.errorGeneric : state.error!,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: Gap.md),
                OutlinedButton(
                  onPressed: () => ref.read(txPagerProvider.notifier).refresh(),
                  child: Text(l10n.retry),
                ),
              ],
            ),
          ),
        ],
      );
    } else if (state.items.isEmpty) {
      body = RefreshIndicator(
        onRefresh: () => ref.read(txPagerProvider.notifier).refresh(),
        child: ListView(
          padding: _pagePadding,
          children: [
            ...header,
            const SizedBox(height: Gap.xl),
            Center(
              child: Column(
                children: [
                  const IconTile(
                    Icons.receipt_long_outlined,
                    tint: ManfaaTint.blue,
                    size: 56,
                    iconSize: 26,
                  ),
                  const SizedBox(height: Gap.md),
                  Text(
                    filter == null ? l10n.txEmptyTitle : l10n.txEmptyFiltered,
                    style: Theme.of(context).textTheme.titleMedium,
                    textAlign: TextAlign.center,
                  ),
                  if (filter == null) ...[
                    const SizedBox(height: Gap.xs),
                    Text(
                      l10n.txEmptyBody,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      );
    } else {
      body = RefreshIndicator(
        onRefresh: () => ref.read(txPagerProvider.notifier).refresh(),
        child: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            if (n.metrics.extentAfter < 400) {
              ref.read(txPagerProvider.notifier).loadMore();
            }
            return false;
          },
          child: ListView.builder(
            padding: _pagePadding,
            itemCount:
                header.length +
                state.items.length +
                (state.loadingMore ? 1 : 0),
            itemBuilder: (context, index) {
              if (index < header.length) return header[index];
              final i = index - header.length;
              if (i >= state.items.length) {
                return const Center(
                  child: Padding(
                    padding: EdgeInsets.all(Gap.lg),
                    child: SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2.5),
                    ),
                  ),
                );
              }
              return Padding(
                padding: const EdgeInsets.only(bottom: Gap.md),
                child: TransactionTile(transaction: state.items[i]),
              );
            },
          ),
        ),
      );
    }

    return Scaffold(body: SafeArea(bottom: false, child: body));
  }
}

const _pagePadding = EdgeInsets.fromLTRB(
  Gap.xl,
  Gap.lg,
  Gap.xl,
  Gap.navClearance,
);

class _FilterChip extends StatelessWidget {
  const _FilterChip({
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

    return Padding(
      padding: const EdgeInsetsDirectional.only(end: Gap.sm),
      child: InkWell(
        borderRadius: BorderRadius.circular(999),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 8),
          decoration: BoxDecoration(
            color: selected
                ? theme.colorScheme.primary
                : theme.colorScheme.surfaceContainerLowest,
            borderRadius: BorderRadius.circular(999),
            border: selected
                ? null
                : Border.all(color: theme.colorScheme.outlineVariant),
          ),
          child: Text(
            label,
            style: theme.textTheme.labelMedium?.copyWith(
              color: selected
                  ? theme.colorScheme.onPrimary
                  : theme.colorScheme.onSurfaceVariant,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
      ),
    );
  }
}

/// One credited sale, ss-era card language: invoice + date leading, the
/// eligible amount + state chip trailing, reason + backdated qualifiers
/// under, and the correction actions inside the API's window only.
class TransactionTile extends ConsumerWidget {
  const TransactionTile({super.key, required this.transaction});

  final MerchantTransaction transaction;

  (IconData, ManfaaTint) _tile() => switch (transaction.state) {
    'tracked' ||
    'awaiting_validation' => (Icons.schedule_rounded, ManfaaTint.amber),
    'payable_unfunded' => (Icons.account_balance_rounded, ManfaaTint.amber),
    'confirmed' => (Icons.check_rounded, ManfaaTint.green),
    'paid' => (Icons.account_balance_rounded, ManfaaTint.blue),
    'on_hold' => (Icons.pause_circle_outline_rounded, ManfaaTint.coral),
    'reversed' => (Icons.replay_rounded, ManfaaTint.neutral),
    'written_off' => (Icons.money_off_rounded, ManfaaTint.coral),
    _ => (Icons.receipt_long_outlined, ManfaaTint.neutral),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final session = ref.watch(sessionProvider);
    final (icon, tint) = _tile();
    final reason = transactionReasonLabel(l10n, transaction.reasonCode);

    // The same window the API enforces; drawing is additionally gated on
    // the role's own slugs (server enforces regardless).
    final correctable =
        transaction.state == 'awaiting_validation' && !transaction.backdated;
    final canAmend = correctable && session.can('transactions.amend');
    final canCancel = correctable && session.can('transactions.cancel');

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconTile(icon, tint: tint),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      transaction.invoiceNo,
                      textDirection: TextDirection.ltr,
                      style: theme.textTheme.titleMedium,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      formatIsoDisplay(transaction.occurredAt),
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: Gap.sm),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  MoneyText(
                    transaction.eligibleLaari,
                    style: theme.textTheme.titleMedium,
                  ),
                  const SizedBox(height: Gap.xs),
                  StatusChip(
                    label: transactionStateLabel(l10n, transaction.state),
                    tone: transactionStateTone(transaction.state),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          Wrap(
            spacing: Gap.sm,
            runSpacing: Gap.xs,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                '${l10n.todayCashback}: ',
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              MoneyText(
                transaction.cashbackLaari,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              if (transaction.backdated)
                StatusChip(
                  label: l10n.backdatedChip,
                  tone: StatusTone.attention,
                ),
              if (reason != null)
                Text(
                  reason,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
            ],
          ),
          if (canAmend || canCancel) ...[
            const SizedBox(height: Gap.xs),
            // A Wrap, not a Row: the two labels sit side by side when they
            // fit and stack when they don't (long dv labels, big fonts).
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Wrap(
                alignment: WrapAlignment.end,
                spacing: Gap.xs,
                children: [
                  if (canAmend)
                    TextButton.icon(
                      onPressed: () => _openAmend(context, ref),
                      icon: const Icon(Icons.edit_outlined, size: 17),
                      label: Text(l10n.amendAction),
                    ),
                  if (canCancel)
                    TextButton.icon(
                      onPressed: () => _openCancel(context, ref),
                      style: TextButton.styleFrom(
                        foregroundColor: theme.colorScheme.error,
                      ),
                      icon: const Icon(Icons.undo_rounded, size: 17),
                      label: Text(l10n.cancelAction),
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  void _openAmend(BuildContext context, WidgetRef ref) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(Corner.sheet)),
      ),
      builder: (_) => AmendSheet(
        transaction: transaction,
        onDone: () => ref.read(txPagerProvider.notifier).refresh(),
      ),
    );
  }

  void _openCancel(BuildContext context, WidgetRef ref) {
    showDialog<void>(
      context: context,
      builder: (_) => CancelDialog(
        transaction: transaction,
        onDone: () => ref.read(txPagerProvider.notifier).refresh(),
      ),
    );
  }
}

/// Localized sentences for the correction refusals; unknown codes render
/// the server's own prose — never the raw code.
String describeCorrectionError(AppLocalizations l10n, Object error) {
  if (error is! MobileApiException) return l10n.errorGeneric;
  return switch (error.code) {
    ApiCode.notAmendableState || ApiCode.invalidState => l10n.errNotAmendable,
    ApiCode.backdatedIrreversible => l10n.errBackdatedIrreversible,
    ApiCode.rateLimited => l10n.errTooManyTries,
    _ => error.message,
  };
}

/// The amend sheet: one eligible field for a single-rate sale; per-line
/// fields when the sale was split — the eligible amount IS their sum, so
/// there is no second figure to disagree (web parity).
class AmendSheet extends ConsumerStatefulWidget {
  const AmendSheet({
    super.key,
    required this.transaction,
    required this.onDone,
  });

  final MerchantTransaction transaction;
  final VoidCallback onDone;

  @override
  ConsumerState<AmendSheet> createState() => _AmendSheetState();
}

class _AmendSheetState extends ConsumerState<AmendSheet> {
  late final TextEditingController _eligible;
  late final List<TextEditingController> _lines;
  var _busy = false;
  String? _error;

  bool get _lined => widget.transaction.lines.isNotEmpty;

  @override
  void initState() {
    super.initState();
    _eligible = TextEditingController(
      text: formatRufiyaa(widget.transaction.eligibleLaari),
    );
    _lines = [
      for (final line in widget.transaction.lines)
        TextEditingController(text: formatRufiyaa(line.amountLaari)),
    ];
  }

  @override
  void dispose() {
    _eligible.dispose();
    for (final controller in _lines) {
      controller.dispose();
    }
    super.dispose();
  }

  List<int?> get _parsedLines => [
    for (final controller in _lines) parseMvrToLaari(controller.text),
  ];

  int? get _parsed {
    if (_lined) {
      final parsed = _parsedLines;
      if (parsed.any((amount) => amount == null || amount < 1)) return null;
      return parsed.fold<int>(0, (sum, amount) => sum + amount!);
    }
    return parseMvrToLaari(_eligible.text);
  }

  bool get _valid {
    final parsed = _parsed;
    return parsed != null &&
        parsed >= 1 &&
        parsed != widget.transaction.eligibleLaari;
  }

  Future<void> _save() async {
    final l10n = context.l10n;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(apiProvider)
          .amendTransaction(
            id: widget.transaction.id,
            eligibleLaari: _parsed!,
            lines: _lined
                ? [
                    for (final (index, line)
                        in widget.transaction.lines.indexed)
                      CreditLine(
                        category: line.category,
                        amountLaari: _parsedLines[index]!,
                      ),
                  ]
                : null,
          );
      if (!mounted) return;
      Navigator.of(context).pop();
      widget.onDone();
      rootMessengerKey.currentState?.showSnackBar(
        SnackBar(content: Text(l10n.amendDone)),
      );
    } catch (e) {
      if (mounted) {
        setState(() => _error = describeCorrectionError(l10n, e));
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
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final tx = widget.transaction;

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.xl,
        bottom: MediaQuery.of(context).viewInsets.bottom + Gap.xl,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.amendTitle, style: theme.textTheme.titleLarge),
          const SizedBox(height: Gap.xs),
          Text(
            l10n.amendBody(tx.invoiceNo),
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: Gap.lg),
          if (_lined) ...[
            Text(l10n.amendLinesLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            for (final (index, line) in tx.lines.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: Gap.sm),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        line.category == null
                            ? l10n.splitEverythingElse
                            : (line.categoryNameEn ?? line.category!),
                        style: theme.textTheme.bodyMedium,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: Gap.md),
                    SizedBox(
                      width: 150,
                      child: TextField(
                        controller: _lines[index],
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        textDirection: TextDirection.ltr,
                        onChanged: (_) => setState(() {}),
                        decoration: const InputDecoration(
                          prefixText: 'MVR ',
                          isDense: true,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            Text(
              l10n.amendLinesHint,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ] else ...[
            Text(l10n.amendEligibleLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _eligible,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              textDirection: TextDirection.ltr,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(prefixText: 'MVR '),
            ),
            const SizedBox(height: 6),
            Text(
              l10n.amendHint,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],
          const SizedBox(height: Gap.md),
          Text(
            l10n.amendCurrent(
              formatMoney(tx.eligibleLaari, dhivehi: dhivehi),
              formatMoney(tx.cashbackLaari, dhivehi: dhivehi),
            ),
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
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
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(
                onPressed: _busy ? null : () => Navigator.of(context).pop(),
                child: Text(l10n.cancel),
              ),
              const SizedBox(width: Gap.sm),
              FilledButton(
                style: FilledButton.styleFrom(
                  minimumSize: const Size(0, 48),
                  padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
                ),
                onPressed: _valid && !_busy ? _save : null,
                child: _busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(l10n.amendSubmit),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// The cancel dialog: reason radio (refund | void | duplicate | error),
/// optional note, and the plain-words warning that the customer has already
/// been told they earned this.
class CancelDialog extends ConsumerStatefulWidget {
  const CancelDialog({
    super.key,
    required this.transaction,
    required this.onDone,
  });

  final MerchantTransaction transaction;
  final VoidCallback onDone;

  @override
  ConsumerState<CancelDialog> createState() => _CancelDialogState();
}

class _CancelDialogState extends ConsumerState<CancelDialog> {
  static const _reasons = ['refund', 'void', 'duplicate', 'error'];

  var _reason = 'refund';
  final _note = TextEditingController();
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  String _reasonLabel(AppLocalizations l10n, String reason) => switch (reason) {
    'refund' => l10n.cancelReasonRefund,
    'void' => l10n.cancelReasonVoid,
    'duplicate' => l10n.cancelReasonDuplicate,
    _ => l10n.cancelReasonError,
  };

  Future<void> _confirm() async {
    final l10n = context.l10n;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(apiProvider)
          .cancelTransaction(
            id: widget.transaction.id,
            reason: _reason,
            note: _note.text.trim().isEmpty ? null : _note.text.trim(),
          );
      if (!mounted) return;
      Navigator.of(context).pop();
      widget.onDone();
      rootMessengerKey.currentState?.showSnackBar(
        SnackBar(content: Text(l10n.cancelDone)),
      );
    } catch (e) {
      if (mounted) {
        setState(() => _error = describeCorrectionError(l10n, e));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return AlertDialog(
      title: Text(l10n.cancelTitle),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              l10n.cancelBody(widget.transaction.invoiceNo),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.md),
            Text(l10n.cancelReasonLabel, style: theme.textTheme.labelLarge),
            for (final reason in _reasons)
              RadioListTile<String>(
                value: reason,
                // ignore: deprecated_member_use
                groupValue: _reason,
                // ignore: deprecated_member_use
                onChanged: (value) =>
                    setState(() => _reason = value ?? _reason),
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(_reasonLabel(l10n, reason)),
              ),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _note,
              maxLength: 500,
              maxLines: 2,
              decoration: InputDecoration(
                labelText: l10n.cancelNoteLabel,
                counterText: '',
              ),
            ),
            const SizedBox(height: Gap.md),
            Text(
              l10n.cancelWarning,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: Gap.sm),
              Text(
                _error!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: Text(l10n.keepSale),
        ),
        FilledButton(
          style: FilledButton.styleFrom(
            minimumSize: const Size(0, 44),
            padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
            backgroundColor: theme.colorScheme.error,
            foregroundColor: Colors.white,
          ),
          onPressed: _busy ? null : _confirm,
          child: _busy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Text(l10n.cancelSubmit),
        ),
      ],
    );
  }
}
