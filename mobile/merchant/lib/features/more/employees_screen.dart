import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show Clipboard, ClipboardData;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';
import '../settlements/settlement_widgets.dart' show ToneBanner;
import 'estate_widgets.dart';
import 'more_providers.dart';

/// Manage Employees (MR5), drawn to Manage Employees.png — minus the
/// mockup's invented chrome (RULE ZERO reconciliation): the stat tiles are
/// REAL counts (total + active; the server models no invite state, so no
/// "Pending invites"), and each row's subtitle is the account's EMAIL —
/// staff have no branch link.
///
/// There is deliberately no delete anywhere here: deactivation is the only
/// removal, so audit trails keep their actors. The guards the server
/// enforces (last active owner, self-targeting, delegation) are mirrored as
/// sentences BEFORE the round trip, and the server's own refusal sentence
/// renders whenever it answers one anyway.
class EmployeesScreen extends ConsumerStatefulWidget {
  const EmployeesScreen({super.key});

  @override
  ConsumerState<EmployeesScreen> createState() => _EmployeesScreenState();
}

class _EmployeesScreenState extends ConsumerState<EmployeesScreen> {
  var _query = '';

  /// Whether the SIGNED-IN account stands on the owner-flagged role — the
  /// staff list's own answer (matched by email; /merchant/me carries no
  /// role). Only an owner hands out the owner role (D5).
  bool _actorIsOwner(List<MerchantStaff> staff) {
    final email = ref.read(sessionProvider).userEmail;
    for (final member in staff) {
      if (member.email == email) return member.role?.isOwner ?? false;
    }
    return false;
  }

  Future<void> _invite(List<MerchantStaff> staff) async {
    final roles = ref.read(rolesProvider).valueOrNull;
    if (roles == null) return;

    final result = await showModalBottomSheet<StaffInviteResult>(
      context: context,
      useRootNavigator: true, // over the floating nav bar
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _InviteSheet(
        roles: roles,
        actorIsOwner: _actorIsOwner(staff),
      ),
    );
    if (result == null || !mounted) return;

    ref.invalidate(staffProvider);
    // The ONE-TIME password handover — modal until acknowledged; the
    // password exists only in this response and is never retrievable again.
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _TempPasswordDialog(result: result),
    );
  }

  Future<void> _edit(MerchantStaff member, List<MerchantStaff> staff) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _StaffEditSheet(
        member: member,
        actorIsOwner: _actorIsOwner(staff),
        actorEmail: ref.read(sessionProvider).userEmail,
        activeOwnerCount: staff
            .where((m) => (m.role?.isOwner ?? false) && m.isActive)
            .length,
      ),
    );
    if (saved ?? false) ref.invalidate(staffProvider);
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final canInvite = session.can('staff.invite');
    final canEdit = session.can('staff.edit');
    final canSeeRoles = session.can('roles.view');

    final staff = ref.watch(staffProvider);
    final roles = canSeeRoles ? ref.watch(rolesProvider) : null;
    final rolesReady = (roles?.valueOrNull ?? const <MerchantRole>[])
        .isNotEmpty;

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
              l10n.employeesTitle,
              style: theme.textTheme.headlineMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.employeesSubtitle,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.lg),
            staff.when(
              loading: () =>
                  const SkeletonBox(height: 320, radius: Corner.card),
              error: (error, _) => SectionErrorCard(
                error: error,
                onRetry: () => ref.invalidate(staffProvider),
              ),
              data: (staff) {
                final active = staff.where((m) => m.isActive).length;
                final query = _query.trim().toLowerCase();
                final filtered = query.isEmpty
                    ? staff
                    : [
                        for (final member in staff)
                          if (member.name.toLowerCase().contains(query) ||
                              member.email.toLowerCase().contains(query))
                            member,
                      ];

                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: StatTile(
                            icon: Icons.group_outlined,
                            tint: ManfaaTint.violet,
                            label: l10n.totalEmployeesLabel,
                            value: '${staff.length}',
                          ),
                        ),
                        const SizedBox(width: Gap.md),
                        Expanded(
                          child: StatTile(
                            icon: Icons.how_to_reg_outlined,
                            tint: ManfaaTint.green,
                            label: l10n.activeEmployeesLabel,
                            value: '$active',
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: Gap.md),
                    if (canInvite || canSeeRoles) ...[
                      Row(
                        children: [
                          if (canInvite)
                            Expanded(
                              child: VioletCta(
                                icon: Icons.add_circle_outline_rounded,
                                label: l10n.addEmployeeCta,
                                onPressed:
                                    rolesReady ? () => _invite(staff) : null,
                              ),
                            ),
                          if (canInvite && canSeeRoles)
                            const SizedBox(width: Gap.md),
                          if (canSeeRoles)
                            Expanded(
                              child: VioletOutlineCta(
                                icon: Icons.local_police_outlined,
                                label: l10n.rolesCta,
                                onPressed: () => context.go('/more/roles'),
                              ),
                            ),
                        ],
                      ),
                      // A `staff.invite` holder without `roles.view` has no
                      // role to pick — say so instead of a dead button.
                      if (canInvite && !canSeeRoles) ...[
                        const SizedBox(height: Gap.sm),
                        Text(
                          l10n.staffNeedsRolesView,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                      const SizedBox(height: Gap.md),
                    ],
                    EstateSearchField(
                      hint: l10n.searchEmployeesHint,
                      onChanged: (value) => setState(() => _query = value),
                    ),
                    const SizedBox(height: Gap.md),
                    ManfaaCard(
                      padding: const EdgeInsets.symmetric(
                        horizontal: Gap.lg,
                        vertical: Gap.xs,
                      ),
                      child: filtered.isEmpty
                          ? Padding(
                              padding: const EdgeInsets.all(Gap.lg),
                              child: Text(
                                l10n.employeesEmpty,
                                textAlign: TextAlign.center,
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  color: theme.colorScheme.onSurfaceVariant,
                                ),
                              ),
                            )
                          : Column(
                              children: [
                                for (final (i, member)
                                    in filtered.indexed) ...[
                                  if (i > 0)
                                    Divider(
                                      height: 1,
                                      color:
                                          theme.colorScheme.outlineVariant,
                                    ),
                                  _StaffRow(
                                    member: member,
                                    index: i,
                                    dhivehi: dhivehi,
                                    isSelf:
                                        member.email == session.userEmail,
                                    onTap: canEdit
                                        ? () => _edit(member, staff)
                                        : null,
                                  ),
                                ],
                              ],
                            ),
                    ),
                    const SizedBox(height: Gap.lg),
                    _PermissionsOverviewCard(
                      staff: staff,
                      dhivehi: dhivehi,
                      canSeeRoles: canSeeRoles,
                    ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _StaffRow extends StatelessWidget {
  const _StaffRow({
    required this.member,
    required this.index,
    required this.dhivehi,
    required this.isSelf,
    this.onTap,
  });

  final MerchantStaff member;
  final int index;
  final bool dhivehi;
  final bool isSelf;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.md),
        child: Row(
          children: [
            TintedInitialAvatar(name: member.name, index: index),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    isSelf
                        ? '${member.name} ${l10n.youMarker}'
                        : member.name,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  // The EMAIL, per reconciliation — staff have no branch
                  // link, and the email is what they sign in with.
                  Text(
                    member.email,
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const SizedBox(width: Gap.sm),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                SoftPill(
                  label: member.role?.label(dhivehi: dhivehi) ??
                      l10n.noRoleLabel,
                ),
                const SizedBox(height: Gap.xs),
                StatusChip(
                  label: member.isActive
                      ? l10n.statusActive
                      : l10n.statusSuspended,
                  tone: member.isActive
                      ? StatusTone.confirmed
                      : StatusTone.attention,
                ),
              ],
            ),
            if (onTap != null)
              Icon(
                Icons.chevron_right_rounded,
                color: theme.colorScheme.onSurfaceVariant,
              ),
          ],
        ),
      ),
    );
  }
}

/// The ref's footer: shield tile, the REAL per-role head-count line, and
/// the Manage roles link (only for `roles.view` holders).
class _PermissionsOverviewCard extends StatelessWidget {
  const _PermissionsOverviewCard({
    required this.staff,
    required this.dhivehi,
    required this.canSeeRoles,
  });

  final List<MerchantStaff> staff;
  final bool dhivehi;
  final bool canSeeRoles;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    // Real counts grouped by the role each account actually stands on.
    final counts = <String, int>{};
    for (final member in staff) {
      final label =
          member.role?.label(dhivehi: dhivehi) ?? l10n.noRoleLabel;
      counts[label] = (counts[label] ?? 0) + 1;
    }
    final line = [
      for (final MapEntry(:key, :value) in counts.entries) '$key $value',
    ].join(' · ');

    return ManfaaCard(
      onTap: canSeeRoles ? () => context.go('/more/roles') : null,
      child: Row(
        children: [
          const IconTile(
            Icons.local_police_outlined,
            tint: ManfaaTint.violet,
            size: 44,
            iconSize: 22,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.permissionsOverviewTitle,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  line,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          if (canSeeRoles) ...[
            const SizedBox(width: Gap.sm),
            Text(
              l10n.manageRolesLink,
              style: theme.textTheme.labelLarge?.copyWith(
                fontWeight: FontWeight.w700,
                color: theme.brightness == Brightness.dark
                    ? const Color(0xFFB79CF6)
                    : ManfaaColors.violetDeep,
              ),
            ),
            Icon(
              Icons.chevron_right_rounded,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ],
        ],
      ),
    );
  }
}

/// Whether the signed-in account may HAND OUT this role — the panel's
/// mirror of RoleService::assertMayAssign (D5), told before the round trip:
/// only an owner hands out the owner role, and nothing leaves your hands
/// that was not in them. The server refuses regardless.
bool mayAssignRole(
  MerchantSession session,
  MerchantRole role, {
  required bool actorIsOwner,
}) {
  if (role.isOwner && !actorIsOwner) return false;
  return role.permissions.every(session.can);
}

/// The invite sheet. The role is REQUIRED and un-defaulted: with per-store
/// roles there is no tier to fall back to, and a preselected role is
/// authority nobody chose. Pops the [StaffInviteResult] — the caller owns
/// the one-time password dialog.
class _InviteSheet extends ConsumerStatefulWidget {
  const _InviteSheet({required this.roles, required this.actorIsOwner});

  final List<MerchantRole> roles;
  final bool actorIsOwner;

  @override
  ConsumerState<_InviteSheet> createState() => _InviteSheetState();
}

class _InviteSheetState extends ConsumerState<_InviteSheet> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  int? _roleId;
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = context.l10n;
    final roleId = _roleId;
    if (roleId == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(apiProvider).inviteStaff(
            name: _name.text.trim(),
            email: _email.text.trim(),
            merchantRoleId: roleId,
          );
      if (!mounted) return;
      Navigator.of(context).pop(result);
    } on MobileApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.inviteFailed);
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
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final anyLocked = widget.roles.any(
      (role) =>
          !mayAssignRole(session, role, actorIsOwner: widget.actorIsOwner),
    );
    final canSubmit = _name.text.trim().isNotEmpty &&
        _email.text.trim().isNotEmpty &&
        _roleId != null &&
        !_busy;

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.sm,
        bottom: MediaQuery.viewInsetsOf(context).bottom + Gap.xl,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              l10n.inviteTitle,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.inviteNameLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _name,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.person_outline_rounded),
              ),
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.inviteEmailLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              textDirection: TextDirection.ltr,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.mail_outline_rounded),
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.inviteEmailHint,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.invitePickRoleLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            for (final role in widget.roles) ...[
              _RoleOption(
                role: role,
                dhivehi: dhivehi,
                selected: _roleId == role.id,
                enabled: mayAssignRole(
                  session,
                  role,
                  actorIsOwner: widget.actorIsOwner,
                ),
                onTap: () => setState(() => _roleId = role.id),
              ),
              const SizedBox(height: Gap.sm),
            ],
            if (anyLocked)
              Text(
                l10n.roleNotAssignableHint,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
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
              onPressed: canSubmit ? _submit : null,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(l10n.inviteCta),
            ),
          ],
        ),
      ),
    );
  }
}

/// One pickable role row — radio dot, name, a lock where delegation
/// forbids it (a disabled control with no explanation is the thing a
/// shopkeeper mails support about, so the hint sentence sits under the
/// list).
class _RoleOption extends StatelessWidget {
  const _RoleOption({
    required this.role,
    required this.dhivehi,
    required this.selected,
    required this.enabled,
    required this.onTap,
  });

  final MerchantRole role;
  final bool dhivehi;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return InkWell(
      borderRadius: BorderRadius.circular(Corner.control),
      onTap: enabled ? onTap : null,
      child: Opacity(
        opacity: enabled ? 1 : 0.5,
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: Gap.md,
            vertical: Gap.sm + 2,
          ),
          decoration: BoxDecoration(
            color: selected
                ? (theme.brightness == Brightness.dark
                    ? ManfaaColors.violet.withValues(alpha: 0.12)
                    : const Color(0xFFF4F1FE))
                : Colors.transparent,
            borderRadius: BorderRadius.circular(Corner.control),
            border: Border.all(
              color: selected
                  ? ManfaaColors.violet
                  : theme.colorScheme.outlineVariant,
            ),
          ),
          child: Row(
            children: [
              Icon(
                selected
                    ? Icons.radio_button_checked_rounded
                    : Icons.radio_button_off_rounded,
                size: 20,
                color: selected ? ManfaaColors.violet : muted,
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Text(
                  role.label(dhivehi: dhivehi),
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              if (!enabled) Icon(Icons.lock_outline_rounded, size: 16, color: muted),
            ],
          ),
        ),
      ),
    );
  }
}

/// The one-time password handover, exactly as the server returns it: the
/// password is shown ONCE, with a copy button, and the dialog cannot be
/// dismissed until the inviter confirms it has been passed on.
class _TempPasswordDialog extends StatefulWidget {
  const _TempPasswordDialog({required this.result});

  final StaffInviteResult result;

  @override
  State<_TempPasswordDialog> createState() => _TempPasswordDialogState();
}

class _TempPasswordDialogState extends State<_TempPasswordDialog> {
  var _acknowledged = false;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final staff = widget.result.staff;

    return AlertDialog(
      title: Text(l10n.tempPasswordTitle(staff.name)),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ToneBanner(
              tone: ToneSurface.pending,
              icon: Icons.key_rounded,
              title: l10n.tempPasswordOnceTitle,
              body: l10n.tempPasswordOnceBody(staff.name),
            ),
            const SizedBox(height: Gap.md),
            Text(
              l10n.tempPasswordLoginEmail,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            const SizedBox(height: 2),
            Text(
              staff.email,
              textDirection: TextDirection.ltr,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: Gap.md),
            Row(
              children: [
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: Gap.md,
                      vertical: Gap.sm + 2,
                    ),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(Corner.tile),
                      border: Border.all(
                        color: theme.colorScheme.outlineVariant,
                      ),
                    ),
                    child: Text(
                      widget.result.tempPassword,
                      textDirection: TextDirection.ltr,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontFamily: 'monospace',
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: Gap.sm),
                IconButton(
                  tooltip: l10n.copyPassword,
                  icon: const Icon(Icons.copy_rounded),
                  onPressed: () async {
                    await Clipboard.setData(
                      ClipboardData(text: widget.result.tempPassword),
                    );
                    if (!context.mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(l10n.passwordCopied)),
                    );
                  },
                ),
              ],
            ),
            const SizedBox(height: Gap.md),
            InkWell(
              onTap: () => setState(() => _acknowledged = !_acknowledged),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Checkbox(
                    value: _acknowledged,
                    onChanged: (value) =>
                        setState(() => _acknowledged = value ?? false),
                  ),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: Gap.md),
                      child: Text(
                        l10n.tempPasswordAck,
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      actions: [
        FilledButton(
          onPressed: _acknowledged
              ? () => Navigator.of(context).pop()
              : null,
          style: FilledButton.styleFrom(
            minimumSize: const Size(0, 44),
            padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
          ),
          child: Text(l10n.doneLabel),
        ),
      ],
    );
  }
}

/// Role reassign + active toggle for one account, with the guards told as
/// sentences BEFORE the round trip (the server enforces each one again):
/// the last active owner is locked entirely, self cannot demote or
/// deactivate, and a role holding permissions the actor lacks cannot be
/// handed out. Pops `true` after a landed save.
class _StaffEditSheet extends ConsumerStatefulWidget {
  const _StaffEditSheet({
    required this.member,
    required this.actorIsOwner,
    required this.actorEmail,
    required this.activeOwnerCount,
  });

  final MerchantStaff member;
  final bool actorIsOwner;
  final String? actorEmail;
  final int activeOwnerCount;

  @override
  ConsumerState<_StaffEditSheet> createState() => _StaffEditSheetState();
}

class _StaffEditSheetState extends ConsumerState<_StaffEditSheet> {
  late int? _roleId = widget.member.role?.id;
  late bool _active = widget.member.isActive;
  var _busy = false;
  String? _error;

  bool get _isSelf => widget.member.email == widget.actorEmail;

  bool get _isOwnerAccount => widget.member.role?.isOwner ?? false;

  /// The D4 arithmetic: this row is the store's ONLY active owner-flagged
  /// account. Both controls lock — losing every active owner locks the
  /// whole settings surface permanently.
  bool get _isLastActiveOwner =>
      _isOwnerAccount && widget.member.isActive && widget.activeOwnerCount <= 1;

  Future<void> _save() async {
    final l10n = context.l10n;
    final roleChanged = _roleId != widget.member.role?.id;
    final activeChanged = _active != widget.member.isActive;
    if (!roleChanged && !activeChanged) {
      Navigator.of(context).pop(false);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).updateStaff(
            widget.member.id,
            merchantRoleId: roleChanged ? _roleId : null,
            isActive: activeChanged ? _active : null,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.staffSaved)));
      Navigator.of(context).pop(true);
    } on MobileApiException catch (e) {
      // The guards answer as sentences (last active owner, self-targeting)
      // and the delegation family as coded refusals — either way the
      // server's own message is the one rendered.
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.staffSaveFailed);
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
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final roles = session.can('roles.view')
        ? ref.watch(rolesProvider).valueOrNull
        : null;

    // Ordered by which refusal the server would answer with first.
    final roleLockReason = _isLastActiveOwner
        ? l10n.staffLastOwnerLocked
        : _isSelf && _isOwnerAccount
            ? l10n.staffSelfDemoteLocked
            : null;
    final activeLockReason = _isLastActiveOwner
        ? l10n.staffLastOwnerLocked
        : _isSelf
            ? l10n.staffSelfActiveLocked
            : null;

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.sm,
        bottom: MediaQuery.viewInsetsOf(context).bottom + Gap.xl,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                TintedInitialAvatar(name: widget.member.name, index: 0),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        widget.member.name,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      Text(
                        widget.member.email,
                        textDirection: TextDirection.ltr,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: muted,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: Gap.lg),
            Text(l10n.employeeRoleLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            if (roles == null)
              Text(
                l10n.staffNeedsRolesView,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              )
            else
              for (final role in roles) ...[
                _RoleOption(
                  role: role,
                  dhivehi: dhivehi,
                  selected: _roleId == role.id,
                  enabled: roleLockReason == null &&
                      mayAssignRole(
                        session,
                        role,
                        actorIsOwner: widget.actorIsOwner,
                      ),
                  onTap: () => setState(() => _roleId = role.id),
                ),
                const SizedBox(height: Gap.sm),
              ],
            if (roleLockReason != null)
              Text(
                roleLockReason,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            const SizedBox(height: Gap.lg),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        l10n.employeeActiveLabel,
                        style: theme.textTheme.labelLarge,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        activeLockReason ?? l10n.employeeActiveHint,
                        style:
                            theme.textTheme.bodySmall?.copyWith(color: muted),
                      ),
                    ],
                  ),
                ),
                Switch(
                  value: _active,
                  activeThumbColor: Colors.white,
                  activeTrackColor: ManfaaColors.violet,
                  onChanged: activeLockReason == null
                      ? (value) => setState(() => _active = value)
                      : null,
                ),
              ],
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
                  : Text(l10n.save),
            ),
          ],
        ),
      ),
    );
  }
}
