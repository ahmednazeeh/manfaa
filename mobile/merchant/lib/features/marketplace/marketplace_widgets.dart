import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'marketplace_providers.dart';

/// Pill tabs, as every ref in this folder draws them: one filled segment on
/// a hairline-bordered white track.
class SegmentedTabs extends StatelessWidget {
  const SegmentedTabs({
    super.key,
    required this.tabs,
    required this.value,
    required this.onChanged,
  });

  final List<(String, String)> tabs;
  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: ManfaaColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: ManfaaColors.line),
      ),
      child: Row(
        children: [
          for (final (key, label) in tabs)
            Expanded(
              child: GestureDetector(
                onTap: () => onChanged(key),
                behavior: HitTestBehavior.opaque,
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 140),
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  decoration: BoxDecoration(
                    color: key == value
                        ? theme.colorScheme.primary
                        : Colors.transparent,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    label,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.labelLarge?.copyWith(
                      color: key == value
                          ? theme.colorScheme.onPrimary
                          : ManfaaColors.inkSoft,
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

enum StatTone { calm, attention }

/// One of the two tiles above a queue — a count with a word for what it
/// means, because "5" alone is not information.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    required this.caption,
    required this.tone,
  });

  final IconData icon;
  final String label;
  final String value;
  final String caption;
  final StatTone tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final attention = tone == StatTone.attention;
    final accent = attention ? ManfaaColors.amber : theme.colorScheme.primary;

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: attention ? ManfaaColors.amberSoft : ManfaaColors.greenSoft,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: Colors.white.withValues(alpha: 0.7),
            child: Icon(icon, size: 20, color: accent),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: theme.textTheme.bodySmall),
                Text(
                  value,
                  style: theme.textTheme.headlineSmall
                      ?.copyWith(color: accent, fontWeight: FontWeight.w700),
                ),
                Text(
                  caption,
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

/// The honest banner both refs carry: this is a phone, some work belongs on
/// a desktop, and saying so beats a shopkeeper hunting for a screen that is
/// not here.
class DesktopHint extends StatelessWidget {
  const DesktopHint({super.key, required this.title, required this.body});

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: ManfaaColors.blueSoft,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.info_outline_rounded,
              size: 20, color: ManfaaColors.blue),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.bodyMedium),
                const SizedBox(height: 2),
                Text(
                  body,
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

class EmptyNote extends StatelessWidget {
  const EmptyNote({
    super.key,
    required this.icon,
    required this.title,
    required this.body,
  });

  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ManfaaCard(
      child: Column(
        children: [
          Icon(icon, size: 32, color: ManfaaColors.textFaint),
          const SizedBox(height: Gap.sm),
          Text(title, style: theme.textTheme.titleMedium),
          const SizedBox(height: 2),
          Text(
            body,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
        ],
      ),
    );
  }
}

/// An error a shopkeeper — or whoever they ring — can actually act on.
///
/// This used to render one sentence: "Something went wrong. Try again."
/// That is worse than useless, because it hides the one fact that would
/// resolve the call. A refused permission, an unreachable server and a
/// switched-off marketplace all looked identical, and the only way to tell
/// them apart was to guess.
class ErrorNote extends StatelessWidget {
  const ErrorNote({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final api = error is MobileApiException ? error as MobileApiException : null;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.error_outline_rounded,
                  color: ManfaaColors.coral),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Text(
                  _headline(api),
                  style: theme.textTheme.titleSmall,
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          Text(
            messageFor(error),
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
          const SizedBox(height: Gap.sm),
          // The detail line. Ugly on purpose — it is meant to be read out
          // over a phone call, not admired.
          SelectableText(
            _detail(api, error),
            style: theme.textTheme.bodySmall
                ?.copyWith(color: ManfaaColors.textFaint),
          ),
          if (onRetry != null) ...[
            const SizedBox(height: Gap.md),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('Try again'),
            ),
          ],
        ],
      ),
    );
  }

  static String _headline(MobileApiException? api) => switch (api?.status) {
        401 => 'Please sign in again',
        403 => 'Your account may not do this',
        404 => 'Not available on this platform',
        422 => 'That was refused',
        null => 'Could not reach Manfaa',
        _ => 'Something failed',
      };

  static String _detail(MobileApiException? api, Object error) => api == null
      ? error.runtimeType.toString()
      : 'HTTP ${api.status ?? '—'} · ${api.code}';
}

/// The queue card from `Orders.png`.
class ShopOrderCard extends ConsumerWidget {
  const ShopOrderCard({super.key, required this.order});

  final ShopOrder order;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '#${order.reference}',
                  style: theme.textTheme.titleMedium
                      ?.copyWith(color: theme.colorScheme.primary),
                ),
              ),
              StatusChip(
                label: shopStateLabel(order.state),
                tone: shopStateTone(order.state),
              ),
              const SizedBox(width: Gap.sm),
              Text(
                relativeTime(order.placedAt),
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.textMuted),
              ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          Text(order.customerName, style: theme.textTheme.titleMedium),
          Text(
            order.branchName,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: ManfaaColors.textMuted),
          ),
          if (order.addressLine.isNotEmpty)
            Row(
              children: [
                const Icon(Icons.place_outlined,
                    size: 14, color: ManfaaColors.textFaint),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    order.addressLine,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: ManfaaColors.textMuted),
                  ),
                ),
              ],
            ),
          const Divider(height: Gap.xl),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _Fact(
                      icon: Icons.shopping_bag_outlined,
                      text: '${order.itemCount} '
                          '${order.itemCount == 1 ? 'item' : 'items'}',
                    ),
                    _Fact(
                      icon: Icons.attach_money_rounded,
                      child: MoneyText(order.subtotalLaari),
                    ),
                    _Fact(
                      icon: order.isDelivery
                          ? Icons.local_shipping_outlined
                          : Icons.storefront_outlined,
                      text: order.isDelivery ? 'Delivery' : 'Pickup',
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  // The first thing a shop must know. Picking an unpaid
                  // order is work we have cost them.
                  StatusChip(
                    label: order.isPaid ? 'Transfer verified' : 'Awaiting payment',
                    tone: order.isPaid ? StatusTone.confirmed : StatusTone.pending,
                  ),
                  const SizedBox(height: Gap.sm),
                  Text(
                    'Cashback',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: ManfaaColors.textMuted),
                  ),
                  MoneyText(
                    order.cashbackLaari,
                    style: theme.textTheme.titleMedium
                        ?.copyWith(color: theme.colorScheme.primary),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () =>
                      context.push('/orders/${order.id}'),
                  icon: const Icon(Icons.visibility_outlined, size: 18),
                  label: const Text('View order'),
                ),
              ),
              if (order.state == 'new') ...[
                const SizedBox(width: Gap.md),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () => acceptShopOrder(context, ref, order.id),
                    icon: const Icon(Icons.check_circle_outline, size: 18),
                    label: const Text('Accept'),
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

class _Fact extends StatelessWidget {
  const _Fact({required this.icon, this.text, this.child});

  final IconData icon;
  final String? text;
  final Widget? child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Icon(icon, size: 16, color: ManfaaColors.textMuted),
          const SizedBox(width: Gap.sm),
          if (child != null) child! else Text(text ?? ''),
        ],
      ),
    );
  }
}

/// The server's own words when it has any, and a sentence naming the LIKELY
/// cause otherwise — never a bare "something went wrong", which tells the
/// person in front of it nothing they can do.
String messageFor(Object error) {
  if (error is MobileApiException) {
    if (error.message.isNotEmpty) return error.message;

    return switch (error.status) {
      401 => 'Your session has expired. Sign out and back in.',
      403 => 'This account does not have permission for the marketplace. '
          'An owner can grant it under Staff and roles.',
      404 => 'The marketplace is switched off for this platform.',
      null => 'Manfaa could not be reached. Check the connection.',
      _ => 'The server refused the request.',
    };
  }

  return 'The app could not read the answer from Manfaa.';
}

String shopStateLabel(String state) => switch (state) {
      'new' => 'New',
      'accepted' => 'Accepted',
      'preparing' => 'Preparing',
      'ready' => 'Ready',
      'out_for_delivery' => 'On the way',
      'delivered' => 'Delivered',
      'rejected' => 'Rejected',
      'cancelled' => 'Cancelled',
      _ => state,
    };

StatusTone shopStateTone(String state) => switch (state) {
      'new' => StatusTone.pending,
      'delivered' => StatusTone.confirmed,
      'rejected' || 'cancelled' => StatusTone.closed,
      _ => StatusTone.paid,
    };

/// "3 min ago" — a shop reads elapsed time, not a clock face.
String relativeTime(DateTime? at) {
  if (at == null) return '';

  final elapsed = DateTime.now().difference(at);

  if (elapsed.inMinutes < 1) return 'just now';
  if (elapsed.inMinutes < 60) return '${elapsed.inMinutes} min ago';
  if (elapsed.inHours < 24) return '${elapsed.inHours} h ago';

  return '${elapsed.inDays} d ago';
}

/// Accepting says the shop can fulfil EVERY line, so it asks first.
Future<void> acceptShopOrder(
  BuildContext context,
  WidgetRef ref,
  int suborderId,
) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Accept this order?'),
      content: const Text(
        'Accepting confirms you can fulfil every item. If something is off '
        'the shelf, you can reduce it while picking and the customer is '
        'refunded the difference.',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('Accept order'),
        ),
      ],
    ),
  );

  if (confirmed != true || !context.mounted) return;

  try {
    await ref.read(apiProvider).acceptShopOrder(suborderId);
    ref.invalidate(shopOrdersProvider);
    ref.invalidate(shopOrderProvider(suborderId));
  } catch (error) {
    if (context.mounted) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(messageFor(error))));
    }
  }
}

/// Rejecting REQUIRES a reason — the customer is told it, word for word.
Future<void> rejectShopOrder(
  BuildContext context,
  WidgetRef ref,
  int suborderId,
) async {
  final controller = TextEditingController();

  final reason = await showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Reject this order?'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'The customer is told why, in your words, and refunded in full.',
          ),
          const SizedBox(height: Gap.md),
          TextField(
            controller: controller,
            autofocus: true,
            maxLength: 200,
            decoration: const InputDecoration(labelText: 'Reason'),
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
          child: const Text('Reject order'),
        ),
      ],
    ),
  );

  if (reason == null || !context.mounted) return;

  try {
    await ref.read(apiProvider).rejectShopOrder(suborderId, reason);
    ref.invalidate(shopOrdersProvider);
    ref.invalidate(shopOrderProvider(suborderId));
  } catch (error) {
    if (context.mounted) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(messageFor(error))));
    }
  }
}
