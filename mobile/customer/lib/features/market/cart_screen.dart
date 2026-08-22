import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'market_providers.dart';

/// The basket, drawn to `Cart Page Collapsible By Merchant.png`.
///
/// A basket spanning three shops is three separate orders, and the screen is
/// built to make that legible rather than to hide it: one card per shop,
/// each carrying its own fulfilment, its own delivery fee, its own minimum
/// and its own cashback. The footer totals them and says plainly how many
/// orders the Checkout button is about to create.
///
/// Sections are COLLAPSED by default (owner decision): a shopper with three
/// shops in the basket wants to see the shape of it first, not scroll past
/// twenty lines to reach the total.
class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  final Set<int> _open = {};
  bool _editing = false;

  @override
  Widget build(BuildContext context) {
    final cart = ref.watch(cartProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(
          cart.valueOrNull == null
              ? 'My Cart'
              : 'My Cart (${cart.value!.storeCount} '
                    '${cart.value!.storeCount == 1 ? 'store' : 'stores'})',
        ),
        actions: [
          if ((cart.valueOrNull?.subcarts.isNotEmpty ?? false))
            TextButton(
              onPressed: () => setState(() => _editing = !_editing),
              child: Text(_editing ? 'Done' : 'Edit Cart'),
            ),
        ],
      ),
      body: cart.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Center(child: Text(error.toString())),
        data: (data) => data.subcarts.isEmpty
            ? const _Empty()
            : ListView(
                padding: const EdgeInsets.fromLTRB(
                  Gap.md,
                  Gap.md,
                  Gap.md,
                  Gap.huge,
                ),
                children: [
                  _EarnHero(laari: data.cashbackLaari),
                  const SizedBox(height: Gap.md),
                  for (final subcart in data.subcarts) ...[
                    _StoreSection(
                      subcart: subcart,
                      open: _open.contains(subcart.branchId),
                      editing: _editing,
                      onToggle: () => setState(() {
                        _open.contains(subcart.branchId)
                            ? _open.remove(subcart.branchId)
                            : _open.add(subcart.branchId);
                      }),
                    ),
                    const SizedBox(height: Gap.md),
                  ],
                  if (data.storeCount > 1) const _SeparateOrdersNote(),
                ],
              ),
      ),
      bottomNavigationBar:
          cart.valueOrNull == null || cart.value!.subcarts.isEmpty
          ? null
          : CheckoutBar(cart: cart.value!),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Gap.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.shopping_cart_outlined,
              size: 48,
              color: ManfaaColors.textFaint,
            ),
            const SizedBox(height: Gap.md),
            Text(
              'Your basket is empty',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: Gap.sm),
            FilledButton(
              onPressed: () => context.go('/market'),
              child: const Text('Browse shops'),
            ),
          ],
        ),
      ),
    );
  }
}

/// What the whole basket earns, at the top where it can be seen before any
/// scrolling.
class _EarnHero extends StatelessWidget {
  const _EarnHero({required this.laari});

  final int laari;

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
          const CircleAvatar(
            backgroundColor: Colors.white,
            child: Icon(
              Icons.account_balance_wallet_outlined,
              color: ManfaaColors.green,
            ),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text("You'll earn", style: theme.textTheme.bodySmall),
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: formatRufiyaa(laari),
                        style: theme.textTheme.titleLarge?.copyWith(
                          color: ManfaaColors.green,
                        ),
                      ),
                      const TextSpan(text: '  cashback'),
                    ],
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

/// One shop's half of the basket.
class _StoreSection extends ConsumerWidget {
  const _StoreSection({
    required this.subcart,
    required this.open,
    required this.editing,
    required this.onToggle,
  });

  final Subcart subcart;
  final bool open;
  final bool editing;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final terms = subcart.delivery;
    final count = subcart.items.fold<int>(0, (sum, item) => sum + item.qty);

    return ManfaaCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          InkWell(
            onTap: onToggle,
            borderRadius: BorderRadius.circular(Corner.card),
            child: Padding(
              padding: const EdgeInsets.all(Gap.md),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.all(Gap.sm),
                    decoration: BoxDecoration(
                      color: ManfaaColors.greenSoft,
                      borderRadius: BorderRadius.circular(Corner.tile),
                    ),
                    child: const Icon(
                      Icons.storefront_rounded,
                      color: ManfaaColors.green,
                    ),
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          subcart.storeName,
                          style: theme.textTheme.titleMedium,
                        ),
                        const SizedBox(height: 4),
                        Wrap(
                          spacing: Gap.sm,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            _FulfilmentChip(terms: terms),
                            Text(
                              '· $count ${count == 1 ? 'item' : 'items'} · '
                              '${formatRufiyaa(subcart.itemsLaari)}',
                              style: theme.textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      if (terms.delivers && terms.minimumMet)
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(
                              Icons.check_circle_rounded,
                              size: 14,
                              color: ManfaaColors.green,
                            ),
                            const SizedBox(width: 4),
                            Text(
                              'Minimum met',
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: ManfaaColors.green,
                              ),
                            ),
                          ],
                        ),
                      Text(
                        terms.delivers
                            ? 'Delivery ${formatRufiyaa(terms.feeLaari)}'
                            : 'Pickup FREE',
                        style: theme.textTheme.bodySmall,
                      ),
                      Text(
                        'Earn ${formatRufiyaa(subcart.cashbackLaari)}'
                        '${subcart.cashbackRatePercent == null ? '' : ' (${subcart.cashbackRatePercent}%)'}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: ManfaaColors.green,
                        ),
                      ),
                    ],
                  ),
                  Icon(open ? Icons.expand_less : Icons.expand_more),
                ],
              ),
            ),
          ),

          if (open) ...[
            const Divider(height: 1),
            Padding(
              padding: const EdgeInsets.all(Gap.md),
              child: Column(
                children: [
                  for (final line in subcart.items)
                    _Line(line: line, editing: editing),
                  const Divider(height: Gap.xl),
                  _Row(
                    label: 'Items ($count)',
                    value: formatRufiyaa(subcart.itemsLaari),
                  ),
                  if (terms.delivers)
                    _Row(
                      label: 'Delivery fee',
                      value: formatRufiyaa(terms.feeLaari),
                    ),
                  _Row(
                    label:
                        'Cashback from ${subcart.storeName}'
                        '${subcart.cashbackRatePercent == null ? '' : ' (${subcart.cashbackRatePercent}%)'}',
                    value: '- ${formatRufiyaa(subcart.cashbackLaari)}',
                    tone: ManfaaColors.green,
                  ),
                ],
              ),
            ),
          ],

          if (terms.delivers && terms.orderMinimumLaari != null)
            _MinimumBar(subcart: subcart),

          // Under the store's minimum for cashback: checkout still works,
          // but the shopper should know why this shop shows no reward.
          if (subcart.belowCashbackMinimum)
            Padding(
              padding: const EdgeInsets.fromLTRB(Gap.md, 0, Gap.md, Gap.md),
              child: Text(
                'Add ${formatRufiyaa(subcart.cashbackShortfallLaari)} more to '
                'earn cashback here — ${subcart.storeName} pays cashback on '
                'orders from ${formatRufiyaa(subcart.cashbackMinLaari)}.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _FulfilmentChip extends StatelessWidget {
  const _FulfilmentChip({required this.terms});

  final DeliveryTerms terms;

  @override
  Widget build(BuildContext context) {
    final delivers = terms.delivers;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: Gap.sm, vertical: 2),
      decoration: BoxDecoration(
        color: delivers ? ManfaaColors.greenSoft : ManfaaColors.blueSoft,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            delivers
                ? Icons.delivery_dining_rounded
                : Icons.storefront_outlined,
            size: 13,
            color: delivers ? ManfaaColors.green : ManfaaColors.blue,
          ),
          const SizedBox(width: 4),
          Text(
            delivers ? 'Delivery' : 'Pickup',
            style: TextStyle(
              fontSize: 11,
              color: delivers ? ManfaaColors.green : ManfaaColors.blue,
            ),
          ),
        ],
      ),
    );
  }
}

/// Met or not met, always with the numbers behind it — a shopper deciding
/// whether to add one more thing needs to see how much more.
class _MinimumBar extends StatelessWidget {
  const _MinimumBar({required this.subcart});

  final Subcart subcart;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final terms = subcart.delivery;
    final minimum = terms.orderMinimumLaari ?? 0;
    final met = terms.minimumMet;
    final progress = minimum == 0
        ? 1.0
        : (subcart.itemsLaari / minimum).clamp(0.0, 1.0);

    return Container(
      margin: const EdgeInsets.fromLTRB(Gap.md, 0, Gap.md, Gap.md),
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: met ? ManfaaColors.greenSoft : ManfaaColors.amberSoft,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Icon(
                met ? Icons.check_circle_rounded : Icons.error_outline_rounded,
                size: 16,
                color: met ? ManfaaColors.green : ManfaaColors.amber,
              ),
              const SizedBox(width: Gap.sm),
              Expanded(
                child: Text(
                  met
                      ? "You've met the minimum order"
                      : 'Add ${formatRufiyaa(terms.shortfallLaari)} more to '
                            'reach the minimum order',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: met ? ManfaaColors.ink : ManfaaColors.amber,
                  ),
                ),
              ),
              Text(
                '${formatRufiyaa(subcart.itemsLaari)} / '
                '${formatRufiyaa(minimum)}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 5,
              backgroundColor: Colors.white,
              valueColor: AlwaysStoppedAnimation(
                met ? ManfaaColors.green : ManfaaColors.amber,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Line extends ConsumerWidget {
  const _Line({required this.line, required this.editing});

  final CartLine line;
  final bool editing;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final controller = ref.read(cartProvider.notifier);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.sm),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(line.name, style: theme.textTheme.bodyMedium),
                Text(
                  formatRufiyaa(line.unitPriceLaari),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: ManfaaColors.textMuted,
                  ),
                ),
                if (!line.available)
                  Text(
                    'Out of stock',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: ManfaaColors.coralDeep,
                    ),
                  ),
              ],
            ),
          ),
          if (editing)
            IconButton(
              visualDensity: VisualDensity.compact,
              onPressed: () => controller.remove(line.cartItemId),
              icon: const Icon(Icons.delete_outline_rounded, size: 20),
            ),
          Container(
            decoration: BoxDecoration(
              border: Border.all(color: ManfaaColors.line),
              borderRadius: BorderRadius.circular(Corner.control),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  visualDensity: VisualDensity.compact,
                  onPressed: () =>
                      controller.setQty(line.cartItemId, line.qty - 1),
                  icon: const Icon(Icons.remove, size: 16),
                ),
                Text('${line.qty}'),
                IconButton(
                  visualDensity: VisualDensity.compact,
                  onPressed: () =>
                      controller.setQty(line.cartItemId, line.qty + 1),
                  icon: const Icon(Icons.add, size: 16),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.md),
          Text(
            formatRufiyaa(line.lineTotalLaari),
            style: theme.textTheme.titleSmall,
          ),
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value, this.tone});

  final String label;
  final String value;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.bodyMedium?.copyWith(color: tone);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: style)),
          Text(value, style: style),
        ],
      ),
    );
  }
}

/// Said once, plainly, rather than discovered at the payment step.
class _SeparateOrdersNote extends StatelessWidget {
  const _SeparateOrdersNote();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ManfaaCard(
      child: Row(
        children: [
          const CircleAvatar(
            backgroundColor: ManfaaColors.greenSoft,
            child: Icon(
              Icons.local_shipping_outlined,
              color: ManfaaColors.green,
            ),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Different stores, separate orders',
                  style: theme.textTheme.titleSmall,
                ),
                Text(
                  'Each store will fulfill your order separately.',
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

/// The foot of the basket: what it costs, what it earns, and the one way
/// forward.
///
/// Laid out as two ROWS rather than one. A single row put the totals and the
/// button side by side, and on a narrow phone the button was pushed off the
/// edge — so the screen showed a tall bar with no way to continue, which is
/// exactly what it was reported as. A full-width button beneath the totals
/// cannot be pushed anywhere.
class CheckoutBar extends StatelessWidget {
  const CheckoutBar({super.key, required this.cart});

  final Cart cart;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: Gap.md,
          vertical: Gap.sm,
        ),
        decoration: const BoxDecoration(
          color: ManfaaColors.surface,
          border: Border(top: BorderSide(color: ManfaaColors.line)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Expanded(
                  child: _Figure(
                    label: 'Total Payable',
                    value: formatRufiyaa(cart.totalPayableLaari),
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                Expanded(
                  child: _Figure(
                    label: "You'll earn",
                    value: formatRufiyaa(cart.cashbackLaari),
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: ManfaaColors.green,
                    ),
                    end: true,
                  ),
                ),
              ],
            ),
            const SizedBox(height: Gap.sm),
            SizedBox(
              width: double.infinity,
              child: cart.needsAddress
                  // WITHOUT an address, delivery cannot be quoted, so no
                  // minimum can be met, so a Checkout button would sit dead
                  // with its own cure on the far side of it. The button
                  // becomes the cure instead.
                  ? FilledButton.icon(
                      onPressed: () => context.push('/market/addresses/new'),
                      icon: const Icon(Icons.place_outlined, size: 18),
                      label: const Text('Add delivery address'),
                    )
                  : FilledButton(
                      onPressed: cart.canCheckout
                          ? () => context.push('/market/checkout')
                          : null,
                      child: Text(
                        cart.canCheckout
                            ? 'Checkout · ${cart.storeCount} separate '
                                  '${cart.storeCount == 1 ? 'order' : 'orders'}'
                            : 'Add more to meet a shop minimum',
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({
    required this.label,
    required this.value,
    this.style,
    this.end = false,
  });

  final String label;
  final String value;
  final TextStyle? style;
  final bool end;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: end
          ? CrossAxisAlignment.end
          : CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        Text(value, style: style, maxLines: 1, overflow: TextOverflow.ellipsis),
      ],
    );
  }
}
