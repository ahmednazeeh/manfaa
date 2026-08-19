import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../market/activity_orders.dart';
import '../market/market_providers.dart';
import '../home/home_screen.dart' show initialsFor;
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

  /// The brand header — top bar, bold title, segmented control — rendered as
  /// the leading items of whichever scroll view the current state shows, so
  /// the tab screen leads with [ManfaaTopBar] like Home and Profile do.
  List<Widget> _header(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final name = ref.watch(sessionProvider).customerName ?? '';

    return [
      ManfaaTopBar(
        initials: initialsFor(name),
        avatarUrl: ref.watch(avatarUrlProvider),
        onAvatarTap: () => context.go('/profile'),
      ),
      const SizedBox(height: Gap.lg),
      Text(l10n.tabActivity, style: theme.textTheme.headlineSmall),
      const SizedBox(height: Gap.lg),
      SegmentedButton<int>(
        segments: [
          ButtonSegment(value: 0, label: Text(l10n.segmentEarned)),
          ButtonSegment(value: 1, label: Text(l10n.segmentPaidOut)),
          // Orders is its OWN tab, not folded into the other two. Cashback,
          // payouts and orders are three different questions a customer
          // asks at three different moments, and a merged stream answered
          // none of them well (owner decision 2026-08-19).
          if (ref.watch(marketplaceEnabledProvider))
            ButtonSegment(value: 2, label: Text(l10n.segmentOrders)),
        ],
        selected: {_segment},
        onSelectionChanged: (s) => setState(() => _segment = s.first),
      ),
      const SizedBox(height: Gap.lg),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final header = _header(context);

    // A tab that vanished under the customer (marketplace switched off mid
    // session) must not leave the screen on an index nothing renders.
    final segment =
        _segment == 2 && !ref.watch(marketplaceEnabledProvider) ? 0 : _segment;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: switch (segment) {
          0 => _EarnedList(header: header),
          1 => _PaidList(header: header),
          _ => YourOrdersView(header: header),
        },
      ),
    );
  }
}

/// The tab screen's scroll padding — content clears the floating nav bar.
const _pagePadding = EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.navClearance);

/// A non-paged state (skeleton, error, empty) with the header on top.
class _StaticList extends StatelessWidget {
  const _StaticList({required this.header, required this.children});

  final List<Widget> header;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: _pagePadding,
      children: [...header, ...children],
    );
  }
}

/// Fires loadMore when the scroll nears the end — the cursor walk.
class _InfiniteList extends StatelessWidget {
  const _InfiniteList({
    required this.header,
    required this.count,
    required this.builder,
    required this.onRefresh,
    required this.onEndReached,
    required this.footerLoading,
  });

  final List<Widget> header;
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
        child: ListView.builder(
          padding: _pagePadding,
          itemCount: header.length + count + (footerLoading ? 1 : 0),
          itemBuilder: (context, index) {
            if (index < header.length) return header[index];

            final i = index - header.length;
            if (i >= count) {
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
              child: builder(context, i),
            );
          },
        ),
      ),
    );
  }
}

class _EarnedList extends ConsumerWidget {
  const _EarnedList({required this.header});

  final List<Widget> header;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(earnedPagerProvider);
    final l10n = context.l10n;

    if (!state.loaded) return _ListSkeleton(header: header);

    if (state.error != null) {
      return _ErrorRetry(
        header: header,
        message: state.error!.isEmpty ? l10n.errorGeneric : state.error!,
        onRetry: () => ref.read(earnedPagerProvider.notifier).refresh(),
      );
    }

    if (state.items.isEmpty) {
      return _EmptyState(
        header: header,
        icon: Icons.storefront_rounded,
        tint: ManfaaTint.coral,
        title: l10n.emptyEarnedTitle,
        body: l10n.emptyEarnedBody,
      );
    }

    return _InfiniteList(
      header: header,
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

  /// The leading icon tile follows money semantics: amber while pending,
  /// green once confirmed, blue when paid out, quiet when closed.
  (IconData, ManfaaTint) _tile() {
    return switch (entry.status) {
      'pending' => (Icons.schedule_rounded, ManfaaTint.amber),
      'confirmed' => (Icons.check_rounded, ManfaaTint.green),
      'paid' => (Icons.account_balance_rounded, ManfaaTint.blue),
      'reversed' => (Icons.replay_rounded, ManfaaTint.neutral),
      _ => (Icons.error_outline_rounded, ManfaaTint.coral),
    };
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final (label, tone) = _status(context);
    final (icon, tint) = _tile();
    final reason = _reason(context);
    final struck = entry.status == 'reversed' || entry.status == 'unpaid';

    // §10: pending money reads muted — only confirmed money is green, and
    // the two never merge into one figure anywhere.
    final amountColor = switch (entry.status) {
      'confirmed' => ManfaaColors.confirmedGreen,
      'paid' => theme.colorScheme.onSurface,
      _ => muted,
    };

    return ManfaaCard(
      padding: const EdgeInsets.all(Gap.lg),
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
                      entry.merchantName,
                      style: theme.textTheme.titleMedium,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      formatDayMonth(entry.occurredAt),
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
                    entry.cashbackLaari,
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: amountColor,
                      decoration: struck ? TextDecoration.lineThrough : null,
                    ),
                  ),
                  const SizedBox(height: Gap.xs),
                  StatusChip(label: label, tone: tone),
                ],
              ),
            ],
          ),
          if (reason != null) ...[
            const SizedBox(height: Gap.md),
            Text(
              reason,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],
        ],
      ),
    );
  }
}

class _PaidList extends ConsumerWidget {
  const _PaidList({required this.header});

  final List<Widget> header;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(paidPagerProvider);
    final l10n = context.l10n;

    if (!state.loaded) return _ListSkeleton(header: header);

    if (state.error != null) {
      return _ErrorRetry(
        header: header,
        message: state.error!.isEmpty ? l10n.errorGeneric : state.error!,
        onRetry: () => ref.read(paidPagerProvider.notifier).refresh(),
      );
    }

    if (state.items.isEmpty) {
      return _EmptyState(
        header: header,
        icon: Icons.account_balance_rounded,
        tint: ManfaaTint.blue,
        title: l10n.emptyPaidTitle,
        body: l10n.emptyPaidBody,
      );
    }

    return _InfiniteList(
      header: header,
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
    final muted = theme.colorScheme.onSurfaceVariant;

    final (label, tone) = switch (entry.status) {
      'paid' => (l10n.payoutPaid, StatusTone.confirmed),
      'sent' => (l10n.payoutSent, StatusTone.paid),
      _ => (l10n.payoutFailed, StatusTone.attention),
    };

    // Tile tint mirrors the chip: green once landed, blue in flight,
    // coral when the transfer needs attention.
    final tint = switch (entry.status) {
      'paid' => ManfaaTint.green,
      'sent' => ManfaaTint.blue,
      _ => ManfaaTint.coral,
    };

    return ManfaaCard(
      onTap: () => context.push('/activity/payout/${entry.id}'),
      padding: const EdgeInsets.all(Gap.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              IconTile(Icons.account_balance_rounded, tint: tint),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    MoneyText(
                      entry.amountLaari,
                      style: theme.textTheme.titleLarge
                          ?.copyWith(color: theme.colorScheme.onSurface),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      l10n.payoutPeriod(
                        formatDayMonth(entry.periodStart),
                        formatDayMonth(entry.periodEnd),
                      ),
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: Gap.sm),
              StatusChip(label: label, tone: tone),
              const SizedBox(width: Gap.xs),
              Icon(Icons.chevron_right_rounded, color: muted),
            ],
          ),
          if (entry.status == 'failed') ...[
            const SizedBox(height: Gap.md),
            Text(
              l10n.payoutFailedNote,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.header,
    required this.icon,
    required this.tint,
    required this.title,
    required this.body,
  });

  final List<Widget> header;
  final IconData icon;
  final ManfaaTint tint;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return _StaticList(
      header: header,
      children: [
        ManfaaCard(
          padding: const EdgeInsets.all(Gap.huge),
          child: Column(
            children: [
              IconTile(icon, tint: tint, size: 64, iconSize: 30),
              const SizedBox(height: Gap.lg),
              Text(
                title,
                textAlign: TextAlign.center,
                style: theme.textTheme.titleLarge,
              ),
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
      ],
    );
  }
}

class _ErrorRetry extends StatelessWidget {
  const _ErrorRetry({
    required this.header,
    required this.message,
    required this.onRetry,
  });

  final List<Widget> header;
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return _StaticList(
      header: header,
      children: [
        ManfaaCard(
          child: Column(
            children: [
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: Gap.lg),
              OutlinedButton(
                onPressed: onRetry,
                child: Text(context.l10n.retry),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ListSkeleton extends StatelessWidget {
  const _ListSkeleton({required this.header});

  final List<Widget> header;

  @override
  Widget build(BuildContext context) {
    return _StaticList(
      header: header,
      children: const [
        SkeletonBox(height: 92, radius: Corner.card),
        SizedBox(height: Gap.md),
        SkeletonBox(height: 92, radius: Corner.card),
        SizedBox(height: Gap.md),
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
