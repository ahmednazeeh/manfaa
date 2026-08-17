import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'activity_screen.dart' show formatDayMonth;

/// One payout, reconciled: the deposit as the bank statement shows it
/// (reference, masked account) and the purchases it covered. The total is
/// the sum of the lines — the lines are the evidence, the amount is the
/// payment.
final payoutDetailProvider =
    FutureProvider.autoDispose.family<PayoutDetail, int>(
  (ref, id) => ref.watch(apiProvider).payoutDetail(id),
);

class PayoutDetailScreen extends ConsumerWidget {
  const PayoutDetailScreen({super.key, required this.payoutId});

  final int payoutId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final detail = ref.watch(payoutDetailProvider(payoutId));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.payoutDetailTitle)),
      body: detail.when(
        loading: () => ListView(
          padding: const EdgeInsets.all(Gap.lg),
          children: const [
            SkeletonBox(height: 160, radius: Corner.card),
            SizedBox(height: Gap.md),
            SkeletonBox(height: 220, radius: Corner.card),
          ],
        ),
        error: (e, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(Gap.huge),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  e is MobileApiException ? e.message : l10n.errorGeneric,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: Gap.lg),
                OutlinedButton(
                  onPressed: () =>
                      ref.invalidate(payoutDetailProvider(payoutId)),
                  child: Text(l10n.retry),
                ),
              ],
            ),
          ),
        ),
        data: (detail) => _Detail(detail: detail),
      ),
    );
  }
}

class _Detail extends StatelessWidget {
  const _Detail({required this.detail});

  final PayoutDetail detail;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final payout = detail.payout;

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(Gap.xl),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                MoneyText(payout.amountLaari,
                    style: theme.textTheme.displaySmall),
                const SizedBox(height: Gap.lg),
                _row(theme, l10n.payoutPeriodLabel,
                    '${formatDayMonth(payout.periodStart)} – ${formatDayMonth(payout.periodEnd)}'),
                if (payout.reference != null)
                  _row(theme, l10n.payoutReferenceLabel, payout.reference!),
                if (payout.accountMasked != null)
                  _row(theme, l10n.payoutAccountLabel,
                      '${payout.bank} ${payout.accountMasked}'),
                if (payout.paidAt != null)
                  _row(theme, l10n.payoutPaidAtLabel,
                      formatDayMonth(payout.paidAt!)),
              ],
            ),
          ),
        ),
        if (payout.status == 'failed') ...[
          const SizedBox(height: Gap.md),
          Builder(builder: (context) {
            final tone =
                toneSurface(ToneSurface.attention, theme.brightness);
            return Card(
              color: tone.background,
              child: Padding(
                padding: const EdgeInsets.all(Gap.lg),
                child: Text(
                  l10n.payoutFailedDetail,
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: tone.foreground),
                ),
              ),
            );
          }),
        ],
        const SizedBox(height: Gap.lg),
        Text(
          l10n.payoutCovered(detail.covered.length),
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: Gap.sm),
        Card(
          child: Column(
            children: [
              for (final (index, purchase) in detail.covered.indexed) ...[
                if (index > 0) const Divider(height: 1),
                ListTile(
                  title: Text(purchase.merchantName),
                  subtitle: Text(formatDayMonth(purchase.occurredAt)),
                  trailing: MoneyText(
                    purchase.cashbackLaari,
                    style: theme.textTheme.titleSmall
                        ?.copyWith(color: ManfaaColors.confirmedGreen),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _row(ThemeData theme, String label, String value) => Padding(
        padding: const EdgeInsets.only(bottom: Gap.sm),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 120,
              child: Text(
                label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
            Expanded(
              child: Text(
                value,
                textDirection: TextDirection.ltr,
                style: theme.textTheme.bodyMedium,
              ),
            ),
          ],
        ),
      );
}
