import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'setup_common.dart';

/// Rate arithmetic in integer BASIS POINTS — percent strings never become
/// doubles (§11), they become ints once, here, exactly as the web does
/// (parsePercentToBp / formatBp in @manfaa/api-client).
///
/// "2.5" → 250 bp; null for anything that is not a plain decimal with at
/// most two fractional digits.
int? parsePercentToBp(String input) {
  final trimmed = input.trim();
  if (!RegExp(r'^\d{1,3}(\.\d{1,2})?$').hasMatch(trimmed)) return null;

  final parts = trimmed.split('.');
  final whole = int.parse(parts[0]);
  final frac = parts.length > 1 ? parts[1].padRight(2, '0') : '00';

  return whole * 100 + int.parse(frac);
}

/// 250 bp → "2.5%", 200 → "2%", 275 → "2.75%".
String formatBp(int bp) {
  final whole = bp ~/ 100;
  final frac = bp % 100;
  if (frac == 0) return '$whole%';
  if (frac % 10 == 0) return '$whole.${frac ~/ 10}%';
  return '$whole.${frac.toString().padLeft(2, '0')}%';
}

/// "2.50" → "2.5", "2.00" → "2" — the placeholder shape for a saved rate.
String trimRate(String percent) {
  final bp = parsePercentToBp(percent);
  return bp == null ? percent : formatBp(bp).replaceFirst('%', '');
}

/// 250 bp → "2.50" — the §11 wire shape (2-decimal percent STRING) for a
/// rate the app validated as bp. The inverse of [parsePercentToBp], used by
/// the credit screen's custom-rate field.
String bpToPercentString(int bp) =>
    '${bp ~/ 100}.${(bp % 100).toString().padLeft(2, '0')}';

/// The §4 static fee bands for the pre-submit all-in preview — the same
/// table the web wizard shows. The platform's published tier schedule is
/// authoritative: the server prices the actual fee and refuses unpriced
/// rates (`rate_not_priced`).
int staticFeeBp(int rateBp) => switch (rateBp) {
      <= 99 => 25,
      <= 199 => 50,
      <= 499 => 75,
      _ => 100,
    };

/// Ceiling laari at `bp` basis points — the API's own §4 formula
/// (`intdiv(eligible * bp + 9999, 10000)`), integer end to end, mirrored for
/// the credit screen's DISPLAY-ONLY estimates. The server always reprices.
int estimateLaariAtBp(int laari, int bp) => (laari * bp + 9999) ~/ 10000;

/// Step 4 — the advertised cashback rate: a percent field bounded by the
/// server's window, with the live all-in preview (cashback + fee) marked as
/// an estimate.
class RateStep extends ConsumerStatefulWidget {
  const RateStep({
    super.key,
    required this.state,
    required this.onSaved,
    required this.onNext,
    required this.onBack,
  });

  final MerchantSetupState state;
  final void Function(MerchantSetupState state) onSaved;

  /// Plain navigation when nothing changed (a saved rate stands).
  final VoidCallback onNext;
  final VoidCallback onBack;

  @override
  ConsumerState<RateStep> createState() => _RateStepState();
}

class _RateStepState extends ConsumerState<RateStep> {
  final _input = TextEditingController();
  var _busy = false;

  /// The server's `rate_not_priced` prose, rendered as an alert (the tier
  /// ceiling is live data only the server knows).
  String? _notPriced;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  int get _minBp => parsePercentToBp(widget.state.rateBounds.minPercent) ?? 50;
  int get _maxBp =>
      parsePercentToBp(widget.state.rateBounds.maxPercent) ?? 1000;
  int? get _savedBp {
    final saved = widget.state.values.cashbackRatePercent;
    return saved == null ? null : parsePercentToBp(saved);
  }

  String? _inputError(String trimmed) {
    final l10n = context.l10n;
    if (trimmed.isEmpty) return null;
    final bp = parsePercentToBp(trimmed);
    if (bp == null) return l10n.errRateFormat;
    if (bp < _minBp) return l10n.errRateMin(formatBp(_minBp));
    if (bp > _maxBp) return l10n.errRateMax(formatBp(_maxBp));
    return null;
  }

  Future<void> _save() async {
    final trimmed = _input.text.trim();
    final typedBp = _inputError(trimmed) == null && trimmed.isNotEmpty
        ? parsePercentToBp(trimmed)
        : null;

    // Nothing typed but a rate already saved — plain navigation. Same for
    // retyping the saved rate.
    if (typedBp == null) {
      if (trimmed.isEmpty && _savedBp != null) widget.onNext();
      return;
    }
    if (typedBp == _savedBp) {
      widget.onNext();
      return;
    }

    setState(() {
      _busy = true;
      _notPriced = null;
    });
    try {
      // The exact string typed goes to the wire (§11) — never re-derived
      // through a number.
      final fresh = await ref.read(apiProvider).saveSetupRate(trimmed);
      if (mounted) widget.onSaved(fresh);
    } on MobileApiException catch (e) {
      if (!mounted) return;
      if (e.code == ApiCode.rateNotPriced) {
        setState(() => _notPriced = e.message);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(describeSetupError(context, e))));
      }
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
    final muted = theme.colorScheme.onSurfaceVariant;

    final trimmed = _input.text.trim();
    final error = _inputError(trimmed);
    final typedBp =
        error == null && trimmed.isNotEmpty ? parsePercentToBp(trimmed) : null;
    // The preview follows the typed rate as soon as it is valid; otherwise
    // the saved one, so a resuming owner sees their choice.
    final previewBp = typedBp ?? _savedBp;
    final previewFeeBp = previewBp == null ? null : staticFeeBp(previewBp);

    final saved = widget.state.values.cashbackRatePercent;
    final canContinue =
        !_busy && (typedBp != null || (trimmed.isEmpty && _savedBp != null));

    return SetupStepCard(
      title: l10n.rateTitle,
      subtitle: l10n.rateSubtitle,
      actionLabel: l10n.continueLabel,
      onAction: canContinue ? _save : null,
      onBack: widget.onBack,
      busy: _busy,
      children: [
        Text(l10n.rateLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        SizedBox(
          width: 160,
          child: TextField(
            controller: _input,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr,
            style: theme.textTheme.titleMedium,
            onChanged: (_) => setState(() => _notPriced = null),
            decoration: InputDecoration(
              hintText: saved == null ? '2.5' : trimRate(saved),
              suffixText: '%',
            ),
          ),
        ),
        const SizedBox(height: Gap.sm),
        Text(
          error ??
              l10n.rateRangeHint(
                formatBp(_minBp),
                formatBp(_maxBp),
              ),
          style: theme.textTheme.bodySmall?.copyWith(
            color: error == null ? muted : theme.colorScheme.error,
          ),
        ),
        if (previewBp != null && previewFeeBp != null) ...[
          const SizedBox(height: Gap.lg),
          Container(
            padding: const EdgeInsets.all(Gap.lg),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerLow,
              borderRadius: BorderRadius.circular(Corner.tile),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _PreviewCell(
                        label: l10n.ratePreviewCashback,
                        value: formatBp(previewBp)),
                    _PreviewCell(
                        label: l10n.ratePreviewFee,
                        value: formatBp(previewFeeBp)),
                    _PreviewCell(
                      label: l10n.ratePreviewAllIn,
                      value: formatBp(previewBp + previewFeeBp),
                      emphasis: true,
                    ),
                  ],
                ),
                const SizedBox(height: Gap.md),
                Text(
                  l10n.ratePreviewNote,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
            ),
          ),
        ],
        if (_notPriced != null) ...[
          const SizedBox(height: Gap.lg),
          SetupNotice(
            title: l10n.rateNotPricedTitle,
            tone: ToneSurface.attention,
            child: Text(
              _notPriced!,
              style: theme.textTheme.bodySmall?.copyWith(
                color:
                    toneSurface(ToneSurface.attention, theme.brightness)
                        .foreground,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _PreviewCell extends StatelessWidget {
  const _PreviewCell({
    required this.label,
    required this.value,
    this.emphasis = false,
  });

  final String label;
  final String value;
  final bool emphasis;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: theme.textTheme.labelSmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            textDirection: TextDirection.ltr,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
              color: emphasis ? theme.colorScheme.secondary : null,
            ),
          ),
        ],
      ),
    );
  }
}
