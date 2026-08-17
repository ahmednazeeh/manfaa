import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';
import '../settlements/settlement_widgets.dart' show ToneBanner;
import 'estate_widgets.dart';
import 'more_providers.dart';

/// Roles (MR5) — Roles.png's LAYOUT restyled into the violet system: role
/// cards with member and permission counts, the owner role in the ref's
/// Full Access treatment and FROZEN (no edit, no delete — its authority is
/// the flag, not a list).
///
/// The editor's checkbox grid is grouped from the SERVED catalogue (D8 —
/// a permission a later deploy adds renders here, under its own heading,
/// with the server's wording), and the D5 delegation law is told in the UI
/// too: a slug the signed-in account does not hold is drawn disabled with
/// the hint, never as a box that ticks and then 403s. The server enforces
/// every rule again regardless.
class RolesScreen extends ConsumerWidget {
  const RolesScreen({super.key});

  static const _tints = [
    ManfaaTint.violet,
    ManfaaTint.blue,
    ManfaaTint.amber,
    ManfaaTint.coral,
  ];
  static const _icons = [
    Icons.badge_outlined,
    Icons.point_of_sale_outlined,
    Icons.inventory_2_outlined,
    Icons.visibility_outlined,
  ];

  Future<void> _openEditor(
    BuildContext context,
    WidgetRef ref, {
    MerchantRole? existing,
  }) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      useRootNavigator: true, // over the floating nav bar
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _RoleEditorSheet(existing: existing),
    );
    if (saved ?? false) {
      ref.invalidate(rolesProvider);
      // Staff rows carry the role name — keep them in step.
      ref.invalidate(staffProvider);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final canManage = session.can('roles.manage');
    final roles = ref.watch(rolesProvider);

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            Gap.xl,
            Gap.sm,
            Gap.xl,
            Gap.navClearance,
          ),
          children: [
            MerchantDetailTopBar(initials: initials),
            const SizedBox(height: Gap.md),
            Text(
              l10n.rolesTitle,
              style: theme.textTheme.headlineMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.rolesSubtitle,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.lg),
            if (canManage) ...[
              VioletCta(
                icon: Icons.add_circle_outline_rounded,
                label: l10n.addRoleCta,
                onPressed: () => _openEditor(context, ref),
              ),
              const SizedBox(height: Gap.md),
            ] else ...[
              Text(
                l10n.rolesReadOnlyHint,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: Gap.md),
            ],
            roles.when(
              loading: () =>
                  const SkeletonBox(height: 320, radius: Corner.card),
              error: (error, _) => SectionErrorCard(
                error: error,
                onRetry: () => ref.invalidate(rolesProvider),
              ),
              data: (roles) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final (i, role) in roles.indexed) ...[
                    if (i > 0) const SizedBox(height: Gap.md),
                    _RoleCard(
                      role: role,
                      dhivehi: dhivehi,
                      icon: role.isOwner
                          ? Icons.local_police_outlined
                          : _icons[i % _icons.length],
                      tint: role.isOwner
                          ? ManfaaTint.green
                          : _tints[i % _tints.length],
                      // The owner role is frozen: renameable server-side,
                      // but this screen offers no edit at all (task
                      // reconciliation) — its card is information.
                      onTap: canManage && !role.isOwner
                          ? () => _openEditor(context, ref, existing: role)
                          : null,
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RoleCard extends StatelessWidget {
  const _RoleCard({
    required this.role,
    required this.dhivehi,
    required this.icon,
    required this.tint,
    this.onTap,
  });

  final MerchantRole role;
  final bool dhivehi;
  final IconData icon;
  final ManfaaTint tint;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      onTap: onTap,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconTile(icon, tint: tint, size: 48, iconSize: 24),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  role.label(dhivehi: dhivehi),
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.employeeCountLabel(role.staffCount),
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
                const SizedBox(height: Gap.sm),
                if (role.isOwner)
                  StatusChip(
                    label: l10n.fullAccessBadge,
                    tone: StatusTone.confirmed,
                  )
                else
                  SoftPill(
                    label: l10n.permissionCountChip(role.permissions.length),
                  ),
                if (role.isOwner) ...[
                  const SizedBox(height: Gap.sm),
                  Text(
                    l10n.ownerFrozenHint,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ],
              ],
            ),
          ),
          if (onTap != null)
            Icon(Icons.chevron_right_rounded, color: muted),
        ],
      ),
    );
  }
}

/// Create/edit one role: name, optional Dhivehi name, and the grouped
/// checkbox grid. On an edit only the ADDITIONS are a grant, so a stored
/// slug stays tickable even by an actor who does not hold it (untick and
/// re-tick — removing a permission hands nobody anything); on a NEW role
/// every tick is a grant. Delete lives here too, with the in-use refusal
/// told before AND rendered after. Pops `true` after any landed write.
class _RoleEditorSheet extends ConsumerStatefulWidget {
  const _RoleEditorSheet({this.existing});

  final MerchantRole? existing;

  @override
  ConsumerState<_RoleEditorSheet> createState() => _RoleEditorSheetState();
}

class _RoleEditorSheetState extends ConsumerState<_RoleEditorSheet> {
  late final _name = TextEditingController(text: widget.existing?.name ?? '');
  late final _nameDv =
      TextEditingController(text: widget.existing?.nameDv ?? '');
  late final Set<String> _ticked = {...?widget.existing?.permissions};
  late final Set<String> _stored = {...?widget.existing?.permissions};
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _nameDv.dispose();
    super.dispose();
  }

  /// D5, the screen's half: a slug may be ticked when the actor holds it,
  /// or when the role already stores it (unticking and re-ticking a stored
  /// slug grants nothing).
  bool _mayTick(MerchantSession session, String slug) =>
      _stored.contains(slug) || session.can(slug);

  Future<void> _save() async {
    final l10n = context.l10n;
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = l10n.roleNameRequired);
      return;
    }
    final nameDv = _nameDv.text.trim();

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ref.read(apiProvider);
      final existing = widget.existing;
      if (existing == null) {
        await api.createRole(
          name: name,
          nameDv: nameDv.isEmpty ? null : nameDv,
          permissions: _ticked.toList(),
        );
      } else {
        await api.updateRole(
          existing.id,
          name: name,
          nameDvChanged: nameDv != (existing.nameDv ?? ''),
          nameDv: nameDv.isEmpty ? null : nameDv,
          permissions: _ticked.toList(),
        );
      }
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.roleSaved)));
      Navigator.of(context).pop(true);
    } on MobileApiException catch (e) {
      // permission_not_held, cannot_edit_own_role, role_cap_reached — the
      // server's sentence says which rule bit.
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.roleSaveFailed);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    final l10n = context.l10n;
    final existing = widget.existing;
    if (existing == null) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(l10n.roleDeleteConfirmTitle(existing.name)),
        content: Text(l10n.roleDeleteConfirmBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            style: FilledButton.styleFrom(
              backgroundColor: ManfaaColors.coralDeep,
              foregroundColor: Colors.white,
              minimumSize: const Size(0, 44),
              padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
            ),
            child: Text(l10n.deleteLabel),
          ),
        ],
      ),
    );
    if (!(confirmed ?? false) || !mounted) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).deleteRole(existing.id);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.roleDeleted)));
      Navigator.of(context).pop(true);
    } on MobileApiException catch (e) {
      // role_in_use answers with the sentence naming the count.
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.roleDeleteFailed);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final session = ref.watch(sessionProvider);
    final catalogue = ref.watch(permissionCatalogueProvider);
    final editing = widget.existing != null;
    final deletable = editing && widget.existing!.staffCount == 0;

    final anyLocked = catalogue.valueOrNull?.groups.any(
          (group) => group.permissions
              .any((permission) => !_mayTick(session, permission.slug)),
        ) ??
        false;

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.sm,
        bottom: MediaQuery.viewInsetsOf(context).bottom + Gap.xl,
      ),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.82,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                editing
                    ? l10n.roleEditorEditTitle
                    : l10n.roleEditorCreateTitle,
                style: theme.textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.lg),
              Text(l10n.roleNameLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _name,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.badge_outlined),
                ),
              ),
              const SizedBox(height: Gap.lg),
              Text(l10n.roleNameDvLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _nameDv,
                textDirection: TextDirection.rtl,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.translate_rounded),
                ),
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.roleNameDvHint,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              const SizedBox(height: Gap.xl),
              Text(
                l10n.rolePermissionsLabel,
                style: theme.textTheme.labelLarge,
              ),
              if (anyLocked) ...[
                const SizedBox(height: Gap.xs),
                Text(
                  l10n.delegationHint,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
              const SizedBox(height: Gap.sm),
              catalogue.when(
                loading: () =>
                    const SkeletonBox(height: 200, radius: Corner.tile),
                error: (error, _) => SectionErrorCard(
                  error: error,
                  onRetry: () =>
                      ref.invalidate(permissionCatalogueProvider),
                ),
                data: (catalogue) => Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final group in catalogue.groups) ...[
                      const SizedBox(height: Gap.sm),
                      Text(
                        // The server's own heading — the same word the
                        // panel's navigation uses.
                        group.label,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      for (final permission in group.permissions)
                        _PermissionTick(
                          label: permission.label,
                          ticked: _ticked.contains(permission.slug),
                          enabled: _mayTick(session, permission.slug),
                          onChanged: (value) => setState(() {
                            if (value) {
                              _ticked.add(permission.slug);
                            } else {
                              _ticked.remove(permission.slug);
                            }
                          }),
                        ),
                    ],
                  ],
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: Gap.md),
                ToneBanner(
                  tone: ToneSurface.attention,
                  icon: Icons.error_outline_rounded,
                  title: _error!,
                ),
              ],
              const SizedBox(height: Gap.lg),
              FilledButton(
                onPressed: _busy ? null : _save,
                child: _busy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(editing ? l10n.saveRoleCta : l10n.createRoleCta),
              ),
              if (editing) ...[
                const SizedBox(height: Gap.sm),
                TextButton.icon(
                  onPressed: _busy || !deletable ? null : _delete,
                  style: TextButton.styleFrom(
                    foregroundColor: ManfaaColors.coralDeep,
                  ),
                  icon: const Icon(Icons.delete_outline_rounded, size: 20),
                  label: Text(l10n.deleteRoleCta),
                ),
                if (!deletable)
                  Text(
                    // Deactivated accounts count — they keep resolving in
                    // audit trails and can be switched back on.
                    l10n.roleInUseHint(widget.existing!.staffCount),
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// One catalogue checkbox. Disabled ticks keep their visual state — a
/// stored slug the actor cannot re-grant still shows as held.
class _PermissionTick extends StatelessWidget {
  const _PermissionTick({
    required this.label,
    required this.ticked,
    required this.enabled,
    required this.onChanged,
  });

  final String label;
  final bool ticked;
  final bool enabled;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: enabled ? () => onChanged(!ticked) : null,
      child: Opacity(
        opacity: enabled ? 1 : 0.45,
        child: Row(
          children: [
            Checkbox(
              value: ticked,
              activeColor: ManfaaColors.violet,
              onChanged: enabled
                  ? (value) => onChanged(value ?? false)
                  : null,
            ),
            Expanded(
              child: Text(
                label,
                style: theme.textTheme.bodyMedium,
              ),
            ),
            if (!enabled)
              Icon(
                Icons.lock_outline_rounded,
                size: 16,
                color: theme.colorScheme.onSurfaceVariant,
              ),
          ],
        ),
      ),
    );
  }
}
