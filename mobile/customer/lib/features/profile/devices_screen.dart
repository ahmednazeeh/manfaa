import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../activity/activity_screen.dart' show formatDayMonth;

/// The lost-phone screen (R5). Every signed-in device, the one you're
/// holding marked; cut one off, or everything at once. The same list is
/// reachable from the WEBSITE with only a session — this screen is for the
/// ordinary case, that one is for the day the phone is gone.
final devicesProvider = FutureProvider.autoDispose<List<DeviceEntry>>(
  (ref) => ref.watch(apiProvider).devices(),
);

class DevicesScreen extends ConsumerWidget {
  const DevicesScreen({super.key});

  Future<void> _revoke(
    BuildContext context,
    WidgetRef ref,
    DeviceEntry device,
  ) async {
    final l10n = context.l10n;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.deviceRevokeTitle(device.deviceName)),
        content: Text(
          device.isCurrentDevice
              ? l10n.deviceRevokeCurrentBody
              : l10n.deviceRevokeBody,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.deviceRevokeAction),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    await ref.read(apiProvider).revokeDevice(device.id);

    if (!context.mounted) return;

    if (device.isCurrentDevice) {
      // We just cut off the token this request rode on — the next API call
      // would 401 anyway; walk out cleanly instead.
      await ref.read(sessionProvider).wipe();
      if (context.mounted) context.go('/signin');
      return;
    }

    ref.invalidate(devicesProvider);
  }

  Future<void> _revokeAll(BuildContext context, WidgetRef ref) async {
    final l10n = context.l10n;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.deviceRevokeAllTitle),
        content: Text(l10n.deviceRevokeAllBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.deviceRevokeAllAction),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    // Includes THIS device: sign out everywhere means everywhere.
    await ref.read(apiProvider).revokeAllDevices();
    await ref.read(sessionProvider).wipe();
    if (context.mounted) context.go('/signin');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final devices = ref.watch(devicesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.profileDevices)),
      body: devices.when(
        loading: () => ListView(
          padding: const EdgeInsets.all(Gap.lg),
          children: const [
            SkeletonBox(height: 72, radius: Corner.card),
            SizedBox(height: Gap.sm),
            SkeletonBox(height: 72, radius: Corner.card),
          ],
        ),
        error: (e, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(Gap.huge),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  e is MobileApiException ? e.message : l10n.errorGeneric,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: Gap.lg),
                OutlinedButton(
                  onPressed: () => ref.invalidate(devicesProvider),
                  child: Text(l10n.retry),
                ),
              ],
            ),
          ),
        ),
        data: (devices) => ListView(
          padding: const EdgeInsets.all(Gap.lg),
          children: [
            Text(
              l10n.devicesIntro,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
            ),
            const SizedBox(height: Gap.lg),
            for (final device in devices)
              Padding(
                padding: const EdgeInsets.only(bottom: Gap.sm),
                child: Card(
                  child: ListTile(
                    onTap: () => _revoke(context, ref, device),
                    leading: Icon(
                      Icons.smartphone_rounded,
                      color: device.isCurrentDevice
                          ? ManfaaColors.confirmedGreen
                          : null,
                    ),
                    title: Row(
                      children: [
                        Flexible(child: Text(device.deviceName)),
                        if (device.isCurrentDevice) ...[
                          const SizedBox(width: Gap.sm),
                          StatusChip(
                            label: l10n.deviceThisOne,
                            tone: StatusTone.confirmed,
                          ),
                        ],
                      ],
                    ),
                    subtitle: Text(
                      device.lastUsedAt.isEmpty
                          ? l10n.deviceSignedIn(formatDayMonth(device.signedInAt))
                          : l10n.deviceLastUsed(formatDayMonth(device.lastUsedAt)),
                    ),
                    trailing: const Icon(Icons.logout_rounded),
                  ),
                ),
              ),
            const SizedBox(height: Gap.lg),
            OutlinedButton.icon(
              onPressed: () => _revokeAll(context, ref),
              icon: const Icon(Icons.logout_rounded),
              label: Text(l10n.deviceRevokeAllAction),
              style: OutlinedButton.styleFrom(
                foregroundColor: ManfaaColors.rose700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
