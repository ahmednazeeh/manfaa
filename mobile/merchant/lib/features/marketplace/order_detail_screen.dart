import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import 'marketplace_providers.dart';
import 'marketplace_widgets.dart';

/// One order, drawn to `Order Details.png` (PLAN-marketplace.md §4.1).
///
/// The six-fact grid, the customer block with Call and Open map, the items,
/// the money breakdown, and a sticky Accept / Reject foot.
///
/// **Editing while picking** (§2.7) lives here too: once an order is
/// accepted every line gains a stepper down to zero, and the screen shows
/// the running refund — *"Refunding MVR 78.00"* — so the shop sees the
/// customer's side of the decision BEFORE committing it. Taking the last
/// line to zero offers Reject instead, because an order with nothing in it
/// is not an amendment.
class ShopOrderDetailScreen extends ConsumerStatefulWidget {
  const ShopOrderDetailScreen({super.key, required this.suborderId});

  final int suborderId;

  @override
  ConsumerState<ShopOrderDetailScreen> createState() =>
      _ShopOrderDetailScreenState();
}

class _ShopOrderDetailScreenState
    extends ConsumerState<ShopOrderDetailScreen> {
  /// Line id → the quantity the shop says it can actually fill. Empty until
  /// somebody touches a stepper, so an untouched order amends nothing.
  final Map<int, int> _picked = {};

  @override
  Widget build(BuildContext context) {
    final order = ref.watch(shopOrderProvider(widget.suborderId));

    return Scaffold(
      appBar: AppBar(title: const Text('Order details')),
      body: order.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Padding(
          padding: const EdgeInsets.all(Gap.lg),
          child: ErrorNote(error: error),
        ),
        data: (data) => _Body(
          order: data,
          picked: _picked,
          onPick: (id, qty) => setState(() => _picked[id] = qty),
        ),
      ),
      bottomNavigationBar: order.maybeWhen(
        data: (data) => _Actions(
          order: data,
          picked: _picked,
          onAmended: () => setState(_picked.clear),
        ),
        orElse: () => null,
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({
    required this.order,
    required this.picked,
    required this.onPick,
  });

  final ShopOrder order;
  final Map<int, int> picked;
  final void Function(int id, int qty) onPick;

  int get _refundLaari {
    var refund = 0;

    for (final item in order.items) {
      final qty = picked[item.id] ?? item.fulfilledQty;
      refund += (item.fulfilledQty - qty).clamp(0, item.qty) *
          item.unitPriceLaari;
    }

    return refund;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // Only an accepted, unfinished order can be edited — you cannot re-pick
    // something already handed over.
    final editable = const {'accepted', 'preparing'}.contains(order.state);

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.lg, Gap.lg, Gap.huge),
      children: [
        Row(
          children: [
            Text(
              '#${order.reference}',
              style: theme.textTheme.titleLarge
                  ?.copyWith(color: theme.colorScheme.primary),
            ),
            const SizedBox(width: Gap.sm),
            StatusChip(
              label: shopStateLabel(order.state),
              tone: shopStateTone(order.state),
            ),
            const Spacer(),
            Text(
              relativeTime(order.placedAt),
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: ManfaaColors.textMuted),
            ),
          ],
        ),
        const SizedBox(height: Gap.md),
        const DesktopHint(
          title: 'Quick actions only — use desktop for advanced order '
              'management.',
          body: 'Pick lists, printing and reporting live in the portal.',
        ),
        const SizedBox(height: Gap.md),

        ManfaaCard(
          child: Wrap(
            runSpacing: Gap.lg,
            children: [
              _GridFact(
                icon: Icons.storefront_outlined,
                label: 'Store',
                value: order.branchName,
              ),
              _GridFact(
                icon: Icons.person_outline_rounded,
                label: 'Customer',
                value: order.customerName,
              ),
              _GridFact(
                icon: Icons.local_shipping_outlined,
                label: 'Fulfilment',
                value: order.isDelivery ? 'Delivery' : 'Pickup',
              ),
              _GridFact(
                icon: Icons.check_circle_outline,
                label: 'Payment',
                value: order.isPaid ? 'Paid / Verified' : 'Awaiting payment',
                tone: order.isPaid ? ManfaaColors.green : ManfaaColors.amber,
              ),
              _GridFact(
                icon: Icons.savings_outlined,
                label: 'Customer cashback',
                money: order.cashbackLaari,
              ),
              _GridFact(
                icon: Icons.account_balance_wallet_outlined,
                label: 'Total order value',
                money: order.subtotalLaari,
              ),
            ],
          ),
        ),
        const SizedBox(height: Gap.md),

        _CustomerCard(order: order),
        const SizedBox(height: Gap.md),

        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Items (${order.items.length})',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(height: Gap.sm),
              for (final item in order.items)
                _ItemRow(
                  item: item,
                  editable: editable,
                  qty: picked[item.id] ?? item.fulfilledQty,
                  onPick: (qty) => onPick(item.id, qty),
                ),
              const Divider(height: Gap.xl),
              _Total(label: 'Items subtotal', laari: order.itemsLaari),
              _Total(label: 'Delivery fee', laari: order.deliveryLaari),
              _Total(
                label: 'Customer cashback',
                laari: -order.cashbackLaari,
                tone: theme.colorScheme.primary,
              ),
              const Divider(height: Gap.xl),
              _Total(
                label: 'Total payable',
                laari: order.subtotalLaari,
                strong: true,
              ),
            ],
          ),
        ),

        if (editable && _refundLaari > 0) ...[
          const SizedBox(height: Gap.md),
          Container(
            padding: const EdgeInsets.all(Gap.md),
            decoration: BoxDecoration(
              color: ManfaaColors.amberSoft,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                const Icon(Icons.undo_rounded, color: ManfaaColors.amber),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // The customer's side of the decision, shown before
                      // the shop commits to it.
                      Text('Refunding', style: theme.textTheme.bodySmall),
                      MoneyText(
                        _refundLaari,
                        style: theme.textTheme.titleMedium,
                      ),
                      Text(
                        'Goes back to their Manfaa wallet, and the service '
                        'charge follows it down.',
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: ManfaaColors.textMuted),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],

        if (order.state == 'new') ...[
          const SizedBox(height: Gap.md),
          Text(
            '• Accepting confirms you can fulfil all items.\n'
            '• Reject requires a reason.',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
        ],
      ],
    );
  }
}

class _CustomerCard extends StatelessWidget {
  const _CustomerCard({required this.order});

  final ShopOrder order;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final note = order.address?['note'] as String?;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                backgroundColor: ManfaaColors.lavender,
                child: Text(
                  order.customerName.isEmpty
                      ? '?'
                      : order.customerName.characters.first.toUpperCase(),
                ),
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(order.customerName,
                        style: theme.textTheme.titleMedium),
                    if (order.customerPhone.isNotEmpty)
                      Text(order.customerPhone,
                          style: theme.textTheme.bodyMedium),
                    if (order.addressLine.isNotEmpty)
                      Text(
                        order.addressLine,
                        style: theme.textTheme.bodyMedium
                            ?.copyWith(color: ManfaaColors.textMuted),
                      ),
                    if (note != null && note.trim().isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                          'Delivery note  $note',
                          style: theme.textTheme.bodySmall,
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              if (order.customerPhone.isNotEmpty)
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => launchUrl(
                      Uri(scheme: 'tel', path: order.customerPhone),
                    ),
                    icon: const Icon(Icons.call_outlined, size: 18),
                    label: const Text('Call'),
                  ),
                ),
              if (order.isDelivery && order.addressLine.isNotEmpty) ...[
                const SizedBox(width: Gap.md),
                Expanded(
                  child: OutlinedButton.icon(
                    // OUR map, our own label — a raw coordinate pair is not
                    // a place (fix of 2026-08-18).
                    onPressed: () => launchUrl(
                      Uri.parse(
                        'https://manfaa.app/map?q='
                        '${Uri.encodeComponent(order.addressLine)}',
                      ),
                      mode: LaunchMode.externalApplication,
                    ),
                    icon: const Icon(Icons.place_outlined, size: 18),
                    label: const Text('Open map'),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({
    required this.item,
    required this.editable,
    required this.qty,
    required this.onPick,
  });

  final ShopOrderItem item;
  final bool editable;
  final int qty;
  final ValueChanged<int> onPick;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final reduced = qty < item.fulfilledQty;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.sm),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.name,
                  style: reduced
                      // Struck through, exactly as the customer will see it.
                      ? theme.textTheme.bodyLarge?.copyWith(
                          decoration: TextDecoration.lineThrough,
                          color: ManfaaColors.textMuted,
                        )
                      : theme.textTheme.bodyLarge,
                ),
                if (item.amended)
                  Text(
                    'Amended · refunded ${laariToString(item.refundLaari)}',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: ManfaaColors.amber),
                  ),
              ],
            ),
          ),
          if (editable)
            _Stepper(
              qty: qty,
              max: item.qty,
              onChanged: onPick,
            )
          else
            Text('× ${item.qty}', style: theme.textTheme.bodyMedium),
          const SizedBox(width: Gap.md),
          MoneyText(item.lineTotalLaari),
        ],
      ),
    );
  }
}

class _Stepper extends StatelessWidget {
  const _Stepper({
    required this.qty,
    required this.max,
    required this.onChanged,
  });

  final int qty;
  final int max;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          visualDensity: VisualDensity.compact,
          // Down to ZERO: a line the shelf cannot fill at all is a line the
          // shop must be able to drop.
          onPressed: qty > 0 ? () => onChanged(qty - 1) : null,
          icon: const Icon(Icons.remove_circle_outline, size: 20),
        ),
        Text('$qty'),
        IconButton(
          visualDensity: VisualDensity.compact,
          onPressed: qty < max ? () => onChanged(qty + 1) : null,
          icon: const Icon(Icons.add_circle_outline, size: 20),
        ),
      ],
    );
  }
}

class _GridFact extends StatelessWidget {
  const _GridFact({
    required this.icon,
    required this.label,
    this.value,
    this.money,
    this.tone,
  });

  final IconData icon;
  final String label;
  final String? value;
  final int? money;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SizedBox(
      width: (MediaQuery.sizeOf(context).width - Gap.lg * 4) / 3,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: tone ?? ManfaaColors.textMuted),
          const SizedBox(height: Gap.xs),
          Text(
            label,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
          if (money != null)
            MoneyText(money!, style: theme.textTheme.titleSmall)
          else
            Text(
              value ?? '',
              style: theme.textTheme.titleSmall?.copyWith(color: tone),
            ),
        ],
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({
    required this.label,
    required this.laari,
    this.strong = false,
    this.tone,
  });

  final String label;
  final int laari;
  final bool strong;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = strong ? theme.textTheme.titleMedium : theme.textTheme.bodyMedium;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: style?.copyWith(color: tone))),
          MoneyText(laari, style: style?.copyWith(color: tone)),
        ],
      ),
    );
  }
}

/// The sticky foot: what this order can have done to it right now.
class _Actions extends ConsumerWidget {
  const _Actions({
    required this.order,
    required this.picked,
    required this.onAmended,
  });

  final ShopOrder order;
  final Map<int, int> picked;
  final VoidCallback onAmended;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final changed = picked.entries.any((entry) {
      final item = order.items.where((row) => row.id == entry.key).firstOrNull;

      return item != null && entry.value != item.fulfilledQty;
    });

    final buttons = <Widget>[];

    if (order.state == 'new') {
      buttons.addAll([
        Expanded(
          child: OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              foregroundColor: ManfaaColors.coralDeep,
              side: const BorderSide(color: ManfaaColors.coral),
            ),
            onPressed: () => rejectShopOrder(context, ref, order.id),
            icon: const Icon(Icons.cancel_outlined, size: 18),
            label: const Text('Reject'),
          ),
        ),
        const SizedBox(width: Gap.md),
        Expanded(
          child: FilledButton.icon(
            onPressed: () => acceptShopOrder(context, ref, order.id),
            icon: const Icon(Icons.check_circle_outline, size: 18),
            label: const Text('Accept order'),
          ),
        ),
      ]);
    } else if (changed) {
      buttons.add(
        Expanded(
          child: FilledButton.icon(
            onPressed: () => _amend(context, ref),
            icon: const Icon(Icons.save_outlined, size: 18),
            label: const Text('Save changes'),
          ),
        ),
      );
    } else if (const {'accepted', 'preparing', 'ready', 'out_for_delivery'}
        .contains(order.state)) {
      buttons.add(
        Expanded(
          child: FilledButton.icon(
            onPressed: () => _advance(context, ref),
            icon: const Icon(Icons.arrow_forward_rounded, size: 18),
            label: Text(_advanceLabel(order)),
          ),
        ),
      );
    }

    if (buttons.isEmpty) return const SizedBox.shrink();

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(Gap.lg),
        child: Row(children: buttons),
      ),
    );
  }

  static String _advanceLabel(ShopOrder order) => switch (order.state) {
        'accepted' => 'Start preparing',
        'preparing' => 'Mark ready',
        'ready' => order.isDelivery ? 'Send out' : 'Mark collected',
        'out_for_delivery' => 'Mark delivered',
        _ => 'Next',
      };

  Future<void> _advance(BuildContext context, WidgetRef ref) async {
    try {
      await ref.read(apiProvider).advanceShopOrder(order.id);
      ref.invalidate(shopOrderProvider(order.id));
      ref.invalidate(shopOrdersProvider);
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(messageFor(error))));
      }
    }
  }

  Future<void> _amend(BuildContext context, WidgetRef ref) async {
    // Taking everything to zero is not an amendment — it is a rejection, and
    // it should be recorded as one.
    final empty = order.items.every((item) => (picked[item.id] ?? item.fulfilledQty) == 0);

    if (empty) {
      await rejectShopOrder(context, ref, order.id);

      return;
    }

    final controller = TextEditingController();

    final reason = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Save these changes?'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'The customer is refunded the difference to their Manfaa '
              'wallet and told what changed.',
            ),
            const SizedBox(height: Gap.md),
            TextField(
              controller: controller,
              autofocus: true,
              maxLength: 200,
              decoration: const InputDecoration(
                labelText: 'Reason (e.g. out of stock)',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              final text = controller.text.trim();
              if (text.isNotEmpty) Navigator.of(context).pop(text);
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (reason == null || !context.mounted) return;

    try {
      await ref.read(apiProvider).amendShopOrder(
            order.id,
            quantities: {
              for (final item in order.items)
                item.id: picked[item.id] ?? item.fulfilledQty,
            },
            reason: reason,
          );
      onAmended();
      ref.invalidate(shopOrderProvider(order.id));
      ref.invalidate(shopOrdersProvider);
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(messageFor(error))));
      }
    }
  }
}
