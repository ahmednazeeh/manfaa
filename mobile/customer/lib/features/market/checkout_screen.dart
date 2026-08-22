import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'market_providers.dart';

/// Checkout (`Delivery Details Step.png`, `Payment Step.png`,
/// `Order Received.png`).
///
/// Address → payment → placed. Payment is RECEIPT-FIRST, the same shape
/// merchants already settle with: we publish the exact amount and our
/// account, the customer transfers and uploads their proof, and nothing is
/// confirmed until a human has seen it. No pretending money has arrived.
final addressesProvider =
    FutureProvider.autoDispose<List<CustomerAddressEntry>>((ref) {
      return ref.watch(apiProvider).addresses();
    });

final paymentAccountsProvider =
    FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) {
      return ref.watch(apiProvider).paymentAccounts();
    });

class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key});

  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  int _step = 0;
  int? _addressId;
  String _method = 'bml';
  bool _placing = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final cart = ref.watch(cartProvider).valueOrNull;

    if (cart == null || cart.isEmpty) {
      return Scaffold(
        appBar: AppBar(title: Text(l10n.checkoutTitle)),
        body: Center(child: Text(l10n.cartEmpty)),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(l10n.checkoutTitle)),
      body: SafeArea(
        child: Column(
          children: [
            _Steps(step: _step),
            Expanded(
              child: _step == 0
                  ? _AddressStep(
                      selected: _addressId,
                      onSelected: (id) => setState(() => _addressId = id),
                    )
                  : _PaymentStep(
                      cart: cart,
                      method: _method,
                      onMethod: (method) => setState(() => _method = method),
                    ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(Gap.lg),
          child: Row(
            children: [
              if (_step > 0)
                TextButton(
                  onPressed: () => setState(() => _step -= 1),
                  child: Text(l10n.back),
                ),
              const Spacer(),
              Expanded(
                child: FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor: ManfaaColors.violet,
                    padding: const EdgeInsets.symmetric(vertical: Gap.md),
                  ),
                  onPressed: _placing ? null : _next,
                  child: Text(_step == 0 ? l10n.next : l10n.placeOrder),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _next() async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    if (_step == 0) {
      setState(() => _step = 1);
      return;
    }

    setState(() => _placing = true);
    try {
      final order = await ref
          .read(apiProvider)
          .placeOrder(paymentMethod: _method, addressId: _addressId);

      // The basket is gone server-side; make the app agree at once so the
      // floating bar does not linger over an order already placed.
      ref.invalidate(cartProvider);

      if (mounted) context.pushReplacement('/market/orders/${order.id}');
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            e is MobileApiException ? e.message : l10n.errorGeneric,
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }
}

class _Steps extends StatelessWidget {
  const _Steps({required this.step});

  final int step;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final labels = [l10n.stepAddress, l10n.stepPayment];

    return Padding(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, 0),
      child: Row(
        children: [
          for (var index = 0; index < labels.length; index++) ...[
            Icon(
              index <= step
                  ? Icons.check_circle_rounded
                  : Icons.circle_outlined,
              size: 18,
              color: index <= step
                  ? ManfaaColors.violet
                  : theme.colorScheme.outlineVariant,
            ),
            const SizedBox(width: Gap.xs),
            Text(
              labels[index],
              style: theme.textTheme.bodySmall?.copyWith(
                color: index <= step ? ManfaaColors.violet : null,
                fontWeight: index == step ? FontWeight.w700 : null,
              ),
            ),
            if (index < labels.length - 1)
              Expanded(
                child: Divider(
                  indent: Gap.sm,
                  endIndent: Gap.sm,
                  color: theme.colorScheme.outlineVariant,
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _AddressStep extends ConsumerWidget {
  const _AddressStep({required this.selected, required this.onSelected});

  final int? selected;
  final void Function(int?) onSelected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final addresses = ref.watch(addressesProvider);

    return addresses.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (error, _) => Center(
        child: Text(
          error is MobileApiException ? error.message : l10n.errorGeneric,
        ),
      ),
      data: (rows) => ListView(
        padding: const EdgeInsets.all(Gap.lg),
        children: [
          Text(l10n.savedAddresses, style: theme.textTheme.titleSmall),
          const SizedBox(height: Gap.sm),
          if (rows.isEmpty)
            ManfaaCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l10n.noAddresses, style: theme.textTheme.titleSmall),
                  const SizedBox(height: Gap.xs),
                  Text(
                    l10n.noAddressesHint,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: Gap.md),
                  FilledButton(
                    onPressed: () => context.push('/market/addresses/new'),
                    child: Text(l10n.addAddress),
                  ),
                ],
              ),
            )
          else
            RadioGroup<int>(
              groupValue:
                  selected ??
                  rows
                      .firstWhere(
                        (row) => row.isDefault,
                        orElse: () => rows.first,
                      )
                      .id,
              onChanged: onSelected,
              child: Column(
                children: [
                  for (final address in rows)
                    Padding(
                      padding: const EdgeInsets.only(bottom: Gap.sm),
                      child: ManfaaCard(
                        padding: EdgeInsets.zero,
                        child: RadioListTile<int>(
                          value: address.id,
                          activeColor: ManfaaColors.violet,
                          title: Text(address.label),
                          subtitle: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${address.recipientName} · ${address.phone}',
                              ),
                              Text(address.oneLine),
                              // A null zone is honest: no shop can quote
                              // delivery there yet, and saying so beats a
                              // silent surprise at the door.
                              if (address.zoneId == null)
                                Text(
                                  l10n.addressNotServed,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: ManfaaColors.amber,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  TextButton.icon(
                    onPressed: () => context.push('/market/addresses/new'),
                    icon: const Icon(Icons.add_rounded),
                    label: Text(l10n.addAddress),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _PaymentStep extends ConsumerWidget {
  const _PaymentStep({
    required this.cart,
    required this.method,
    required this.onMethod,
  });

  final Cart cart;
  final String method;
  final void Function(String) onMethod;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final accounts = ref.watch(paymentAccountsProvider);

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(l10n.orderSummary, style: theme.textTheme.titleSmall),
              const SizedBox(height: Gap.md),
              for (final subcart in cart.subcarts)
                Padding(
                  padding: const EdgeInsets.only(bottom: Gap.sm),
                  child: Row(
                    children: [
                      Expanded(child: Text(subcart.title)),
                      MoneyText(
                        subcart.subtotalLaari,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ],
                  ),
                ),
              Divider(color: theme.colorScheme.outlineVariant),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      l10n.cartTotalPayable,
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                  MoneyText(
                    cart.totalPayableLaari,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Gap.xs),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      l10n.cashbackAfterValidation,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                  MoneyText(
                    cart.cashbackLaari,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: ManfaaColors.green,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: Gap.lg),
        Text(l10n.paymentMethod, style: theme.textTheme.titleSmall),
        const SizedBox(height: Gap.sm),
        accounts.when(
          loading: () => const SkeletonBox(height: 120, radius: Corner.card),
          error: (_, _) => Text(l10n.errorGeneric),
          data: (rows) => RadioGroup<String>(
            groupValue: method,
            onChanged: (value) => onMethod(value ?? 'bml'),
            child: Column(
              children: [
                for (final account in rows)
                  Padding(
                    padding: const EdgeInsets.only(bottom: Gap.sm),
                    child: ManfaaCard(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Radio<String>(
                                value:
                                    ((account['bank_name'] as String?) ?? '')
                                        .toLowerCase()
                                        .contains('mib')
                                    ? 'mib'
                                    : 'bml',
                                activeColor: ManfaaColors.violet,
                              ),
                              Text(
                                (account['bank_name'] as String?) ?? '',
                                style: theme.textTheme.titleSmall,
                              ),
                            ],
                          ),
                          const SizedBox(height: Gap.sm),
                          _Copyable(
                            label: l10n.transferExactly,
                            value: formatMoney(
                              cart.totalPayableLaari,
                              dhivehi:
                                  Localizations.localeOf(
                                    context,
                                  ).languageCode ==
                                  'dv',
                            ),
                          ),
                          _Copyable(
                            label: l10n.accountName,
                            value: (account['account_name'] as String?) ?? '',
                          ),
                          _Copyable(
                            label: l10n.accountNumber,
                            value: (account['account_no'] as String?) ?? '',
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: Gap.md),
        // Said plainly, because it is the whole shape of this payment.
        Container(
          padding: const EdgeInsets.all(Gap.md),
          decoration: BoxDecoration(
            color: ManfaaColors.violetSoft,
            borderRadius: BorderRadius.circular(Corner.tile),
          ),
          child: Text(
            l10n.receiptFirstNote,
            style: theme.textTheme.bodySmall?.copyWith(
              color: ManfaaColors.violet,
            ),
          ),
        ),
      ],
    );
  }
}

class _Copyable extends StatelessWidget {
  const _Copyable({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          Text(value, style: theme.textTheme.bodyMedium),
        ],
      ),
    );
  }
}
