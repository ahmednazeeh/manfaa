import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';

/// One order, tracked (`Order Received.png`, `Customer App Order
/// Tracking.png`).
///
/// A multi-vendor order shows its SHOPS, not one summary word: across three
/// stores the shops are the status, and a single word would hide that two
/// are confirmed and one is not.
final orderProvider =
    FutureProvider.autoDispose.family<CustomerOrder, int>((ref, id) {
  return ref.watch(apiProvider).order(id);
});

class OrderScreen extends ConsumerStatefulWidget {
  const OrderScreen({super.key, required this.orderId});

  final int orderId;

  @override
  ConsumerState<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends ConsumerState<OrderScreen> {
  bool _uploading = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final order = ref.watch(orderProvider(widget.orderId));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.orderReceived)),
      body: SafeArea(
        child: order.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Center(
            child: Text(
              error is MobileApiException ? error.message : l10n.errorGeneric,
            ),
          ),
          data: (data) => _Body(
            order: data,
            uploading: _uploading,
            onUpload: () => _upload(data.id),
          ),
        ),
      ),
    );
  }

  Future<void> _upload(int orderId) async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    final picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
    );

    if (picked == null) return;

    setState(() => _uploading = true);
    try {
      await ref.read(apiProvider).uploadOrderReceipt(
            orderId,
            bytes: await picked.readAsBytes(),
            filename: picked.name,
          );
      ref.invalidate(orderProvider(orderId));
      messenger.showSnackBar(SnackBar(content: Text(l10n.receiptUploaded)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(
        content: Text(e is MobileApiException ? e.message : l10n.errorGeneric),
      ));
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }
}

class _Body extends StatelessWidget {
  const _Body({
    required this.order,
    required this.uploading,
    required this.onUpload,
  });

  final CustomerOrder order;
  final bool uploading;
  final VoidCallback onUpload;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(order.reference,
                        style: theme.textTheme.titleMedium),
                  ),
                  MoneyText(
                    order.totalPayableLaari,
                    style: theme.textTheme.titleMedium
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ],
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.orderStores(order.storeCount),
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
              const SizedBox(height: Gap.md),
              StatusChip(
                label: order.paymentState.replaceAll('_', ' '),
                tone: order.paymentState == 'verified'
                    ? StatusTone.confirmed
                    : StatusTone.pending,
              ),
              if (order.cashbackTotalLaari > 0) ...[
                const SizedBox(height: Gap.md),
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
                      order.cashbackTotalLaari,
                      style: theme.textTheme.titleSmall
                          ?.copyWith(color: ManfaaColors.green),
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
        // The receipt is what turns a placed order into a real one.
        if (order.needsReceipt) ...[
          const SizedBox(height: Gap.md),
          ManfaaCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.receiptFirstNote,
                    style: theme.textTheme.bodyMedium),
                const SizedBox(height: Gap.md),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: uploading ? null : onUpload,
                    icon: const Icon(Icons.upload_rounded),
                    label: Text(l10n.uploadReceipt),
                    style: FilledButton.styleFrom(
                      backgroundColor: ManfaaColors.violet,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ] else if (order.paymentState == 'proof_submitted') ...[
          const SizedBox(height: Gap.md),
          Container(
            padding: const EdgeInsets.all(Gap.md),
            decoration: BoxDecoration(
              color: ManfaaColors.violetSoft,
              borderRadius: BorderRadius.circular(Corner.tile),
            ),
            child: Text(l10n.orderUnderReview,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.violet)),
          ),
        ],
        const SizedBox(height: Gap.lg),
        for (final suborder in order.suborders) ...[
          _SuborderCard(suborder: suborder, dhivehi: dhivehi),
          const SizedBox(height: Gap.md),
        ],
      ],
    );
  }
}

class _SuborderCard extends StatelessWidget {
  const _SuborderCard({required this.suborder, required this.dhivehi});

  final CustomerSuborder suborder;
  final bool dhivehi;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(suborder.title, style: theme.textTheme.titleSmall),
              ),
              StatusChip(
                label: suborder.state.replaceAll('_', ' '),
                tone: switch (suborder.state) {
                  'delivered' => StatusTone.paid,
                  'rejected' || 'cancelled' => StatusTone.closed,
                  'new' => StatusTone.pending,
                  _ => StatusTone.confirmed,
                },
              ),
            ],
          ),
          if (suborder.pickupCode != null) ...[
            const SizedBox(height: Gap.sm),
            Row(
              children: [
                Text(l10n.pickupCode,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted)),
                const SizedBox(width: Gap.sm),
                Text(
                  suborder.pickupCode!,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    letterSpacing: 2,
                  ),
                ),
              ],
            ),
          ],
          if (suborder.rejectReason != null) ...[
            const SizedBox(height: Gap.sm),
            Text(
              suborder.rejectReason!,
              style: theme.textTheme.bodySmall?.copyWith(color: ManfaaColors.amber),
            ),
          ],
          // The shop cut this order. Named, with the refund.
          if (suborder.wasAmended) ...[
            const SizedBox(height: Gap.sm),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(Gap.md),
              decoration: BoxDecoration(
                color: ManfaaColors.amberSoft,
                borderRadius: BorderRadius.circular(Corner.tile),
              ),
              child: Text(
                l10n.orderRefunded(
                  suborder.storeName,
                  formatMoney(suborder.refundedLaari, dhivehi: dhivehi),
                ),
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.amber),
              ),
            ),
          ],
          const SizedBox(height: Gap.md),
          for (final line in suborder.items)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      line.name,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        // A removed line stays on screen, struck through: it
                        // was ordered, and a row that vanishes reads as a bug.
                        decoration:
                            line.removed ? TextDecoration.lineThrough : null,
                        color: line.removed ? muted : null,
                      ),
                    ),
                  ),
                  if (line.amended) ...[
                    Text(
                      '×${line.qty}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: muted,
                        decoration: TextDecoration.lineThrough,
                      ),
                    ),
                    const SizedBox(width: Gap.xs),
                  ],
                  Text('×${line.fulfilledQty}',
                      style: theme.textTheme.bodyMedium),
                  const SizedBox(width: Gap.md),
                  if (line.refundLaari > 0)
                    Text(
                      formatMoney(line.refundLaari, dhivehi: dhivehi),
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: ManfaaColors.amber),
                    )
                  else
                    MoneyText(line.lineTotalLaari,
                        style: theme.textTheme.bodyMedium),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
