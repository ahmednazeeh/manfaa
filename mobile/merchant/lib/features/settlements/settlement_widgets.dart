import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/tx_format.dart';

/// The MR3 money building blocks, shared by the Dashboard, the Settlements
/// tab, the bank pay flow and the settlement detail. Two laws rule here:
/// every figure on screen is a server integer rendered through MoneyText —
/// no rate is ever applied client-side — and a discount refusal is a
/// sentence with a reason, never an absence.

// ---------------------------------------------------------------------------
// Small shared pieces
// ---------------------------------------------------------------------------

/// A label/value money row (breakdown and summary cards).
class MoneyRow extends StatelessWidget {
  const MoneyRow({
    super.key,
    required this.label,
    this.laari,
    this.child,
    this.emphasized = false,
    this.color,
  }) : assert(laari != null || child != null);

  final String label;
  final int? laari;
  final Widget? child;
  final bool emphasized;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final valueStyle = (emphasized
            ? theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)
            : theme.textTheme.bodyMedium)
        ?.copyWith(color: color);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: (emphasized
                      ? theme.textTheme.titleSmall
                      : theme.textTheme.bodySmall)
                  ?.copyWith(
                color: emphasized
                    ? theme.colorScheme.onSurface
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(width: Gap.md),
          child ?? MoneyText(laari!, style: valueStyle),
        ],
      ),
    );
  }
}

/// The platform fee with its discount applied: the full fee struck through,
/// the discounted fee beside it — an exact integer subtraction of two
/// server figures, never a rate applied here (web DiscountedFeeAmount).
class DiscountedFeeAmount extends StatelessWidget {
  const DiscountedFeeAmount({
    super.key,
    required this.feeLaari,
    required this.feeDiscountLaari,
  });

  final int feeLaari;
  final int feeDiscountLaari;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (feeDiscountLaari <= 0) return MoneyText(feeLaari);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        MoneyText(
          feeLaari,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
            decoration: TextDecoration.lineThrough,
          ),
        ),
        const SizedBox(width: Gap.sm),
        MoneyText(feeLaari - feeDiscountLaari),
      ],
    );
  }
}

/// A value the merchant retypes into their banking app: LTR mono text with
/// a copy button beside it.
class CopyValue extends StatelessWidget {
  const CopyValue({super.key, required this.value, this.boxed = true});

  final String value;

  /// Reference and account number sit in a muted code box; names don't.
  final bool boxed;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    void copy() {
      Clipboard.setData(ClipboardData(text: value));
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(l10n.copiedToast)));
    }

    final text = Text(
      value,
      textDirection: TextDirection.ltr,
      style: theme.textTheme.bodyMedium?.copyWith(
        fontWeight: FontWeight.w700,
        fontFeatures: const [FontFeature.tabularFigures()],
      ),
    );

    // The WHOLE value is the tap target (owner, 2026-08-24) — a thumb
    // lands on the number it is reading, not on a 17px icon beside it.
    // The icon stays as the visual cue that tapping copies.
    return InkWell(
      borderRadius: BorderRadius.circular(Corner.tile),
      onTap: copy,
      child: Row(
        children: [
          Flexible(
            child: boxed
                ? Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(Corner.tile),
                    ),
                    child: text,
                  )
                : text,
          ),
          IconButton(
            visualDensity: VisualDensity.compact,
            tooltip: l10n.copyTooltip,
            icon: Icon(
              Icons.copy_rounded,
              size: 17,
              color: theme.colorScheme.onSurfaceVariant,
            ),
            onPressed: copy,
          ),
        ],
      ),
    );
  }
}

/// A soft banner in one of the design system's tone surfaces.
class ToneBanner extends StatelessWidget {
  const ToneBanner({
    super.key,
    required this.tone,
    required this.icon,
    required this.title,
    this.body,
    this.action,
  });

  final ToneSurface tone;
  final IconData icon;
  final String title;
  final String? body;
  final Widget? action;

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
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: colors.foreground),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: colors.foreground,
                  ),
                ),
                if (body != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    body!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: colors.foreground,
                    ),
                  ),
                ],
                if (action != null) ...[
                  const SizedBox(height: Gap.sm),
                  action!,
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The violet discount banner surface (Dashboard.png / Settlements.png):
/// clock icon on secondaryContainer — the merchant accent, not a tone.
class _VioletBanner extends StatelessWidget {
  const _VioletBanner({required this.title, this.body});

  final String title;
  final String? body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fg = theme.colorScheme.onSecondaryContainer;

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.schedule_rounded, size: 22, color: fg),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: theme.textTheme.titleSmall?.copyWith(color: fg),
                ),
                if (body != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    body!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: fg.withValues(alpha: 0.85),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Discount banners (advisory — the server re-decides at submit)
// ---------------------------------------------------------------------------

/// The refusal in a sentence, or null when there is nothing to explain
/// (granted, or disabled platform-wide).
String? discountRefusalText(
  AppLocalizations l10n,
  SettlementDiscount discount,
) {
  if (discount.eligible || discount.disabled) return null;
  return switch (discount.reasonCode) {
    'not_all_outstanding' =>
      l10n.discountReasonNotAll(trimRatePercent(discount.ratePercent)),
    'line_too_old' => l10n.discountReasonTooOld(discount.maxAgeDays),
    'clock_not_started' => l10n.discountReasonClockNotStarted,
    _ => null,
  };
}

/// The Dashboard's standing hint (Dashboard.png): the DAY the oldest
/// payable sale stops earning the discount, plus what settling everything
/// before then saves. Refusals render as their sentence; a switched-off
/// discount, a zero-worth grant and a board with no started clocks render
/// nothing (web PromptDiscountDeadline, decision for decision).
class DiscountDeadlineBanner extends StatelessWidget {
  const DiscountDeadlineBanner({
    super.key,
    required this.discount,
    required this.rows,
  });

  final SettlementDiscount discount;
  final List<SettlementPickerRow> rows;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    if (discount.disabled) return const SizedBox.shrink();

    final refusal = discountRefusalText(l10n, discount);
    if (refusal != null) {
      return ToneBanner(
        tone: ToneSurface.closed,
        icon: Icons.schedule_rounded,
        title: refusal,
      );
    }

    final deadline = discountDeadlineDate(
      [for (final row in rows) row.clockStartAt],
      discount.maxAgeDays,
    );
    if (discount.discountLaari == 0 || deadline == null) {
      return const SizedBox.shrink();
    }

    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return _VioletBanner(
      title: l10n.discountDeadlineTitle(
        trimRatePercent(discount.ratePercent),
        deadline,
      ),
      body: l10n.discountDeadlineBody(
        formatMoney(discount.discountLaari, dhivehi: dhivehi),
      ),
    );
  }
}

/// The Settlements tab's banner (Settlements.png): "Pay within N days to
/// keep your 5% prompt-payment discount." + the oldest due date. Same
/// silence rules as the deadline banner.
class DiscountKeepBanner extends StatelessWidget {
  const DiscountKeepBanner({super.key, required this.discount, this.dueAt});

  final SettlementDiscount discount;

  /// The preview's earliest due_at (ISO), printed as a business date.
  final String? dueAt;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    if (discount.disabled) return const SizedBox.shrink();

    final refusal = discountRefusalText(l10n, discount);
    if (refusal != null) {
      return ToneBanner(
        tone: ToneSurface.closed,
        icon: Icons.schedule_rounded,
        title: refusal,
      );
    }

    if (discount.discountLaari == 0) return const SizedBox.shrink();

    return _VioletBanner(
      title: l10n.discountKeepTitle(
        discount.maxAgeDays,
        trimRatePercent(discount.ratePercent),
      ),
      body: dueAt == null
          ? null
          : l10n.oldestDueDate(formatBusinessDate(dueAt!)),
    );
  }
}

// ---------------------------------------------------------------------------
// Breakdown
// ---------------------------------------------------------------------------

/// The priced breakdown (Settlements.png "Breakdown"): cashback (never
/// discounted — the customer's reward is untouched), fee and GST with the
/// discount struck through when earned, the §7 credit netted, and the total
/// due — every figure a preview integer.
///
/// MR11 (owner report): the accounting sits behind a "View breakdown"
/// disclosure — the same idiom the dashboard's settlement card uses — so
/// the tab opens on the amount and the action, not on a ledger.
class PreviewBreakdownCard extends StatefulWidget {
  const PreviewBreakdownCard({super.key, required this.preview});

  final SettlementPreviewData preview;

  @override
  State<PreviewBreakdownCard> createState() => _PreviewBreakdownCardState();
}

class _PreviewBreakdownCardState extends State<PreviewBreakdownCard> {
  var _open = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final preview = widget.preview;
    final discount = preview.discount;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(Corner.tile),
            onTap: () => setState(() => _open = !_open),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: Gap.xs),
              child: Row(
                children: [
                  Text(
                    l10n.viewBreakdown,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.secondary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(width: Gap.xs),
                  Icon(
                    _open
                        ? Icons.keyboard_arrow_up_rounded
                        : Icons.keyboard_arrow_right_rounded,
                    size: 20,
                    color: theme.colorScheme.secondary,
                  ),
                ],
              ),
            ),
          ),
          if (_open) ...[
            const SizedBox(height: Gap.sm),
            MoneyRow(
              label: l10n.payableCashback,
              laari: preview.cashbackTotalLaari,
            ),
            MoneyRow(
              label: l10n.payableFee,
              child: DiscountedFeeAmount(
                feeLaari: preview.feeTotalLaari,
                feeDiscountLaari: discount?.feeDiscountLaari ?? 0,
              ),
            ),
            // Manfaa's charge and the tax on it are two lines, never one
            // blended number — and while GST does not apply the line is
            // ABSENT, not a row of MVR 0.00 (owner, 2026-08-24).
            if (preview.feeGstTotalLaari > 0)
              MoneyRow(
                label: l10n.payableGst,
                child: DiscountedFeeAmount(
                  feeLaari: preview.feeGstTotalLaari,
                  feeDiscountLaari: discount?.gstReliefLaari ?? 0,
                ),
              ),
            if (discount != null && preview.discountLaari > 0) ...[
              const Divider(height: Gap.lg),
              MoneyRow(
                label: l10n.discountRow(trimRatePercent(discount.ratePercent)),
                child: MoneyText(
                  -preview.discountLaari,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.secondary,
                  ),
                ),
              ),
              Text(
                l10n.discountAdvisoryNote(discount.maxAgeDays),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
            if (preview.creditAppliedLaari > 0) ...[
              const Divider(height: Gap.lg),
              MoneyRow(
                label: l10n.creditAppliedRow,
                child: MoneyText(
                  -preview.creditAppliedLaari,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: ManfaaColors.green,
                  ),
                ),
              ),
              Text(
                l10n.creditAppliedHint,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
            const Divider(height: Gap.xl),
            MoneyRow(
              label: l10n.totalDueLabel,
              laari: preview.amountDueLaari,
              emphasized: true,
            ),
          ],
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Payment instructions (PLAN §1 receipt-first)
// ---------------------------------------------------------------------------

/// What the figure on a transfer screen is MADE OF: the customers' cashback,
/// Manfaa's own fee, the GST on that fee — three separate figures, never one
/// blended number — and the two reductions the server already applied, so
/// the lines reconcile to the amount above them.
///
/// Every field is a server integer. Nothing here derives a tax, applies a
/// rate or nets anything: the card only NAMES what the preview priced.
class OwedBreakdown {
  const OwedBreakdown({
    required this.cashbackLaari,
    required this.feeLaari,
    required this.feeGstLaari,
    this.discountLaari = 0,
    this.discountRatePercent,
    this.creditAppliedLaari = 0,
  });

  /// Straight off a priced preview. The itemised bill the server publishes
  /// on `payment_instructions` is preferred; a server that predates it
  /// still answers the same three totals at the top level, so the merchant
  /// sees the split either way.
  factory OwedBreakdown.fromPreview(SettlementPreviewData preview) {
    final instructions = preview.paymentInstructions;
    final published =
        instructions.cashbackTotalLaari != 0 ||
        instructions.feeTotalLaari != 0 ||
        instructions.feeGstTotalLaari != 0;

    return OwedBreakdown(
      cashbackLaari: published
          ? instructions.cashbackTotalLaari
          : preview.cashbackTotalLaari,
      feeLaari: published ? instructions.feeTotalLaari : preview.feeTotalLaari,
      feeGstLaari: published
          ? instructions.feeGstTotalLaari
          : preview.feeGstTotalLaari,
      discountLaari: preview.discountLaari,
      discountRatePercent: preview.discount?.ratePercent,
      creditAppliedLaari: preview.creditAppliedLaari,
    );
  }

  final int cashbackLaari;
  final int feeLaari;

  /// Zero whenever GST does not apply — the platform has it off, or every
  /// row in the batch was priced before it came in. The line is then not
  /// drawn at all.
  final int feeGstLaari;

  /// The prompt-payment discount ALREADY subtracted from the amount due,
  /// with the rate it was granted at.
  final int discountLaari;
  final String? discountRatePercent;

  /// The §7 reversal credit already netted off.
  final int creditAppliedLaari;

  /// False when the server published no split (a created settlement's
  /// instructions carry none, and a remainder is a part-payment no split
  /// describes) — the card then shows the amount alone rather than an
  /// itemisation nobody priced.
  bool get itemised =>
      cashbackLaari != 0 || feeLaari != 0 || feeGstLaari != 0;
}

/// The itemised bill under the amount: what the customers earned, what
/// Manfaa charged, the tax on that charge, and the reductions — each on its
/// own line, each a server integer.
class _OwedLines extends StatelessWidget {
  const _OwedLines({required this.breakdown});

  final OwedBreakdown breakdown;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget row(String label, int laari, {Color? color}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ),
          const SizedBox(width: Gap.sm),
          MoneyText(
            laari,
            style: theme.textTheme.bodySmall?.copyWith(color: color),
          ),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          l10n.amountCoversTitle.toUpperCase(),
          style: theme.textTheme.labelSmall?.copyWith(
            color: muted,
            letterSpacing: 0.6,
          ),
        ),
        const SizedBox(height: Gap.xs),
        row(l10n.payableCashback, breakdown.cashbackLaari),
        row(l10n.payableFee, breakdown.feeLaari),
        if (breakdown.feeGstLaari > 0)
          row(l10n.payableGst, breakdown.feeGstLaari),
        if (breakdown.discountLaari > 0)
          row(
            l10n.discountRow(
              breakdown.discountRatePercent == null
                  ? ''
                  : trimRatePercent(breakdown.discountRatePercent!),
            ),
            -breakdown.discountLaari,
            color: theme.colorScheme.secondary,
          ),
        if (breakdown.creditAppliedLaari > 0)
          row(
            l10n.creditAppliedRow,
            -breakdown.creditAppliedLaari,
            color: ManfaaColors.green,
          ),
      ],
    );
  }
}

/// Where to send the transfer, what to send, and what to quote. Every value
/// the merchant retypes carries a copy button; when nothing is configured
/// the details are absent — never invented — and the merchant is told to
/// contact Manfaa. With more than one platform bank on offer the details
/// stay hidden until the merchant picks one (web parity: a transfer to the
/// wrong bank is a fee and a day they cannot undo).
class PaymentInstructionsCard extends StatelessWidget {
  const PaymentInstructionsCard({
    super.key,
    required this.instructions,
    required this.amountDueLaari,
    this.breakdown,
    this.selectedAccountId,
    this.onSelectAccount,
    this.referenceNote,
  });

  final SettlementInstructions instructions;
  final int amountDueLaari;

  /// What that amount is made of, when the server priced a split for it.
  /// Null on the screens where there is none to show — a wallet top-up is
  /// the merchant's own money, and a settlement remainder is a part-payment
  /// no itemisation describes.
  final OwedBreakdown? breakdown;
  final int? selectedAccountId;
  final ValueChanged<int>? onSelectAccount;

  /// What to say in place of a reference when there is none. Defaults to
  /// the settlement wording ("Manfaa will generate one after you upload
  /// the receipt"); a wallet top-up, which never gets a reference, passes
  /// its own sentence.
  final String? referenceNote;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    if (instructions.needsConfiguration) {
      return ToneBanner(
        tone: ToneSurface.pending,
        icon: Icons.error_outline_rounded,
        title: l10n.noAccountTitle,
        body: l10n.noAccountBody,
      );
    }

    final choices = instructions.bankAccounts;
    final choosable = choices.length > 1 && onSelectAccount != null;
    final chosen = choosable
        ? choices
            .where((account) => account.id == selectedAccountId)
            .firstOrNull
        : (choices.firstOrNull ?? instructions.bankAccount);

    Widget field(String label, Widget value) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: theme.textTheme.labelSmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 2),
        value,
      ],
    );

    // The one number on this page that matters, on the quietest possible
    // lavender ground (owner, 2026-08-24: subtle tint, no gradient), with
    // its own copy button — the merchant pastes the exact figure into
    // their banking app instead of retyping it.
    final violet = tintColors(ManfaaTint.violet, theme.brightness);
    final amountBg = Color.alphaBlend(
      violet.bg.withValues(
        alpha: theme.brightness == Brightness.light ? 0.32 : 0.12,
      ),
      theme.colorScheme.surfaceContainerLowest,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.symmetric(
            horizontal: Gap.lg,
            vertical: Gap.md,
          ),
          decoration: BoxDecoration(
            color: amountBg,
            borderRadius: BorderRadius.circular(Corner.control),
          ),
          child: Row(
            children: [
              Expanded(
                child: field(
                  l10n.amountToTransfer,
                  MoneyText(
                    amountDueLaari,
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
              IconButton(
                visualDensity: VisualDensity.compact,
                tooltip: l10n.copyTooltip,
                icon: Icon(
                  Icons.copy_rounded,
                  size: 18,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                onPressed: () {
                  // The bare figure ("111.20") — banking apps refuse "MVR".
                  Clipboard.setData(
                    ClipboardData(text: laariToInput(amountDueLaari)),
                  );
                  ScaffoldMessenger.of(context)
                    ..hideCurrentSnackBar()
                    ..showSnackBar(
                      SnackBar(content: Text(l10n.copiedToast)),
                    );
                },
              ),
            ],
          ),
        ),
        if (breakdown != null && breakdown!.itemised) ...[
          const SizedBox(height: Gap.md),
          _OwedLines(breakdown: breakdown!),
        ],
        const SizedBox(height: Gap.md),
        // Before the receipt lands there IS no settlement, so there is no
        // reference to quote — saying so beats quoting a number the next
        // submitter can take (owner decision 2026-08-18).
        if (instructions.reference != null) ...[
          field(l10n.referenceLabel, CopyValue(value: instructions.reference!)),
          Text(
            l10n.referenceFinalNote,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ] else
          Text(
            referenceNote ?? l10n.referenceComesLaterNote,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        const SizedBox(height: Gap.lg),
        Container(
          padding: const EdgeInsets.all(Gap.lg),
          decoration: BoxDecoration(
            border: Border.all(color: theme.colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(Corner.control),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                l10n.transferToLabel.toUpperCase(),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  letterSpacing: 0.6,
                ),
              ),
              if (choosable) ...[
                const SizedBox(height: Gap.sm),
                Text(
                  l10n.chooseBankLabel,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: Gap.sm),
                // MR11 (owner report): small tiles side by side — the
                // mark and the short name — instead of two full-width
                // rows. The account number belongs to the CHOSEN bank and
                // appears once, below. (start, not stretch: this Row lives
                // in an unbounded column, and every tile is the same shape
                // anyway.)
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final (index, account) in choices.indexed) ...[
                      if (index > 0) const SizedBox(width: Gap.sm),
                      Expanded(
                        child: _BankChoice(
                          account: account,
                          selected: account.id == chosen?.id,
                          onTap: () => onSelectAccount!(account.id ?? 0),
                        ),
                      ),
                    ],
                  ],
                ),
              ],
              if (chosen == null)
                Padding(
                  padding: const EdgeInsets.only(top: Gap.sm),
                  child: Text(
                    l10n.chooseBankFirst,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                )
              else ...[
                // The selected bank tile above already names the bank —
                // a "Bank" row here said it twice (owner, 2026-08-24).
                const SizedBox(height: Gap.md),
                field(l10n.accountNoLabel, CopyValue(value: chosen.accountNo)),
                const SizedBox(height: Gap.sm),
                field(
                  l10n.accountNameLabel,
                  CopyValue(value: chosen.accountName, boxed: false),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// One compact bank card: the bank's own mark over its short name (BML,
/// MIB). No account number here — that is the chosen bank's business, shown
/// once below the row.
class _BankChoice extends StatelessWidget {
  const _BankChoice({
    required this.account,
    required this.selected,
    required this.onTap,
  });

  final PlatformBankAccount account;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.control),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: Gap.sm,
          vertical: Gap.md,
        ),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(Corner.control),
          // Brand violet marks the chosen bank (owner, 2026-08-24);
          // unselected tiles stay plain surface with the hairline.
          border: Border.all(
            color: selected
                ? tintColors(ManfaaTint.violet, theme.brightness).fg
                : theme.colorScheme.outlineVariant,
            width: selected ? 1.6 : 1,
          ),
          color: selected
              ? tintColors(ManfaaTint.violet, theme.brightness).bg
              : Colors.transparent,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            BankLogoTile(bankName: account.bankName),
            const SizedBox(height: Gap.sm),
            Text(
              bankShortName(account.bankName),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: theme.textTheme.titleSmall?.copyWith(
                color: selected
                    ? theme.colorScheme.onSurface
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A bank's mark on a WHITE rounded tile — white in both themes on purpose:
/// MIB's PNG is transparent and vanishes on a dark surface. A bank Manfaa
/// ships no art for gets the neutral glyph instead of an invented logo.
class BankLogoTile extends StatelessWidget {
  const BankLogoTile({super.key, required this.bankName, this.size = 44});

  final String bankName;
  final double size;

  @override
  Widget build(BuildContext context) {
    final asset = bankLogoAsset(bankName);

    return Container(
      width: size,
      height: size,
      padding: EdgeInsets.all(size * 0.14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(Corner.tile),
        border: Border.all(color: const Color(0x14000000)),
      ),
      child: asset == null
          ? Icon(
              Icons.account_balance_rounded,
              size: size * 0.5,
              color: ManfaaColors.ink,
            )
          : Image.asset(
              asset,
              fit: BoxFit.contain,
              errorBuilder: (_, _, _) => Icon(
                Icons.account_balance_rounded,
                size: size * 0.5,
                color: ManfaaColors.ink,
              ),
            ),
    );
  }
}

// ---------------------------------------------------------------------------
// Receipt form (the slip + the amount actually transferred)
// ---------------------------------------------------------------------------

/// A slip the merchant picked, before any bytes go to the wire.
class PickedSlip {
  const PickedSlip({
    required this.name,
    required this.sizeBytes,
    required this.bytes,
  });

  final String name;
  final int sizeBytes;
  final Uint8List bytes;
}

typedef SlipPickFn = Future<PickedSlip?> Function(bool camera);

/// The OS picker behind the two buttons — swappable so tests can hand back
/// a fabricated file (including an over-limit one without 5 MB in memory:
/// the pre-flight reads [PickedSlip.sizeBytes], not the byte list).
@visibleForTesting
SlipPickFn slipPicker = defaultSlipPicker;

/// The real picker (also what tests restore after overriding).
Future<PickedSlip?> defaultSlipPicker(bool camera) async {
  final picked = await ImagePicker().pickImage(
    source: camera ? ImageSource.camera : ImageSource.gallery,
  );
  if (picked == null) return null;
  final bytes = await picked.readAsBytes();
  return PickedSlip(name: picked.name, sizeBytes: bytes.length, bytes: bytes);
}

/// What the form hands back on submit — exact integer laari, never a float.
class ReceiptSubmission {
  const ReceiptSubmission({
    required this.amountLaari,
    required this.slipBytes,
    required this.slipFilename,
  });

  final int amountLaari;
  final Uint8List slipBytes;
  final String slipFilename;
}

/// Integer laari → "1234.56" for the amount input (string surgery — no
/// float ever touches money).
String laariToInput(int laari) {
  final sign = laari < 0 ? '-' : '';
  final abs = laari.abs();
  return '$sign${abs ~/ 100}.${(abs % 100).toString().padLeft(2, '0')}';
}

/// The receipt half of the receipt-first flow: pick the slip (camera or
/// gallery, pre-flighted against SlipStorage's 5 MB cap with a clear
/// refusal), confirm the amount actually transferred, submit. Used by the
/// wizard's bank path (where submitting CREATES the settlement) and by the
/// detail's remainder card (a further receipt).
class ReceiptForm extends StatefulWidget {
  const ReceiptForm({
    super.key,
    required this.amountDueLaari,
    required this.submitLabel,
    required this.busy,
    this.error,
    required this.onSubmit,
  });

  /// Prefills the amount and drives the under/over-payment notes.
  final int amountDueLaari;
  final String submitLabel;
  final bool busy;
  final MobileApiException? error;
  final ValueChanged<ReceiptSubmission> onSubmit;

  @override
  State<ReceiptForm> createState() => _ReceiptFormState();
}

class _ReceiptFormState extends State<ReceiptForm> {
  PickedSlip? _slip;
  String? _slipError;
  var _touched = false;

  Future<void> _pick(bool camera) async {
    final l10n = context.l10n;
    final picked = await slipPicker(camera);
    if (picked == null || !mounted) return;

    // Pre-flight: size first, then type — the server's SlipStorage rules,
    // enforced here only to save the merchant a 5 MB round trip.
    final rejection = checkSlipFile(
      name: picked.name,
      sizeBytes: picked.sizeBytes,
    );
    if (rejection != null) {
      setState(() {
        _slip = null;
        _slipError = rejection == SlipRejection.tooLarge
            ? l10n.slipTooLarge
            : l10n.slipUnsupported;
      });
      return;
    }
    setState(() {
      _slip = picked;
      _slipError = null;
    });
  }

  void _submit() {
    setState(() => _touched = true);
    final slip = _slip;
    if (slip == null || widget.busy) return;
    // The amount input is gone (owner, 2026-08-24): the merchant is told
    // to transfer exactly the due figure, so that is what the submission
    // claims — verification reconciles against the real bank credit.
    widget.onSubmit(
      ReceiptSubmission(
        amountLaari: widget.amountDueLaari,
        slipBytes: slip.bytes,
        slipFilename: slip.name,
      ),
    );
  }

  String _errorText(AppLocalizations l10n, MobileApiException error) =>
      switch (error.code) {
        ApiCode.duplicateBankRef => l10n.duplicateBankRefMsg,
        ApiCode.slipTooLarge => l10n.slipTooLarge,
        ApiCode.slipUnsupportedType => l10n.slipUnsupported,
        // The domain's eligibility refusal arrives as validation_failed
        // with the server's own prose; show that sentence.
        _ => error.message.isNotEmpty ? error.message : l10n.submitSlipFailed,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final slip = _slip;
    final isPdf = slip != null && slip.name.toLowerCase().endsWith('.pdf');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // MR11 (owner report): the heading stands alone — the sentence
        // under it explained what the picker below already shows.
        Text(l10n.uploadSlipTitle, style: theme.textTheme.titleMedium),
        const SizedBox(height: Gap.md),
        Container(
          padding: const EdgeInsets.all(Gap.lg),
          decoration: BoxDecoration(
            border: Border.all(
              color: slip == null
                  ? theme.colorScheme.outlineVariant
                  : tintColors(ManfaaTint.green, theme.brightness).fg
                        .withValues(alpha: 0.5),
              style: BorderStyle.solid,
            ),
            borderRadius: BorderRadius.circular(Corner.control),
          ),
          child: slip == null
              ? Column(
                  children: [
                    Icon(
                      Icons.upload_file_rounded,
                      size: 28,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    const SizedBox(height: Gap.sm),
                    Text(
                      l10n.slipHint,
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: Gap.md),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: widget.busy ? null : () => _pick(true),
                            icon: const Icon(
                              Icons.photo_camera_outlined,
                              size: 18,
                            ),
                            label: Text(l10n.slipTakePhoto),
                          ),
                        ),
                        const SizedBox(width: Gap.sm),
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: widget.busy ? null : () => _pick(false),
                            icon: const Icon(
                              Icons.attach_file_rounded,
                              size: 18,
                            ),
                            label: Text(l10n.slipChooseFile),
                          ),
                        ),
                      ],
                    ),
                  ],
                )
              : Column(
                  children: [
                    if (isPdf)
                      Icon(
                        Icons.picture_as_pdf_rounded,
                        size: 40,
                        color: theme.colorScheme.onSurfaceVariant,
                      )
                    else
                      ClipRRect(
                        borderRadius: BorderRadius.circular(Corner.tile),
                        child: Image.memory(
                          slip.bytes,
                          height: 140,
                          fit: BoxFit.contain,
                          errorBuilder: (_, _, _) => Icon(
                            Icons.receipt_long_rounded,
                            size: 40,
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ),
                    const SizedBox(height: Gap.sm),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          Icons.check_circle_rounded,
                          size: 16,
                          color: tintColors(
                            ManfaaTint.green,
                            theme.brightness,
                          ).fg,
                        ),
                        const SizedBox(width: Gap.xs),
                        Flexible(
                          child: Text(
                            '${l10n.slipAttached} \u00B7 ${slip.name}',
                            textDirection: TextDirection.ltr,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: Gap.sm),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size(0, 40),
                          ),
                          onPressed: widget.busy ? null : () => _pick(false),
                          child: Text(l10n.slipReplace),
                        ),
                        const SizedBox(width: Gap.sm),
                        TextButton(
                          onPressed: widget.busy
                              ? null
                              : () => setState(() {
                                  _slip = null;
                                  _slipError = null;
                                }),
                          child: Text(l10n.slipRemove),
                        ),
                      ],
                    ),
                  ],
                ),
        ),
        if (_slipError != null) ...[
          const SizedBox(height: Gap.sm),
          Text(
            _slipError!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ] else if (_touched && slip == null) ...[
          const SizedBox(height: Gap.sm),
          Text(
            l10n.slipRequired,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
        // The "Amount transferred" input is gone (owner, 2026-08-24:
        // unnecessary) — the page already tells the merchant the exact
        // figure to send, and verification reconciles the slip against
        // the actual bank credit either way.
        if (widget.error != null) ...[
          const SizedBox(height: Gap.sm),
          ToneBanner(
            tone: ToneSurface.attention,
            icon: Icons.error_outline_rounded,
            title: _errorText(l10n, widget.error!),
          ),
        ],
        const SizedBox(height: Gap.lg),
        FilledButton(
          onPressed: widget.busy ? null : _submit,
          child: widget.busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.upload_rounded, size: 20),
                    const SizedBox(width: Gap.sm),
                    Text(widget.submitLabel),
                  ],
                ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Settlement list row (Recent settlements / history)
// ---------------------------------------------------------------------------

class SettlementRow extends StatelessWidget {
  const SettlementRow({super.key, required this.settlement, this.onTap});

  final MerchantSettlement settlement;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final settled = settlement.state == 'settled';
    final attention = settlement.state == 'cancelled';

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.tile),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.sm),
        child: Row(
          children: [
            IconTile(
              settled
                  ? Icons.check_circle_outline_rounded
                  : attention
                  ? Icons.error_outline_rounded
                  : Icons.hourglass_empty_rounded,
              tint: settled
                  ? ManfaaTint.green
                  : attention
                  ? ManfaaTint.coral
                  : ManfaaTint.amber,
              size: 38,
              iconSize: 19,
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    settlement.reference,
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.titleSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    formatBusinessDate(settlement.createdAt),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: Gap.sm),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                MoneyText(
                  settlement.amountDueLaari,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                StatusChip(
                  label: settlementStateLabel(l10n, settlement.state),
                  tone: settlementStateTone(settlement.state),
                ),
              ],
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
