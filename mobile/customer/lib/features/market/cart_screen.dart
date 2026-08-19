import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import 'market_providers.dart';

/// The multi-vendor cart (`Cart Page Collapsible By Merchant.png`,
/// `Cart Page Expanded.png`).
///
/// Owner decisions this screen exists to honour, all three visible at once:
/// each shop's card is **collapsed by default**, a shop short of its minimum
/// carries a **warning saying how short**, and the order summary at the
/// bottom **expands**.
class CartScreen extends ConsumerWidget {
  const CartScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final cart = ref.watch(cartProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(
          cart.valueOrNull == null || cart.valueOrNull!.storeCount == 0
              ? l10n.cartTitle
              : l10n.cartTitleWithStores(cart.valueOrNull!.storeCount),
        ),
        actions: [
          if ((cart.valueOrNull?.storeCount ?? 0) > 0)
            TextButton(
              onPressed: () => ref.read(cartProvider.notifier).clear(),
              child: Text(l10n.cartClear),
            ),
        ],
      ),
      body: SafeArea(
        child: cart.when(
          loading: () => const Padding(
            padding: EdgeInsets.all(Gap.lg),
            child: Column(children: [
              SkeletonBox(height: 90, radius: Corner.card),
              SizedBox(height: Gap.md),
              SkeletonBox(height: 90, radius: Corner.card),
            ]),
          ),
          error: (error, _) => Center(
            child: Padding(
              padding: const EdgeInsets.all(Gap.huge),
              child: Text(
                error is MobileApiException ? error.message : l10n.errorGeneric,
                textAlign: TextAlign.center,
              ),
            ),
          ),
          data: (data) => data.isEmpty ? _Empty() : _Basket(cart: data),
        ),
      ),
      bottomNavigationBar: cart.valueOrNull == null || cart.valueOrNull!.isEmpty
          ? null
          : _CheckoutBar(cart: cart.value!),
    );
  }
}

class _Basket extends StatelessWidget {
  const _Basket({required this.cart});

  final Cart cart;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.xl),
      children: [
        // What they will earn, at the top — the reason they are here.
        if (cart.cashbackLaari > 0)
          Container(
            padding: const EdgeInsets.all(Gap.lg),
            decoration: BoxDecoration(
              color: ManfaaColors.greenSoft,
              borderRadius: BorderRadius.circular(Corner.card),
            ),
            child: Row(
              children: [
                IconTile(Icons.savings_outlined,
                    tint: ManfaaTint.green, size: 40, iconSize: 20),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l10n.cartYouWillEarn,
                          style: theme.textTheme.bodySmall),
                      MoneyText(
                        cart.cashbackLaari,
                        style: theme.textTheme.titleLarge?.copyWith(
                          color: ManfaaColors.green,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: Gap.md),
        for (final subcart in cart.subcarts) ...[
          SubcartCard(subcart: subcart),
          const SizedBox(height: Gap.md),
        ],
        // Said plainly, because it surprises people the first time.
        if (cart.storeCount > 1)
          ManfaaCard(
            child: Row(
              children: [
                IconTile(Icons.local_shipping_outlined,
                    tint: ManfaaTint.blue, size: 40, iconSize: 20),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l10n.cartSeparateOrders,
                          style: theme.textTheme.titleSmall),
                      Text(
                        l10n.cartSeparateOrdersHint,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        if (cart.needsAddress) ...[
          const SizedBox(height: Gap.md),
          Text(
            l10n.cartNeedsAddress,
            style: theme.textTheme.bodySmall?.copyWith(color: ManfaaColors.amber),
          ),
        ],
      ],
    );
  }
}

/// One shop's card. **Collapsed by default** (owner decision): the header
/// alone answers "what am I buying here and does it qualify", and only
/// someone changing quantities needs the lines.
class SubcartCard extends ConsumerStatefulWidget {
  const SubcartCard({super.key, required this.subcart});

  final Subcart subcart;

  @override
  ConsumerState<SubcartCard> createState() => _SubcartCardState();
}

class _SubcartCardState extends ConsumerState<SubcartCard> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final subcart = widget.subcart;
    final delivery = subcart.delivery;
    final short = !delivery.minimumMet;

    return ManfaaCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(Corner.card),
            onTap: () => setState(() => _open = !_open),
            child: Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                children: [
                  Row(
                    children: [
                      IconTile(Icons.storefront_rounded,
                          tint: ManfaaTint.violet, size: 40, iconSize: 20),
                      const SizedBox(width: Gap.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(subcart.title,
                                style: theme.textTheme.titleSmall,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis),
                            Text(
                              l10n.cartItemsAnd(
                                subcart.items.length,
                                formatMoney(subcart.itemsLaari, dhivehi: dhivehi),
                              ),
                              style: theme.textTheme.bodySmall
                                  ?.copyWith(color: muted),
                            ),
                          ],
                        ),
                      ),
                      Icon(
                        _open ? Icons.expand_less_rounded : Icons.expand_more_rounded,
                        color: muted,
                      ),
                    ],
                  ),
                  const SizedBox(height: Gap.sm),
                  Row(
                    children: [
                      StatusChip(
                        label: delivery.delivers
                            ? l10n.fulfilmentDelivery
                            : l10n.fulfilmentPickup,
                        tone: StatusTone.pending,
                      ),
                      const Spacer(),
                      if (subcart.cashbackLaari > 0)
                        Text(
                          l10n.cartEarn(formatMoney(
                              subcart.cashbackLaari, dhivehi: dhivehi)),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: ManfaaColors.green,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          // THE WARNING (owner decision): a shop short of its minimum says
          // exactly how short, in the attention tone, and blocks checkout.
          if (short)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(
                  horizontal: Gap.lg, vertical: Gap.md),
              color: ManfaaColors.amberSoft,
              child: Row(
                children: [
                  const Icon(Icons.error_outline_rounded,
                      size: 18, color: ManfaaColors.amber),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: Text(
                      l10n.cartAddMoreToMinimum(
                        formatMoney(delivery.shortfallLaari, dhivehi: dhivehi),
                      ),
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: ManfaaColors.amber),
                    ),
                  ),
                  Text(
                    '${formatMoney(subcart.itemsLaari, dhivehi: dhivehi)} / '
                    '${formatMoney(delivery.orderMinimumLaari ?? 0, dhivehi: dhivehi)}',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: ManfaaColors.amber),
                  ),
                ],
              ),
            ),
          if (!subcart.allAvailable)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(
                  horizontal: Gap.lg, vertical: Gap.md),
              color: ManfaaColors.amberSoft,
              child: Text(
                l10n.cartSomethingUnavailable,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.amber),
              ),
            ),
          if (_open) ...[
            Divider(height: 1, color: theme.colorScheme.outlineVariant),
            Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                children: [
                  for (final line in subcart.items) ...[
                    _Line(line: line),
                    const SizedBox(height: Gap.md),
                  ],
                  _Money(
                    label: l10n.cartItemsLabel(subcart.items.length),
                    laari: subcart.itemsLaari,
                  ),
                  _Money(
                    label: l10n.cartDelivery,
                    laari: delivery.feeLaari,
                    strikethroughFree: delivery.feeWaived,
                  ),
                  if (subcart.cashbackLaari > 0)
                    _Money(
                      label: l10n.cartCashbackFrom(subcart.storeName),
                      laari: subcart.cashbackLaari,
                      tint: ManfaaColors.green,
                      negative: true,
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _Line extends ConsumerWidget {
  const _Line({required this.line});

  final CartLine line;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final cart = ref.read(cartProvider.notifier);

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                line.displayName(dhivehi),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  // A sold-out line stays visible and struck, never dropped.
                  decoration:
                      line.available ? null : TextDecoration.lineThrough,
                  color: line.available ? null : muted,
                ),
              ),
              Row(
                children: [
                  Text(formatMoney(line.unitPriceLaari, dhivehi: dhivehi),
                      style: theme.textTheme.bodySmall?.copyWith(color: muted)),
                  // The price moved while it sat here. Said out loud.
                  if (line.priceChanged && line.priceWasLaari != null) ...[
                    const SizedBox(width: Gap.xs),
                    Text(
                      formatMoney(line.priceWasLaari!, dhivehi: dhivehi),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: muted,
                        decoration: TextDecoration.lineThrough,
                      ),
                    ),
                  ],
                  if (!line.available) ...[
                    const SizedBox(width: Gap.sm),
                    Text(l10n.outOfStock,
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: ManfaaColors.amber)),
                  ],
                ],
              ),
            ],
          ),
        ),
        IconButton(
          visualDensity: VisualDensity.compact,
          onPressed: () => cart.remove(line.cartItemId),
          icon: const Icon(Icons.delete_outline_rounded, size: 20),
          color: muted,
        ),
        _QtyStepper(line: line),
        const SizedBox(width: Gap.sm),
        MoneyText(line.lineTotalLaari, style: theme.textTheme.bodyMedium),
      ],
    );
  }
}

class _QtyStepper extends ConsumerWidget {
  const _QtyStepper({required this.line});

  final CartLine line;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final cart = ref.read(cartProvider.notifier);

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          InkWell(
            onTap: () => cart.setQty(line.cartItemId, line.qty - 1),
            child: const Padding(
              padding: EdgeInsets.all(6),
              child: Icon(Icons.remove_rounded, size: 16),
            ),
          ),
          Text('${line.qty}', style: theme.textTheme.bodyMedium),
          InkWell(
            onTap: () => cart.setQty(line.cartItemId, line.qty + 1),
            child: const Padding(
              padding: EdgeInsets.all(6),
              child: Icon(Icons.add_rounded, size: 16),
            ),
          ),
        ],
      ),
    );
  }
}

class _Money extends StatelessWidget {
  const _Money({
    required this.label,
    required this.laari,
    this.tint,
    this.negative = false,
    this.strikethroughFree = false,
  });

  final String label;
  final int laari;
  final Color? tint;
  final bool negative;
  final bool strikethroughFree;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(
            child: Text(label,
                style: theme.textTheme.bodySmall?.copyWith(color: tint)),
          ),
          if (strikethroughFree)
            Text(l10n.freeDelivery,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.green))
          else
            Text(
              negative ? '− ' : '',
              style: theme.textTheme.bodySmall?.copyWith(color: tint),
            ),
          if (!strikethroughFree)
            MoneyText(laari,
                style: theme.textTheme.bodySmall?.copyWith(color: tint)),
        ],
      ),
    );
  }
}

/// The sticky footer. Total Payable **expands** into the breakdown (owner
/// decision), and the button says which shop is stopping checkout rather
/// than refusing without a reason.
class _CheckoutBar extends ConsumerStatefulWidget {
  const _CheckoutBar({required this.cart});

  final Cart cart;

  @override
  ConsumerState<_CheckoutBar> createState() => _CheckoutBarState();
}

class _CheckoutBarState extends ConsumerState<_CheckoutBar> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final cart = widget.cart;
    final blocking = cart.blocking;

    return SafeArea(
      child: Container(
        padding: const EdgeInsets.all(Gap.lg),
        decoration: BoxDecoration(
          color: theme.colorScheme.surface,
          border: Border(
            top: BorderSide(color: theme.colorScheme.outlineVariant),
          ),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (_open) ...[
              _Money(label: l10n.cartItemsLabel(cart.itemCount), laari: cart.itemsLaari),
              _Money(label: l10n.cartDelivery, laari: cart.deliveryLaari),
              _Money(
                label: l10n.cartYouWillEarn,
                laari: cart.cashbackLaari,
                tint: ManfaaColors.green,
              ),
              const SizedBox(height: Gap.sm),
            ],
            Row(
              children: [
                Expanded(
                  child: InkWell(
                    onTap: () => setState(() => _open = !_open),
                    child: Row(
                      children: [
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(l10n.cartTotalPayable,
                                style: theme.textTheme.bodySmall),
                            MoneyText(
                              cart.totalPayableLaari,
                              style: theme.textTheme.titleLarge
                                  ?.copyWith(fontWeight: FontWeight.w800),
                            ),
                          ],
                        ),
                        Icon(
                          _open
                              ? Icons.expand_more_rounded
                              : Icons.expand_less_rounded,
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: FilledButton(
                    onPressed: cart.canCheckout ? () {} : null,
                    style: FilledButton.styleFrom(
                      backgroundColor: ManfaaColors.violet,
                      padding: const EdgeInsets.symmetric(vertical: Gap.md),
                    ),
                    child: Text(
                      cart.canCheckout
                          ? l10n.cartCheckoutOrders(cart.storeCount)
                          // Names the shop rather than refusing silently.
                          : l10n.cartBlockedBy(blocking.first.storeName),
                      textAlign: TextAlign.center,
                      maxLines: 2,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconTile(Icons.shopping_cart_outlined,
              tint: ManfaaTint.violet, size: 56, iconSize: 28),
          const SizedBox(height: Gap.lg),
          Text(l10n.cartEmpty, style: theme.textTheme.titleMedium),
        ],
      ),
    );
  }
}
