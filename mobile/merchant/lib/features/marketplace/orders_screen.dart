import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
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
    // Three states, not two: enrolled, definitely not enrolled, and we
    // could not ask. The third used to render as the second, which told an
    // approved vendor its store does not sell.
    final enrolled = ref.watch(sellsOnMarketplaceProvider);
    final unknown = ref.watch(shopEnrolmentUnknownProvider);
    final page = ref.watch(shopOrdersProvider);
    final tab = ref.watch(shopOrderTabProvider);
    final query = ref.watch(shopOrderQueryProvider).trim().toLowerCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async {
            // The enrolment answer is re-asked too. A refusal cached before
            // a permission was granted would otherwise outlive the fix.
            ref.invalidate(shopEnrolmentProvider);
            ref.invalidate(shopOrdersProvider);
          },
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

              if (enrolled && !unknown) ...[
                _QueueTabs(
                  value: tab,
                  onChanged: (next) =>
                      ref.read(shopOrderTabProvider.notifier).state = next,
                ),
                const SizedBox(height: Gap.md),
              ],

              if (enrolled && !unknown) TextField(
                onChanged: (value) =>
                    ref.read(shopOrderQueryProvider.notifier).state = value,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search_rounded),
                  hintText: 'Search orders by #, customer, or store',
                ),
              ),
              if (enrolled && !unknown) const SizedBox(height: Gap.md),

              if (unknown)
                const _CouldNotCheck()
              else if (!enrolled)
                const _NotSelling()
              else
                page.when(
                  loading: () => const _QueueSkeleton(),
                  error: (error, _) => ErrorNote(
                    error: error,
                    onRetry: () => ref.invalidate(shopOrdersProvider),
                  ),
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

/// Not a vendor yet. Says where to go rather than showing an empty queue
/// that reads as "no orders today".
class _NotSelling extends StatelessWidget {
  const _NotSelling();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(Gap.sm),
                decoration: BoxDecoration(
                  color: ManfaaColors.violetSoft,
                  borderRadius: BorderRadius.circular(Corner.tile),
                ),
                child: const Icon(Icons.storefront_outlined,
                    color: ManfaaColors.violet),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Text(
                  'Your store does not sell on the marketplace yet',
                  style: theme.textTheme.titleMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          Text(
            'Shoppers search every Manfaa store at once — applying puts your '
            'products in front of them. It takes a minute, and you can do it '
            'here.',
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
          const SizedBox(height: Gap.md),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: () => context.push('/more/marketplace'),
              icon: const Icon(Icons.storefront_outlined, size: 18),
              label: const Text('Apply to sell on the marketplace'),
            ),
          ),
        ],
      ),
    );
  }
}

/// We could not ask whether this store sells. Says exactly that, and offers
/// the retry — an approved vendor must never be told it is not one.
class _CouldNotCheck extends ConsumerWidget {
  const _CouldNotCheck();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final loading = ref.watch(shopEnrolmentProvider).isLoading;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              if (loading)
                const SizedBox(
                  height: 20,
                  width: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              else
                const Icon(Icons.cloud_off_rounded,
                    color: ManfaaColors.textMuted),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Text(
                  loading
                      ? 'Checking your store…'
                      : 'Could not check your store just now',
                  style: theme.textTheme.titleMedium,
                ),
              ),
            ],
          ),
          if (!loading) ...[
            const SizedBox(height: Gap.sm),
            Text(
              'This says nothing about whether you sell on the marketplace — '
              'only that the check did not go through. Pull down to try '
              'again.',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: ManfaaColors.textMuted),
            ),
            const SizedBox(height: Gap.md),
            OutlinedButton.icon(
              onPressed: () => ref.invalidate(shopEnrolmentProvider),
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('Try again'),
            ),
          ],
        ],
      ),
    );
  }
}
