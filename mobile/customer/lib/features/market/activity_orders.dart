import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';

/// Which slice of the order list is showing.
final activityTabProvider = StateProvider<String>((_) => 'active');

final activityQueryProvider = StateProvider<String>((_) => '');

/// Orders ONLY, from the orders endpoint.
///
/// This once read a merged activity stream — cashback, payouts and orders in
/// one list. That was the wrong shape: they are three questions a customer
/// asks at three different moments, and one stream answered none of them
/// well (owner decision 2026-08-19). Activity has three tabs now, so this
/// list has one subject.
final customerOrdersProvider = FutureProvider.autoDispose<List<CustomerOrder>>((
  ref,
) {
  return ref.watch(apiProvider).orders();
});

/// The Orders tab of Activity (`Customer App Order Tracking.png`).
///
/// A card per order with a line per shop — because in a multi-vendor order
/// the shops ARE the status, and one summary word would hide that two are
/// confirmed and one is not.
class YourOrdersView extends ConsumerWidget {
  const YourOrdersView({super.key, this.header = const []});

  /// The tab screen's shared brand header, so this list leads like its
  /// siblings rather than starting abruptly.
  final List<Widget> header;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final orders = ref.watch(customerOrdersProvider);
    final tab = ref.watch(activityTabProvider);
    final query = ref.watch(activityQueryProvider).trim().toLowerCase();

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(customerOrdersProvider),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(
          Gap.lg,
          Gap.md,
          Gap.lg,
          Gap.navClearance,
        ),
        children: [
          ...header,
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
          orders.when(
            loading: () => const Column(
              children: [
                SkeletonBox(height: 160),
                SizedBox(height: Gap.md),
                SkeletonBox(height: 160),
              ],
            ),
            error: (error, _) => Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Text(
                error is MobileApiException && error.message.isNotEmpty
                    ? error.message
                    : 'Your orders could not be loaded.',
                textAlign: TextAlign.center,
              ),
            ),
            data: (rows) => _OrderList(orders: rows, tab: tab, query: query),
          ),
          const SizedBox(height: Gap.md),
          const _HowItWorks(),
        ],
      ),
    );
  }
}

class _OrderList extends StatelessWidget {
  const _OrderList({
    required this.orders,
    required this.tab,
    required this.query,
  });

  final List<CustomerOrder> orders;
  final String tab;
  final String query;

  /// Which tab an order belongs to. Decided here rather than by asking the
  /// server three times — this is a customer's own orders, not a feed.
  static bool _inTab(CustomerOrder order, String tab) => switch (tab) {
    'completed' => order.state == 'completed',
    'cancelled' =>
      order.state == 'cancelled' || order.paymentState == 'refused',
    _ =>
      order.state != 'completed' &&
          order.state != 'cancelled' &&
          order.paymentState != 'refused',
  };

  @override
  Widget build(BuildContext context) {
    final shown = orders
        .where((order) {
          if (!_inTab(order, tab)) return false;
          if (query.isEmpty) return true;

          return order.reference.toLowerCase().contains(query) ||
              order.suborders.any(
                (sub) => sub.storeName.toLowerCase().contains(query),
              );
        })
        .toList(growable: false);

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.huge),
        child: Center(
          child: Text(
            query.isEmpty ? 'No orders here yet.' : 'Nothing matches "$query".',
            style: const TextStyle(color: ManfaaColors.textMuted),
          ),
        ),
      );
    }

    return Column(
      children: [
        for (final order in shown) ...[
          _OrderCard(order: order),
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
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
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
  const _OrderCard({required this.order});

  final CustomerOrder order;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final pickup = order.suborders
        .where(
          (sub) =>
              sub.fulfilment == 'pickup' &&
              sub.state == 'ready' &&
              (sub.pickupCode ?? '').isNotEmpty,
        )
        .firstOrNull;

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
                child: const Icon(
                  Icons.shopping_basket_outlined,
                  color: ManfaaColors.green,
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '#${order.reference}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium,
                    ),
                    if (_placed(order) != null)
                      Text(
                        _when(_placed(order)!),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: ManfaaColors.textMuted,
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: Gap.sm),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    formatRufiyaa(order.totalPayableLaari),
                    maxLines: 1,
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
          for (final sub in order.suborders)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      sub.storeName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: Gap.sm),
                  StatusChip(
                    label: storeStateLabel(sub.state),
                    tone: storeStateTone(sub.state),
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
                const Icon(
                  Icons.savings_outlined,
                  size: 18,
                  color: ManfaaColors.green,
                ),
                const SizedBox(width: Gap.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Cashback after validation',
                        style: theme.textTheme.bodySmall,
                      ),
                      Text(
                        formatRufiyaa(order.cashbackTotalLaari),
                        maxLines: 1,
                        style: theme.textTheme.titleMedium?.copyWith(
                          color: ManfaaColors.green,
                        ),
                      ),
                    ],
                  ),
                ),
                if (pickup != null)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    mainAxisSize: MainAxisSize.min,
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
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: () => context.push('/market/orders/${order.id}'),
              icon: const Icon(Icons.local_shipping_outlined, size: 18),
              label: const Text('Track order'),
            ),
          ),
        ],
      ),
    );
  }

  /// The model carries the wire string; parsing belongs here rather than in
  /// a model that other screens read raw.
  static DateTime? _placed(CustomerOrder order) =>
      order.placedAt == null ? null : DateTime.tryParse(order.placedAt!);

  static String _when(DateTime at) {
    final now = DateTime.now();
    final sameDay =
        at.year == now.year && at.month == now.month && at.day == now.day;
    final time =
        '${at.hour.toString().padLeft(2, '0')}:'
        '${at.minute.toString().padLeft(2, '0')}';

    return sameDay ? 'Today, $time' : '${at.day}/${at.month}/${at.year}';
  }
}

class _HowItWorks extends StatelessWidget {
  const _HowItWorks();

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
                Text(
                  'How order cashback works',
                  style: theme.textTheme.titleSmall,
                ),
                Text(
                  'Marketplace cashback is credited after the store '
                  'validates your order.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: ManfaaColors.textMuted,
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

String orderStateLabel(CustomerOrder order) {
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

StatusTone orderStateTone(CustomerOrder order) {
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
