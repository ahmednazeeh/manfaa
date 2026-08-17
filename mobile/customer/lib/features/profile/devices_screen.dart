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
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final devices = ref.watch(devicesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.profileDevices)),
      body: devices.when(
        loading: () => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
          children: const [
            SkeletonBox(height: 86, radius: Corner.card),
            SizedBox(height: Gap.md),
            SkeletonBox(height: 86, radius: Corner.card),
          ],
        ),
        error: (e, _) => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
          children: [
            ManfaaCard(
              child: Column(
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
          ],
        ),
        data: (devices) => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.huge),
          children: [
            Text(
              l10n.devicesIntro,
              style: theme.textTheme.bodyMedium?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.lg),
            for (final device in devices)
              Padding(
                padding: const EdgeInsets.only(bottom: Gap.md),
                child: _DeviceCard(
                  device: device,
                  onTap: () => _revoke(context, ref, device),
                ),
              ),
            const SizedBox(height: Gap.md),

            // The nuclear option, styled like Profile's sign-out card: coral
            // says "careful", and the whole card is the affordance.
            ManfaaCard(
              onTap: () => _revokeAll(context, ref),
              padding: const EdgeInsets.symmetric(
                  horizontal: Gap.lg, vertical: Gap.lg),
              child: Row(
                children: [
                  const IconTile(Icons.logout_rounded,
                      tint: ManfaaTint.coral, size: 40, iconSize: 20),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Text(
                      l10n.deviceRevokeAllAction,
                      style: theme.textTheme.titleMedium
                          ?.copyWith(color: ManfaaColors.coralDeep),
                    ),
                  ),
                  Icon(Icons.chevron_right_rounded, color: muted),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// One signed-in device. The WHOLE row is the revoke affordance — tap it to
/// open the confirm dialog. The device you're holding leads with a green
/// tile and wears the "This phone" chip; every other one is blue.
class _DeviceCard extends StatelessWidget {
  const _DeviceCard({required this.device, required this.onTap});

  final DeviceEntry device;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.lg),
      child: Row(
        children: [
          IconTile(
            Icons.smartphone_rounded,
            tint: device.isCurrentDevice ? ManfaaTint.green : ManfaaTint.blue,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        device.deviceName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleMedium,
                      ),
                    ),
                    if (device.isCurrentDevice) ...[
                      const SizedBox(width: Gap.sm),
                      StatusChip(
                        label: l10n.deviceThisOne,
                        tone: StatusTone.confirmed,
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  device.lastUsedAt.isEmpty
                      ? l10n.deviceSignedIn(formatDayMonth(device.signedInAt))
                      : l10n.deviceLastUsed(formatDayMonth(device.lastUsedAt)),
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.sm),
          Tooltip(
            message: l10n.deviceRevokeAction,
            child: Icon(Icons.logout_rounded, color: muted),
          ),
        ],
      ),
    );
  }
}
