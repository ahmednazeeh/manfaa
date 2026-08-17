import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../push/push_registrar.dart';

/// The push preference, exposed for the toggle. Read once from the session.
final pushEnabledProvider = StateProvider<bool>(
  (ref) => ref.watch(sessionProvider).pushEnabled,
);

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  Future<void> _signOut(BuildContext context, WidgetRef ref) async {
    final l10n = context.l10n;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.signOutConfirmTitle),
        content: Text(l10n.signOutConfirmBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.signOut),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    // Local wipe always succeeds; the router's redirect walks us out the
    // moment the session revision bumps.
    await ref.read(apiProvider).signOut();
    if (context.mounted) context.go('/signin');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final session = ref.watch(sessionProvider);
    final locale = ref.watch(localeProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tabProfile)),
      body: ListView(
        padding: const EdgeInsets.all(Gap.lg),
        children: [
          Card(
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: ManfaaColors.rose100,
                foregroundColor: ManfaaColors.rose700,
                child: Text(
                  session.customerName?.isNotEmpty == true
                      ? session.customerName![0].toUpperCase()
                      : '؟',
                ),
              ),
              title: Text(session.customerName ?? ''),
              subtitle: Text(
                session.customerCode ?? '',
                textDirection: TextDirection.ltr,
              ),
            ),
          ),
          const SizedBox(height: Gap.md),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l10n.profileLanguage, style: theme.textTheme.labelLarge),
                  const SizedBox(height: Gap.md),
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(
                        value: 'en',
                        label: Text(l10n.languageEnglish),
                      ),
                      ButtonSegment(
                        value: 'dv',
                        label: Text(l10n.languageDhivehi),
                      ),
                    ],
                    selected: {locale.languageCode},
                    onSelectionChanged: (selection) =>
                        ref.read(localeProvider.notifier).set(selection.first),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: Gap.md),
          Card(
            child: ListTile(
              onTap: () => context.push('/profile/payout-account'),
              leading: const Icon(Icons.account_balance_rounded),
              title: Text(l10n.payoutAccountTitle),
              subtitle: Text(l10n.payoutAccountTileBody),
              trailing: const Icon(Icons.chevron_right_rounded),
            ),
          ),
          const SizedBox(height: Gap.md),
          Card(
            child: SwitchListTile(
              value: ref.watch(pushEnabledProvider),
              onChanged: (on) async {
                ref.read(pushEnabledProvider.notifier).state = on;
                await ref.read(pushRegistrarProvider).setEnabled(on);
              },
              secondary: const Icon(Icons.notifications_rounded),
              title: Text(l10n.notificationsTitle),
              subtitle: Text(l10n.notificationsBody),
            ),
          ),
          const SizedBox(height: Gap.md),
          Card(
            child: ListTile(
              onTap: () => context.push('/profile/devices'),
              leading: const Icon(Icons.devices_rounded),
              title: Text(l10n.profileDevices),
              subtitle: Text(l10n.profileDevicesBody),
              trailing: const Icon(Icons.chevron_right_rounded),
            ),
          ),
          const SizedBox(height: Gap.xxl),
          OutlinedButton.icon(
            onPressed: () => _signOut(context, ref),
            icon: const Icon(Icons.logout_rounded),
            label: Text(l10n.signOut),
            style: OutlinedButton.styleFrom(
              foregroundColor: ManfaaColors.rose700,
            ),
          ),
        ],
      ),
    );
  }
}
