import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'paged.dart';

/// Activity (R3): the web's Transactions and Payouts pages merged into one
/// segmented money history — both answer "where is my cashback?", and the
/// freed tab slot is what keeps the nav breathable in Thaana.
///
/// Reason lines follow the contract: the API sends KEYS
/// (validation_window, merchant_settlement_window, …) and the app renders
/// localized sentences — a raw key on screen is a bug by project law.
final earnedPagerProvider =
    StateNotifierProvider.autoDispose<Pager<TransactionEntry>, PagedState<TransactionEntry>>(
  (ref) => Pager(({cursor}) => ref.read(apiProvider).transactions(cursor: cursor)),
);

final paidPagerProvider =
    StateNotifierProvider.autoDispose<Pager<PayoutEntry>, PagedState<PayoutEntry>>(
  (ref) => Pager(({cursor}) => ref.read(apiProvider).payouts(cursor: cursor)),
);

class ActivityScreen extends ConsumerStatefulWidget {
  const ActivityScreen({super.key});

  @override
  ConsumerState<ActivityScreen> createState() => _ActivityScreenState();
}

class _ActivityScreenState extends ConsumerState<ActivityScreen> {
  var _segment = 0;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tabActivity)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.md),
            child: SegmentedButton<int>(
              segments: [
                ButtonSegment(value: 0, label: Text(l10n.segmentEarned)),
                ButtonSegment(value: 1, label: Text(l10n.segmentPaidOut)),
              ],
              selected: {_segment},
              onSelectionChanged: (s) => setState(() => _segment = s.first),
            ),
          ),
          Expanded(
            child: _segment == 0 ? const _EarnedList() : const _PaidList(),
          ),
        ],
      ),
    );
  }
}

/// Fires loadMore when the scroll nears the end — the cursor walk.
class _InfiniteList extends StatelessWidget {
  const _InfiniteList({
    required this.count,
    required this.builder,
    required this.onRefresh,
    required this.onEndReached,
    required this.footerLoading,
  });

  final int count;
  final Widget Function(BuildContext, int) builder;
  final Future<void> Function() onRefresh;
  final VoidCallback onEndReached;
  final bool footerLoading;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.extentAfter < 400) onEndReached();
          return false;
        },
        child: ListView.separated(
          padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.xxl),
          itemCount: count + (footerLoading ? 1 : 0),
          separatorBuilder: (_, _) => const SizedBox(height: Gap.sm),
          itemBuilder: (context, index) => index >= count
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(Gap.lg),
                    child: SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2.5),
                    ),
                  ),
                )
              : builder(context, index),
        ),
      ),
    );
  }
}

class _EarnedList extends ConsumerWidget {
  const _EarnedList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(earnedPagerProvider);
    final l10n = context.l10n;

    if (!state.loaded) return const _ListSkeleton();

    if (state.error != null) {
      return _ErrorRetry(
        message: state.error!.isEmpty ? l10n.errorGeneric : state.error!,
        onRetry: () => ref.read(earnedPagerProvider.notifier).refresh(),
      );
    }

    if (state.items.isEmpty) {
      return _EmptyState(
        icon: Icons.storefront_rounded,
        title: l10n.emptyEarnedTitle,
        body: l10n.emptyEarnedBody,
      );
    }

    return _InfiniteList(
      count: state.items.length,
      onRefresh: () => ref.read(earnedPagerProvider.notifier).refresh(),
      onEndReached: () => ref.read(earnedPagerProvider.notifier).loadMore(),
      footerLoading: state.loadingMore,
      builder: (context, index) => _EarnedTile(entry: state.items[index]),
    );
  }
}

class _EarnedTile extends StatelessWidget {
  const _EarnedTile({required this.entry});

  final TransactionEntry entry;

  /// The reason KEY → localized sentence mapping. Unknown keys (a server
  /// deploy can add reversal reason codes at any time) fall back to the
  /// generic reversed line rather than leaking snake_case.
  String? _reason(BuildContext context) {
    final l10n = context.l10n;

    return switch (entry.statusReason) {
      null => null,
      'validation_window' => l10n.reasonValidationWindow(entry.merchantName),
      'merchant_settlement_window' =>
        l10n.reasonSettlementWindow(entry.merchantName),
      'under_review' => l10n.reasonUnderReview,
      'merchant_not_settled' => l10n.reasonNotSettled,
      'customer_refund' => l10n.reasonRefund,
      'below_minimum' => l10n.reasonBelowMinimum,
      _ => l10n.reasonReversed,
    };
  }

  (String, StatusTone) _status(BuildContext context) {
    final l10n = context.l10n;

    return switch (entry.status) {
      'pending' => (l10n.statusPending, StatusTone.pending),
      'confirmed' => (l10n.statusConfirmed, StatusTone.confirmed),
      'paid' => (l10n.statusPaid, StatusTone.paid),
      'reversed' => (l10n.statusReversed, StatusTone.closed),
      _ => (l10n.statusUnpaid, StatusTone.attention),
    };
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (label, tone) = _status(context);
    final reason = _reason(context);
    final struck = entry.status == 'reversed' || entry.status == 'unpaid';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Gap.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    entry.merchantName,
                    style: theme.textTheme.titleMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                MoneyText(
                  entry.cashbackLaari,
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: struck
                        ? theme.colorScheme.onSurfaceVariant
                        : ManfaaColors.confirmedGreen,
                    decoration: struck ? TextDecoration.lineThrough : null,
                  ),
                ),
              ],
            ),
            const SizedBox(height: Gap.xs),
            Row(
              children: [
                StatusChip(label: label, tone: tone),
                const SizedBox(width: Gap.sm),
                Expanded(
                  child: Text(
                    formatDayMonth(entry.occurredAt),
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ),
              ],
            ),
            if (reason != null) ...[
              const SizedBox(height: Gap.sm),
              Text(
                reason,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _PaidList extends ConsumerWidget {
  const _PaidList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(paidPagerProvider);
    final l10n = context.l10n;

    if (!state.loaded) return const _ListSkeleton();

    if (state.error != null) {
      return _ErrorRetry(
        message: state.error!.isEmpty ? l10n.errorGeneric : state.error!,
        onRetry: () => ref.read(paidPagerProvider.notifier).refresh(),
      );
    }

    if (state.items.isEmpty) {
      return _EmptyState(
        icon: Icons.account_balance_rounded,
        title: l10n.emptyPaidTitle,
        body: l10n.emptyPaidBody,
      );
    }

    return _InfiniteList(
      count: state.items.length,
      onRefresh: () => ref.read(paidPagerProvider.notifier).refresh(),
      onEndReached: () => ref.read(paidPagerProvider.notifier).loadMore(),
      footerLoading: state.loadingMore,
      builder: (context, index) => _PayoutTile(entry: state.items[index]),
    );
  }
}

class _PayoutTile extends StatelessWidget {
  const _PayoutTile({required this.entry});

  final PayoutEntry entry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final (label, tone) = switch (entry.status) {
      'paid' => (l10n.payoutPaid, StatusTone.confirmed),
      'sent' => (l10n.payoutSent, StatusTone.paid),
      _ => (l10n.payoutFailed, StatusTone.attention),
    };

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.card),
        onTap: () => context.push('/activity/payout/${entry.id}'),
        child: Padding(
          padding: const EdgeInsets.all(Gap.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: MoneyText(
                      entry.amountLaari,
                      style: theme.textTheme.titleLarge,
                    ),
                  ),
                  StatusChip(label: label, tone: tone),
                ],
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.payoutPeriod(
                  formatDayMonth(entry.periodStart),
                  formatDayMonth(entry.periodEnd),
                ),
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
              if (entry.status == 'failed') ...[
                const SizedBox(height: Gap.sm),
                Text(
                  l10n.payoutFailedNote,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: ManfaaColors.rose700),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.icon,
    required this.title,
    required this.body,
  });

  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Gap.huge),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 56, color: ManfaaColors.rose400),
            const SizedBox(height: Gap.lg),
            Text(title, style: theme.textTheme.titleLarge),
            const SizedBox(height: Gap.sm),
            Text(
              body,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorRetry extends StatelessWidget {
  const _ErrorRetry({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Gap.huge),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: Gap.lg),
            OutlinedButton(onPressed: onRetry, child: Text(context.l10n.retry)),
          ],
        ),
      ),
    );
  }
}

class _ListSkeleton extends StatelessWidget {
  const _ListSkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.lg),
      children: const [
        SkeletonBox(height: 92, radius: Corner.card),
        SizedBox(height: Gap.sm),
        SkeletonBox(height: 92, radius: Corner.card),
        SizedBox(height: Gap.sm),
        SkeletonBox(height: 92, radius: Corner.card),
      ],
    );
  }
}

/// "2026-08-25" or ISO → "25 Aug 2026". Manual, locale-stable: intl carries
/// no Divehi date symbols, and dd/MM digits read fine in both scripts.
/// A proper localized date pass belongs to R6.
String formatDayMonth(String iso) {
  final date = DateTime.tryParse(iso);
  if (date == null) return iso;

  const months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  return '${date.day} ${months[date.month - 1]} ${date.year}';
}
