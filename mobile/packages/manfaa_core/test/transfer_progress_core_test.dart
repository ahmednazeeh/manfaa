import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The transfer-progress reads (owner, 2026-08-25) — one wire shape for both
/// flows, and the honesty rule that governs it: a progress bar may only run
/// while the SERVER says it is really asking the bank. Every doubtful,
/// contradictory or unrecognised payload here must land on "not watching".
class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this._respond);

  final ResponseBody Function(RequestOptions options) _respond;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return _respond(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Object payload, int status) => ResponseBody.fromString(
  jsonEncode(payload),
  status,
  headers: {
    Headers.contentTypeHeader: ['application/json'],
  },
);

MerchantApi _api(_RecordingAdapter adapter) {
  final api = MerchantApi(session: MerchantSession(MemorySecretStore()));
  api.dio.httpClientAdapter = adapter;
  return api;
}

/// A watch genuinely running: pending, the switch on, the bank routed, the
/// window open. `checked_at` is the SERVER's clock — 11m40s before the
/// window closes.
const _watchingFixture = {
  'data': {
    'kind': 'settlement_payment',
    'id': 91,
    'settlement_id': 44,
    'state': 'pending',
    'amount_laari': 11825,
    'amount_mvr': '118.25',
    'watching': true,
    'reason': null,
    'watch_started_at': '2026-08-25T10:00:00+00:00',
    'watch_until': '2026-08-25T10:15:00+00:00',
    'attempts': 3,
    'auto_matched': false,
    'decided_at': null,
    'checked_at': '2026-08-25T10:03:20+00:00',
    'outcome': null,
  },
};

void main() {
  group('settlement payment progress', () {
    test('polls the batch id and reads a live watch off the server', () async {
      final adapter = _RecordingAdapter((_) => _json(_watchingFixture, 200));
      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(
        adapter.requests.single.uri.path,
        endsWith('/merchant/settlements/44/payment-progress'),
      );
      expect(adapter.requests.single.method, 'GET');

      expect(progress.kind, MatchKind.settlementPayment);
      expect(progress.id, 91);
      expect(progress.settlementId, 44);
      expect(progress.state, MatchState.pending);
      expect(progress.amountLaari, 11825);
      expect(progress.attempts, 3);
      expect(progress.autoMatched, isFalse);
      expect(progress.outcome, isNull);
      expect(progress.isWatching, isTrue);
      expect(progress.reason, isNull);
      expect(progress.awaitsPerson, isFalse);
      expect(progress.isDecided, isFalse);
    });

    test('counts down on the SERVER clock, not the handset', () async {
      final adapter = _RecordingAdapter((_) => _json(_watchingFixture, 200));
      final progress = await _api(adapter).settlementPaymentProgress(44);

      // watch_until − checked_at, exactly. The fixture is dated: a device
      // clock would answer something else entirely, which is the whole
      // reason checked_at is on the wire.
      expect(progress.watchRemaining, const Duration(minutes: 11, seconds: 40));
      expect(progress.watchUntil?.isUtc, isTrue);
      expect(progress.watchStartedAt, isNotNull);
    });

    test('a settled batch reports the outcome and owes nothing', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'state': 'matched',
            'watching': false,
            'reason': 'terminal',
            'auto_matched': true,
            'decided_at': '2026-08-25T10:04:10+00:00',
            'outcome': {
              'result': 'settled',
              'settlement_state': 'settled',
              'reference': 'ST-2026-00044',
              'amount_received_laari': 11825,
              'amount_received_mvr': '118.25',
              'amount_outstanding_laari': 0,
              'amount_outstanding_mvr': '0.00',
              'rejected_reason': null,
            },
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.isWatching, isFalse);
      expect(progress.reason, MatchWatchReason.terminal);
      expect(progress.awaitsPerson, isFalse);
      expect(progress.isDecided, isTrue);
      expect(progress.autoMatched, isTrue);
      expect(progress.decidedAt, isNotNull);

      final outcome = progress.outcome!;
      expect(outcome.isSettled, isTrue);
      expect(outcome.settlementState, 'settled');
      expect(outcome.reference, 'ST-2026-00044');
      expect(outcome.amountReceivedLaari, 11825);
      expect(outcome.amountOutstandingLaari, 0);
      expect(outcome.creditedLaari, isNull);
      expect(outcome.balanceLaari, isNull);
    });

    test('a partial match keeps the remainder, and never says settled', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'state': 'matched',
            'watching': false,
            'reason': 'terminal',
            'outcome': {
              'result': 'partially_settled',
              'settlement_state': 'partially_settled',
              'reference': 'ST-2026-00044',
              'amount_received_laari': 4125,
              'amount_received_mvr': '41.25',
              'amount_outstanding_laari': 7700,
              'amount_outstanding_mvr': '77.00',
              'rejected_reason': null,
            },
          },
        }, 200),
      );

      final outcome = (await _api(adapter).settlementPaymentProgress(44))
          .outcome!;

      expect(outcome.isPartiallySettled, isTrue);
      expect(outcome.isSettled, isFalse);
      expect(outcome.amountOutstandingLaari, 7700);
    });

    test('a refused receipt carries the reason verbatim', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'state': 'rejected',
            'watching': false,
            'reason': 'terminal',
            'outcome': {
              'result': 'rejected',
              'settlement_state': 'cancelled',
              'reference': 'ST-2026-00044',
              'amount_received_laari': 0,
              'amount_received_mvr': '0.00',
              'amount_outstanding_laari': 0,
              'amount_outstanding_mvr': '0.00',
              'rejected_reason': 'The slip is for another store.',
            },
          },
        }, 200),
      );

      final outcome = (await _api(adapter).settlementPaymentProgress(44))
          .outcome!;

      expect(outcome.isRejected, isTrue);
      expect(outcome.rejectedReason, 'The slip is for another store.');
      // A cancelled batch releases its lines: owing nothing on a dead
      // reference is the honest reading.
      expect(outcome.amountOutstandingLaari, 0);
    });
  });

  group('wallet top-up progress', () {
    test('polls the claim id and reads the credit + balance now', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            'kind': 'wallet_top_up',
            'id': 12,
            'settlement_id': null,
            'state': 'matched',
            'amount_laari': 50000,
            'amount_mvr': '500.00',
            'watching': false,
            'reason': 'terminal',
            'watch_started_at': '2026-08-25T10:00:00+00:00',
            'watch_until': null,
            'attempts': 5,
            'auto_matched': true,
            'decided_at': '2026-08-25T10:02:00+00:00',
            'checked_at': '2026-08-25T10:02:30+00:00',
            'outcome': {
              'result': 'credited',
              'credited_laari': 50000,
              'credited_mvr': '500.00',
              'balance_laari': 58175,
              'balance_mvr': '581.75',
              'rejected_reason': null,
            },
          },
        }, 200),
      );

      final progress = await _api(adapter).walletTopUpProgress(12);

      expect(
        adapter.requests.single.uri.path,
        endsWith('/merchant/wallet/top-ups/12/progress'),
      );

      expect(progress.kind, MatchKind.walletTopUp);
      expect(progress.settlementId, isNull);
      expect(progress.isWatching, isFalse);
      expect(progress.watchRemaining, Duration.zero);

      final outcome = progress.outcome!;
      expect(outcome.isCredited, isTrue);
      expect(outcome.creditedLaari, 50000);
      expect(outcome.balanceLaari, 58175);
      expect(outcome.amountOutstandingLaari, isNull);
      expect(outcome.reference, isNull);
    });

    test('a refused claim credits nothing', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            'kind': 'wallet_top_up',
            'id': 12,
            'settlement_id': null,
            'state': 'rejected',
            'amount_laari': 50000,
            'amount_mvr': '500.00',
            'watching': false,
            'reason': 'terminal',
            'attempts': 5,
            'auto_matched': false,
            'checked_at': '2026-08-25T10:02:30+00:00',
            'outcome': {
              'result': 'rejected',
              'credited_laari': 0,
              'credited_mvr': '0.00',
              'balance_laari': 8175,
              'balance_mvr': '81.75',
              'rejected_reason': 'No transfer of that amount arrived.',
            },
          },
        }, 200),
      );

      final outcome = (await _api(adapter).walletTopUpProgress(12)).outcome!;

      expect(outcome.isRejected, isTrue);
      expect(outcome.creditedLaari, 0);
      expect(outcome.balanceLaari, 8175);
      expect(outcome.rejectedReason, 'No transfer of that amount arrived.');
    });
  });

  group('the honesty rule', () {
    test('the switch being off is not a watch — and says which', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'watching': false,
            'reason': 'auto_verify_off',
            'watch_until': '2026-08-25T10:15:00+00:00',
            'attempts': 0,
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.isWatching, isFalse);
      expect(progress.reason, MatchWatchReason.autoVerifyOff);
      expect(progress.awaitsPerson, isTrue);
      // The window is nominally open, but nothing is looking through it: a
      // countdown here would be an animation over nothing.
      expect(progress.watchRemaining, Duration.zero);
    });

    test('an unrouted bank reads as unwatched, not as expired', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'watching': false,
            'reason': 'no_verify_profile',
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.reason, MatchWatchReason.noVerifyProfile);
      expect(progress.awaitsPerson, isTrue);
    });

    test('a transfer no watch ever started on is its own reason', () async {
      // Uploaded while the platform switch was down: no window on the row,
      // no job behind it, and the copy layer must NOT reach for the expired
      // wording ("we watched the bank and have not seen it arrive").
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'watching': false,
            'reason': 'never_watched',
            'watch_started_at': null,
            'watch_until': null,
            'attempts': 0,
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.reason, MatchWatchReason.neverWatched);
      expect(progress.reason, isNot(MatchWatchReason.windowExpired));
      expect(progress.isWatching, isFalse);
      expect(progress.awaitsPerson, isTrue);
      expect(progress.watchRemaining, Duration.zero);
    });

    test('a lapsed window stops the bar', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'watching': false,
            'reason': 'window_expired',
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.reason, MatchWatchReason.windowExpired);
      expect(progress.isWatching, isFalse);
    });
  });

  group('defensive parsing', () {
    test('an unrecognised reason does not throw, and reads as unwatched', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'watching': false,
            'reason': 'bank_on_fire',
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.reason, MatchWatchReason.unknown);
      expect(progress.isWatching, isFalse);
      expect(progress.awaitsPerson, isTrue);
    });

    test('an unrecognised state cannot keep a bar running', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'state': 'quantum',
            'watching': true,
            'reason': null,
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.state, MatchState.unknown);
      expect(progress.isWatching, isFalse);
      // Not watching always has a reason to word, even when the server sent
      // none — the copy layer never has to invent one.
      expect(progress.reason, MatchWatchReason.terminal);
    });

    test('an unrecognised result does not throw', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'state': 'matched',
            'watching': false,
            'reason': 'terminal',
            'outcome': {
              'result': 'teleported',
              'settlement_state': 'settled',
              'reference': 'ST-2026-00044',
              'rejected_reason': null,
            },
          },
        }, 200),
      );

      final outcome = (await _api(adapter).settlementPaymentProgress(44))
          .outcome!;

      expect(outcome.result, MatchResult.unknown);
      expect(outcome.isSettled, isFalse);
      expect(outcome.isRejected, isFalse);
    });

    test('a payload claiming both a watch and an outcome believes the outcome', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'watching': true,
            'reason': null,
            'outcome': {
              'result': 'settled',
              'settlement_state': 'settled',
              'reference': 'ST-2026-00044',
              'amount_received_laari': 11825,
              'amount_outstanding_laari': 0,
              'rejected_reason': null,
            },
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.isDecided, isTrue);
      expect(progress.isWatching, isFalse);
      expect(progress.outcome!.isSettled, isTrue);
    });

    test('money on the outcome that is not an integer degrades to null '
        'instead of throwing', () async {
      // A raw `as int?` cast here threw a TypeError out of the parser — the
      // one failure the polling screen could not see, because it is not the
      // exception its loop counts. Every refusal lands on the safe side.
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'state': 'matched',
            'watching': false,
            'reason': 'terminal',
            'outcome': {
              'result': 'settled',
              'settlement_state': 'settled',
              'reference': 'ST-2026-00044',
              'amount_received_laari': '11825',
              'amount_outstanding_laari': 0.0,
              'rejected_reason': null,
            },
          },
        }, 200),
      );

      final outcome = (await _api(adapter).settlementPaymentProgress(44))
          .outcome!;

      expect(outcome.result, MatchResult.settled);
      expect(outcome.amountReceivedLaari, isNull);
      expect(outcome.amountOutstandingLaari, isNull);
    });

    test('an empty body parses to a safe, unwatched nothing', () async {
      final adapter = _RecordingAdapter((_) => _json({'data': {}}, 200));

      final progress = await _api(adapter).walletTopUpProgress(12);

      expect(progress.kind, MatchKind.unknown);
      expect(progress.id, 0);
      expect(progress.state, MatchState.unknown);
      expect(progress.amountLaari, 0);
      expect(progress.attempts, 0);
      expect(progress.autoMatched, isFalse);
      expect(progress.isWatching, isFalse);
      expect(progress.reason, MatchWatchReason.terminal);
      expect(progress.outcome, isNull);
      expect(progress.checkedAt, isNull);
      expect(progress.watchRemaining, Duration.zero);
    });

    test('a watch whose window already closed reports no time left', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            ..._watchingFixture['data']!,
            'checked_at': '2026-08-25T10:20:00+00:00',
          },
        }, 200),
      );

      final progress = await _api(adapter).settlementPaymentProgress(44);

      expect(progress.isWatching, isTrue);
      expect(progress.watchRemaining, Duration.zero);
    });
  });
}
