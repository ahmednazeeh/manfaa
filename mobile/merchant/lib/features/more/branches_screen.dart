import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../../widgets/osm_map.dart';
import '../settlements/settlement_widgets.dart' show ToneBanner;
import 'estate_widgets.dart';
import 'more_providers.dart';
import 'pending_change_card.dart';

/// Manage Branches (MR5) — Branches.png's LAYOUT restyled into the violet
/// system, minus the mockup chrome with no backing data (RULE ZERO): no
/// photos, no opening hours, no phone, and no Active/Inactive state —
/// branches carry none. What IS real: the numbered cards, name, address,
/// pin state, and the stat strip counts (total / pinned / no pin).
///
/// The pin is a nullable PAIR — the picker hands back both halves or
/// neither — and this screen is the ONLY surface that can take a pin away
/// again. DELETE is refused (409 `branch_referenced`) once transactions or
/// promotions reference the branch: history must keep resolving, and the
/// soft alternative is simply to stop using it.
class BranchesScreen extends ConsumerStatefulWidget {
  const BranchesScreen({super.key});

  @override
  ConsumerState<BranchesScreen> createState() => _BranchesScreenState();
}

class _BranchesScreenState extends ConsumerState<BranchesScreen> {
  static const _tints = [
    ManfaaTint.green,
    ManfaaTint.blue,
    ManfaaTint.violet,
    ManfaaTint.amber,
    ManfaaTint.coral,
  ];

  var _query = '';

  /// MR7 — the expanded list | editor split's right pane: open with a
  /// branch id to edit, open with null to add. Local state, cleared when
  /// the tab is left (MR8's tab-reset). Phones keep the bottom sheet
  /// instead.
  var _paneOpen = false;
  int? _paneBranchId;

  /// The expanded pane's completion — same follow-up as the sheet's pop.
  void _onPaneFinished(bool saved) {
    setState(() => _paneOpen = false);
    if (saved) ref.invalidate(branchesProvider);
  }

  Widget _pane(MerchantBranchEstate estate) {
    final l10n = context.l10n;

    if (_paneOpen) {
      MerchantBranch? editing;
      for (final branch in estate.branches) {
        if (branch.id == _paneBranchId) editing = branch;
      }
      if (_paneBranchId == null || editing != null) {
        return EditorPane(
          onClose: () => setState(() => _paneOpen = false),
          child: _BranchSheet(
            key: ValueKey('branch-${editing?.id ?? 'new'}'),
            existing: editing,
            pending: editing == null ? null : estate.pendingFor(editing.id),
            onFinished: _onPaneFinished,
          ),
        );
      }
    }

    return PaneHint(
      icon: Icons.storefront_outlined,
      title: l10n.paneBranchesHintTitle,
      body: l10n.paneBranchesHintBody,
    );
  }

  Future<void> _openEditor(
    BuildContext context, {
    MerchantBranch? existing,
    MerchantChangeRequest? pending,
  }) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      useRootNavigator: true, // over the floating nav bar
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _BranchSheet(existing: existing, pending: pending),
    );
    if (saved ?? false) ref.invalidate(branchesProvider);
  }

  Future<void> _confirmDelete(MerchantBranch branch) async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(l10n.branchDeleteConfirmTitle(branch.name)),
        content: Text(l10n.branchDeleteConfirmBody),
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

    try {
      // MR9: for a live store the removal QUEUES — the branch stays on the
      // list, and saying "Branch deleted" would be a plain lie.
      final result = await ref.read(apiProvider).deleteBranch(branch.id);
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            result.pending ? l10n.branchRemovalSentForReview : l10n.branchDeleted,
          ),
        ),
      );
      ref.invalidate(branchesProvider);
    } on MobileApiException catch (e) {
      // The 409 refusal as a SENTENCE a shopkeeper can act on — a known
      // code gets the localized wording, an unknown one the server's prose.
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            e.code == 'branch_referenced'
                ? l10n.branchReferencedError
                : e.message,
          ),
        ),
      );
    } catch (_) {
      messenger.showSnackBar(SnackBar(content: Text(l10n.branchDeleteFailed)));
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final canCreate = session.can('branches.create');
    final canEdit = session.can('branches.edit');
    final canDelete = session.can('branches.delete');
    final branches = ref.watch(branchesProvider);

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: SCREEN layout reads CONTENT width (the rail shell's 96dp is
        // already gone from these constraints). ≥840dp of content splits
        // into list | editor — the phone's bottom sheet seated in the
        // right pane, same fields, same pin picker. Phones render the
        // shipped column untouched.
        child: LayoutBuilder(
          builder: (context, constraints) {
            final expanded =
                constraints.maxWidth >= kExpandedMinWidth &&
                (canCreate || canEdit);
            return ContentRail(
              maxWidth: expanded ? kWideContentWidth : kContentRailWidth,
              child: ListView(
                padding: EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.sm,
                  Gap.xl,
                  bottomClearanceOf(context),
                ),
                children: [
                  MerchantDetailTopBar(initials: initials),
                  const SizedBox(height: Gap.md),
                  Text(
                    l10n.branchesTitle,
                    style: theme.textTheme.headlineMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: Gap.xs),
                  Text(
                    l10n.branchesSubtitle,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: Gap.lg),
                  branches.when(
                    loading: () =>
                        const SkeletonBox(height: 320, radius: Corner.card),
                    error: (error, _) => SectionErrorCard(
                      error: error,
                      onRetry: () => ref.invalidate(branchesProvider),
                    ),
                    data: (estate) {
                      final branches = estate.branches;
                      final pinned = branches.where((b) => b.pinned).length;
                      final query = _query.trim().toLowerCase();
                      final filtered = query.isEmpty
                          ? branches
                          : [
                              for (final branch in branches)
                                if (branch.name.toLowerCase().contains(query) ||
                                    (branch.address ?? '')
                                        .toLowerCase()
                                        .contains(query))
                                  branch,
                            ];
                      // MR9 — branches the owner asked for that have no row
                      // yet. They match the search on their PROPOSED name,
                      // because that is the only name they have.
                      final creating = [
                        for (final change in estate.pendingCreates)
                          if (query.isEmpty ||
                              '${change.proposed['name'] ?? ''} '
                                      '${change.proposed['address'] ?? ''}'
                                  .toLowerCase()
                                  .contains(query))
                            change,
                      ];

                      final list = Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          // The ref's stat strip, from REAL fields: total and the
                          // pin split (there is no active flag to count).
                          _StatStrip(
                            total: branches.length,
                            pinned: pinned,
                            unpinned: branches.length - pinned,
                          ),
                          const SizedBox(height: Gap.md),
                          if (canCreate) ...[
                            VioletCta(
                              icon: Icons.add_circle_outline_rounded,
                              label: l10n.addBranchCta,
                              onPressed: () => expanded
                                  ? setState(() {
                                      _paneOpen = true;
                                      _paneBranchId = null;
                                    })
                                  : _openEditor(context),
                            ),
                            const SizedBox(height: Gap.md),
                          ],
                          EstateSearchField(
                            hint: l10n.searchBranchesHint,
                            onChanged: (value) =>
                                setState(() => _query = value),
                          ),
                          const SizedBox(height: Gap.md),
                          // Queued NEW branches lead the list: they are the
                          // owner's most recent act and the one the list
                          // itself cannot show — no row exists for them yet.
                          for (final change in creating) ...[
                            PendingChangeCard(
                              request: change,
                              note: l10n.pendingCreateNote,
                            ),
                            const SizedBox(height: Gap.md),
                          ],
                          if (filtered.isEmpty && creating.isEmpty)
                            ManfaaCard(
                              child: Text(
                                l10n.branchesEmpty,
                                textAlign: TextAlign.center,
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  color: theme.colorScheme.onSurfaceVariant,
                                ),
                              ),
                            )
                          else
                            for (final (i, branch) in filtered.indexed) ...[
                              if (i > 0) const SizedBox(height: Gap.md),
                              _BranchCard(
                                branch: branch,
                                number: branches.indexOf(branch) + 1,
                                tint:
                                    _tints[branches.indexOf(branch) %
                                        _tints.length],
                                onEdit: canEdit
                                    ? () => expanded
                                          ? setState(() {
                                              _paneOpen = true;
                                              _paneBranchId = branch.id;
                                            })
                                          : _openEditor(
                                              context,
                                              existing: branch,
                                              pending: estate.pendingFor(
                                                branch.id,
                                              ),
                                            )
                                    : null,
                                onDelete: canDelete
                                    ? () => _confirmDelete(branch)
                                    : null,
                              ),
                              // The change waiting against THIS address, under
                              // the card that still shows what a shopper sees.
                              if (estate.pendingFor(branch.id) case final change?) ...[
                                const SizedBox(height: Gap.sm),
                                PendingChangeCard(
                                  request: change,
                                  note: change.isBranchDelete
                                      ? l10n.pendingRemovalNote
                                      : l10n.pendingBranchNote,
                                ),
                              ],
                            ],
                        ],
                      );

                      if (!expanded) return list;
                      return Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: list),
                          const SizedBox(width: Gap.lg),
                          Expanded(child: _pane(estate)),
                        ],
                      );
                    },
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

/// Total · Pinned · No pin, in the ref's one-card three-segment strip.
class _StatStrip extends StatelessWidget {
  const _StatStrip({
    required this.total,
    required this.pinned,
    required this.unpinned,
  });

  final int total;
  final int pinned;
  final int unpinned;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    Widget cell(String label, String value, Color dot) => Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(color: dot, shape: BoxShape.circle),
              ),
              const SizedBox(width: Gap.xs + 2),
              Flexible(
                child: Text(
                  label,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Padding(
            padding: const EdgeInsetsDirectional.only(start: 14),
            child: Text(
              value,
              textDirection: TextDirection.ltr,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );

    return ManfaaCard(
      child: Row(
        children: [
          const IconTile(
            Icons.storefront_outlined,
            tint: ManfaaTint.green,
            size: 44,
            iconSize: 22,
          ),
          const SizedBox(width: Gap.md),
          cell(l10n.totalBranchesLabel, '$total', ManfaaColors.violet),
          cell(l10n.pinnedBranchesLabel, '$pinned', ManfaaColors.green),
          cell(l10n.unpinnedBranchesLabel, '$unpinned', ManfaaColors.amber),
        ],
      ),
    );
  }
}

class _BranchCard extends StatelessWidget {
  const _BranchCard({
    required this.branch,
    required this.number,
    required this.tint,
    this.onEdit,
    this.onDelete,
  });

  final MerchantBranch branch;
  final int number;
  final ManfaaTint tint;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final colors = tintColors(tint, theme.brightness);

    Widget detailRow(IconData icon, String text, {TextDirection? direction}) =>
        Padding(
          padding: const EdgeInsets.only(top: Gap.xs),
          child: Row(
            children: [
              Icon(icon, size: 16, color: muted),
              const SizedBox(width: Gap.sm),
              Expanded(
                child: Text(
                  text,
                  textDirection: direction,
                  textAlign:
                      direction == TextDirection.ltr &&
                          Directionality.of(context) == TextDirection.rtl
                      ? TextAlign.right
                      : null,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        );

    return ManfaaCard(
      onTap: onEdit,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The ref's numbered circle, tinted.
          Container(
            width: 40,
            height: 40,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: colors.bg, shape: BoxShape.circle),
            child: Text(
              '$number',
              textDirection: TextDirection.ltr,
              style: TextStyle(fontWeight: FontWeight.w800, color: colors.fg),
            ),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  branch.name,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                if ((branch.address ?? '').isNotEmpty)
                  detailRow(Icons.location_on_outlined, branch.address!),
                detailRow(
                  branch.pinned
                      ? Icons.push_pin_outlined
                      : Icons.location_off_outlined,
                  branch.pinned
                      ? '${branch.lat!.toStringAsFixed(5)}, '
                            '${branch.lng!.toStringAsFixed(5)}'
                      : l10n.branchNotPinned,
                  direction: branch.pinned ? TextDirection.ltr : null,
                ),
              ],
            ),
          ),
          if (onEdit != null || onDelete != null)
            PopupMenuButton<String>(
              icon: Icon(Icons.more_vert_rounded, color: muted),
              onSelected: (value) =>
                  value == 'edit' ? onEdit?.call() : onDelete?.call(),
              itemBuilder: (context) => [
                if (onEdit != null)
                  PopupMenuItem(value: 'edit', child: Text(l10n.edit)),
                if (onDelete != null)
                  PopupMenuItem(value: 'delete', child: Text(l10n.deleteLabel)),
              ],
            ),
        ],
      ),
    );
  }
}

/// Add/edit one branch: name, optional address, and the optional pin — the
/// setup location step's drag-under-a-fixed-pin picker, reused. Pops `true`
/// after a landed save.
class _BranchSheet extends ConsumerStatefulWidget {
  const _BranchSheet({
    super.key,
    this.existing,
    this.pending,
    this.onFinished,
  });

  final MerchantBranch? existing;

  /// MR9 — the change already waiting against THIS branch, if any. Shown at
  /// the top of the editor because saving again supersedes it.
  final MerchantChangeRequest? pending;

  /// MR7 — set when the editor lives inline in the expanded pane:
  /// completion calls back instead of popping a sheet route.
  final void Function(bool saved)? onFinished;

  @override
  ConsumerState<_BranchSheet> createState() => _BranchSheetState();
}

class _BranchSheetState extends ConsumerState<_BranchSheet> {
  /// Malé — where most first pins are a short drag from (the setup step's
  /// own fallback).
  static const _fallbackCenter = LatLng(4.1755, 73.5093);

  late final _name = TextEditingController(text: widget.existing?.name ?? '');
  late final _address = TextEditingController(
    text: widget.existing?.address ?? '',
  );

  /// Whether the branch HAS a pin — toggled off by "remove pin"; the map
  /// only renders while true, so the sheet stays honest about what will be
  /// saved (both halves or neither).
  late bool _pinSet = widget.existing?.pinned ?? false;
  late LatLng _center = _pinSet
      ? LatLng(widget.existing!.lat!, widget.existing!.lng!)
      : _fallbackCenter;
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _address.dispose();
    super.dispose();
  }

  double _round7(double value) => double.parse(value.toStringAsFixed(7));

  var _geocoding = false;

  /// Ask the server what the dropped pin is called, and put the answer in
  /// the field. A SUGGESTION: it lands somewhere the merchant can retype,
  /// because an address is a claim about their own shop.
  Future<void> _fillAddressFromPin() async {
    final l10n = context.l10n;
    setState(() => _geocoding = true);
    try {
      final address = await ref.read(apiProvider).reverseGeocodePin(
            lat: _round7(_center.latitude),
            lng: _round7(_center.longitude),
          );
      if (!mounted) return;
      if (address == null || address.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(l10n.branchAddressFromPinEmpty)),
        );
        return;
      }
      _address.text = address;
    } catch (_) {
      if (!mounted) return;
      // Never fatal — the merchant types it instead.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(l10n.branchAddressFromPinEmpty)),
      );
    } finally {
      if (mounted) setState(() => _geocoding = false);
    }
  }

  Future<void> _save() async {
    final l10n = context.l10n;
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = l10n.branchNameRequired);
      return;
    }
    final address = _address.text.trim();
    // Required now (owner decision 2026-08-18): a pin with no address reads
    // as a raw coordinate in every map app a customer is handed off to.
    if (address.isEmpty) {
      setState(() => _error = l10n.branchAddressRequired);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ref.read(apiProvider);
      final lat = _pinSet ? _round7(_center.latitude) : null;
      final lng = _pinSet ? _round7(_center.longitude) : null;
      final existing = widget.existing;
      final result = existing == null
          ? await api.createBranch(
              name: name,
              address: address,
              lat: lat,
              lng: lng,
            )
          : await api.updateBranch(
              existing.id,
              name: name,
              address: address,
              lat: lat,
              lng: lng,
            );
      if (!mounted) return;
      // MR9: a live store's branch writes QUEUE. The list will not have
      // grown, and the branch will not have moved — say so.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            switch ((existing == null, result.pending)) {
              (true, true) => l10n.branchSentForReview,
              (true, false) => l10n.branchAdded,
              (false, true) => l10n.branchChangeSentForReview,
              (false, false) => l10n.branchSaved,
            },
          ),
        ),
      );
      if (widget.onFinished != null) {
        widget.onFinished!(true);
      } else {
        Navigator.of(context).pop(true);
      }
    } on MobileApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.branchSaveFailed);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final editing = widget.existing != null;

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
                    ? l10n.editBranchTitle(widget.existing!.name)
                    : l10n.addBranchCta,
                style: theme.textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.lg),
              if (widget.pending case final pending?) ...[
                PendingChangeCard(
                  request: pending,
                  note: pending.isBranchDelete
                      ? l10n.pendingRemovalNote
                      : l10n.pendingReplaceNote,
                ),
                const SizedBox(height: Gap.lg),
              ],
              Text(l10n.branchNameLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _name,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.storefront_outlined),
                ),
              ),
              const SizedBox(height: Gap.lg),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      l10n.branchAddressLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                  ),
                  // Only offered once there is a pin to read: the address
                  // comes FROM the pin, so without one there is nothing to
                  // ask for.
                  if (_pinSet)
                    TextButton(
                      onPressed: _geocoding ? null : _fillAddressFromPin,
                      child: Text(
                        _geocoding ? l10n.branchAddressFinding : l10n.branchAddressFromPin,
                      ),
                    ),
                ],
              ),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _address,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.location_on_outlined),
                ),
              ),
              const SizedBox(height: Gap.lg),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      l10n.branchPinLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                  ),
                  TextButton(
                    onPressed: () => setState(() {
                      _pinSet = !_pinSet;
                      if (_pinSet && widget.existing?.pinned == true) {
                        _center = LatLng(
                          widget.existing!.lat!,
                          widget.existing!.lng!,
                        );
                      }
                    }),
                    child: Text(_pinSet ? l10n.clearPinCta : l10n.setPinCta),
                  ),
                ],
              ),
              if (_pinSet) ...[
                ClipRRect(
                  borderRadius: BorderRadius.circular(Corner.tile),
                  child: SizedBox(
                    height: 220,
                    // MR8: the shared pin-picker map — identified OSM user
                    // agent, https tiles, and the graceful tile-error state
                    // live THERE (the setup location step renders the same
                    // widget).
                    child: PinPickerMap(
                      initialCenter: _center,
                      initialZoom: widget.existing?.pinned == true ? 17 : 15,
                      onCenterChanged: (center) =>
                          setState(() => _center = center),
                    ),
                  ),
                ),
                const SizedBox(height: Gap.xs),
                Text(
                  '${_center.latitude.toStringAsFixed(7)}, '
                  '${_center.longitude.toStringAsFixed(7)}',
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
              const SizedBox(height: Gap.xs),
              Text(
                l10n.branchPinHint,
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
                onPressed: _busy ? null : _save,
                child: _busy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(editing ? l10n.save : l10n.addBranchCta),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
