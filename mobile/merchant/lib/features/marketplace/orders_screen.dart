import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'marketplace_providers.dart';
import 'marketplace_widgets.dart';

/// The shop's order queue, drawn to `Orders.png` (PLAN-marketplace.md §4.1).
///
/// Four tabs, two stat tiles, a search field, and a card per order carrying
/// the one thing a shopkeeper must know before touching it: whether the
/// customer has actually paid. Accept and Reject sit on the card, because
/// the whole point of this screen is that the common case takes one tap.
///
/// The banner at the foot tells the truth rather than hiding it: catalogue
/// work and reporting belong on the desktop panel, and pretending otherwise
/// would send somebody hunting through a phone for a screen that is not here.
class ShopOrdersScreen extends ConsumerWidget {
  const ShopOrdersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final page = ref.watch(shopOrdersProvider);
    final tab = ref.watch(shopOrderTabProvider);
    final query = ref.watch(shopOrderQueryProvider).trim().toLowerCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async => ref.invalidate(shopOrdersProvider),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              Gap.lg,
              Gap.lg,
              Gap.lg,
              // The floating nav bar overlays content, so the last card has
              // to scroll clear of it rather than sit under it.
              Gap.navClearance,
            ),
            children: [
              Text('Orders', style: theme.textTheme.headlineMedium),
              const SizedBox(height: Gap.xs),
              Text(
                'Receive, accept, and fulfill marketplace orders.',
                style: theme.textTheme.bodyMedium
                    ?.copyWith(color: ManfaaColors.textMuted),
              ),
              const SizedBox(height: Gap.lg),

              _QueueTabs(
                value: tab,
                onChanged: (next) =>
                    ref.read(shopOrderTabProvider.notifier).state = next,
              ),
              const SizedBox(height: Gap.md),

              TextField(
                onChanged: (value) =>
                    ref.read(shopOrderQueryProvider.notifier).state = value,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search_rounded),
                  hintText: 'Search orders by #, customer, or store',
                ),
              ),
              const SizedBox(height: Gap.md),

              page.when(
                loading: () => const _QueueSkeleton(),
                error: (error, _) => ErrorNote(error: error),
                data: (data) => _Queue(page: data, query: query),
              ),

              const SizedBox(height: Gap.lg),
              const DesktopHint(
                title: 'Quick actions only — use desktop for advanced order '
                    'management.',
                body: 'Visit the Manfaa Merchant portal to manage settings, '
                    'menus, and reports.',
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Queue extends StatelessWidget {
  const _Queue({required this.page, required this.query});

  final ShopOrderPage page;
  final String query;

  @override
  Widget build(BuildContext context) {
    // Filtered here rather than server-side: the queue is a working set of a
    // few dozen, and a round trip per keystroke would be slower than the
    // list is long.
    final orders = page.orders.where((order) {
      if (query.isEmpty) return true;

      return order.reference.toLowerCase().contains(query) ||
          order.customerName.toLowerCase().contains(query) ||
          order.branchName.toLowerCase().contains(query);
    }).toList(growable: false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: StatTile(
                icon: Icons.shopping_bag_outlined,
                label: 'New orders',
                value: '${page.newCount}',
                caption: 'Just received',
                tone: StatTone.calm,
              ),
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: StatTile(
                icon: Icons.error_outline_rounded,
                label: 'Awaiting action',
                value: '${page.awaitingActionCount}',
                caption: 'Accept or reject',
                tone: StatTone.attention,
              ),
            ),
          ],
        ),
        const SizedBox(height: Gap.md),
        if (orders.isEmpty)
          const EmptyNote(
            icon: Icons.inbox_outlined,
            title: 'Nothing here',
            body: 'Orders in this state will appear as they come in.',
          )
        else
          for (final order in orders) ...[
            ShopOrderCard(order: order),
            const SizedBox(height: Gap.md),
          ],
      ],
    );
  }
}

class _QueueTabs extends StatelessWidget {
  const _QueueTabs({required this.value, required this.onChanged});

  final String value;
  final ValueChanged<String> onChanged;

  static const _tabs = <(String, String)>[
    ('new', 'New'),
    ('preparing', 'Preparing'),
    ('ready', 'Ready'),
    ('completed', 'Completed'),
  ];

  @override
  Widget build(BuildContext context) {
    return SegmentedTabs(
      tabs: [for (final (key, label) in _tabs) (key, label)],
      value: value,
      onChanged: onChanged,
    );
  }
}

class _QueueSkeleton extends StatelessWidget {
  const _QueueSkeleton();

  @override
  Widget build(BuildContext context) {
    return const Column(
      children: [
        SkeletonBox(height: 88),
        SizedBox(height: Gap.md),
        SkeletonBox(height: 190),
        SizedBox(height: Gap.md),
        SkeletonBox(height: 190),
      ],
    );
  }
}
