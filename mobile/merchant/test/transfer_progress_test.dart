import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/money/money_providers.dart';
import 'package:manfaa_merchant/features/money/transfer_progress_view.dart';
import 'package:manfaa_merchant/l10n/gen/app_localizations.dart';

/// The live transfer view (owner, 2026-08-25). What is under test is the
/// HONESTY RULE first and the mechanics second:
///
///  - a determinate bar is drawn ONLY while the server says `watching`,
///    and its fill is real elapsed time on the SERVER's clock;
///  - every not-watching reason draws NO bar and says a person will
///    confirm it shortly;
///  - the real outcome replaces both — settled, the honest remainder,
///    the credited amount with the balance NOW, or the refusal's reason;
///  - the poll stops dead at a terminal answer and at a shut window, backs
///    off after the first minute, suspends while backgrounded, and is
///    CANCELLED on dispose (a leaked timer fails the whole suite).
void main() {
  // The fixture window: 10:00 → 10:15, read at 10:03. One fifth elapsed,
  // twelve minutes left — figures a device clock could never invent, which
  // is the point of counting against `checked_at`.
  const started = '2026-08-25T10:00:00+05:00';
  const until = '2026-08-25T10:15:00+05:00';
  const checked = '2026-08-25T10:03:00+05:00';

  Map<String, dynamic> watching({
    String kind = 'settlement_payment',
    int id = 91,
    int? settlementId = 45,
    int amountLaari = 2712,
    int attempts = 3,
    String checkedAt = checked,
  }) => {
    'kind': kind,
    'id': id,
    'settlement_id': settlementId,
    'state': 'pending',
    'amount_laari': amountLaari,
    'amount_mvr': '27.12',
    'watching': true,
    'reason': null,
    'watch_started_at': started,
    'watch_until': until,
    'attempts': attempts,
    'auto_matched': false,
    'decided_at': null,
    'checked_at': checkedAt,
    'outcome': null,
  };

  Map<String, dynamic> notWatching(String reason, {String kind = 'settlement_payment'}) => {
    ...watching(kind: kind),
    'watching': false,
    'reason': reason,
    'watch_started_at': reason == 'window_expired' ? started : null,
    'watch_until': reason == 'window_expired' ? until : null,
    'attempts': reason == 'window_expired' ? 12 : 0,
  };

  /// THIS payment's claim and the bank's fact (owner, 2026-08-25).
  /// `claimed` is what the merchant typed; `bankSent` is what the credit
  /// really was, and null stands for a payment matched by hand off a
  /// statement with no figure recorded.
  Map<String, dynamic> claimAndFact({
    required int claimed,
    required int? bankSent,
  }) => {
    'claimed_laari': claimed,
    'claimed_mvr': '27.12',
    'received_laari': bankSent,
    'received_mvr': bankSent == null ? null : '27.12',
    'amount_differs': bankSent != null && bankSent != claimed,
  };

  Map<String, dynamic> settlementOutcome(
    String result, {
    int received = 2712,
    int outstanding = 0,
    int claimed = 2712,
    int? bankSent = 2712,
    String? rejected,
  }) => {
    ...watching(amountLaari: claimed),
    'state': result == 'rejected' ? 'rejected' : 'matched',
    'watching': false,
    'reason': 'terminal',
    'auto_matched': result != 'rejected',
    'decided_at': checked,
    'outcome': {
      'result': result,
      ...claimAndFact(claimed: claimed, bankSent: bankSent),
      // A refused receipt always cancels the batch and releases its lines
      // (SettlementBuilder::reject is the only writer of `rejected`), so
      // `cancelled` is the only settlement_state the server ever pairs with
      // a rejection.
      'settlement_state': result == 'rejected' ? 'cancelled' : result,
      'reference': 'ST-2026-00045',
      'amount_received_laari': received,
      'amount_received_mvr': '27.12',
      'amount_outstanding_laari': outstanding,
      'amount_outstanding_mvr': '0.00',
      'rejected_reason': rejected,
    },
  };

  Map<String, dynamic> topUpOutcome(
    String result, {
    int credited = 50000,
    int balance = 58175,
    int claimed = 50000,
    int? bankSent = 50000,
    String? rejected,
  }) => {
    ...watching(
      kind: 'wallet_top_up',
      id: 12,
      settlementId: null,
      amountLaari: claimed,
    ),
    'state': result == 'rejected' ? 'rejected' : 'matched',
    'watching': false,
    'reason': 'terminal',
    'auto_matched': result != 'rejected',
    'decided_at': checked,
    'outcome': {
      'result': result,
      // WHAT WENT IN: the bank's figure on a matched claim, never the claim.
      'credited_laari': result == 'rejected' ? 0 : credited,
      'credited_mvr': '500.00',
      ...claimAndFact(
        claimed: claimed,
        // A refused claim credited nothing, so no bank figure exists.
        bankSent: result == 'rejected' ? null : bankSent,
      ),
      'balance_laari': balance,
      'balance_mvr': '581.75',
      'rejected_reason': rejected,
    },
  };

  /// Pump the view alone over a scripted API. The screens' own wiring is
  /// covered by money_test/wallet_test; what matters here is the loop.
  Future<_ProgressApi> pump(
    WidgetTester tester,
    List<Map<String, dynamic>> script, {
    bool settlement = true,
    bool watchWallet = false,
  }) async {
    await tester.binding.setSurfaceSize(const Size(420, 900));
    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    final api = _ProgressApi(session: session, script: script);

    final view = settlement
        ? const TransferProgressView.settlement(
            settlementId: 45,
            reference: 'ST-2026-00045',
            amountLaari: 2712,
          )
        : const TransferProgressView.topUp(topUpId: 12, amountLaari: 50000);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          apiProvider.overrideWith((ref) => api),
        ],
        child: MaterialApp(
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: Scaffold(
            body: watchWallet
                // Only to prove the balance is REFETCHED when the outcome
                // lands, so the wallet screen behind is right on return.
                ? Consumer(
                    builder: (context, ref, _) {
                      ref.watch(walletProvider);
                      return view;
                    },
                  )
                : view,
          ),
        ),
      ),
    );
    // The read fired from initState, and the bar's tween settled.
    await tester.pumpAndSettle();
    return api;
  }

  /// Advance the fake clock and let the scripted answer land.
  Future<void> tick(WidgetTester tester, Duration d) async {
    await tester.pump(d);
    await tester.pumpAndSettle();
  }

  /// formatMoney puts a NON-BREAKING space after MVR; typing a plain one
  /// in an expectation would silently never match.
  String mvr(int laari) => formatMoney(laari, dhivehi: false);

  double? barValue(WidgetTester tester) =>
      tester.widget<LinearProgressIndicator>(
        find.byType(LinearProgressIndicator),
      ).value;

  testWidgets('a watched transfer draws a REAL bar — server elapsed time, '
      'server countdown — and says the screen may be closed', (tester) async {
    final api = await pump(tester, [watching()]);

    expect(api.calls, 1);
    expect(find.text('Manfaa is verifying your transfer'), findsOneWidget);
    // One fifth of 10:00→10:15 has elapsed at 10:03. Never an
    // indeterminate spinner: `value` is a number, and it is THAT number.
    expect(barValue(tester), closeTo(0.2, 0.001));
    expect(find.text('About 12 minutes left'), findsOneWidget);
    expect(
      find.textContaining('You can close this screen'),
      findsOneWidget,
    );
    // attempts == 0 would be the ordinary first second; nothing about the
    // attempt count is ever put in front of the merchant.
    expect(find.textContaining('attempt'), findsNothing);
  });

  testWidgets('a settled outcome replaces the bar, names the reference and '
      'the amount RECEIVED, and stops the poll dead', (tester) async {
    final api = await pump(tester, [
      watching(),
      settlementOutcome('settled'),
    ]);

    await tick(tester, const Duration(seconds: 5));
    expect(api.calls, 2);

    expect(find.text('Settled — your transfer matched'), findsOneWidget);
    expect(
      find.textContaining(
        'We received ${mvr(2712)} for settlement ST-2026-00045',
      ),
      findsOneWidget,
    );
    expect(find.byType(LinearProgressIndicator), findsNothing);
    // Decided: no reassurance about waiting, and no further reads ever.
    expect(find.textContaining('You can close this screen'), findsNothing);
    await tick(tester, const Duration(minutes: 2));
    expect(api.calls, 2);
  });

  testWidgets('a partial match reports the REMAINDER and congratulates '
      'nobody', (tester) async {
    await pump(tester, [
      watching(),
      settlementOutcome('partially_settled', received: 2000, outstanding: 712),
    ]);
    await tick(tester, const Duration(seconds: 5));

    expect(find.text('Part of this settlement is paid'), findsOneWidget);
    expect(
      find.textContaining(
        'We received ${mvr(2000)}. ${mvr(712)} is still owed',
      ),
      findsOneWidget,
    );
    expect(find.text('Settled — your transfer matched'), findsNothing);
  });

  testWidgets('a credited top-up says the amount added AND the balance now, '
      'and refreshes the wallet behind it', (tester) async {
    final api = await pump(
      tester,
      [watching(kind: 'wallet_top_up', id: 12, settlementId: null), topUpOutcome('credited')],
      settlement: false,
      watchWallet: true,
    );
    expect(api.walletReads, 1);

    await tick(tester, const Duration(seconds: 5));
    expect(find.text('${mvr(50000)} added to your wallet'), findsOneWidget);
    expect(find.text('Your balance is now ${mvr(58175)}.'), findsOneWidget);
    // The figures agreed, so no discrepancy sentence intrudes — that line
    // has to stay signal.
    expect(find.textContaining('Your bank sent'), findsNothing);
    // The money moved: the wallet answer behind this screen was re-read,
    // so navigating back shows the new balance and not the old one.
    expect(api.walletReads, 2);
  });

  // THE ROW THAT STARTED THIS ROUND (owner, 2026-08-25): a merchant typed
  // MVR 20.00 over a real MVR 10.00 transfer. The bank's figure is the money
  // — and the screen has to SAY that, or a store reading "MVR 10.00 added"
  // over their own MVR 20.00 concludes something went wrong.
  testWidgets('a credit that is not the amount typed quotes the BANK and '
      'says so in one plain sentence', (tester) async {
    await pump(
      tester,
      [
        watching(
          kind: 'wallet_top_up',
          id: 12,
          settlementId: null,
          amountLaari: 2000,
        ),
        topUpOutcome(
          'credited',
          credited: 1000,
          balance: 1000,
          claimed: 2000,
          bankSent: 1000,
        ),
      ],
      settlement: false,
    );
    await tick(tester, const Duration(seconds: 5));

    // The headline is what ARRIVED, not what was asked for.
    expect(find.text('${mvr(1000)} added to your wallet'), findsOneWidget);
    expect(find.text('${mvr(2000)} added to your wallet'), findsNothing);
    expect(find.text('Your balance is now ${mvr(1000)}.'), findsOneWidget);

    // …and the substitution is explained, naming BOTH figures.
    expect(
      find.textContaining(
        'Your bank sent ${mvr(1000)}, not the ${mvr(2000)} you entered',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('Nothing is lost'), findsOneWidget);
  });

  testWidgets('a part-settled batch names the bank figure against the claim', (
    tester,
  ) async {
    await pump(tester, [
      watching(),
      settlementOutcome(
        'partially_settled',
        received: 2000,
        outstanding: 712,
        claimed: 2712,
        bankSent: 2000,
      ),
    ]);
    await tick(tester, const Duration(seconds: 5));

    expect(find.text('Part of this settlement is paid'), findsOneWidget);
    expect(
      find.textContaining(
        'Your bank sent ${mvr(2000)}, not the ${mvr(2712)} you entered',
      ),
      findsOneWidget,
    );
    // The remainder is still the honest one, computed by the server.
    expect(
      find.textContaining('${mvr(712)} is still owed'),
      findsOneWidget,
    );
  });

  testWidgets('a payment matched by hand names no discrepancy it cannot '
      'show', (tester) async {
    // No bank figure was recorded, so there is no second number to name —
    // and an unknown is never announced as a mismatch.
    await pump(tester, [
      watching(),
      settlementOutcome('settled', received: 2712, bankSent: null),
    ]);
    await tick(tester, const Duration(seconds: 5));

    expect(find.text('Settled — your transfer matched'), findsOneWidget);
    expect(find.textContaining('Your bank sent'), findsNothing);
  });

  testWidgets('a refused claim carries no amount sentence — nothing was '
      'credited to explain', (tester) async {
    await pump(
      tester,
      [
        watching(
          kind: 'wallet_top_up',
          id: 12,
          settlementId: null,
          amountLaari: 2000,
        ),
        topUpOutcome(
          'rejected',
          credited: 0,
          claimed: 2000,
          rejected: 'Reference not on the statement',
        ),
      ],
      settlement: false,
    );
    await tick(tester, const Duration(seconds: 5));

    expect(find.text('This transfer was not matched'), findsOneWidget);
    expect(find.textContaining('Your bank sent'), findsNothing);
  });

  testWidgets('a rejection says so and carries the reason verbatim', (
    tester,
  ) async {
    await pump(tester, [
      watching(),
      settlementOutcome('rejected', received: 0, rejected: 'Slip belongs to another store'),
    ]);
    await tick(tester, const Duration(seconds: 5));

    expect(find.text('This transfer was not matched'), findsOneWidget);
    expect(
      find.text('Reason: Slip belongs to another store'),
      findsOneWidget,
    );
    // A refused settlement receipt CANCELS the batch — there is no adding a
    // better slip to it — so the copy must not say "try again" here.
    expect(
      find.textContaining(
        'Settlement ST-2026-00045 is cancelled and its transactions are '
        'payable again',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('try again'), findsNothing);
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets('a refused TOP-UP still says to check the slip and try again', (
    tester,
  ) async {
    // The other half of the branch: a top-up claim really can be sent
    // again, and nothing is cancelled by refusing one.
    await pump(
      tester,
      [
        watching(kind: 'wallet_top_up', id: 12, settlementId: null),
        topUpOutcome('rejected', credited: 0, rejected: 'Reference not on the statement'),
      ],
      settlement: false,
    );
    await tick(tester, const Duration(seconds: 5));

    expect(find.text('This transfer was not matched'), findsOneWidget);
    expect(find.textContaining('try again'), findsOneWidget);
    expect(find.textContaining('is cancelled'), findsNothing);
  });

  testWidgets('auto-verify OFF: no bar, no countdown — a person will '
      'confirm it, and the screen stops asking', (tester) async {
    final api = await pump(tester, [notWatching('auto_verify_off')]);

    expect(find.text('Our team will confirm this shortly'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsNothing);
    expect(find.textContaining('minutes left'), findsNothing);
    // And not a word about a check that "keeps running" — nothing is.
    expect(find.textContaining('the check keeps running'), findsNothing);
    expect(find.textContaining('You can close this screen'), findsOneWidget);
    // Nothing is being watched, so nothing is polled either.
    await tick(tester, const Duration(minutes: 2));
    expect(api.calls, 1);
  });

  testWidgets('a bank with no read profile is the same plain sentence', (
    tester,
  ) async {
    await pump(tester, [notWatching('no_verify_profile')]);

    expect(find.text('Our team will confirm this shortly'), findsOneWidget);
    expect(find.textContaining('Someone checks it against the bank'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets('a lapsed window says we looked and did not see it', (
    tester,
  ) async {
    await pump(tester, [notWatching('window_expired')]);

    expect(find.text('Our team will confirm this shortly'), findsOneWidget);
    expect(
      find.textContaining('have not seen it arrive yet'),
      findsOneWidget,
    );
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets('the poll runs at 5s for the first minute, then 15s', (
    tester,
  ) async {
    final api = await pump(tester, [watching()]);
    expect(api.calls, 1);

    // 11 more reads, 5s apart: the first minute.
    await tick(tester, const Duration(seconds: 55));
    expect(api.calls, 12);

    // The 13th does NOT come at 5s any more…
    await tick(tester, const Duration(seconds: 5));
    expect(api.calls, 12);
    // …it comes at 15.
    await tick(tester, const Duration(seconds: 10));
    expect(api.calls, 13);
  });

  testWidgets('a window already shut gets ONE grace read, then silence', (
    tester,
  ) async {
    // The server still says "watching", but its own clock is past
    // watch_until — the outcome may have landed in that last second.
    final api = await pump(tester, [
      watching(checkedAt: '2026-08-25T10:15:30+05:00'),
    ]);

    expect(api.calls, 1);
    expect(find.textContaining('minutes left'), findsNothing);
    await tick(tester, const Duration(seconds: 5));
    expect(api.calls, 2);
    await tick(tester, const Duration(minutes: 2));
    expect(api.calls, 2);
  });

  testWidgets('backgrounding suspends the poll; coming back reads at once', (
    tester,
  ) async {
    final api = await pump(tester, [watching()]);
    await tick(tester, const Duration(seconds: 5));
    expect(api.calls, 2);

    for (final state in const [
      AppLifecycleState.inactive,
      AppLifecycleState.hidden,
      AppLifecycleState.paused,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pump();

    // A pocketed phone asks the server nothing: the poll is a WINDOW, and
    // the window is shut.
    await tick(tester, const Duration(minutes: 5));
    expect(api.calls, 2);

    for (final state in const [
      AppLifecycleState.hidden,
      AppLifecycleState.inactive,
      AppLifecycleState.resumed,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pumpAndSettle();
    // Caught up immediately, not after the interval.
    expect(api.calls, 3);
  });

  testWidgets('leaving the screen cancels the timer — nothing is read after '
      'dispose', (tester) async {
    final api = await pump(tester, [watching()]);
    expect(api.calls, 1);

    await tester.pumpWidget(const SizedBox.shrink());
    await tick(tester, const Duration(minutes: 5));
    // A leaked Timer would also fail this test in teardown, which is the
    // second half of the assertion.
    expect(api.calls, 1);
  });

  testWidgets('a read that fails changes nothing on screen, and hopeless '
      'reads stop', (tester) async {
    // Every read 404s (a batch with no bank payment, say).
    final api = await pump(tester, const [], settlement: true);

    expect(api.calls, 1);
    // The card keeps saying what it honestly knows: the slip reached us.
    expect(find.text('Manfaa is verifying your transfer'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsNothing);
    await tick(tester, const Duration(minutes: 2));
    // A 404 is hopeless — asked once, never again.
    expect(api.calls, 1);
  });

  testWidgets('a transfer nobody ever watched gets the plain promise, not '
      'the "we looked and did not find it" sentence', (tester) async {
    // Uploaded while auto-verification was switched off: no poll job was
    // ever dispatched for it, so no check ran and none will.
    await pump(tester, [notWatching('never_watched')]);

    expect(find.text('Our team will confirm this shortly'), findsOneWidget);
    expect(find.textContaining('Someone checks it against the bank'), findsOneWidget);
    expect(find.textContaining('have not seen it arrive yet'), findsNothing);
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets('a run of failed reads takes the bar down rather than '
      'animating over a poll that has stopped', (tester) async {
    // Four consecutive nothing-answers — twenty seconds of dead signal, an
    // ordinary thing on a phone.
    final api = await pump(tester, [
      watching(),
      _fail,
      _fail,
      _fail,
      _fail,
    ]);

    expect(barValue(tester), closeTo(0.2, 0.001));

    await tick(tester, const Duration(seconds: 20));
    expect(api.calls, 5);

    // The screen is no longer observing anything, so it stops claiming to
    // be: no bar, no frozen countdown, and none of the "the check keeps
    // running" reassurance that belongs to a live watch.
    expect(find.byType(LinearProgressIndicator), findsNothing);
    expect(find.textContaining('minutes left'), findsNothing);
    expect(find.textContaining('the check keeps running'), findsNothing);
    expect(find.text('Our team will confirm this shortly'), findsOneWidget);

    // And it really has stopped asking.
    await tick(tester, const Duration(minutes: 2));
    expect(api.calls, 5);
  });

  testWidgets('coming back to the app forgives the failures and reads '
      'again — the server has probably decided by now', (tester) async {
    final api = await pump(tester, [
      watching(),
      _fail,
      _fail,
      _fail,
      _fail,
      settlementOutcome('settled'),
    ]);

    await tick(tester, const Duration(seconds: 20));
    expect(api.calls, 5);
    expect(find.byType(LinearProgressIndicator), findsNothing);

    for (final state in const [
      AppLifecycleState.inactive,
      AppLifecycleState.hidden,
      AppLifecycleState.paused,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pump();

    for (final state in const [
      AppLifecycleState.hidden,
      AppLifecycleState.inactive,
      AppLifecycleState.resumed,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pumpAndSettle();

    expect(api.calls, 6);
    expect(find.text('Settled — your transfer matched'), findsOneWidget);
  });

  testWidgets('a payload this build cannot read counts as a failed read, '
      'and does not spin forever', (tester) async {
    // `outcome` arriving as something that is not an object throws out of
    // the parser — not a MobileApiException, and therefore not the failure
    // the loop used to count. It has to count now, or the screen polls for
    // ever while frozen on "verifying".
    final api = await pump(tester, [
      {...watching(), 'outcome': 'not-an-object'},
    ]);

    expect(api.calls, 1);
    expect(find.text('Manfaa is verifying your transfer'), findsOneWidget);

    await tick(tester, const Duration(seconds: 20));
    // Four reads in all, then it gives up — not an endless loop.
    expect(api.calls, 4);
    expect(find.text('Our team will confirm this shortly'), findsOneWidget);

    await tick(tester, const Duration(minutes: 2));
    expect(api.calls, 4);
  });
}

/// A script entry that answers with a retryable failure — no signal, a 429,
/// a 5xx. NOT an answer to the question, and the loop must treat it as such.
const _fail = {'__fail': true};

/// A MerchantApi whose progress route answers from a script: read n returns
/// script[n], and the last entry repeats forever. An EMPTY script 404s.
class _ProgressApi extends MerchantApi {
  _ProgressApi({required super.session, required this.script});

  // The guided setup is not live in this fixture, so the shell's chip and
  // the Dashboard's tour prompt draw nothing and every assertion below is
  // about the screen it is about. Overridden rather than inherited because
  // the base class would reach the NETWORK from a unit test.
  @override
  Future<MerchantOnboardingGuide> onboarding() async =>
      MerchantOnboardingGuide.hidden;

  final List<Map<String, dynamic>> script;
  var calls = 0;
  var walletReads = 0;

  MatchProgress _next() {
    final index = calls;
    calls++;
    if (script.isEmpty) {
      throw MobileApiException(
        code: ApiCode.notFound,
        message: 'No transfer to report on.',
      );
    }
    final entry = script[index < script.length ? index : script.length - 1];

    if (entry['__fail'] == true) {
      throw MobileApiException(
        code: ApiCode.serverError,
        message: 'Something went wrong.',
      );
    }

    return MatchProgress.fromJson(entry);
  }

  @override
  Future<MatchProgress> settlementPaymentProgress(int settlementId) async =>
      _next();

  @override
  Future<MatchProgress> walletTopUpProgress(int topUpId) async => _next();

  @override
  Future<MerchantWalletState> wallet() async {
    walletReads++;
    return MerchantWalletState.fromJson(const {
      'balance_laari': 58175,
      'currency': 'MVR',
      'top_up_min_laari': 10000,
      'auto_settle_from_wallet': true,
      'transactions': <Object>[],
    });
  }

  /// GET /merchant/fee-promotion. Defaults to NOTHING RUNNING — the state
  /// every shipped assertion and golden was written against — and is
  /// settable so a test can throw the switch the way a superadmin does.
  MerchantFeePromotion promotion = MerchantFeePromotion.none;

  @override
  Future<MerchantFeePromotion> feePromotion() async => promotion;
}
