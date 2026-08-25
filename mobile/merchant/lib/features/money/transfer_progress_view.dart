import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import 'money_providers.dart';

/// The first minute is read every [_fastEvery]; after that every
/// [_slowEvery]. A 15-minute window is therefore 12 + 56 = 68 reads, well
/// under the route's 120/min ceiling even with the panel open beside it.
const _fastEvery = Duration(seconds: 5);
const _slowEvery = Duration(seconds: 15);

/// How many reads run at the fast cadence — one minute's worth.
const _fastReads = 12;

/// Consecutive failed reads after which the screen stops asking. The
/// server keeps working regardless; the push + SMS still arrive.
const _giveUpAfter = 4;

/// What the screen is currently allowed to SAY. Derived from the server's
/// own answer — never from a timestamp, a state, or the mere existence of a
/// claim.
enum _Phase {
  /// Nothing has come back yet (or the answer was unreadable): the slip is
  /// with Manfaa and the screen says only that. No bar.
  unknown,

  /// The server is genuinely reading the bank right now — the ONE phase
  /// that may draw a progress bar.
  watching,

  /// Nobody is watching and nothing is decided: a person confirms it.
  awaitingTeam,

  settled,
  partial,
  credited,
  rejected,
}

/// ONE live view over a transfer the merchant has just told us about — a
/// SETTLEMENT payment or a WALLET TOP-UP — replacing the static "Manfaa is
/// verifying your transfer" both flows used to park on (owner, 2026-08-25).
///
/// THE HONESTY RULE THIS WIDGET EXISTS TO KEEP. A progress bar is drawn
/// ONLY while the server says `watching` — auto-verification on, the bank
/// the merchant paid into routed to a live read profile, and the window
/// still open. When it says otherwise the bar is not drawn at all and the
/// card says, plainly, that our team will confirm this shortly. The client
/// never infers a watch from a timestamp or from the row being pending, and
/// the bar's fill is real elapsed time on the SERVER's clock
/// (`checked_at − watch_started_at` over the whole window), not an
/// animation invented to look busy.
///
/// This screen OBSERVES. It starts nothing and it stops nothing: the poll
/// runs on the server whether or not the app is open, and the push + SMS on
/// a match (SettlementAccepted / wallet_top_up_received) fire either way.
/// Closing the screen costs the merchant nothing, and the card says so.
class TransferProgressView extends ConsumerStatefulWidget {
  /// The bank path of a settlement: [settlementId] is the BATCH's id — the
  /// route reports its newest payment, so a second slip on a partly paid
  /// batch is what this watches.
  const TransferProgressView.settlement({
    super.key,
    required int this.settlementId,
    required String this.reference,
    required this.amountLaari,
    this.discountLaari = 0,
    this.padding,
  }) : kind = MatchKind.settlementPayment,
       topUpId = null;

  /// A wallet top-up claim: [topUpId] is the claim's own id.
  const TransferProgressView.topUp({
    super.key,
    required int this.topUpId,
    required this.amountLaari,
    this.padding,
  }) : kind = MatchKind.walletTopUp,
       settlementId = null,
       reference = null,
       discountLaari = 0;

  final MatchKind kind;
  final int? settlementId;
  final int? topUpId;

  /// The batch reference — settlements only, and only for the copy.
  final String? reference;

  /// What the merchant said they transferred. Shown until the SERVER says
  /// what actually arrived.
  final int amountLaari;

  /// The prompt-payment discount this batch earned, if any.
  final int discountLaari;

  /// The screens differ in what sits under them (the top-up route lives
  /// under the floating nav bar), so each passes its own.
  final EdgeInsets? padding;

  @override
  ConsumerState<TransferProgressView> createState() =>
      _TransferProgressViewState();
}

class _TransferProgressViewState extends ConsumerState<TransferProgressView> {
  /// The server's last answer. Null until the first read lands — and the
  /// card says nothing about a watch until it does.
  MatchProgress? _progress;

  /// THE timer. Exactly one, never periodic (so two reads can never
  /// overlap), and cancelled in [dispose] — a leaked timer fails the whole
  /// flutter_test suite, not merely its own test.
  Timer? _poll;
  AppLifecycleListener? _lifecycle;

  var _reads = 0;
  var _failures = 0;

  /// No further reads, ever: decided, nobody watching, the window shut, or
  /// the answer is final and hopeless (a 401/403/404 — the row is not ours,
  /// or there is nothing to watch).
  var _stopped = false;

  /// [_giveUpAfter] reads in a row failed for a reason that is NOT an answer
  /// — no signal, a 429, a 5xx. Two things follow, and they belong together:
  /// the loop pauses, AND the card stops claiming a live watch. What is in
  /// [_progress] is a memory now, not an observation, so the bar and its
  /// countdown come off and the quiet copy takes over. Unlike [_stopped]
  /// this is recoverable: bringing the app back to the foreground clears it
  /// and asks again, because the server has very probably decided the
  /// transfer in the meantime.
  var _blind = false;
  var _inFlight = false;

  /// One read is allowed AFTER the window looks shut on the server's clock,
  /// so the outcome that landed in the last second is still seen.
  var _graceUsed = false;

  /// The money providers are refreshed once, when the outcome lands.
  var _refreshed = false;

  /// Polling is suspended while the app is not in the foreground: the
  /// server does not need us, so a backgrounded app must not hold a poll.
  var _foreground = true;

  @override
  void initState() {
    super.initState();
    _lifecycle = AppLifecycleListener(onStateChange: _onLifecycle);
    // Ask immediately — the answer may already be an outcome.
    unawaited(_read());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _poll = null;
    _lifecycle?.dispose();
    _lifecycle = null;
    super.dispose();
  }

  void _onLifecycle(AppLifecycleState state) {
    final foreground = state == AppLifecycleState.resumed;
    if (foreground == _foreground) return;
    _foreground = foreground;

    if (!foreground) {
      _poll?.cancel();
      _poll = null;
      return;
    }
    // Back on screen: catch up NOW rather than waiting out the interval.
    // A read already in flight reschedules itself when it lands.
    //
    // A run of failed reads is forgiven here. The phone that could not reach
    // us a minute ago is very likely on a different network now, and the
    // alternative — a screen that gave up for good while the server has
    // already decided the transfer — is the worse of the two.
    _failures = 0;
    if (_blind) setState(() => _blind = false);
    unawaited(_read());
  }

  Duration get _interval => _reads < _fastReads ? _fastEvery : _slowEvery;

  Future<void> _read() async {
    if (_stopped || _blind || _inFlight || !mounted) return;
    _inFlight = true;

    try {
      final api = ref.read(apiProvider);
      final progress = widget.kind == MatchKind.settlementPayment
          ? await api.settlementPaymentProgress(widget.settlementId!)
          : await api.walletTopUpProgress(widget.topUpId!);
      if (!mounted) return;

      _failures = 0;
      _reads++;
      setState(() => _progress = progress);
      if (progress.isDecided) _onDecided();
    } on MobileApiException catch (e) {
      if (!mounted) return;
      // An ANSWER, not a failure: the row is not ours, or there is nothing
      // to watch. Asking again cannot change it.
      if (e.code == ApiCode.notFound ||
          e.code == ApiCode.forbidden ||
          e.code == ApiCode.permissionRequired ||
          e.code == ApiCode.unauthenticated) {
        _reads++;
        _stopped = true;
        return;
      }
      _failed();
    } catch (_) {
      if (!mounted) return;
      // A response this build could not read at all. It counts exactly like
      // a failed read — otherwise the loop would spin forever on a payload
      // it can never use, with the card frozen on "verifying".
      _failed();
    } finally {
      _inFlight = false;
      if (mounted) _schedule();
    }
  }

  /// One read that produced no answer. NOTHING visible changes for the first
  /// few: the card keeps saying what it last honestly knew rather than
  /// flashing an error over a transfer that is perfectly fine. Past
  /// [_giveUpAfter] in a row the claim itself goes — see [_blind].
  void _failed() {
    _reads++;
    _failures++;
    if (_failures >= _giveUpAfter && !_blind) {
      setState(() => _blind = true);
    }
  }

  /// The next read, or none. Every stop condition lives here.
  void _schedule() {
    _poll?.cancel();
    _poll = null;
    if (_stopped || _blind || !_foreground) return;

    final progress = _progress;
    if (progress != null) {
      // Decided, or nobody is watching (auto-verify off, an unrouted bank,
      // a lapsed window): asking again changes nothing a person must do.
      if (!progress.isWatching) {
        _stopped = true;
        return;
      }
      // Past `watch_until` on the SERVER's clock — one grace read, then stop.
      if (progress.watchUntil != null &&
          progress.watchRemaining == Duration.zero) {
        if (_graceUsed) {
          _stopped = true;
          return;
        }
        _graceUsed = true;
      }
    }

    _poll = Timer(_interval, _read);
  }

  /// The money moved (or was refused): the wallet balance, the outstanding
  /// board, the batch history and the batch itself are all stale now, so
  /// they are refreshed BEFORE the merchant navigates back to them.
  void _onDecided() {
    if (_refreshed) return;
    _refreshed = true;

    invalidateMoney(ref);
    final id = widget.settlementId;
    if (id != null) ref.invalidate(settlementDetailProvider(id));
  }

  // ---------------------------------------------------------------- reading

  _Phase get _phase {
    final progress = _progress;

    // We have stopped asking. Whatever the last payload said about a watch,
    // this screen is no longer observing one — so it says the true thing
    // instead: a person confirms it, and the push and SMS arrive either way.
    if (_blind && progress?.outcome == null) return _Phase.awaitingTeam;

    if (progress == null) return _Phase.unknown;

    final outcome = progress.outcome;
    if (outcome != null) {
      return switch (outcome.result) {
        MatchResult.settled => _Phase.settled,
        MatchResult.partiallySettled => _Phase.partial,
        MatchResult.credited => _Phase.credited,
        MatchResult.rejected => _Phase.rejected,
        // A verdict this build does not know: say nothing about it rather
        // than guess a wrong one.
        MatchResult.unknown => _Phase.unknown,
      };
    }

    return progress.isWatching ? _Phase.watching : _Phase.awaitingTeam;
  }

  /// How much of the watch window has actually elapsed, on the server's own
  /// clock. Null when it cannot be computed honestly — and then no bar is
  /// drawn at all.
  double? get _fraction {
    final progress = _progress;
    if (progress == null || !progress.isWatching) return null;

    final started = progress.watchStartedAt;
    final until = progress.watchUntil;
    final checked = progress.checkedAt;
    if (started == null || until == null || checked == null) return null;

    final total = until.difference(started).inMilliseconds;
    if (total <= 0) return null;

    return (checked.difference(started).inMilliseconds / total).clamp(0.0, 1.0);
  }

  // --------------------------------------------------------------- painting

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final progress = _progress;
    final outcome = progress?.outcome;
    final phase = _phase;
    final settlement = widget.kind == MatchKind.settlementPayment;

    String money(int laari) => formatMoney(laari, dhivehi: dhivehi);

    final (IconData icon, ManfaaTint tint, String title) = switch (phase) {
      _Phase.unknown => (
        Icons.verified_user_outlined,
        ManfaaTint.green,
        l10n.successVerifyingTitle,
      ),
      _Phase.watching => (
        Icons.account_balance_rounded,
        ManfaaTint.blue,
        l10n.successVerifyingTitle,
      ),
      _Phase.awaitingTeam => (
        Icons.schedule_rounded,
        ManfaaTint.amber,
        l10n.transferTeamTitle,
      ),
      _Phase.settled => (
        Icons.check_circle_outline_rounded,
        ManfaaTint.green,
        l10n.transferSettledTitle,
      ),
      _Phase.partial => (
        Icons.pie_chart_outline_rounded,
        ManfaaTint.amber,
        l10n.transferPartialTitle,
      ),
      _Phase.credited => (
        Icons.account_balance_wallet_outlined,
        ManfaaTint.green,
        l10n.transferCreditedTitle(
          money(outcome?.creditedLaari ?? widget.amountLaari),
        ),
      ),
      _Phase.rejected => (
        Icons.report_gmailerrorred_rounded,
        ManfaaTint.coral,
        l10n.transferRejectedTitle,
      ),
    };

    final body = switch (phase) {
      _Phase.unknown => settlement
          ? l10n.successVerifyingBody(widget.reference ?? '')
          : l10n.topUpSuccessBody(money(widget.amountLaari)),
      // The SERVER's own copy of what the merchant said they transferred,
      // once it has answered; the screen's own figure only until then.
      _Phase.watching => l10n.transferWatchingBody(
        money(progress?.amountLaari ?? widget.amountLaari),
      ),
      _Phase.awaitingTeam =>
        progress?.reason == MatchWatchReason.windowExpired
            ? l10n.transferTeamExpiredBody
            : l10n.transferTeamBody,
      _Phase.settled => l10n.transferSettledBody(
        money(outcome?.amountReceivedLaari ?? widget.amountLaari),
        widget.reference ?? '',
      ),
      _Phase.partial => l10n.transferPartialBody(
        money(outcome?.amountReceivedLaari ?? 0),
        money(outcome?.amountOutstandingLaari ?? 0),
      ),
      _Phase.credited => l10n.transferBalanceNow(
        money(outcome?.balanceLaari ?? 0),
      ),
      // A refused settlement receipt does not leave the batch waiting for a
      // better slip: SettlementBuilder::reject CANCELS it and releases its
      // transactions, so "try again" would be an instruction the merchant
      // cannot follow. A top-up claim really can be sent again.
      _Phase.rejected => settlement
          ? l10n.transferSettlementRejectedBody(
              outcome?.reference ?? widget.reference ?? '',
            )
          : l10n.transferRejectedBody,
    };

    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListView(
      padding: widget.padding ?? const EdgeInsets.all(Gap.xl),
      children: [
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  IconTile(icon, tint: tint, size: 48, iconSize: 24),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Text(title, style: theme.textTheme.titleMedium),
                  ),
                ],
              ),
              const SizedBox(height: Gap.md),
              Text(body, style: muted),

              // The ONE place a bar may be drawn, and only with a fraction
              // the server's own stamps produced.
              if (phase == _Phase.watching) ...[
                const SizedBox(height: Gap.lg),
                _WatchBar(fraction: _fraction),
                if (progress != null &&
                    progress.watchRemaining > Duration.zero) ...[
                  const SizedBox(height: Gap.sm),
                  Text(
                    _remaining(l10n, progress.watchRemaining),
                    style: muted,
                  ),
                ],
              ],

              // Why it was refused, in the words of whoever refused it.
              if (phase == _Phase.rejected) ...[
                const SizedBox(height: Gap.sm),
                Text(
                  (outcome?.rejectedReason ?? '').trim().isEmpty
                      ? l10n.statusRejectedNoReason
                      : l10n.topUpRejectedReason(outcome!.rejectedReason!),
                  style: muted,
                ),
              ],

              // Nothing is decided yet: closing this screen costs nothing.
              // Only the WATCHED phase may say the check keeps running —
              // when nobody is watching, saying that would be the same lie
              // as the bar.
              if (phase == _Phase.unknown ||
                  phase == _Phase.watching ||
                  phase == _Phase.awaitingTeam) ...[
                const SizedBox(height: Gap.md),
                Text(
                  phase == _Phase.watching
                      ? l10n.transferCloseHint
                      : l10n.transferCloseHintTeam,
                  style: muted,
                ),
              ],

              if (widget.discountLaari > 0 && phase != _Phase.rejected) ...[
                const SizedBox(height: Gap.md),
                Text(
                  l10n.discountSavedNote(money(widget.discountLaari)),
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: theme.colorScheme.secondary,
                  ),
                ),
              ],

              const SizedBox(height: Gap.lg),
              _actions(context, l10n),
            ],
          ),
        ),
      ],
    );
  }

  String _remaining(AppLocalizations l10n, Duration left) => left.inSeconds < 60
      ? l10n.transferWatchLeftShort
      : l10n.transferWatchLeft((left.inSeconds / 60).ceil());

  /// The CTAs each flow already shipped, unchanged: a settlement offers its
  /// batch, a top-up simply closes back onto the wallet.
  Widget _actions(BuildContext context, AppLocalizations l10n) {
    final id = widget.settlementId;
    if (id == null) {
      return FilledButton(
        onPressed: () => context.canPop() ? context.pop() : context.go('/wallet'),
        child: Text(l10n.doneCta),
      );
    }

    return Row(
      children: [
        Expanded(
          child: FilledButton(
            onPressed: () {
              Navigator.of(context).pop();
              context.push('/settlements/$id');
            },
            child: Text(l10n.viewSettlementCta),
          ),
        ),
        const SizedBox(width: Gap.sm),
        Expanded(
          child: OutlinedButton(
            onPressed: () => Navigator.of(context).pop(),
            child: Text(l10n.doneCta),
          ),
        ),
      ],
    );
  }
}

/// The bar itself. [fraction] is elapsed watch time on the server's clock;
/// null means it could not be computed, and then the bar is a flat rail
/// rather than an animation pretending to know something.
class _WatchBar extends StatelessWidget {
  const _WatchBar({required this.fraction});

  final double? fraction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final track = theme.colorScheme.onSurface.withValues(alpha: 0.08);

    if (fraction == null) {
      return Container(
        height: 6,
        decoration: BoxDecoration(
          color: track,
          borderRadius: BorderRadius.circular(3),
        ),
      );
    }

    // The value moves only when a READ moves it; the tween merely stops it
    // jumping. Never indeterminate — an indeterminate bar is the animation
    // over nothing this whole round exists to remove.
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: fraction),
      duration: const Duration(milliseconds: 600),
      curve: Curves.easeOut,
      builder: (context, value, _) => LinearProgressIndicator(
        value: value,
        minHeight: 6,
        backgroundColor: track,
        borderRadius: BorderRadius.circular(3),
      ),
    );
  }
}
