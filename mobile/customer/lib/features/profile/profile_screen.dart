import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../home/home_screen.dart' show initialsFor;
import '../push/push_registrar.dart';

/// The push preference, exposed for the toggle. Read once from the session.
final pushEnabledProvider = StateProvider<bool>(
  (ref) => ref.watch(sessionProvider).pushEnabled,
);

/// Profile — the brand mockup: header, an identity card, then grouped setting
/// cards (payout account, appearance, language, notifications, devices) and a
/// red sign-out. Every control here is functional; nothing is a dead row.
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

    await ref.read(apiProvider).signOut();
    if (context.mounted) context.go('/signin');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final session = ref.watch(sessionProvider);
    final locale = ref.watch(localeProvider);
    // Dhivehi readers see their Thaana name; the fallback keeps a name on
    // screen for anyone whose was never written.
    final name = displayName(
      english: session.customerName ?? '',
      dhivehi: session.customerNameDv,
      preferDhivehi: locale.languageCode == 'dv',
    );

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            Gap.lg,
            Gap.md,
            Gap.lg,
            Gap.navClearance,
          ),
          children: [
            ManfaaTopBar(
              initials: initialsFor(name),
              avatarUrl: ref.watch(avatarUrlProvider),
              showDot: false,
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.tabProfile, style: theme.textTheme.headlineSmall),
            const SizedBox(height: Gap.lg),

            // Identity — the avatar is the tap target for changing the
            // profile picture; the pencil badge is the affordance.
            ManfaaCard(
              child: Row(
                children: [
                  const _EditableAvatar(),
                  const SizedBox(width: Gap.lg),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(name, style: theme.textTheme.titleLarge),
                        const SizedBox(height: 2),
                        Text(
                          session.customerCode ?? '',
                          textDirection: TextDirection.ltr,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: muted,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: Gap.lg),

            // The Thaana name a model wrote at registration, and the place to
            // correct it — a transliterated name is a guess until its owner
            // says otherwise.
            _MenuCard(
              children: [
                _MenuRow(
                  tile: const IconTile(
                    Icons.translate_rounded,
                    tint: ManfaaTint.violet,
                  ),
                  title: l10n.dhivehiNameTitle,
                  subtitle: session.customerNameDv ?? l10n.dhivehiNameEmpty,
                  onTap: () => context.push('/profile/dhivehi-name'),
                ),
              ],
            ),
            const SizedBox(height: Gap.lg),

            // Payout account
            _MenuCard(
              children: [
                _MenuRow(
                  tile: const IconTile(
                    Icons.account_balance_rounded,
                    tint: ManfaaTint.green,
                  ),
                  title: l10n.payoutAccountTitle,
                  subtitle: l10n.payoutAccountTileBody,
                  onTap: () => context.push('/profile/payout-account'),
                ),
                _MenuRow(
                  tile: const IconTile(
                    Icons.devices_rounded,
                    tint: ManfaaTint.blue,
                  ),
                  title: l10n.profileDevices,
                  subtitle: l10n.profileDevicesBody,
                  onTap: () => context.push('/profile/devices'),
                ),
              ],
            ),
            const SizedBox(height: Gap.lg),

            // Appearance
            ManfaaCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _SettingLabel(
                    icon: Icons.brightness_6_rounded,
                    tint: ManfaaTint.violet,
                    label: l10n.profileTheme,
                  ),
                  const SizedBox(height: Gap.md),
                  SegmentedButton<ThemeMode>(
                    segments: [
                      ButtonSegment(
                        value: ThemeMode.light,
                        icon: const Icon(Icons.light_mode_rounded),
                        label: Text(l10n.themeLight),
                      ),
                      ButtonSegment(
                        value: ThemeMode.dark,
                        icon: const Icon(Icons.dark_mode_rounded),
                        label: Text(l10n.themeDark),
                      ),
                      ButtonSegment(
                        value: ThemeMode.system,
                        icon: const Icon(Icons.brightness_auto_rounded),
                        label: Text(l10n.themeSystem),
                      ),
                    ],
                    selected: {ref.watch(themeModeProvider)},
                    showSelectedIcon: false,
                    onSelectionChanged: (s) =>
                        ref.read(themeModeProvider.notifier).set(s.first),
                  ),
                ],
              ),
            ),
            const SizedBox(height: Gap.lg),

            // Language
            ManfaaCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _SettingLabel(
                    icon: Icons.language_rounded,
                    tint: ManfaaTint.amber,
                    label: l10n.profileLanguage,
                  ),
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
                    onSelectionChanged: (s) =>
                        ref.read(localeProvider.notifier).set(s.first),
                  ),
                ],
              ),
            ),
            const SizedBox(height: Gap.lg),

            // Notifications
            ManfaaCard(
              padding: const EdgeInsets.fromLTRB(
                Gap.lg,
                Gap.sm,
                Gap.md,
                Gap.sm,
              ),
              child: Row(
                children: [
                  const IconTile(
                    Icons.notifications_rounded,
                    tint: ManfaaTint.coral,
                    size: 40,
                    iconSize: 20,
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          l10n.notificationsTitle,
                          style: theme.textTheme.titleMedium,
                        ),
                        Text(
                          l10n.notificationsBody,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: muted,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Switch(
                    value: ref.watch(pushEnabledProvider),
                    onChanged: (on) async {
                      ref.read(pushEnabledProvider.notifier).state = on;
                      await ref.read(pushRegistrarProvider).setEnabled(on);
                    },
                  ),
                ],
              ),
            ),
            const SizedBox(height: Gap.xl),

            // Sign out
            ManfaaCard(
              onTap: () => _signOut(context, ref),
              padding: const EdgeInsets.symmetric(
                horizontal: Gap.lg,
                vertical: Gap.lg,
              ),
              child: Row(
                children: [
                  const IconTile(
                    Icons.logout_rounded,
                    tint: ManfaaTint.coral,
                    size: 40,
                    iconSize: 20,
                  ),
                  const SizedBox(width: Gap.md),
                  Text(
                    l10n.signOut,
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: ManfaaColors.coralDeep,
                    ),
                  ),
                  const Spacer(),
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

enum _PhotoAction { choose, remove }

/// The identity card's avatar: tappable, with a small pencil badge as the
/// edit affordance. Tap → bottom sheet: choose a photo (the OS photo
/// picker), or remove the current one. Upload shows a spinner over the
/// circle; success refreshes the session cache and /home so every avatar in
/// the app repaints; failure lands in a snackbar with the app's usual
/// localized error rule.
class _EditableAvatar extends ConsumerStatefulWidget {
  const _EditableAvatar();

  @override
  ConsumerState<_EditableAvatar> createState() => _EditableAvatarState();
}

class _EditableAvatarState extends ConsumerState<_EditableAvatar> {
  static const _size = 56.0;

  var _busy = false;

  Future<void> _edit() async {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final hasPhoto = ref.read(avatarUrlProvider) != null;

    final action = await showModalBottomSheet<_PhotoAction>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(
                Gap.xl,
                Gap.xl,
                Gap.xl,
                Gap.sm,
              ),
              child: Text(
                l10n.profilePhotoTitle,
                style: theme.textTheme.titleMedium,
              ),
            ),
            ListTile(
              leading: const IconTile(
                Icons.photo_library_rounded,
                tint: ManfaaTint.violet,
                size: 40,
                iconSize: 20,
              ),
              title: Text(l10n.choosePhoto),
              onTap: () => Navigator.pop(context, _PhotoAction.choose),
            ),
            if (hasPhoto)
              ListTile(
                leading: const IconTile(
                  Icons.delete_outline_rounded,
                  tint: ManfaaTint.coral,
                  size: 40,
                  iconSize: 20,
                ),
                title: Text(
                  l10n.removePhoto,
                  style: TextStyle(color: ManfaaColors.coralDeep),
                ),
                onTap: () => Navigator.pop(context, _PhotoAction.remove),
              ),
            const SizedBox(height: Gap.md),
          ],
        ),
      ),
    );

    if (!mounted || action == null) return;

    switch (action) {
      case _PhotoAction.choose:
        await _choose();
      case _PhotoAction.remove:
        await _run(
          () => ref.read(apiProvider).removeAvatar(),
          context.l10n.photoRemoved,
        );
    }
  }

  Future<void> _choose() async {
    // The system photo picker — no runtime permission needed on modern
    // Android/iOS. Downscaled client-side: a 12MP portrait is wasted bytes
    // for a 40px circle, and the server caps uploads at 4MB.
    final picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      maxWidth: 1024,
      maxHeight: 1024,
      imageQuality: 85,
    );

    if (picked == null || !mounted) return;

    final bytes = await picked.readAsBytes();
    if (!mounted) return;

    await _run(
      () => ref
          .read(apiProvider)
          .uploadAvatar(bytes: bytes, filename: picked.name),
      context.l10n.photoUpdated,
    );
  }

  Future<void> _run(Future<void> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      await action();
      // The session cache (and with it avatarUrlProvider) is already
      // updated by the api call; /home carries the avatar too, so refetch
      // it rather than let a cached copy disagree.
      ref.invalidate(homeProvider);
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(done)));
      }
    } catch (e) {
      if (mounted) {
        // The contract's error rule: the server's prose is safe to show;
        // anything else gets the generic sentence.
        final message = e is MobileApiException
            ? e.message
            : context.l10n.errorGeneric;
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final name = ref.watch(sessionProvider).customerName ?? '';
    final avatarUrl = ref.watch(avatarUrlProvider);

    return Semantics(
      button: true,
      label: context.l10n.profilePhotoEdit,
      child: GestureDetector(
        onTap: _busy ? null : _edit,
        behavior: HitTestBehavior.opaque,
        child: SizedBox(
          width: _size + 4,
          height: _size + 4,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              ManfaaAvatar(initialsFor(name), size: _size, imageUrl: avatarUrl),
              if (_busy)
                Container(
                  width: _size,
                  height: _size,
                  alignment: Alignment.center,
                  decoration: const BoxDecoration(
                    color: Color(0x66000000),
                    shape: BoxShape.circle,
                  ),
                  child: const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.5,
                      color: Colors.white,
                    ),
                  ),
                )
              else
                Positioned(
                  right: -2,
                  bottom: -2,
                  child: Container(
                    width: 22,
                    height: 22,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: theme.colorScheme.primary,
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: theme.colorScheme.surface,
                        width: 2,
                      ),
                    ),
                    child: Icon(
                      Icons.edit_rounded,
                      size: 11,
                      color: theme.colorScheme.onPrimary,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MenuCard extends StatelessWidget {
  const _MenuCard({required this.children});
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final divided = <Widget>[];
    for (var i = 0; i < children.length; i++) {
      if (i > 0) {
        divided.add(
          Divider(
            height: 1,
            color: Theme.of(context).colorScheme.outlineVariant,
          ),
        );
      }
      divided.add(children[i]);
    }
    return ManfaaCard(
      padding: EdgeInsets.zero,
      child: Column(children: divided),
    );
  }
}

class _MenuRow extends StatelessWidget {
  const _MenuRow({
    required this.tile,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final Widget tile;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(
          horizontal: Gap.lg,
          vertical: Gap.lg,
        ),
        child: Row(
          children: [
            tile,
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: theme.textTheme.titleMedium),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ],
              ),
            ),
            Icon(Icons.chevron_right_rounded, color: muted),
          ],
        ),
      ),
    );
  }
}

class _SettingLabel extends StatelessWidget {
  const _SettingLabel({
    required this.icon,
    required this.tint,
    required this.label,
  });
  final IconData icon;
  final ManfaaTint tint;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        IconTile(icon, tint: tint, size: 40, iconSize: 20),
        const SizedBox(width: Gap.md),
        Text(label, style: Theme.of(context).textTheme.titleMedium),
      ],
    );
  }
}
