import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../widgets/tx_format.dart';
import '../setup/rate_step.dart' show formatBp, parsePercentToBp;

/// The Credit screen's building blocks, drawn to the two Credit refs: the
/// segmented input-mode row, the 6-digit OTP-style code boxes, the verified
/// customer chip, the queue banners, and the result card.

enum CodeMode { enter, scan, recent }

/// The Enter code / Scan QR / Recent segmented row from the refs — selected
/// slot gets the violet-soft pill.
class ModeRow extends StatelessWidget {
  const ModeRow({super.key, required this.mode, required this.onChanged});

  final CodeMode mode;
  final ValueChanged<CodeMode> onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return Row(
      children: [
        _ModeButton(
          icon: Icons.keyboard_alt_outlined,
          label: l10n.modeEnterCode,
          selected: mode == CodeMode.enter,
          onTap: () => onChanged(CodeMode.enter),
        ),
        _ModeButton(
          icon: Icons.qr_code_scanner_rounded,
          label: l10n.modeScanQr,
          selected: mode == CodeMode.scan,
          onTap: () => onChanged(CodeMode.scan),
        ),
        _ModeButton(
          icon: Icons.history_rounded,
          label: l10n.modeRecent,
          selected: mode == CodeMode.recent,
          onTap: () => onChanged(CodeMode.recent),
        ),
      ],
    );
  }
}

class _ModeButton extends StatelessWidget {
  const _ModeButton({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fg = selected
        ? theme.colorScheme.onSecondaryContainer
        : theme.colorScheme.onSurfaceVariant;

    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.control),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
          decoration: BoxDecoration(
            color: selected
                ? theme.colorScheme.secondaryContainer
                : Colors.transparent,
            borderRadius: BorderRadius.circular(Corner.control),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 18, color: fg),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: fg,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Six OTP-style digit boxes over one real (invisible) TextField, so the
/// soft keyboard, paste, hardware keyboards and widget tests all speak to a
/// normal field. [onSubmitted] is the Enter a USB/BT barcode gun sends after
/// its digits (MR7) — the screen decides what Enter means.
class CodeBoxes extends StatelessWidget {
  const CodeBoxes({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.onChanged,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final ValueChanged<String> onChanged;
  final ValueChanged<String>? onSubmitted;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GestureDetector(
      onTap: focusNode.requestFocus,
      child: SizedBox(
        height: 56,
        child: Stack(
          children: [
            ValueListenableBuilder<TextEditingValue>(
              valueListenable: controller,
              builder: (context, value, _) {
                final digits = value.text;
                return Row(
                  textDirection: TextDirection.ltr,
                  children: [
                    for (var i = 0; i < 6; i++) ...[
                      if (i > 0) const SizedBox(width: Gap.sm),
                      Expanded(
                        child: Container(
                          height: 56,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: theme.colorScheme.surfaceContainerLow,
                            borderRadius: BorderRadius.circular(Corner.tile),
                            border: Border.all(
                              color: focusNode.hasFocus && i == digits.length
                                  ? theme.colorScheme.secondary
                                  : theme.colorScheme.outlineVariant,
                              width: focusNode.hasFocus && i == digits.length
                                  ? 1.6
                                  : 1,
                            ),
                          ),
                          child: Text(
                            i < digits.length ? digits[i] : '',
                            style: theme.textTheme.titleLarge?.copyWith(
                              fontWeight: FontWeight.w700,
                              fontFeatures: const [
                                FontFeature.tabularFigures(),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ],
                  ],
                );
              },
            ),
            // The real input: full-size, invisible, digits-only.
            Opacity(
              opacity: 0,
              child: TextField(
                controller: controller,
                focusNode: focusNode,
                keyboardType: TextInputType.number,
                textInputAction: TextInputAction.done,
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(6),
                ],
                showCursor: false,
                enableInteractiveSelection: false,
                autocorrect: false,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  counterText: '',
                ),
                onChanged: onChanged,
                onSubmitted: onSubmitted,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The verified customer chip from the refs: green circle avatar, full name
/// + code, and the green Verified pill.
class VerifiedCustomerChip extends StatelessWidget {
  const VerifiedCustomerChip({
    super.key,
    required this.name,
    required this.code,
  });

  final String name;
  final String code;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final green = tintColors(ManfaaTint.green, theme.brightness);

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLow,
        borderRadius: BorderRadius.circular(Corner.control),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(color: green.bg, shape: BoxShape.circle),
            child: Icon(
              Icons.person_outline_rounded,
              color: green.fg,
              size: 26,
            ),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  code,
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.sm),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: green.bg,
              borderRadius: BorderRadius.circular(999),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.check_circle_outline_rounded,
                  size: 15,
                  color: green.fg,
                ),
                const SizedBox(width: 4),
                Text(
                  l10n.lookupVerified,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: green.fg,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The pending-sync banner: N composed sales waiting for the network, with
/// a manual "Sync now".
class QueueBanner extends StatelessWidget {
  const QueueBanner({super.key, required this.count, required this.onSync});

  final int count;
  final VoidCallback onSync;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final colors = toneSurface(ToneSurface.pending, theme.brightness);

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        children: [
          Icon(Icons.sync_rounded, size: 20, color: colors.foreground),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.queuedBannerTitle(count),
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: colors.foreground,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.queuedBannerBody,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: colors.foreground,
                  ),
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: onSync,
            child: Text(
              l10n.queuedBannerAction,
              style: TextStyle(
                color: colors.foreground,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Parked refusals — queued sales the drain met a permanent refusal on.
/// Every entry is shown with its server sentence and a human decision:
/// try again or dismiss. Never silently dropped.
class NeedsAttentionCard extends StatelessWidget {
  const NeedsAttentionCard({
    super.key,
    required this.entries,
    required this.onRetry,
    required this.onDiscard,
  });

  final List<QueuedCredit> entries;
  final void Function(QueuedCredit entry) onRetry;
  final void Function(QueuedCredit entry) onDiscard;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final colors = toneSurface(ToneSurface.attention, theme.brightness);

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
              Icon(
                Icons.error_outline_rounded,
                size: 18,
                color: colors.foreground,
              ),
              const SizedBox(width: Gap.sm),
              Text(
                l10n.attentionTitle,
                style: theme.textTheme.titleSmall?.copyWith(
                  color: colors.foreground,
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.xs),
          Text(
            l10n.attentionBody,
            style: theme.textTheme.bodySmall?.copyWith(
              color: colors.foreground,
            ),
          ),
          for (final entry in entries) ...[
            const SizedBox(height: Gap.md),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${entry.invoiceNo} · ${entry.customerName}',
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: colors.foreground,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    MoneyText(
                      entry.eligibleLaari,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: colors.foreground,
                      ),
                    ),
                  ],
                ),
                if ((entry.failMessage ?? '').isNotEmpty)
                  Text(
                    entry.failMessage!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: colors.foreground,
                    ),
                  ),
                Row(
                  children: [
                    TextButton(
                      onPressed: () => onRetry(entry),
                      child: Text(
                        l10n.attentionRetry,
                        style: TextStyle(
                          color: colors.foreground,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    TextButton(
                      onPressed: () => onDiscard(entry),
                      child: Text(
                        l10n.attentionDiscard,
                        style: TextStyle(color: colors.foreground),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The violet "will appear as pending" note above the CTA (With-Category
/// ref).
class PendingNote extends StatelessWidget {
  const PendingNote({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.info_outline_rounded,
            size: 18,
            color: theme.colorScheme.onSecondaryContainer,
          ),
          const SizedBox(width: Gap.sm),
          Expanded(
            child: Text(
              context.l10n.pendingNote,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSecondaryContainer,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The recorded-credit card: state-specific headline, the money rows, the
/// priced lines when the sale was split, and the replayed note when the
/// answer came from the server's idempotency record.
class CreditResultCard extends StatelessWidget {
  const CreditResultCard({
    super.key,
    required this.result,
    required this.customerName,
    required this.categories,
    required this.onReset,
  });

  final MerchantCreditResult result;
  final String customerName;

  /// For dv names on priced lines; [] is always safe.
  final List<ProductCategory> categories;
  final VoidCallback onReset;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final tx = result.transaction;

    final belowMinimum =
        tx.state == 'reversed' && tx.reasonCode == 'below_minimum';
    final underReview = tx.state == 'on_hold';
    final backdatedFinal = tx.reasonCode == 'backdated_final';

    final (icon, tint, title) = belowMinimum
        ? (
            Icons.error_outline_rounded,
            ManfaaTint.amber,
            l10n.resultBelowMinTitle,
          )
        : underReview
        ? (Icons.schedule_rounded, ManfaaTint.violet, l10n.resultOnHoldTitle)
        : backdatedFinal
        ? (
            Icons.error_outline_rounded,
            ManfaaTint.amber,
            l10n.backdatedResultTitle,
          )
        : (
            Icons.check_circle_outline_rounded,
            ManfaaTint.green,
            l10n.resultRecordedTitle,
          );

    final lined = tx.lines.isNotEmpty;
    final rateSuffix = lined
        ? l10n.previewPerLine
        : formatBp(parsePercentToBp(tx.cashbackRatePercent) ?? 0);
    final feeSuffix = lined
        ? l10n.previewPerLine
        : formatBp(parsePercentToBp(tx.platformFeePercent) ?? 0);
    // The treatment STAMPED on this sale, never the live setting — and only
    // meaningful while the row actually carries tax.
    final feeInclusive = tx.feeTreatment == 'inclusive' && tx.feeGstLaari > 0;

    String lineName(MerchantTransactionLine line) {
      if (line.category == null) return l10n.splitEverythingElse;
      final category = categories
          .where((c) => c.slug == line.category)
          .firstOrNull;
      if (category != null) return category.label(dhivehi: dhivehi);
      return line.categoryNameEn ?? line.category!;
    }

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              IconTile(icon, tint: tint, size: 44, iconSize: 22),
              const SizedBox(width: Gap.md),
              Expanded(child: Text(title, style: theme.textTheme.titleMedium)),
            ],
          ),
          if (result.replayed) ...[
            const SizedBox(height: Gap.md),
            Container(
              padding: const EdgeInsets.all(Gap.md),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerLow,
                borderRadius: BorderRadius.circular(Corner.tile),
              ),
              child: Row(
                children: [
                  Icon(
                    Icons.replay_rounded,
                    size: 16,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: Text(
                      l10n.resultReplayedNote,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          if (belowMinimum || underReview || backdatedFinal) ...[
            const SizedBox(height: Gap.md),
            Text(
              belowMinimum
                  ? l10n.resultBelowMinBody
                  : underReview
                  ? l10n.resultOnHoldBody
                  : l10n.backdatedResultBody,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
          const SizedBox(height: Gap.lg),
          _ResultRow(label: l10n.resultCustomer, child: Text(customerName)),
          _ResultRow(
            label: l10n.resultInvoice,
            child: Text(tx.invoiceNo, textDirection: TextDirection.ltr),
          ),
          _ResultRow(
            label: l10n.resultState,
            child: StatusChip(
              label: transactionStateLabel(l10n, tx.state),
              tone: transactionStateTone(tx.state),
            ),
          ),
          _ResultRow(
            label: l10n.resultEligible,
            child: MoneyText(tx.eligibleLaari),
          ),
          _ResultRow(
            label: l10n.previewCashback(rateSuffix),
            child: MoneyText(tx.cashbackLaari),
          ),
          // THE RATE AND THE MONEY ON A ROW MUST DESCRIBE THE SAME NUMBER.
          // `platformFeePercent` is the GROSS quoted rate under both
          // treatments, while `feeLaari` is Manfaa's NET revenue — equal
          // under `on_top`, short by the tax under `inclusive`, where the
          // tax was carved OUT of the quoted fee. So under `inclusive` this
          // row prints the gross figure its rate actually produces and the
          // tax below is disclosed as a COMPONENT of it. Either way the two
          // rows sum to what the merchant owes.
          _ResultRow(
            label: l10n.previewFee(feeSuffix),
            child: MoneyText(
              feeInclusive ? tx.feeLaari + tx.feeGstLaari : tx.feeLaari,
            ),
          ),
          // The GST on that fee is its own line — the "You pay" total below
          // has always included it, and never said so. Absent entirely when
          // the sale carries no GST (the platform has it off, or the row
          // was priced before it came in).
          if (tx.feeGstLaari > 0)
            _ResultRow(
              label: feeInclusive
                  ? l10n.previewGstIncluded(trimRatePercent(tx.feeGstPercent))
                  : l10n.previewGst(trimRatePercent(tx.feeGstPercent)),
              child: MoneyText(tx.feeGstLaari),
            ),
          const Divider(height: Gap.xl),
          _ResultRow(
            label: l10n.resultYouPay,
            emphasized: true,
            child: MoneyText(
              tx.cashbackLaari + tx.feeLaari + tx.feeGstLaari,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          if (lined) ...[
            const SizedBox(height: Gap.md),
            Text(
              l10n.resultLinesTitle,
              style: theme.textTheme.labelLarge?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.sm),
            for (final line in tx.lines)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        lineName(line),
                        style: theme.textTheme.bodySmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Text(
                      formatBp(parsePercentToBp(line.cashbackRatePercent) ?? 0),
                      textDirection: TextDirection.ltr,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(width: Gap.md),
                    MoneyText(
                      line.cashbackLaari,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
          ],
          const SizedBox(height: Gap.lg),
          OutlinedButton(onPressed: onReset, child: Text(l10n.creditAnother)),
        ],
      ),
    );
  }
}

class _ResultRow extends StatelessWidget {
  const _ResultRow({
    required this.label,
    required this.child,
    this.emphasized = false,
  });

  final String label;
  final Widget child;
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style:
                  (emphasized
                          ? theme.textTheme.titleSmall
                          : theme.textTheme.bodySmall)
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ),
          const SizedBox(width: Gap.md),
          DefaultTextStyle.merge(
            style: theme.textTheme.bodyMedium!,
            child: child,
          ),
        ],
      ),
    );
  }
}
