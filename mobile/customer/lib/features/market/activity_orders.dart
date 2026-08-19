import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';

/// Which slice of the timeline is showing.
final activityTabProvider = StateProvider<String>((_) => 'active');

final activityQueryProvider = StateProvider<String>((_) => '');

final activityProvider = FutureProvider.autoDispose<ActivityPage>((ref) {
  return ref.watch(apiProvider).activity(tab: ref.watch(activityTabProvider));
});

/// "Your orders" — the one timeline (`Customer App Order Tracking.png`).
///
/// Marketplace orders and cashback credits in a single stream, which is how
/// a customer thinks about what they have going on. The distinction between
/// the two is ours, not theirs.
///
/// This screen exists because an order was reachable exactly once, on the
/// push straight after checkout, and never again — there was no list
/// anywhere in the app.
class YourOrdersView extends ConsumerWidget {
  const YourOrdersView({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final page = ref.watch(activityProvider);
    final tab = ref.watch(activityTabProvider);
    final query = ref.watch(activityQueryProvider).trim().toLowerCase();

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(activityProvider),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(Gap.md, 0, Gap.md, Gap.navClearance),
        children: [
          Text('Your orders', style: theme.textTheme.headlineMedium),
          const SizedBox(height: Gap.xs),
          Text(
            'Track your marketplace and cashback orders in one place.',
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
          const SizedBox(height: Gap.lg),

          _Tabs(
            value: tab,
            onChanged: (next) =>
                ref.read(activityTabProvider.notifier).state = next,
          ),
          const SizedBox(height: Gap.md),

          TextField(
            onChanged: (value) =>
                ref.read(activityQueryProvider.notifier).state = value,
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search_rounded),
              hintText: 'Search order # or store',
            ),
          ),
          const SizedBox(height: Gap.md),

          page.when(
            loading: () => const Column(
              children: [
                SkeletonBox(height: 160),
                SizedBox(height: Gap.md),
                SkeletonBox(height: 120),
              ],
            ),
            error: (error, _) => Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Text(
                error is MobileApiException && error.message.isNotEmpty
                    ? error.message
                    : 'That could not be loaded.',
                textAlign: TextAlign.center,
              ),
            ),
            data: (data) => _Timeline(page: data, query: query),
          ),

          const SizedBox(height: Gap.md),
          _HowItWorks(),
        ],
      ),
    );
  }
}

class _Timeline extends StatelessWidget {
  const _Timeline({required this.page, required this.query});

  final ActivityPage page;
  final String query;

  @override
  Widget build(BuildContext context) {
    final entries = page.entries.where((entry) {
      if (query.isEmpty) return true;

      final order = entry.order;
      if (order != null) {
        return order.reference.toLowerCase().contains(query) ||
            order.stores.any(
              (store) => store.storeName.toLowerCase().contains(query),
            );
      }

      final tx = entry.transaction;

      return (tx?.reference.toLowerCase().contains(query) ?? false) ||
          (tx?.merchantName?.toLowerCase().contains(query) ?? false);
    }).toList(growable: false);

    if (entries.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.huge),
        child: Center(
          child: Text(
            query.isEmpty
                ? 'Nothing here yet.'
                : 'Nothing matches "$query".',
            style: const TextStyle(color: ManfaaColors.textMuted),
          ),
        ),
      );
    }

    return Column(
      children: [
        for (final entry in entries) ...[
          if (entry.isOrder)
            _OrderCard(order: entry.order!, at: entry.at)
          else
            _TransactionCard(transaction: entry.transaction!),
          const SizedBox(height: Gap.md),
        ],
      ],
    );
  }
}

class _Tabs extends StatelessWidget {
  const _Tabs({required this.value, required this.onChanged});

  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: ManfaaColors.surface,
        borderRadius: BorderRadius.circular(Corner.control),
        border: Border.all(color: ManfaaColors.line),
      ),
      child: Row(
        children: [
          for (final (key, label) in const [
            ('active', 'Active'),
            ('completed', 'Completed'),
            ('cancelled', 'Cancelled'),
          ])
            Expanded(
              child: GestureDetector(
                behavior: HitTestBehavior.opaque,
                onTap: () => onChanged(key),
                child: Container(
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  decoration: BoxDecoration(
                    color: key == value
                        ? theme.colorScheme.primary.withValues(alpha: 0.08)
                        : Colors.transparent,
                    borderRadius: BorderRadius.circular(10),
                    border: key == value
                        ? Border.all(color: theme.colorScheme.primary)
                        : null,
                  ),
                  child: Text(
                    label,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.labelLarge?.copyWith(
                      color: key == value
                          ? theme.colorScheme.primary
                          : ManfaaColors.textMuted,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.order, required this.at});

  final ActivityOrder order;
  final DateTime? at;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pickup = order.pickupReady;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(Gap.sm),
                decoration: BoxDecoration(
                  color: ManfaaColors.greenSoft,
                  borderRadius: BorderRadius.circular(Corner.tile),
                ),
                child: const Icon(Icons.shopping_basket_outlined,
                    color: ManfaaColors.green),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('#${order.reference}',
                        style: theme.textTheme.titleMedium),
                    if (at != null)
                      Text(
                        _when(at!),
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: ManfaaColors.textMuted),
                      ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    formatRufiyaa(order.totalPayableLaari),
                    style: theme.textTheme.titleMedium,
                  ),
                  const SizedBox(height: 2),
                  StatusChip(
                    label: orderStateLabel(order),
                    tone: orderStateTone(order),
                  ),
                ],
              ),
            ],
          ),

          if (order.storeCount > 1) ...[
            const SizedBox(height: Gap.md),
            Text(
              '${order.storeCount} stores in this order',
              style: theme.textTheme.bodySmall,
            ),
          ],
          const SizedBox(height: Gap.sm),

          // A line per shop: in a multi-vendor order the shops ARE the
          // status, and one summary word would hide that two are confirmed
          // and one is not.
          for (final store in order.stores)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                children: [
                  Expanded(child: Text(store.storeName)),
                  StatusChip(
                    label: storeStateLabel(store.state),
                    tone: storeStateTone(store.state),
                  ),
                ],
              ),
            ),

          const SizedBox(height: Gap.md),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(Gap.md),
            decoration: BoxDecoration(
              color: ManfaaColors.greenSoft,
              borderRadius: BorderRadius.circular(Corner.tile),
            ),
            child: Row(
              children: [
                const Icon(Icons.savings_outlined,
                    size: 18, color: ManfaaColors.green),
                const SizedBox(width: Gap.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Cashback after validation',
                          style: theme.textTheme.bodySmall),
                      Text(
                        formatRufiyaa(order.cashbackTotalLaari),
                        style: theme.textTheme.titleMedium
                            ?.copyWith(color: ManfaaColors.green),
                      ),
                    ],
                  ),
                ),
                if (pickup != null)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text('Pickup code', style: theme.textTheme.bodySmall),
                      Text(
                        pickup.pickupCode!,
                        style: theme.textTheme.titleMedium,
                      ),
                    ],
                  ),
              ],
            ),
          ),

          const SizedBox(height: Gap.md),
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: () => context.push('/market/orders/${order.id}'),
                  icon: const Icon(Icons.local_shipping_outlined, size: 18),
                  label: const Text('Track order'),
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: OutlinedButton(
                  onPressed: () => context.push('/market/orders/${order.id}'),
                  child: const Text('View details'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static String _when(DateTime at) {
    final now = DateTime.now();
    final sameDay =
        at.year == now.year && at.month == now.month && at.day == now.day;
    final time =
        '${at.hour.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')}';

    return sameDay ? 'Today, $time' : '${at.day}/${at.month}/${at.year}';
  }
}

class _TransactionCard extends StatelessWidget {
  const _TransactionCard({required this.transaction});

  final ActivityTransaction transaction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final paid = transaction.state == 'paid';

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(transaction.merchantName ?? 'Cashback',
                        style: theme.textTheme.titleMedium),
                    Text(
                      '#${transaction.reference}',
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: ManfaaColors.textMuted),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    formatRufiyaa(transaction.amountLaari),
                    style: theme.textTheme.titleMedium,
                  ),
                  const SizedBox(height: 2),
                  StatusChip(
                    label: paid ? 'Paid out' : 'Earned',
                    tone: paid ? StatusTone.paid : StatusTone.confirmed,
                  ),
                ],
              ),
            ],
          ),
          if (paid) ...[
            const SizedBox(height: Gap.sm),
            Row(
              children: [
                const Icon(Icons.check_circle_rounded,
                    size: 16, color: ManfaaColors.green),
                const SizedBox(width: Gap.sm),
                Text(
                  'Cashback sent to your payout account',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ],
          const SizedBox(height: Gap.sm),
          Row(
            children: [
              Text('Cashback earned', style: theme.textTheme.bodySmall),
              const Spacer(),
              Text(
                formatRufiyaa(transaction.cashbackLaari),
                style: theme.textTheme.titleMedium
                    ?.copyWith(color: ManfaaColors.green),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _HowItWorks extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: ManfaaColors.greenSoft,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded, color: ManfaaColors.green),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('How order cashback works',
                    style: theme.textTheme.titleSmall),
                Text(
                  'Marketplace cashback is credited after the store '
                  'validates your order.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: ManfaaColors.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

String orderStateLabel(ActivityOrder order) {
  if (order.paymentState == 'proof_submitted') return 'Under review';
  if (order.paymentState == 'refused') return 'Payment refused';

  return switch (order.state) {
    'placed' => 'Placed',
    'under_review' => 'Under review',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    _ => order.state,
  };
}

StatusTone orderStateTone(ActivityOrder order) {
  if (order.paymentState == 'refused') return StatusTone.closed;
  if (order.state == 'completed') return StatusTone.confirmed;
  if (order.state == 'cancelled') return StatusTone.closed;

  return StatusTone.pending;
}

String storeStateLabel(String state) => switch (state) {
      'new' => 'Under review',
      'accepted' || 'preparing' => 'Confirmed',
      'ready' => 'Ready for pickup',
      'out_for_delivery' => 'On the way',
      'delivered' => 'Delivered',
      'rejected' => 'Rejected',
      'cancelled' => 'Cancelled',
      _ => state,
    };

StatusTone storeStateTone(String state) => switch (state) {
      'delivered' => StatusTone.confirmed,
      'rejected' || 'cancelled' => StatusTone.closed,
      'new' => StatusTone.pending,
      _ => StatusTone.paid,
    };
