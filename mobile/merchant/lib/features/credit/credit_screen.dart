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
import '../fee_promotion/fee_promotion_banner.dart';
import '../money/money_providers.dart';
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

/// The till's ONE action, drawn twice (MR11): beside the heading and in the
/// flow under the pending banner. Keyed so a test can hold both to the same
/// enabled state.
const kCreditCtaTopKey = Key('credit-cta-top');
const kCreditCtaFlowKey = Key('credit-cta-flow');

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
  final _eligibleFocus = FocusNode();
  final _sale = TextEditingController();
  final _saleFocus = FocusNode();

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

  /// The COMPLETE rows the editor reported, plus its all-rows-valid flag.
  /// MR8: while the split is ON these rows ARE the eligible amount — the
  /// field is hidden and the sum is computed in the background, so the old
  /// sum-mismatch refusal is impossible from this UI.
  var _splitRows = <SplitRow>[];
  var _splitComplete = false;

  /// Bumped whenever the split must start over (toggle, reset) — the
  /// editor owns its draft controllers, so a fresh key is the reset.
  var _splitEpoch = 0;

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
    // This screen QUOTES the promotional fee, so it has to notice a
    // campaign ending — but it is not a money screen and must not drag the
    // whole board's refetches along with it.
    Future.microtask(() => refreshStaleFeePromotion(ref));
    _codeFocus.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _code.dispose();
    _codeFocus.dispose();
    _invoice.dispose();
    _invoiceFocus.dispose();
    _eligible.dispose();
    _eligibleFocus.dispose();
    _sale.dispose();
    _saleFocus.dispose();
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
  /// The keyboard's return key WALKS the form; it never finalises.
  ///
  /// It used to submit a complete form (MR7, for a barcode gun's Enter). On a
  /// touch keyboard the return key is the tick in the corner — exactly where a
  /// thumb goes to dismiss the keyboard — and cashiers were crediting
  /// customers by accident, mid-entry, before the amounts were right (owner
  /// report 2026-08-20). Crediting is money leaving; it now takes the
  /// deliberate "Credit now" press and nothing else.
  ///
  /// The gun still walks the till hands-free: code -> invoice -> eligible ->
  /// full sale. Only the last step changed, and it is the step that spends.
  void _advance(FocusNode? next) {
    if (next == null) {
      FocusManager.instance.primaryFocus?.unfocus();
      return;
    }
    next.requestFocus();
  }

  // ------------------------------------------------------------ compose

  int get _splitSum =>
      _splitRows.fold<int>(0, (acc, row) => acc + row.amountLaari);

  /// The eligible amount as it will go to the wire: the typed field, or —
  /// split ON — the lines' sum (null until every row is complete, so a
  /// half-built split can never submit a partial amount).
  int? get _eligibleLaari => _splitEnabled
      ? (_splitComplete && _splitRows.isNotEmpty ? _splitSum : null)
      : (_eligible.text.trim().isEmpty
            ? null
            : parseMvrToLaari(_eligible.text));

  bool get _eligibleInvalid =>
      !_splitEnabled &&
      _eligible.text.trim().isNotEmpty &&
      (parseMvrToLaari(_eligible.text) == null ||
          parseMvrToLaari(_eligible.text)! < 1);

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
    _splitComplete = false;
    _splitEpoch++;
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
    // GET /merchant/rate quotes the §4 TIER fee — the server keeps
    // promotions out of the standing-terms endpoint on purpose — so the
    // quote is priced at min(promotion, tier) here, exactly as
    // TermsResolver will a second later. "Nothing running" (and a failed
    // read) leaves every figure below byte-identical to before.
    final promotion = ref.watch(activeFeePromotionProvider);
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
      // Directly above the cost preview whose fee row it explains: the
      // preview now quotes min(promotion, tier) — the server's own rule —
      // and this is what stops that figure looking like a mistake.
      const FeePromotionBanner(bottomGap: Gap.md),
      if (_splitEnabled) ...[
        SplitEditorCard(
          key: ValueKey('split-$_splitEpoch'),
          categories: activeCategories,
          onChanged: (rows, complete) => setState(() {
            _splitRows = rows;
            _splitComplete = complete;
            _error = null;
          }),
        ),
        const SizedBox(height: Gap.md),
      ],
      _buildPreviewCard(l10n, theme, rateAsync, activeCategories, promotion),
      if (_backdatedWarning) ...[
        const SizedBox(height: Gap.md),
        _buildBackdatedNotice(l10n, theme),
      ],
      if (_error != null) ...[
        const SizedBox(height: Gap.md),
        _buildErrorNotice(l10n, theme, _error!),
      ],
      const SizedBox(height: Gap.sm),
      const PendingNote(),
      // MR11 (owner report): the CTA lives IN the flow, directly under the
      // violet pending banner, on every width. The pinned bar it replaces
      // rendered under the shell's floating nav (extendBody) and was partly
      // unreachable; the heading's compact twin is what keeps the action
      // within reach without covering anything.
      const SizedBox(height: Gap.md),
      _buildCta(l10n),
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
            final list = ContentRail(
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
                  // The heading carries the action at its trailing edge —
                  // the SAME button as the one in the flow below (one
                  // enabled state, one submit path), compact so it reads as
                  // the heading's affordance rather than a second CTA.
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(
                        child: Text(
                          l10n.creditTitle,
                          style: theme.textTheme.headlineMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      const SizedBox(width: Gap.sm),
                      _buildCompactCta(l10n, theme),
                    ],
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

            // Nothing is pinned any more: the list's own bottom padding is
            // [bottomClearanceOf], so the flow CTA scrolls clear of the
            // shell's floating nav instead of sitting under it.
            return list;
          },
        ),
      ),
    );
  }

  /// The one button that ends the sale — the heading's compact twin, sized
  /// to sit beside a headline rather than under the form. Same [_submittable]
  /// gate, same [_submit]: one action drawn twice, never two.
  Widget _buildCompactCta(AppLocalizations l10n, ThemeData theme) =>
      FilledButton(
        key: kCreditCtaTopKey,
        onPressed: _submittable ? _submit : null,
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 40),
          padding: const EdgeInsets.symmetric(horizontal: Gap.md),
          textStyle: theme.textTheme.labelLarge,
        ),
        child: _busy
            ? const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.receipt_long_outlined, size: 18),
                  const SizedBox(width: Gap.xs),
                  Text(l10n.creditCtaShort),
                ],
              ),
      );

  /// The one button that ends the sale.
  Widget _buildCta(AppLocalizations l10n) => FilledButton(
    key: kCreditCtaFlowKey,
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
  );

  bool get _submittable {
    final overrideBroken =
        _overrideOpen &&
        _override.text.trim().isNotEmpty &&
        _activeOverrideBp == null;
    final overrideHalf = _overrideOpen && _override.text.trim().isEmpty;

    return _lookup?.phase == _LookupPhase.found &&
        _invoice.text.trim().isNotEmpty &&
        // Split ON: _eligibleLaari IS the lines' sum and stays null until
        // every row is complete — the sum-mismatch state cannot exist.
        _eligibleLaari != null &&
        _eligibleLaari! >= 1 &&
        !_saleInvalid &&
        // A half-typed custom rate must never silently fall back to the
        // standing one: fix it or close the control (web parity).
        !overrideBroken &&
        !overrideHalf &&
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
    // MR10 (owner report): the till's own field metrics. The house 15pt
    // vertical padding suits a settings form; a counter wants the same
    // touch target with less air, so this ONE card runs denser — still
    // comfortably above the 44dp minimum tap target.
    return Theme(
      data: theme.copyWith(
        inputDecorationTheme: theme.inputDecorationTheme.copyWith(
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 14,
            vertical: 11,
          ),
        ),
      ),
      child: ManfaaCard(
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
              textInputAction: TextInputAction.next,
              onChanged: (_) => setState(() => _error = null),
              onSubmitted: (_) =>
                  _advance(_splitEnabled ? _saleFocus : _eligibleFocus),
              decoration: const InputDecoration(
                hintText: 'INV-1001',
                counterText: '',
              ),
            ),
            // MR10 (owner report): the sale time is "now" for virtually every
            // credit, so it costs ONE quiet line instead of a label, a
            // full-height field and a sentence. Backdating is a deliberate
            // detour: tap the time to open the picker, tap Now to come back.
            const SizedBox(height: Gap.md),
            _SaleTimeRow(
              occurredAt: _occurredAt,
              edited: _occurredEdited,
              onPick: _pickOccurredAt,
              onNow: () => setState(() {
                _occurredAt = creditClock();
                _occurredEdited = false;
                _backdatedConfirmed = false;
                _serverRequiresBackdated = false;
              }),
            ),
            // MR8 (owner report): with the split ON the eligible amount IS
            // the lines' sum — the field disappears so the two can never
            // contradict ("doesn't add up"). The full-sale field below stays.
            if (!_splitEnabled) ...[
              const SizedBox(height: Gap.lg),
              _FieldLabel(
                icon: Icons.payments_outlined,
                label: l10n.eligibleLabel,
              ),
              const SizedBox(height: Gap.sm),
              _MvrField(
                controller: _eligible,
                focusNode: _eligibleFocus,
                invalid: _eligibleInvalid,
                textInputAction: TextInputAction.next,
                onChanged: (_) => setState(() => _error = null),
                onSubmitted: (_) => _advance(_saleFocus),
              ),
              if (_eligibleInvalid) ...[
                const SizedBox(height: 6),
                Text(
                  l10n.eligibleInvalid,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
            ],
            const SizedBox(height: Gap.lg),
            _FieldLabel(
              icon: Icons.request_quote_outlined,
              label: l10n.fullSaleLabel,
              suffix: '(${l10n.optionalLabel})',
            ),
            const SizedBox(height: Gap.sm),
            _MvrField(
              controller: _sale,
              focusNode: _saleFocus,
              invalid: _saleInvalid,
              onChanged: (_) => setState(() => _error = null),
              onSubmitted: (_) => _advance(null),
            ),
            if (_saleInvalid) ...[
              const SizedBox(height: 6),
              Text(
                l10n.fullSaleInvalid,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
            // PLAN §1 per-sale override — rendered only for roles holding
            // credits.custom_rate; the server refuses the FIELD regardless.
            if (_canSetCustomRate) ...[
              const SizedBox(height: Gap.lg),
              _ToggleRow(
                value: _overrideOpen,
                title: l10n.customRateToggle,
                subtitle: null,
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
                subtitle: null,
                onChanged: (value) => setState(() {
                  _splitEnabled = value;
                  // Either direction starts the split over: rows compose
                  // against the CURRENT sale, never a previous one's.
                  _splitRows = [];
                  _splitComplete = false;
                  _splitEpoch++;
                  _error = null;
                }),
              ),
            ],
          ],
        ),
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
    MerchantFeePromotion promotion,
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
        body = _buildPreviewRows(
          l10n,
          theme,
          current,
          rateAsync.valueOrNull?.tax ?? const MerchantTaxTerms(),
          active,
          promotion,
        );
      }
    }

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(l10n.costPreviewTitle, style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.sm),
          body,
        ],
      ),
    );
  }

  Widget _buildPreviewRows(
    AppLocalizations l10n,
    ThemeData theme,
    RateWindow current,
    MerchantTaxTerms tax,
    List<ProductCategory> active,
    MerchantFeePromotion promotion,
  ) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final eligible = _eligibleInvalid ? null : _eligibleLaari;

    final standingBp = parsePercentToBp(current.cashbackRatePercent);
    final standingFeeBp = current.platformFeePercent == null
        ? null
        : parsePercentToBp(current.platformFeePercent!);
    final overrideBp = _activeOverrideBp;
    final baseRateBp = overrideBp ?? standingBp;
    // The §4 tier fee this sale would carry with no promotion running —
    // the standing one, or the band a per-sale override falls into.
    final tierFeeBp = overrideBp != null
        ? staticFeeBp(overrideBp)
        : standingFeeBp;
    // TermsResolver::priceAt(): charged fee bp = min(promotion, tier). The
    // MERCHANT WINS — a store already on a cheaper tier keeps it — and an
    // unpriced rate (null) stays unpriced rather than gaining a fee.
    final baseFeeBp = promotion.chargedFeeBp(tierFeeBp);

    int? cashback;
    int? fee;
    String rateLabel;
    String feeLabel;
    String youPayLabel;

    // The GST terms a sale recorded RIGHT NOW would be stamped with. The
    // quote has no row to read a stamp off — it prices what is ABOUT to
    // happen — so it prices from the live policy, exactly as the server
    // will a second later. "0.00" (today) is the identity under both
    // treatments and leaves every figure below unchanged.
    final gstRateBp = parsePercentToBp(tax.gstRatePercent) ?? 0;
    int? gst;

    final splitActive = _splitEnabled && _splitRows.isNotEmpty;
    if (splitActive) {
      final estimate = estimateSplit(
        _splitRows,
        active,
        baseRateBp: baseRateBp,
        // The TIER fee, not the promoted one: a category rate gives a line
        // its own tier fee, so the min() has to be taken PER LINE — inside
        // estimateSplit — exactly as the server prices each priced unit.
        baseFeeBp: tierFeeBp,
        tax: tax,
        promotion: promotion,
      );
      cashback = estimate?.cashback;
      // Already split per line by estimateSplit — `fee` is gross under
      // `inclusive`, net under `on_top`, exactly as the rows below print it.
      fee = estimate?.fee;
      gst = estimate?.gst;
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
      // The all-in percentage covers cashback + fee. Under `on_top` the
      // bill also carries the tax, which no single rate on this screen
      // describes — so the suffix goes rather than label a total it no
      // longer adds up to.
      youPayLabel = gstRateBp > 0 && !tax.inclusive
          ? l10n.resultYouPay
          : l10n.previewYouPay(
              baseRateBp != null && baseFeeBp != null
                  ? formatBp(baseRateBp + baseFeeBp)
                  : '—',
            );
    }

    // The quoted fee is GROSS under both treatments — `fee_bp` × the sale,
    // which is what the rate beside it says. `on_top` ADDS the tax (the
    // merchant owes more); `inclusive` carves it out (the merchant owes the
    // same, and the split only says how much of the fee was tax). "You pay"
    // is cashback + fee + GST either way, matching the recorded result card
    // laari for laari. A LINED sale was already split per line above.
    if (!splitActive && fee != null) {
      final (net, lineGst) = tax.split(fee, gstRateBp);
      gst = lineGst;
      // Printed gross under `inclusive`; the tax below is a component of it.
      fee = tax.inclusive ? net + lineGst : net;
    }

    Widget row(String label, int? laari, {bool strong = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
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
        // Absent, not MVR 0.00, while the platform charges no tax — the
        // same rule every other surface follows.
        if (gst != null && gst > 0)
          row(
            tax.inclusive
                ? l10n.previewGstIncluded(trimRatePercent(tax.gstRatePercent))
                : l10n.previewGst(trimRatePercent(tax.gstRatePercent)),
            gst,
          ),
        Divider(height: Gap.md, color: theme.colorScheme.outlineVariant),
        row(
          youPayLabel,
          cashback != null && fee != null
              ? cashback + (tax.inclusive ? fee : fee + (gst ?? 0))
              : null,
          strong: true,
        ),
        const SizedBox(height: 6),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.info_outline_rounded, size: 13, color: muted),
            const SizedBox(width: 5),
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
    this.focusNode,
    this.onSubmitted,
    this.textInputAction = TextInputAction.done,
  });

  final TextEditingController controller;
  final bool invalid;
  final ValueChanged<String> onChanged;
  final FocusNode? focusNode;
  final ValueChanged<String>? onSubmitted;
  final TextInputAction textInputAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return TextField(
      controller: controller,
      focusNode: focusNode,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      textDirection: TextDirection.ltr,
      textInputAction: textInputAction,
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
    this.subtitle,
    required this.onChanged,
  });

  final bool value;
  final String title;
  final String? subtitle;
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
              if (subtitle != null) ...[
                const SizedBox(height: 2),
                Text(
                  subtitle!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
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

/// The sale time as ONE line (MR10 owner report): a credit is "now" almost
/// every time, so the default costs a single quiet row instead of a label,
/// a full-height field and a sentence. The whole row opens the picker;
/// once edited it turns violet and offers "Now" to undo — backdating stays
/// possible, it just stops charging every sale for the privilege.
class _SaleTimeRow extends StatelessWidget {
  const _SaleTimeRow({
    required this.occurredAt,
    required this.edited,
    required this.onPick,
    required this.onNow,
  });

  final DateTime occurredAt;
  final bool edited;
  final VoidCallback onPick;
  final VoidCallback onNow;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final muted = theme.colorScheme.onSurfaceVariant;
    final accent = theme.colorScheme.secondary;

    return Row(
      children: [
        Expanded(
          child: InkWell(
            onTap: onPick,
            borderRadius: BorderRadius.circular(Corner.tile),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: [
                  Icon(
                    Icons.schedule_rounded,
                    size: 16,
                    color: edited ? accent : muted,
                  ),
                  const SizedBox(width: Gap.sm),
                  Text(
                    l10n.saleTimeLabel,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: Text(
                      edited
                          ? formatDateTimeDisplay(occurredAt)
                          : l10n.saleTimeNow,
                      textDirection: edited ? TextDirection.ltr : null,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: edited ? accent : theme.colorScheme.onSurface,
                      ),
                    ),
                  ),
                  if (!edited)
                    Icon(Icons.edit_calendar_outlined, size: 18, color: muted),
                ],
              ),
            ),
          ),
        ),
        if (edited)
          TextButton(
            onPressed: onNow,
            style: TextButton.styleFrom(
              visualDensity: VisualDensity.compact,
              padding: const EdgeInsets.symmetric(horizontal: Gap.sm),
              minimumSize: const Size(0, 32),
            ),
            child: Text(l10n.saleTimeSetNow),
          ),
      ],
    );
  }
}
