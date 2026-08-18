import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../../widgets/tx_format.dart';
import '../settlements/settlement_widgets.dart' show ToneBanner;
import '../setup/rate_step.dart'
    show bpToPercentString, formatBp, parsePercentToBp;
import 'estate_widgets.dart';
import 'more_providers.dart';

/// Promotions (MR5) — no mockup exists; the layout is the house card idiom
/// and the content is the web promotions page's exactly (§10): a
/// time-boxed cashback BOOST above the standing rate, four separate
/// permissions for read/draft/publish/cancel, and a lifecycle the server
/// owns — create draft, publish (immutable once live: no edit, no early
/// end), cancel DRAFT only.
///
/// Money law: the promo rate travels as the EXACT percent string typed and
/// is compared as integer bp; the min-purchase and per-customer-cap
/// amounts stay integer laari; the cost picture is the SERVER's
/// `cost_preview`, rendered verbatim and never derived here.
class PromotionsScreen extends ConsumerStatefulWidget {
  const PromotionsScreen({super.key});

  @override
  ConsumerState<PromotionsScreen> createState() => _PromotionsScreenState();
}

class _PromotionsScreenState extends ConsumerState<PromotionsScreen> {
  /// The server's cost picture from the last create/publish this visit.
  PromotionCostPreview? _preview;
  var _actionBusy = false;

  /// MR7 — whether the expanded list | builder split's right pane holds the
  /// draft builder. Local state on a shell-branch screen, so rail
  /// navigation away and back keeps it. Phones keep the bottom sheet.
  var _builderOpen = false;

  Future<void> _openBuilder() async {
    final result = await showModalBottomSheet<PromotionWriteResult>(
      context: context,
      useRootNavigator: true, // over the floating nav bar
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _PromotionBuilderSheet(
        standingRate: ref.read(sessionProvider).can('rate.view')
            ? ref
                  .read(rateSettingsProvider)
                  .valueOrNull
                  ?.current
                  ?.cashbackRatePercent
            : null,
      ),
    );
    if (result == null || !mounted) return;
    setState(() => _preview = result.costPreview);
    ref.invalidate(promotionsProvider);
  }

  Future<void> _publish(Promotion promotion) async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(
          l10n.publishConfirmTitle(
            trimRatePercent(promotion.cashbackRatePercent),
          ),
        ),
        // The web's own copy: immutable once live, the whole window.
        content: Text(
          l10n.publishConfirmBody(
            formatIsoDisplay(promotion.startsAt),
            formatIsoDisplay(promotion.endsAt),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(l10n.keepDraftCta),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            style: FilledButton.styleFrom(
              minimumSize: const Size(0, 44),
              padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
            ),
            child: Text(l10n.publishCta),
          ),
        ],
      ),
    );
    if (!(confirmed ?? false) || !mounted) return;

    setState(() => _actionBusy = true);
    try {
      final result = await ref.read(apiProvider).publishPromotion(promotion.id);
      if (!mounted) return;
      messenger.showSnackBar(SnackBar(content: Text(l10n.promotionPublished)));
      setState(() => _preview = result.costPreview);
      ref.invalidate(promotionsProvider);
    } on MobileApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      messenger.showSnackBar(SnackBar(content: Text(l10n.publishFailed)));
    } finally {
      if (mounted) setState(() => _actionBusy = false);
    }
  }

  Future<void> _cancel(Promotion promotion) async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    setState(() => _actionBusy = true);
    try {
      await ref.read(apiProvider).cancelPromotion(promotion.id);
      if (!mounted) return;
      messenger.showSnackBar(SnackBar(content: Text(l10n.draftCancelled)));
      ref.invalidate(promotionsProvider);
    } on MobileApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      messenger.showSnackBar(SnackBar(content: Text(l10n.cancelDraftFailed)));
    } finally {
      if (mounted) setState(() => _actionBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    // Three separate acts behind what reads like one screen: drafting,
    // publishing (the irreversible one) and cancelling are each their own
    // grant, exactly as the web page treats them.
    final canDraft = session.can('promotions.create');
    final canPublish = session.can('promotions.publish');
    final canCancel = session.can('promotions.cancel');

    final promotions = ref.watch(promotionsProvider);
    final branchNames = ref.watch(branchNamesProvider).valueOrNull;
    if (session.can('rate.view')) {
      // Warm the standing rate so the builder can tell "must boost" before
      // the round trip.
      ref.watch(rateSettingsProvider);
    }

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();
    final preview = _preview;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: SCREEN layout reads CONTENT width (the rail shell's 96dp is
        // already gone from these constraints). ≥840dp of content splits
        // into list | builder for `promotions.create` holders — the
        // phone's bottom sheet seated in the right pane, same validation,
        // same server cost picture. Phones render the shipped column
        // untouched.
        child: LayoutBuilder(
          builder: (context, constraints) {
            final expanded =
                constraints.maxWidth >= kExpandedMinWidth && canDraft;

            final board = <Widget>[
              if (canDraft) ...[
                VioletCta(
                  icon: Icons.add_circle_outline_rounded,
                  label: l10n.newPromotionCta,
                  onPressed: expanded
                      ? () => setState(() => _builderOpen = true)
                      : _openBuilder,
                ),
                const SizedBox(height: Gap.md),
              ],
              if (preview != null) ...[
                _CostPreviewBanner(preview: preview),
                const SizedBox(height: Gap.md),
              ],
              promotions.when(
                loading: () =>
                    const SkeletonBox(height: 280, radius: Corner.card),
                error: (error, _) => SectionErrorCard(
                  error: error,
                  onRetry: () => ref.invalidate(promotionsProvider),
                ),
                data: (promotions) => promotions.isEmpty
                    ? ManfaaCard(
                        child: Text(
                          l10n.promotionsEmpty,
                          textAlign: TextAlign.center,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      )
                    : Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          for (final (i, promotion) in promotions.indexed) ...[
                            if (i > 0) const SizedBox(height: Gap.md),
                            _PromotionCard(
                              promotion: promotion,
                              branchNames: branchNames,
                              busy: _actionBusy,
                              onPublish:
                                  canPublish && promotion.status == 'draft'
                                  ? () => _publish(promotion)
                                  : null,
                              onCancel: canCancel && promotion.status == 'draft'
                                  ? () => _cancel(promotion)
                                  : null,
                            ),
                          ],
                        ],
                      ),
              ),
            ];

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
                    l10n.promotionsTitle,
                    style: theme.textTheme.headlineMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: Gap.xs),
                  Text(
                    l10n.promotionsSubtitle,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: Gap.lg),
                  if (!expanded)
                    ...board
                  else
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: board,
                          ),
                        ),
                        const SizedBox(width: Gap.lg),
                        Expanded(child: _pane()),
                      ],
                    ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  /// The expanded right pane: the draft builder, or the quiet hint.
  Widget _pane() {
    final l10n = context.l10n;

    if (_builderOpen) {
      return EditorPane(
        onClose: () => setState(() => _builderOpen = false),
        child: _PromotionBuilderSheet(
          key: const ValueKey('promo-builder'),
          standingRate: ref.read(sessionProvider).can('rate.view')
              ? ref
                    .read(rateSettingsProvider)
                    .valueOrNull
                    ?.current
                    ?.cashbackRatePercent
              : null,
          onFinished: (result) {
            setState(() {
              _builderOpen = false;
              _preview = result.costPreview;
            });
            ref.invalidate(promotionsProvider);
          },
        ),
      );
    }

    return PaneHint(
      icon: Icons.campaign_outlined,
      title: l10n.panePromoHintTitle,
      body: l10n.panePromoHintBody,
    );
  }
}

/// The SERVER's §4 cost picture, verbatim — what the boost costs per
/// eligible sale, the delta against standing terms, and the tier-cliff
/// warning when the higher rate lands on a higher fee tier.
class _CostPreviewBanner extends StatelessWidget {
  const _CostPreviewBanner({required this.preview});

  final PromotionCostPreview preview;

  /// The server's signed delta ("6.00", "-0.26") as trimmed percentage
  /// points: "+6pp". Display trim only — the digits are the server's.
  static String _pp(String delta) {
    var value = delta;
    if (value.contains('.')) {
      value = value
          .replaceAll(RegExp(r'0+$'), '')
          .replaceAll(RegExp(r'\.$'), '');
    }
    if (value.isEmpty) value = '0';
    final sign = value.startsWith('-') || value == '0' ? '' : '+';
    return '$sign${value}pp';
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final promo = preview.promo;
    final standing = preview.standing;

    final parts = <String>[
      if (promo.platformFeePercent != null)
        l10n.promoCostBody(
          trimRatePercent(promo.cashbackRatePercent),
          trimRatePercent(promo.platformFeePercent!),
        ),
      if (standing != null &&
          preview.allInDeltaPercent != null &&
          standing.allInPercent != null)
        l10n.promoCostDelta(
          _pp(preview.allInDeltaPercent!),
          trimRatePercent(standing.allInPercent!),
        ),
      if (preview.tierChanged &&
          standing != null &&
          standing.platformFeePercent != null &&
          promo.platformFeePercent != null)
        l10n.promoCostTier(
          trimRatePercent(standing.cashbackRatePercent),
          trimRatePercent(promo.cashbackRatePercent),
          trimRatePercent(standing.platformFeePercent!),
          trimRatePercent(promo.platformFeePercent!),
        ),
    ];

    return ToneBanner(
      tone: preview.tierChanged ? ToneSurface.pending : ToneSurface.info,
      icon: preview.tierChanged
          ? Icons.warning_amber_rounded
          : Icons.info_outline_rounded,
      title: promo.allInPercent != null
          ? l10n.promoCostTitle(trimRatePercent(promo.allInPercent!))
          : l10n.promoCostBody(trimRatePercent(promo.cashbackRatePercent), '—'),
      body: parts.isEmpty ? null : parts.join(' '),
    );
  }
}

class _PromotionCard extends StatelessWidget {
  const _PromotionCard({
    required this.promotion,
    required this.branchNames,
    required this.busy,
    this.onPublish,
    this.onCancel,
  });

  final Promotion promotion;
  final Map<int, String>? branchNames;
  final bool busy;
  final VoidCallback? onPublish;
  final VoidCallback? onCancel;

  (String, StatusTone) _statusChip(BuildContext context) {
    final l10n = context.l10n;
    return switch (promotion.status) {
      'draft' => (l10n.promoStatusDraft, StatusTone.pending),
      'published' when promotion.isLive => (
        l10n.promoStatusLive,
        StatusTone.confirmed,
      ),
      'published' => (l10n.promoStatusPublished, StatusTone.paid),
      'ended' => (l10n.promoStatusEnded, StatusTone.closed),
      'cancelled' => (l10n.promoStatusCancelled, StatusTone.closed),
      _ => (promotion.status, StatusTone.closed),
    };
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final (statusLabel, statusTone) = _statusChip(context);

    final branchLine = promotion.branchId == null
        ? l10n.promoAllBranches
        : branchNames?[promotion.branchId] ??
              l10n.promoBranchFallback(promotion.branchId!);

    Widget detail(IconData icon, String text) => Padding(
      padding: const EdgeInsets.only(top: Gap.xs),
      child: Row(
        children: [
          Icon(icon, size: 16, color: muted),
          const SizedBox(width: Gap.sm),
          Expanded(
            child: Text(
              text,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ),
        ],
      ),
    );

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const IconTile(
                Icons.campaign_outlined,
                tint: ManfaaTint.coral,
                size: 44,
                iconSize: 22,
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.promoRateTitle(
                        trimRatePercent(promotion.cashbackRatePercent),
                      ),
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      // Null fee: a stale draft the current fee schedule no
                      // longer prices — still listed so it can be
                      // cancelled; publishing is refused.
                      promotion.allInPercent != null &&
                              promotion.platformFeePercent != null
                          ? l10n.promoYouPay(
                              trimRatePercent(promotion.allInPercent!),
                              trimRatePercent(promotion.platformFeePercent!),
                            )
                          : l10n.promoYouPayDash,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: Gap.sm),
              StatusChip(label: statusLabel, tone: statusTone),
            ],
          ),
          const SizedBox(height: Gap.sm),
          detail(
            Icons.schedule_rounded,
            '${formatIsoDisplay(promotion.startsAt)} – '
            '${formatIsoDisplay(promotion.endsAt)}',
          ),
          if (promotion.minPurchaseLaari != null)
            detail(
              Icons.shopping_cart_outlined,
              l10n.promoMinPurchase(
                formatMoney(promotion.minPurchaseLaari!, dhivehi: dhivehi),
              ),
            ),
          if (promotion.maxCashbackPerCustomerLaari != null)
            detail(
              Icons.person_outline_rounded,
              l10n.promoPerCustomerCap(
                formatMoney(
                  promotion.maxCashbackPerCustomerLaari!,
                  dhivehi: dhivehi,
                ),
              ),
            ),
          detail(Icons.storefront_outlined, branchLine),
          if (onPublish != null || onCancel != null) ...[
            const SizedBox(height: Gap.md),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (onCancel != null)
                  OutlinedButton(
                    onPressed: busy ? null : onCancel,
                    // The theme's minimumSize is full-width (for stacked
                    // CTAs); inside this Row that is an infinite-width
                    // constraint — bound it.
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size(0, 44),
                      padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
                    ),
                    child: Text(l10n.cancelDraftCta),
                  ),
                if (onPublish != null) ...[
                  const SizedBox(width: Gap.sm),
                  FilledButton(
                    onPressed: busy ? null : onPublish,
                    style: FilledButton.styleFrom(
                      backgroundColor: ManfaaColors.violet,
                      foregroundColor: Colors.white,
                      minimumSize: const Size(0, 44),
                      padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
                    ),
                    child: Text(l10n.publishCta),
                  ),
                ],
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The draft builder. Validation mirrors the web page: exact-2dp percent
/// parsed once to integer bp for the §4 bounds and the must-boost
/// comparison (never floats), a window that ends after it starts, optional
/// MVR amounts parsed to integer laari. The window is picked in Maldives
/// wall time and travels with the explicit +05:00 offset.
class _PromotionBuilderSheet extends ConsumerStatefulWidget {
  const _PromotionBuilderSheet({super.key, this.standingRate, this.onFinished});

  /// The standing rate as the API states it, or null when unknown (unset,
  /// or the account lacks `rate.view` — the server still enforces).
  final String? standingRate;

  /// MR7 — set when the builder lives inline in the expanded pane:
  /// completion calls back instead of popping a sheet route.
  final void Function(PromotionWriteResult result)? onFinished;

  @override
  ConsumerState<_PromotionBuilderSheet> createState() =>
      _PromotionBuilderSheetState();
}

class _PromotionBuilderSheetState
    extends ConsumerState<_PromotionBuilderSheet> {
  /// §4 structural bounds, integer bp. The active fee schedule may end
  /// lower — that refusal (`rate_not_priced`) renders when it answers.
  static const _minBp = 50;
  static const _maxBp = 2000;

  /// Maldives is UTC+05:00 year-round — timestamps leave with it explicit.
  static const _businessOffset = '+05:00';

  final _rate = TextEditingController();
  final _minPurchase = TextEditingController();
  final _cap = TextEditingController();
  late DateTime _startsAt = DateTime.now();
  DateTime? _endsAt;
  int? _branchId;
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _rate.dispose();
    _minPurchase.dispose();
    _cap.dispose();
    super.dispose();
  }

  String _iso(DateTime dt) {
    String two(int v) => v.toString().padLeft(2, '0');
    return '${dt.year}-${two(dt.month)}-${two(dt.day)}'
        'T${two(dt.hour)}:${two(dt.minute)}:00$_businessOffset';
  }

  Future<void> _pick({required bool starts}) async {
    final initial = starts ? _startsAt : (_endsAt ?? _startsAt);
    final date = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime.now().subtract(const Duration(days: 1)),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(initial),
    );
    if (time == null || !mounted) return;
    final picked = DateTime(
      date.year,
      date.month,
      date.day,
      time.hour,
      time.minute,
    );
    setState(() {
      if (starts) {
        _startsAt = picked;
      } else {
        _endsAt = picked;
      }
      _error = null;
    });
  }

  Future<void> _submit(int rateBp, int? minLaari, int? capLaari) async {
    final l10n = context.l10n;
    final endsAt = _endsAt;
    if (endsAt == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(apiProvider)
          .createPromotion(
            // PLAN §1 wire format: the rate leaves as the exact 2-decimal
            // percent string the bp above was parsed from.
            cashbackRatePercent: bpToPercentString(rateBp),
            startsAt: _iso(_startsAt),
            endsAt: _iso(endsAt),
            minPurchaseLaari: minLaari,
            maxCashbackPerCustomerLaari: capLaari,
            branchId: _branchId,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.draftCreated)));
      if (widget.onFinished != null) {
        widget.onFinished!(result);
      } else {
        Navigator.of(context).pop(result);
      }
    } on MobileApiException catch (e) {
      // rate_not_priced and the domain's window/boost prose both arrive as
      // sentences — render them where the form is.
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = l10n.createDraftFailed);
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
    // The estate's ROWS only: a branch waiting on MR9 approval has no id to
    // scope a promotion to, and offering one would build an offer against a
    // branch that may never exist.
    final branches = session.can('branches.view')
        ? ref.watch(branchesProvider).valueOrNull?.branches
        : null;

    // Compared as integer bp — "must boost" is a comparison and 4.99 vs
    // 5.00 is a tier.
    final standingBp = widget.standingRate == null
        ? null
        : parsePercentToBp(widget.standingRate!);

    final trimmed = _rate.text.trim();
    final bp = trimmed.isEmpty ? null : parsePercentToBp(trimmed);
    final rateError = trimmed.isEmpty
        ? null
        : bp == null
        ? l10n.customRateFormat
        : bp < _minBp || bp > _maxBp
        ? l10n.rateBoundsHint(formatBp(_minBp), formatBp(_maxBp))
        : standingBp != null && bp <= standingBp
        ? l10n.promoMustBoost(trimRatePercent(widget.standingRate!))
        : null;
    final validBp = rateError == null ? bp : null;

    final endsAt = _endsAt;
    final windowError = endsAt != null && !endsAt.isAfter(_startsAt)
        ? l10n.promoWindowOrderError
        : null;

    final minText = _minPurchase.text.trim();
    final minLaari = minText.isEmpty ? null : parseMvrToLaari(minText);
    final minError = minText.isNotEmpty && (minLaari == null || minLaari < 0)
        ? l10n.promoAmountInvalid
        : null;

    final capText = _cap.text.trim();
    final capLaari = capText.isEmpty ? null : parseMvrToLaari(capText);
    final capError = capText.isNotEmpty && (capLaari == null || capLaari < 1)
        ? l10n.promoAmountInvalid
        : null;

    final canSubmit =
        validBp != null &&
        endsAt != null &&
        windowError == null &&
        minError == null &&
        capError == null &&
        !_busy;

    Widget whenRow(String label, String value, VoidCallback onTap) => InkWell(
      borderRadius: BorderRadius.circular(Corner.control),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: Gap.md,
          vertical: Gap.md,
        ),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(Corner.control),
          border: Border.all(color: theme.colorScheme.outlineVariant),
        ),
        child: Row(
          children: [
            Icon(Icons.event_rounded, size: 18, color: muted),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    value,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
            Icon(Icons.edit_calendar_outlined, size: 18, color: muted),
          ],
        ),
      ),
    );

    return Padding(
      padding: EdgeInsets.only(
        left: Gap.xl,
        right: Gap.xl,
        top: Gap.sm,
        bottom: MediaQuery.viewInsetsOf(context).bottom + Gap.xl,
      ),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.85,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                l10n.newPromotionCta,
                style: theme.textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.lg),
              Text(l10n.promoRateLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _rate,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                textDirection: TextDirection.ltr,
                onChanged: (_) => setState(() => _error = null),
                decoration: InputDecoration(
                  hintText: '7.50',
                  suffixText: '%',
                  errorText: rateError,
                ),
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.promoRateHint,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              const SizedBox(height: Gap.lg),
              whenRow(
                l10n.promoStartsLabel,
                formatDateTimeDisplay(_startsAt),
                () => _pick(starts: true),
              ),
              const SizedBox(height: Gap.sm),
              whenRow(
                l10n.promoEndsLabel,
                endsAt == null ? '—' : formatDateTimeDisplay(endsAt),
                () => _pick(starts: false),
              ),
              const SizedBox(height: Gap.xs),
              Text(
                windowError ?? l10n.promoWindowHint,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: windowError != null ? theme.colorScheme.error : muted,
                ),
              ),
              const SizedBox(height: Gap.lg),
              Text(l10n.promoMinLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _minPurchase,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                textDirection: TextDirection.ltr,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  prefixText: 'MVR ',
                  errorText: minError,
                ),
              ),
              const SizedBox(height: Gap.lg),
              Text(l10n.promoCapLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: Gap.sm),
              TextField(
                controller: _cap,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                textDirection: TextDirection.ltr,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  prefixText: 'MVR ',
                  errorText: capError,
                ),
              ),
              if (branches != null && branches.isNotEmpty) ...[
                const SizedBox(height: Gap.lg),
                Text(l10n.promoBranchLabel, style: theme.textTheme.labelLarge),
                const SizedBox(height: Gap.sm),
                DropdownButtonFormField<int?>(
                  initialValue: _branchId,
                  items: [
                    DropdownMenuItem<int?>(
                      value: null,
                      child: Text(l10n.promoAllBranches),
                    ),
                    for (final branch in branches)
                      DropdownMenuItem<int?>(
                        value: branch.id,
                        child: Text(
                          branch.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: (value) => setState(() => _branchId = value),
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: Gap.md),
                ToneBanner(
                  tone: ToneSurface.attention,
                  icon: Icons.error_outline_rounded,
                  title: l10n.rateNotPricedTitle,
                  body: _error,
                ),
              ],
              const SizedBox(height: Gap.lg),
              FilledButton(
                onPressed: canSubmit
                    ? () => _submit(validBp, minLaari, capLaari)
                    : null,
                child: _busy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(l10n.createDraftCta),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
