import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../../widgets/tx_format.dart';
import '../setup/rate_step.dart'
    show
        bpToPercentString,
        estimateLaariAtBp,
        formatBp,
        parsePercentToBp,
        staticFeeBp;
import 'credit_widgets.dart';
import 'split_editor.dart';

/// The till (MR2) — the Credit screen per the two Credit refs.
///
/// Everything money-true lives on the server: rate frozen at occurred_at,
/// ceiling rounding, below-minimum zeroing, duplicate-invoice rejection.
/// This screen confirms the customer BY NAME first, previews the cost as an
/// estimate at the current rate, and submits every credit — online or not —
/// through the offline queue so the idempotency key minted at compose time
/// is the one every retry carries.

/// §1 default validation window — the platform ceiling a store's own window
/// is capped at. The till cannot read the store's actual number (web
/// parity: that sits behind `preferences.update`), so the backdated warning
/// fires across the whole band it could fall in. See the web credit page.
const _kDefaultValidationWindowDays = 3;

/// CreditRecorder::STALE_GRACE_DAYS — added to the window before a credit
/// counts as backdated.
const _kBackdatedGraceDays = 3;

/// §4 structural bounds for a per-sale rate, integer bp.
const _kMinRateBp = 50;
const _kMaxRateBp = 2000;

/// The screen's clock — overridable so the screenshot harness renders a
/// stable sale time instead of the live minute. Production never touches it.
@visibleForTesting
DateTime Function() creditClock = DateTime.now;

final creditRateProvider = FutureProvider<MerchantRate>(
  (ref) => ref.watch(apiProvider).merchantRate(),
);

final creditCategoriesProvider = FutureProvider<List<ProductCategory>>(
  (ref) => ref.watch(apiProvider).productCategories(),
);

enum _LookupPhase { checking, found, notFound, failed }

class _Lookup {
  _Lookup(this.code, this.phase, {this.name, this.error});

  final String code;
  final _LookupPhase phase;
  final String? name;
  final MobileApiException? error;
}

class CreditScreen extends ConsumerStatefulWidget {
  const CreditScreen({super.key});

  @override
  ConsumerState<CreditScreen> createState() => _CreditScreenState();
}

class _CreditScreenState extends ConsumerState<CreditScreen> {
  var _mode = CodeMode.enter;
  final _code = TextEditingController();
  final _codeFocus = FocusNode();
  _Lookup? _lookup;

  final _invoice = TextEditingController();
  final _invoiceFocus = FocusNode();
  final _eligible = TextEditingController();
  final _sale = TextEditingController();

  /// Business wall time (+05:00 on the wire). Only SENT when edited —
  /// untouched means "record as now", the key simply absent.
  DateTime _occurredAt = creditClock();
  var _occurredEdited = false;
  var _backdatedConfirmed = false;

  /// The server answered `backdated_confirmation_required` — its clock, not
  /// ours, is the truth, so the warning + confirm render regardless of the
  /// local bands.
  var _serverRequiresBackdated = false;

  var _overrideOpen = false;
  final _override = TextEditingController();

  var _splitEnabled = false;
  var _splitRows = <SplitRow>[];

  var _busy = false;
  MobileApiException? _error;
  (MerchantCreditResult, String)? _result;

  @override
  void initState() {
    super.initState();
    // Standing at the till usually means signal: give the queue a chance.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) ref.read(creditQueueProvider.notifier).drain();
    });
    _codeFocus.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _code.dispose();
    _codeFocus.dispose();
    _invoice.dispose();
    _invoiceFocus.dispose();
    _eligible.dispose();
    _sale.dispose();
    _override.dispose();
    super.dispose();
  }

  // ------------------------------------------------------------- lookup

  void _onCodeChanged(String value) {
    setState(() {
      _error = null;
      _lookup = value.length == 6 ? _lookup : null;
    });
    if (value.length == 6) _fireLookup(value);
  }

  Future<void> _fireLookup(String code) async {
    setState(() => _lookup = _Lookup(code, _LookupPhase.checking));
    try {
      final hit = await ref.read(apiProvider).lookupCustomer(code);
      if (!mounted || _code.text != code) return;
      setState(
        () => _lookup = hit.valid
            ? _Lookup(code, _LookupPhase.found, name: hit.name ?? '')
            : _Lookup(code, _LookupPhase.notFound),
      );
    } on MobileApiException catch (e) {
      if (!mounted || _code.text != code) return;
      setState(() => _lookup = _Lookup(code, _LookupPhase.failed, error: e));
    }
  }

  void _useCode(String code) {
    _code.text = code;
    setState(() => _mode = CodeMode.enter);
    _onCodeChanged(code);
  }

  /// Enter in the code entry — a hardware keyboard's ⏎, and exactly what a
  /// USB/BT barcode gun sends after its digits. A verified code walks focus
  /// on to the invoice field; anything else with six digits (re)fires the
  /// lookup, so a failed check retries on the gun's next Enter. Fewer than
  /// six digits stays quiet.
  void _onCodeSubmitted(String value) {
    if (value.length != 6) return;
    final lookup = _lookup;
    if (lookup != null &&
        lookup.code == value &&
        lookup.phase == _LookupPhase.found) {
      _invoiceFocus.requestFocus();
      return;
    }
    if (lookup == null || lookup.phase != _LookupPhase.checking) {
      _fireLookup(value);
    }
  }

  /// Enter on any of the form's text fields: submit when the form is
  /// complete, stay quiet when it is not — the [_submittable] gate is the
  /// same one the CTA button stands on.
  void _submitFromKeyboard() {
    if (_submittable) _submit();
  }

  // ------------------------------------------------------------ compose

  int? get _eligibleLaari =>
      _eligible.text.trim().isEmpty ? null : parseMvrToLaari(_eligible.text);

  bool get _eligibleInvalid =>
      _eligible.text.trim().isNotEmpty &&
      (_eligibleLaari == null || _eligibleLaari! < 1);

  int? get _saleLaari =>
      _sale.text.trim().isEmpty ? null : parseMvrToLaari(_sale.text);

  bool get _saleInvalid =>
      _sale.text.trim().isNotEmpty &&
      (_saleLaari == null ||
          (_eligibleLaari != null && _saleLaari! < _eligibleLaari!));

  String _businessIso(DateTime dt) {
    String two(int v) => v.toString().padLeft(2, '0');
    return '${dt.year}-${two(dt.month)}-${two(dt.day)}'
        'T${two(dt.hour)}:${two(dt.minute)}:00+05:00';
  }

  /// Age of the picked sale time, reading the wall clock as +05:00 exactly
  /// as it will go to the wire.
  Duration get _age => _occurredEdited
      ? creditClock().toUtc().difference(
          DateTime.parse(_businessIso(_occurredAt)),
        )
      : Duration.zero;

  bool get _certainlyBackdated =>
      _serverRequiresBackdated ||
      (_occurredEdited &&
          _age.inMilliseconds >
              (_kDefaultValidationWindowDays + _kBackdatedGraceDays) *
                  Duration.millisecondsPerDay);

  bool get _possiblyBackdated =>
      !_certainlyBackdated &&
      _occurredEdited &&
      _age.inMilliseconds > _kBackdatedGraceDays * Duration.millisecondsPerDay;

  bool get _backdatedWarning => _certainlyBackdated || _possiblyBackdated;

  bool get _canSetCustomRate =>
      ref.read(sessionProvider).can('credits.custom_rate');

  int? get _advertisedBp {
    final current = ref.read(creditRateProvider).valueOrNull?.current;
    return current == null
        ? null
        : parsePercentToBp(current.cashbackRatePercent);
  }

  // ------------------------------------------------------------- submit

  Future<void> _submit() async {
    final l10n = context.l10n;
    final lookup = _lookup;
    if (lookup == null || lookup.phase != _LookupPhase.found) return;
    final customerName = lookup.name ?? '';
    final overrideBp = _activeOverrideBp;

    final entry = QueuedCredit(
      key: mintIdempotencyKey(),
      customerCode: lookup.code,
      customerName: customerName,
      invoiceNo: _invoice.text.trim(),
      eligibleLaari: _eligibleLaari!,
      saleLaari: _saleInvalid ? null : _saleLaari,
      occurredAt: _occurredEdited ? _businessIso(_occurredAt) : null,
      cashbackRatePercent: overrideBp == null
          ? null
          : bpToPercentString(overrideBp),
      lines: _splitEnabled
          ? [
              for (final row in _splitRows)
                CreditLine(
                  category: row.category,
                  amountLaari: row.amountLaari,
                ),
            ]
          : const [],
      backdatedAcknowledged: _backdatedWarning && _backdatedConfirmed,
      queuedAt: creditClock().toIso8601String(),
    );

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(creditQueueProvider.notifier).submit(entry);
      if (!mounted) return;
      if (result != null) {
        setState(() => _result = (result, customerName));
        _resetForm();
      } else {
        // Queued: the pending-sync banner carries the count from here on.
        _resetForm();
        setState(() {});
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(l10n.queuedSnack)));
      }
    } on MobileApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        if (e.code == ApiCode.backdatedConfirmationRequired) {
          _serverRequiresBackdated = true;
          _backdatedConfirmed = false;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _resetForm() {
    _code.clear();
    _invoice.clear();
    _eligible.clear();
    _sale.clear();
    _override.clear();
    _lookup = null;
    _mode = CodeMode.enter;
    _occurredAt = creditClock();
    _occurredEdited = false;
    _backdatedConfirmed = false;
    _serverRequiresBackdated = false;
    _overrideOpen = false;
    _splitEnabled = false;
    _splitRows = [];
  }

  int? get _activeOverrideBp {
    if (!_canSetCustomRate || !_overrideOpen) return null;
    final trimmed = _override.text.trim();
    if (trimmed.isEmpty) return null;
    final bp = parsePercentToBp(trimmed);
    if (bp == null || bp < _kMinRateBp || bp > _kMaxRateBp) return null;
    final advertised = _advertisedBp;
    if (advertised != null && bp < advertised) return null;
    return bp;
  }

  // -------------------------------------------------------------- build

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    ref.watch(sessionTickProvider);
    final queue = ref.watch(creditQueueProvider);
    final rateAsync = ref.watch(creditRateProvider);
    final categoriesAsync = ref.watch(creditCategoriesProvider);
    final categories = categoriesAsync.valueOrNull ?? const <ProductCategory>[];
    final activeCategories = [
      for (final c in categories)
        if (c.active) c,
    ];

    final session = ref.watch(sessionProvider);
    final initials = (session.merchantName ?? session.userName ?? 'M');

    final result = _result;

    final resultCard = result == null
        ? null
        : CreditResultCard(
            result: result.$1,
            customerName: result.$2,
            categories: categories,
            onReset: () => setState(() => _result = null),
          );

    // The compose-state pieces, shared by both layouts. IDENTIFY: who and
    // what (mode row + code boxes + invoice/date/amounts). ACT: how it
    // prices and the CTA (split editor + cost preview + notes + submit).
    List<Widget> identify() => [
      _buildCustomerCard(l10n, theme, queue),
      const SizedBox(height: Gap.md),
      _buildFormCard(l10n, theme, rateAsync, activeCategories),
    ];

    List<Widget> act() => [
      if (_splitEnabled) ...[
        SplitEditorCard(
          rows: _splitRows,
          categories: activeCategories,
          eligibleLaari: _eligibleInvalid ? null : _eligibleLaari,
          onRowsChanged: (rows) => setState(() => _splitRows = rows),
        ),
        const SizedBox(height: Gap.md),
      ],
      _buildPreviewCard(l10n, theme, rateAsync, activeCategories),
      if (_backdatedWarning) ...[
        const SizedBox(height: Gap.md),
        _buildBackdatedNotice(l10n, theme),
      ],
      if (_error != null) ...[
        const SizedBox(height: Gap.md),
        _buildErrorNotice(l10n, theme, _error!),
      ],
      const SizedBox(height: Gap.md),
      const PendingNote(),
      const SizedBox(height: Gap.lg),
      FilledButton(
        onPressed: _submittable ? _submit : null,
        child: _busy
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.receipt_long_outlined, size: 20),
                  const SizedBox(width: Gap.sm),
                  Text(l10n.creditCta),
                ],
              ),
      ),
    ];

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // Content width (the rail's 96dp is already gone from these
        // constraints): ≥840dp puts identify left and act right — the
        // counter layout this round exists for. Phones keep the shipped
        // single column untouched.
        child: LayoutBuilder(
          builder: (context, constraints) {
            final expanded = constraints.maxWidth >= kExpandedMinWidth;
            return ContentRail(
              maxWidth: expanded ? kWideContentWidth : kContentRailWidth,
              child: ListView(
                padding: EdgeInsets.fromLTRB(
                  Gap.xl,
                  Gap.lg,
                  Gap.xl,
                  bottomClearanceOf(context),
                ),
                children: [
                  MerchantTopBar(
                    initials: initials.isEmpty
                        ? 'M'
                        : initials.characters.first.toUpperCase(),
                  ),
                  const SizedBox(height: Gap.lg),
                  Text(
                    l10n.creditTitle,
                    style: theme.textTheme.headlineMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: Gap.xs),
                  Text(
                    l10n.creditSubtitle,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: Gap.lg),
                  if (queue.pendingCount > 0) ...[
                    QueueBanner(
                      count: queue.pendingCount,
                      onSync: () =>
                          ref.read(creditQueueProvider.notifier).drain(),
                    ),
                    const SizedBox(height: Gap.md),
                  ],
                  if (queue.parked.isNotEmpty) ...[
                    NeedsAttentionCard(
                      entries: queue.parked,
                      onRetry: (entry) => ref
                          .read(creditQueueProvider.notifier)
                          .retryParked(entry.key),
                      onDiscard: (entry) => ref
                          .read(creditQueueProvider.notifier)
                          .discard(entry.key),
                    ),
                    const SizedBox(height: Gap.md),
                  ],
                  if (resultCard != null)
                    // The recorded answer reads as one card either way; on
                    // the wide canvas it keeps the single-column rail width.
                    expanded
                        ? Center(
                            child: ConstrainedBox(
                              constraints: const BoxConstraints(
                                maxWidth: kContentRailWidth,
                              ),
                              child: resultCard,
                            ),
                          )
                        : resultCard
                  else if (expanded)
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(child: Column(children: identify())),
                        const SizedBox(width: Gap.lg),
                        Expanded(child: Column(children: act())),
                      ],
                    )
                  else ...[
                    ...identify(),
                    const SizedBox(height: Gap.md),
                    ...act(),
                  ],
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  bool get _submittable {
    final overrideBroken =
        _overrideOpen &&
        _override.text.trim().isNotEmpty &&
        _activeOverrideBp == null;
    final overrideHalf = _overrideOpen && _override.text.trim().isEmpty;
    final splitSum = _splitRows.fold<int>(
      0,
      (acc, row) => acc + row.amountLaari,
    );

    return _lookup?.phase == _LookupPhase.found &&
        _invoice.text.trim().isNotEmpty &&
        _eligibleLaari != null &&
        _eligibleLaari! >= 1 &&
        !_saleInvalid &&
        // A half-typed custom rate must never silently fall back to the
        // standing one: fix it or close the control (web parity).
        !overrideBroken &&
        !overrideHalf &&
        (!_splitEnabled ||
            (_splitRows.isNotEmpty && splitSum == _eligibleLaari)) &&
        (!_backdatedWarning || _backdatedConfirmed) &&
        !_busy;
  }

  // ------------------------------------------------------ customer card

  Widget _buildCustomerCard(
    AppLocalizations l10n,
    ThemeData theme,
    CreditQueue queue,
  ) {
    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ModeRow(
            mode: _mode,
            onChanged: (mode) => setState(() => _mode = mode),
          ),
          const SizedBox(height: Gap.md),
          switch (_mode) {
            CodeMode.enter => Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                CodeBoxes(
                  controller: _code,
                  focusNode: _codeFocus,
                  onChanged: _onCodeChanged,
                  onSubmitted: _onCodeSubmitted,
                ),
                const SizedBox(height: Gap.md),
                _buildLookupState(l10n, theme),
              ],
            ),
            CodeMode.scan => _buildScanBody(l10n, theme),
            CodeMode.recent => _buildRecentBody(l10n, theme, queue),
          },
        ],
      ),
    );
  }

  Widget _buildLookupState(AppLocalizations l10n, ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final lookup = _lookup;

    if (lookup == null) {
      return Text(
        l10n.codeAskHint,
        style: theme.textTheme.bodySmall?.copyWith(color: muted),
      );
    }

    return switch (lookup.phase) {
      _LookupPhase.checking => Row(
        children: [
          const SizedBox(
            width: 16,
            height: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          const SizedBox(width: Gap.sm),
          Text(
            l10n.lookupChecking,
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        ],
      ),
      _LookupPhase.found => VerifiedCustomerChip(
        name: lookup.name ?? '',
        code: lookup.code,
      ),
      _LookupPhase.notFound => _InlineNotice(
        tone: ToneSurface.pending,
        icon: Icons.search_off_rounded,
        title: l10n.lookupNotFoundTitle,
        body: l10n.lookupNotFoundBody,
      ),
      _LookupPhase.failed => _InlineNotice(
        tone: ToneSurface.attention,
        icon: Icons.error_outline_rounded,
        title: lookup.error?.code == ApiCode.rateLimited
            ? l10n.errTooManyTries
            : l10n.lookupFailed,
        body: null,
      ),
    };
  }

  Widget _buildScanBody(AppLocalizations l10n, ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(Corner.control),
          child: SizedBox(
            height: 240,
            child: MobileScanner(
              onDetect: (capture) {
                for (final barcode in capture.barcodes) {
                  final raw = barcode.rawValue?.trim() ?? '';
                  if (RegExp(r'^\d{6}$').hasMatch(raw)) {
                    _useCode(raw);
                    return;
                  }
                }
              },
              errorBuilder: (context, error) => Container(
                color: theme.colorScheme.surfaceContainerLow,
                alignment: Alignment.center,
                padding: const EdgeInsets.all(Gap.xl),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const IconTile(
                      Icons.no_photography_outlined,
                      tint: ManfaaTint.neutral,
                      size: 48,
                      iconSize: 24,
                    ),
                    const SizedBox(height: Gap.md),
                    Text(
                      error.errorCode == MobileScannerErrorCode.permissionDenied
                          ? l10n.scanDenied
                          : l10n.scanFallbackHint,
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: Gap.md),
        Text(
          l10n.scanPrompt,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
        if (_lookup?.phase == _LookupPhase.found) ...[
          const SizedBox(height: Gap.md),
          VerifiedCustomerChip(name: _lookup!.name ?? '', code: _lookup!.code),
        ],
      ],
    );
  }

  Widget _buildRecentBody(
    AppLocalizations l10n,
    ThemeData theme,
    CreditQueue queue,
  ) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final recents = queue.recents;

    if (recents.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.lg),
        child: Column(
          children: [
            const IconTile(
              Icons.history_rounded,
              tint: ManfaaTint.neutral,
              size: 48,
              iconSize: 24,
            ),
            const SizedBox(height: Gap.md),
            Text(l10n.recentEmptyTitle, style: theme.textTheme.titleSmall),
            const SizedBox(height: 2),
            Text(
              l10n.recentEmptyBody,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],
        ),
      );
    }

    return Column(
      children: [
        for (final recent in recents)
          InkWell(
            borderRadius: BorderRadius.circular(Corner.tile),
            onTap: () => _useCode(recent.code),
            child: Padding(
              padding: const EdgeInsets.symmetric(
                vertical: Gap.sm,
                horizontal: Gap.xs,
              ),
              child: Row(
                children: [
                  ManfaaAvatar(
                    recent.name.isEmpty
                        ? '?'
                        : recent.name.characters.first.toUpperCase(),
                    size: 40,
                    showDot: false,
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Text(
                      recent.name,
                      style: theme.textTheme.titleSmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  Text(
                    recent.code,
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  Icon(Icons.chevron_right_rounded, color: muted),
                ],
              ),
            ),
          ),
      ],
    );
  }

  // ---------------------------------------------------------- form card

  Widget _buildFormCard(
    AppLocalizations l10n,
    ThemeData theme,
    AsyncValue<MerchantRate> rateAsync,
    List<ProductCategory> active,
  ) {
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _FieldLabel(
            icon: Icons.receipt_long_outlined,
            label: l10n.invoiceLabel,
          ),
          const SizedBox(height: Gap.sm),
          TextField(
            controller: _invoice,
            focusNode: _invoiceFocus,
            maxLength: 64,
            textInputAction: TextInputAction.done,
            onChanged: (_) => setState(() => _error = null),
            onSubmitted: (_) => _submitFromKeyboard(),
            decoration: const InputDecoration(
              hintText: 'INV-1001',
              counterText: '',
            ),
          ),
          const SizedBox(height: 6),
          Text(
            l10n.invoiceHint,
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: Gap.lg),
          _FieldLabel(
            icon: Icons.calendar_today_outlined,
            label: l10n.saleDateTimeLabel,
          ),
          const SizedBox(height: Gap.sm),
          InkWell(
            borderRadius: BorderRadius.circular(Corner.control),
            onTap: _pickOccurredAt,
            child: InputDecorator(
              decoration: const InputDecoration(),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      formatDateTimeDisplay(_occurredAt),
                      textDirection: TextDirection.ltr,
                      style: theme.textTheme.bodyLarge,
                    ),
                  ),
                  Icon(Icons.edit_calendar_outlined, size: 20, color: muted),
                ],
              ),
            ),
          ),
          const SizedBox(height: 6),
          if (_occurredEdited)
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: Gap.sm,
              children: [
                Text(
                  l10n.saleDateTimeEdited,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
                InkWell(
                  onTap: () => setState(() {
                    _occurredAt = creditClock();
                    _occurredEdited = false;
                    _backdatedConfirmed = false;
                    _serverRequiresBackdated = false;
                  }),
                  child: Text(
                    l10n.saleDateTimeSetNow,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.secondary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
            )
          else
            Text(
              l10n.saleDateTimeNow,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          const SizedBox(height: Gap.lg),
          _FieldLabel(icon: Icons.payments_outlined, label: l10n.eligibleLabel),
          const SizedBox(height: Gap.sm),
          _MvrField(
            controller: _eligible,
            invalid: _eligibleInvalid,
            onChanged: (_) => setState(() => _error = null),
            onSubmitted: (_) => _submitFromKeyboard(),
          ),
          const SizedBox(height: 6),
          Text(
            _eligibleInvalid ? l10n.eligibleInvalid : l10n.eligibleHint,
            style: theme.textTheme.bodySmall?.copyWith(
              color: _eligibleInvalid ? theme.colorScheme.error : muted,
            ),
          ),
          const SizedBox(height: Gap.lg),
          _FieldLabel(
            icon: Icons.request_quote_outlined,
            label: l10n.fullSaleLabel,
            suffix: '(${l10n.optionalLabel})',
          ),
          const SizedBox(height: Gap.sm),
          _MvrField(
            controller: _sale,
            invalid: _saleInvalid,
            onChanged: (_) => setState(() => _error = null),
            onSubmitted: (_) => _submitFromKeyboard(),
          ),
          const SizedBox(height: 6),
          Text(
            _saleInvalid ? l10n.fullSaleInvalid : l10n.fullSaleHint,
            style: theme.textTheme.bodySmall?.copyWith(
              color: _saleInvalid ? theme.colorScheme.error : muted,
            ),
          ),
          // PLAN §1 per-sale override — rendered only for roles holding
          // credits.custom_rate; the server refuses the FIELD regardless.
          if (_canSetCustomRate) ...[
            const SizedBox(height: Gap.lg),
            _ToggleRow(
              value: _overrideOpen,
              title: l10n.customRateToggle,
              subtitle: l10n.customRateToggleHint,
              onChanged: (value) => setState(() {
                _overrideOpen = value;
                if (!value) _override.clear();
              }),
            ),
            if (_overrideOpen) ...[
              const SizedBox(height: Gap.md),
              _buildOverrideField(l10n, theme),
            ],
          ],
          if (active.isNotEmpty) ...[
            const SizedBox(height: Gap.lg),
            _ToggleRow(
              value: _splitEnabled,
              title: l10n.splitToggle,
              subtitle: l10n.splitToggleHint,
              onChanged: (value) => setState(() => _splitEnabled = value),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildOverrideField(AppLocalizations l10n, ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final trimmed = _override.text.trim();
    final bp = parsePercentToBp(trimmed);
    final advertised = _advertisedBp;

    String? error;
    if (trimmed.isNotEmpty) {
      if (bp == null) {
        error = l10n.customRateFormat;
      } else if (bp < _kMinRateBp || bp > _kMaxRateBp) {
        error = l10n.customRateRange(
          formatBp(_kMinRateBp),
          formatBp(_kMaxRateBp),
        );
      } else if (advertised != null && bp < advertised) {
        error = l10n.customRateTooLow(formatBp(advertised));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 160,
          child: TextField(
            controller: _override,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr,
            onChanged: (_) => setState(() => _error = null),
            decoration: InputDecoration(
              hintText: advertised == null
                  ? '2.5'
                  : formatBp(advertised).replaceFirst('%', ''),
              suffixText: '%',
            ),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          error ??
              (advertised == null
                  ? l10n.customRateHintNoRate
                  : l10n.customRateHint(formatBp(advertised))),
          style: theme.textTheme.bodySmall?.copyWith(
            color: error == null ? muted : theme.colorScheme.error,
          ),
        ),
      ],
    );
  }

  Future<void> _pickOccurredAt() async {
    final now = creditClock();
    final date = await showDatePicker(
      context: context,
      initialDate: _occurredAt.isAfter(now) ? now : _occurredAt,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now,
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(_occurredAt),
    );
    if (time == null || !mounted) return;
    setState(() {
      _occurredAt = DateTime(
        date.year,
        date.month,
        date.day,
        time.hour,
        time.minute,
      );
      _occurredEdited = true;
      // Changing the time re-opens the question.
      _backdatedConfirmed = false;
      _serverRequiresBackdated = false;
      _error = null;
    });
  }

  // ------------------------------------------------------- preview card

  Widget _buildPreviewCard(
    AppLocalizations l10n,
    ThemeData theme,
    AsyncValue<MerchantRate> rateAsync,
    List<ProductCategory> active,
  ) {
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget body;
    if (rateAsync.hasError) {
      body = Text(
        l10n.previewUnavailable,
        style: theme.textTheme.bodySmall?.copyWith(color: muted),
      );
    } else if (rateAsync.isLoading) {
      body = const Column(
        children: [
          SkeletonBox(height: 16),
          SizedBox(height: Gap.sm),
          SkeletonBox(height: 16),
          SizedBox(height: Gap.sm),
          SkeletonBox(height: 20),
        ],
      );
    } else {
      final current = rateAsync.valueOrNull?.current;
      if (current == null) {
        body = Text(
          l10n.previewNoRate,
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        );
      } else {
        body = _buildPreviewRows(l10n, theme, current, active);
      }
    }

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.costPreviewTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.md),
          body,
        ],
      ),
    );
  }

  Widget _buildPreviewRows(
    AppLocalizations l10n,
    ThemeData theme,
    RateWindow current,
    List<ProductCategory> active,
  ) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final eligible = _eligibleInvalid ? null : _eligibleLaari;

    final standingBp = parsePercentToBp(current.cashbackRatePercent);
    final standingFeeBp = current.platformFeePercent == null
        ? null
        : parsePercentToBp(current.platformFeePercent!);
    final overrideBp = _activeOverrideBp;
    final baseRateBp = overrideBp ?? standingBp;
    final baseFeeBp = overrideBp != null
        ? staticFeeBp(overrideBp)
        : standingFeeBp;

    int? cashback;
    int? fee;
    String rateLabel;
    String feeLabel;
    String youPayLabel;

    final splitActive = _splitEnabled && _splitRows.isNotEmpty;
    if (splitActive) {
      final estimate = estimateSplit(
        _splitRows,
        active,
        baseRateBp: baseRateBp,
        baseFeeBp: baseFeeBp,
      );
      cashback = estimate?.cashback;
      fee = estimate?.fee;
      rateLabel = l10n.previewCashback(l10n.previewPerLine);
      feeLabel = l10n.previewFee(l10n.previewPerLine);
      // "You pay" is the sale's total either way — no per-line suffix.
      youPayLabel = l10n.resultYouPay;
    } else {
      if (eligible != null && baseRateBp != null && baseFeeBp != null) {
        cashback = estimateLaariAtBp(eligible, baseRateBp);
        fee = estimateLaariAtBp(eligible, baseFeeBp);
      }
      rateLabel = l10n.previewCashback(
        baseRateBp == null ? '—' : formatBp(baseRateBp),
      );
      feeLabel = l10n.previewFee(baseFeeBp == null ? '—' : formatBp(baseFeeBp));
      youPayLabel = l10n.previewYouPay(
        baseRateBp != null && baseFeeBp != null
            ? formatBp(baseRateBp + baseFeeBp)
            : '—',
      );
    }

    Widget row(String label, int? laari, {bool strong = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style:
                  (strong
                          ? theme.textTheme.titleSmall
                          : theme.textTheme.bodySmall)
                      ?.copyWith(color: strong ? null : muted),
            ),
          ),
          if (laari != null)
            MoneyText(
              laari,
              style: strong
                  ? theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    )
                  : theme.textTheme.bodyMedium,
            )
          else
            Text(
              '—',
              style: theme.textTheme.bodyMedium?.copyWith(color: muted),
            ),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        row(rateLabel, cashback),
        row(feeLabel, fee),
        Divider(height: Gap.lg, color: theme.colorScheme.outlineVariant),
        row(
          youPayLabel,
          cashback != null && fee != null ? cashback + fee : null,
          strong: true,
        ),
        const SizedBox(height: Gap.sm),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.info_outline_rounded, size: 14, color: muted),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                l10n.previewEstimateNote,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ------------------------------------------------------------ notices

  Widget _buildBackdatedNotice(AppLocalizations l10n, ThemeData theme) {
    final tone = _certainlyBackdated
        ? ToneSurface.attention
        : ToneSurface.pending;
    final colors = toneSurface(tone, theme.brightness);

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                Icons.warning_amber_rounded,
                size: 18,
                color: colors.foreground,
              ),
              const SizedBox(width: Gap.sm),
              Expanded(
                child: Text(
                  _certainlyBackdated
                      ? l10n.backdatedTitle
                      : l10n.backdatedMaybeTitle,
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: colors.foreground,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.xs),
          Text(
            _certainlyBackdated ? l10n.backdatedBody : l10n.backdatedMaybeBody,
            style: theme.textTheme.bodySmall?.copyWith(
              color: colors.foreground,
            ),
          ),
          const SizedBox(height: Gap.sm),
          InkWell(
            onTap: () =>
                setState(() => _backdatedConfirmed = !_backdatedConfirmed),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 24,
                  height: 24,
                  child: Checkbox(
                    value: _backdatedConfirmed,
                    onChanged: (value) =>
                        setState(() => _backdatedConfirmed = value ?? false),
                    activeColor: colors.foreground,
                  ),
                ),
                const SizedBox(width: Gap.sm),
                Expanded(
                  child: Text(
                    l10n.backdatedConfirm,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: colors.foreground,
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (!_backdatedConfirmed) ...[
            const SizedBox(height: Gap.xs),
            Text(
              l10n.backdatedConfirmRequired,
              style: theme.textTheme.labelSmall?.copyWith(
                color: colors.foreground,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildErrorNotice(
    AppLocalizations l10n,
    ThemeData theme,
    MobileApiException error,
  ) {
    String title;
    String? body;
    switch (error.code) {
      case ApiCode.duplicateInvoice:
        title = l10n.errDuplicateInvoiceTitle;
        body = l10n.errDuplicateInvoiceBody;
      case ApiCode.merchantNotActive ||
          ApiCode.storeNotTrading ||
          ApiCode.storeNotApproved:
        title = l10n.errSuspendedTitle;
        body = l10n.errSuspendedBody;
      case ApiCode.rateBelowAdvertised:
        title = l10n.customRateRefusedTitle;
        final advertised =
            error.meta['advertised_cashback_rate_percent'] as String?;
        final bp = advertised == null ? null : parsePercentToBp(advertised);
        body = bp == null
            ? l10n.customRateRefusedBodyNoRate
            : l10n.customRateRefusedBody(formatBp(bp));
      case ApiCode.rateNotPriced:
        title = l10n.customRateNotPricedTitle;
        body = error.message;
      case ApiCode.backdatedConfirmationRequired:
        title = l10n.backdatedTitle;
        body = error.message;
      case ApiCode.rateLimited:
        title = l10n.errTooManyTries;
        body = null;
      default:
        title = l10n.errCreditFailed;
        // Unknown code → the server's own prose, never the raw code.
        body = error.message;
    }
    return _InlineNotice(
      tone: ToneSurface.attention,
      icon: Icons.error_outline_rounded,
      title: title,
      body: body,
    );
  }
}

// ---------------------------------------------------------- small pieces

class _FieldLabel extends StatelessWidget {
  const _FieldLabel({required this.icon, required this.label, this.suffix});

  final IconData icon;
  final String label;
  final String? suffix;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      children: [
        Icon(icon, size: 18, color: theme.colorScheme.onSurfaceVariant),
        const SizedBox(width: Gap.sm),
        Flexible(
          child: Text(
            label,
            style: theme.textTheme.labelLarge,
            overflow: TextOverflow.ellipsis,
          ),
        ),
        if (suffix != null) ...[
          const SizedBox(width: Gap.xs),
          Flexible(
            child: Text(
              suffix!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ],
    );
  }
}

/// An amount field with the refs' "MVR" leading addon. LTR always: digits.
class _MvrField extends StatelessWidget {
  const _MvrField({
    required this.controller,
    required this.invalid,
    required this.onChanged,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final bool invalid;
  final ValueChanged<String> onChanged;
  final ValueChanged<String>? onSubmitted;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return TextField(
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      textDirection: TextDirection.ltr,
      textInputAction: TextInputAction.done,
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      style: theme.textTheme.bodyLarge?.copyWith(
        fontFeatures: const [FontFeature.tabularFigures()],
      ),
      decoration: InputDecoration(
        hintText: '0.00',
        prefixIcon: Container(
          margin: const EdgeInsetsDirectional.only(end: Gap.md),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainer,
            borderRadius: const BorderRadius.horizontal(
              left: Radius.circular(Corner.control),
            ),
          ),
          alignment: Alignment.center,
          child: Text(
            'MVR',
            style: theme.textTheme.labelLarge?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        // Tight, not min: an unconstrained prefix would swallow the row.
        prefixIconConstraints: const BoxConstraints.tightFor(
          width: 68,
          height: 52,
        ),
        errorText: invalid ? '' : null,
        errorStyle: const TextStyle(height: 0, fontSize: 0),
      ),
    );
  }
}

class _ToggleRow extends StatelessWidget {
  const _ToggleRow({
    required this.value,
    required this.title,
    required this.subtitle,
    required this.onChanged,
  });

  final bool value;
  final String title;
  final String subtitle;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Switch(value: value, onChanged: onChanged),
        const SizedBox(width: Gap.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(title, style: theme.textTheme.titleSmall),
              ),
              const SizedBox(height: 2),
              Text(
                subtitle,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _InlineNotice extends StatelessWidget {
  const _InlineNotice({
    required this.tone,
    required this.icon,
    required this.title,
    required this.body,
  });

  final ToneSurface tone;
  final IconData icon;
  final String title;
  final String? body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = toneSurface(tone, theme.brightness);

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.tile),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: colors.foreground),
          const SizedBox(width: Gap.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: colors.foreground,
                  ),
                ),
                if (body != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    body!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: colors.foreground,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
