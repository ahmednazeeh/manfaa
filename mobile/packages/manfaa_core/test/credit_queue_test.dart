import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// MR2 core: the till endpoints' wire shapes and the offline credit queue's
/// laws — key stability across replays, 2xx clears, refusal parks, order
/// preserved.

// ---------------------------------------------------------------- adapters

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

const _transactionFixture = {
  'id': 42,
  'origin': 'manual',
  'invoice_no': 'INV-7',
  'state': 'awaiting_validation',
  'reason_code': null,
  'backdated': false,
  'currency': 'MVR',
  'eligible_laari': 50000,
  'sale_laari': null,
  'cashback_rate_percent': '2.00',
  'platform_fee_percent': '0.75',
  'effective_cashback_rate_percent': '2.00',
  'effective_platform_fee_percent': '0.75',
  'cashback_laari': 1000,
  'fee_laari': 375,
  'fee_gst_laari': 30,
  'occurred_at': '2026-08-17T10:00:00+05:00',
  'received_at': '2026-08-17T10:00:01+05:00',
};

// ------------------------------------------------------------- queue seams

/// A scriptable sender that records every entry (and its key) it was handed.
class _FakeSender {
  _FakeSender();

  final sentKeys = <String>[];
  final sentInvoices = <String>[];

  /// Outcomes consumed one per call: a [MerchantCreditResult] succeeds, a
  /// [MobileApiException] is thrown.
  final script = <Object>[];

  Future<MerchantCreditResult> call(QueuedCredit entry) async {
    sentKeys.add(entry.key);
    sentInvoices.add(entry.invoiceNo);
    final outcome = script.isEmpty ? _ok() : script.removeAt(0);
    if (outcome is MobileApiException) throw outcome;
    return outcome as MerchantCreditResult;
  }
}

MerchantCreditResult _ok({bool replayed = false}) => MerchantCreditResult(
      transaction: MerchantTransaction.fromJson(_transactionFixture),
      replayed: replayed,
    );

MobileApiException _network() => MobileApiException.network();

MobileApiException _refusal(String code, {int status = 422}) =>
    MobileApiException(code: code, message: 'refused: $code', status: status);

QueuedCredit _credit(String invoice, {String? key}) => QueuedCredit(
      key: key ?? mintIdempotencyKey(),
      customerCode: '374230',
      customerName: 'Ahmed Nazeeh',
      invoiceNo: invoice,
      eligibleLaari: 100000,
      queuedAt: '2026-08-17T10:00:00+05:00',
    );

void main() {
  group('money parsing', () {
    test('parseMvrToLaari is exact string surgery, never a float', () {
      expect(parseMvrToLaari('1,250.50'), 125050);
      expect(parseMvrToLaari('1000'), 100000);
      expect(parseMvrToLaari('0.01'), 1);
      expect(parseMvrToLaari('19.99'), 1999);
      expect(parseMvrToLaari('7.5'), 750);
      expect(parseMvrToLaari(' 42 '), 4200);
      expect(parseMvrToLaari(''), isNull);
      expect(parseMvrToLaari('12.345'), isNull);
      expect(parseMvrToLaari('-5'), isNull);
      expect(parseMvrToLaari('1..2'), isNull);
      expect(parseMvrToLaari('abc'), isNull);
    });
  });

  group('idempotency key minting', () {
    test('mints v4 UUIDs, unique per compose', () {
      final a = mintIdempotencyKey();
      final b = mintIdempotencyKey();
      final v4 = RegExp(
          r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
      expect(a, matches(v4));
      expect(b, matches(v4));
      expect(a, isNot(b));
    });
  });

  group('till endpoint wire shapes', () {
    test('lookupCustomer sends the code and parses the unwrapped answer',
        () async {
      final adapter = _RecordingAdapter(
          (_) => _json({'valid': true, 'name': 'Ahmed Nazeeh'}, 200));
      final api = _api(adapter);

      final hit = await api.lookupCustomer('374230');

      final request = adapter.requests.single;
      expect(request.path, '/merchant/customers/lookup');
      expect(request.queryParameters, {'code': '374230'});
      expect(hit.valid, isTrue);
      expect(hit.name, 'Ahmed Nazeeh');
    });

    test('an unknown code is {valid:false} — no name, no oracle', () async {
      final adapter = _RecordingAdapter((_) => _json({'valid': false}, 200));

      final miss = await _api(adapter).lookupCustomer('000000');

      expect(miss.valid, isFalse);
      expect(miss.name, isNull);
    });

    test('merchantRate parses current + pending windows', () async {
      final adapter = _RecordingAdapter((_) => _json({
            'data': {
              'current': {
                'cashback_rate_percent': '2.00',
                'platform_fee_percent': '0.75',
                'all_in_percent': '2.75',
                'effective_from': '2026-08-01T00:00:00+05:00',
                'effective_to': null,
              },
              'pending': null,
            },
          }, 200));

      final rate = await _api(adapter).merchantRate();

      expect(rate.current?.cashbackRatePercent, '2.00');
      expect(rate.current?.platformFeePercent, '0.75');
      expect(rate.current?.allInPercent, '2.75');
      expect(rate.pending, isNull);
    });

    test('productCategories parses the ordered list, inactive included',
        () async {
      final adapter = _RecordingAdapter((_) => _json({
            'data': [
              {
                'id': 1,
                'slug': 'fruits',
                'name_en': 'Fruits',
                'name_dv': 'މޭވާ',
                'mode': 'rate',
                'cashback_rate_percent': '5.00',
                'active': true,
                'sort': 0,
                'created_at': '2026-08-01T00:00:00+05:00',
                'updated_at': '2026-08-01T00:00:00+05:00',
              },
              {
                'id': 2,
                'slug': 'tobacco',
                'name_en': 'Tobacco',
                'name_dv': null,
                'mode': 'excluded',
                'cashback_rate_percent': null,
                'active': false,
                'sort': 1,
                'created_at': '2026-08-01T00:00:00+05:00',
                'updated_at': '2026-08-01T00:00:00+05:00',
              },
            ],
          }, 200));

      final categories = await _api(adapter).productCategories();

      expect(categories, hasLength(2));
      expect(categories.first.slug, 'fruits');
      expect(categories.first.cashbackRatePercent, '5.00');
      expect(categories.first.excluded, isFalse);
      expect(categories.first.label(dhivehi: true), 'މޭވާ');
      expect(categories.last.excluded, isTrue);
      expect(categories.last.cashbackRatePercent, isNull);
      expect(categories.last.active, isFalse);
      expect(categories.last.label(dhivehi: true), 'Tobacco');
    });

    test('amendTransaction PATCHes the wire names, lines replacing wholesale',
        () async {
      final adapter =
          _RecordingAdapter((_) => _json({'data': _transactionFixture}, 200));

      final amended = await _api(adapter).amendTransaction(
        id: 42,
        eligibleLaari: 50000,
        lines: const [
          CreditLine(category: null, amountLaari: 30000),
          CreditLine(category: 'staples', amountLaari: 20000),
        ],
      );

      final request = adapter.requests.single;
      expect(request.method, 'PATCH');
      expect(request.path, '/merchant/transactions/42');
      final body = request.data as Map;
      expect(body['eligible_amount'], 50000);
      // Untouched sale amount: key absent, not null-sent.
      expect(body.containsKey('sale_amount'), isFalse);
      // The category key is PRESENT even for the null bucket.
      expect((body['lines'] as List).first, {
        'category': null,
        'amount_laari': 30000,
      });
      expect(amended.id, 42);
      expect(amended.state, 'awaiting_validation');
    });

    test('cancelTransaction POSTs reason (+ note only when given)', () async {
      final adapter = _RecordingAdapter((_) => _json({
            'data': {..._transactionFixture, 'state': 'reversed'},
          }, 200));
      final api = _api(adapter);

      final reversed =
          await api.cancelTransaction(id: 42, reason: 'refund');
      await api.cancelTransaction(id: 42, reason: 'void', note: 'till slip');

      expect(adapter.requests.first.path, '/merchant/transactions/42/cancel');
      expect(adapter.requests.first.data, {'reason': 'refund'});
      expect(adapter.requests.last.data, {
        'reason': 'void',
        'note': 'till slip',
      });
      expect(reversed.state, 'reversed');
    });
  });

  group('credit queue', () {
    test('a 2xx clears the entry and records the recent customer', () async {
      final store = MemorySecretStore();
      final sender = _FakeSender();
      final queue = CreditQueue(store, sender.call);

      final result = await queue.submit(_credit('INV-1'));

      expect(result, isNotNull);
      expect(queue.entries, isEmpty);
      expect(queue.pendingCount, 0);
      expect(queue.recents.single.code, '374230');
      expect(queue.recents.single.name, 'Ahmed Nazeeh');
      // And the cleared state is what a cold start reads back.
      final rebooted = CreditQueue(store, sender.call);
      await rebooted.load();
      expect(rebooted.entries, isEmpty);
      expect(rebooted.recents.single.code, '374230');
    });

    test('a replay 2xx clears exactly like a first commit', () async {
      final sender = _FakeSender()..script.add(_ok(replayed: true));
      final queue = CreditQueue(MemorySecretStore(), sender.call);

      final result = await queue.submit(_credit('INV-1'));

      expect(result?.replayed, isTrue);
      expect(queue.entries, isEmpty);
    });

    test(
        'a network failure keeps the entry, and every replay carries the '
        'ORIGINAL compose-time key', () async {
      final store = MemorySecretStore();
      final sender = _FakeSender()
        ..script.addAll([_network(), _network(), _ok()]);
      final queue = CreditQueue(store, sender.call);

      final entry = _credit('INV-1', key: 'key-original');
      expect(await queue.submit(entry), isNull);
      expect(queue.pendingCount, 1);
      expect(queue.entries.single.attempts, 1);

      // Second replay from a COLD START — the key must survive persistence.
      final rebooted = CreditQueue(store, sender.call);
      await rebooted.drain(); // network again — still queued
      expect(rebooted.pendingCount, 1);
      await rebooted.drain(); // back online — lands and clears
      expect(rebooted.entries, isEmpty);

      expect(sender.sentKeys, ['key-original', 'key-original', 'key-original']);
    });

    test('a documented-terminal refusal during drain PARKS, never drops',
        () async {
      final store = MemorySecretStore();
      // Queue it while offline…
      final offline = _FakeSender()..script.add(_network());
      final queue = CreditQueue(store, offline.call);
      await queue.submit(_credit('INV-1'));
      expect(queue.pendingCount, 1);

      // …and meet a duplicate-invoice refusal on the later drain.
      final refusing = _FakeSender()
        ..script.add(_refusal(ApiCode.duplicateInvoice, status: 409));
      final drained = CreditQueue(store, refusing.call);
      await drained.drain();

      expect(drained.pendingCount, 0);
      expect(drained.parked, hasLength(1));
      expect(drained.parked.single.failCode, ApiCode.duplicateInvoice);
      expect(drained.parked.single.failMessage, isNotEmpty);
      // Parked survives a restart — needs-attention is not a toast.
      final rebooted = CreditQueue(store, refusing.call);
      await rebooted.load();
      expect(rebooted.parked, hasLength(1));
    });

    test('an interactive terminal refusal rethrows for the watching cashier',
        () async {
      final sender = _FakeSender()
        ..script.add(_refusal(ApiCode.rateBelowAdvertised));
      final queue = CreditQueue(MemorySecretStore(), sender.call);

      await expectLater(
        queue.submit(_credit('INV-1')),
        throwsA(isA<MobileApiException>()
            .having((e) => e.code, 'code', ApiCode.rateBelowAdvertised)),
      );
      // Surfaced, not silently parked — the form still holds the sale.
      expect(queue.entries, isEmpty);
    });

    test('an UNDOCUMENTED 4xx is retryable (guide §6), not parked', () async {
      final sender = _FakeSender()
        ..script.add(_refusal('brand_new_server_code', status: 422));
      final queue = CreditQueue(MemorySecretStore(), sender.call);

      expect(await queue.submit(_credit('INV-1')), isNull);
      expect(queue.pendingCount, 1);
    });

    test('drain replays FIFO and stops at the first retryable failure',
        () async {
      final store = MemorySecretStore();
      final offline = _FakeSender()
        ..script.addAll([_network(), _network(), _network()]);
      final queue = CreditQueue(store, offline.call);
      await queue.submit(_credit('INV-1'));
      await queue.submit(_credit('INV-2'));
      await queue.submit(_credit('INV-3'));
      expect(queue.pendingCount, 3);

      // First drain: INV-1 lands, INV-2 hits the dead network — INV-3 must
      // NOT jump the queue.
      final flaky = _FakeSender()..script.addAll([_ok(), _network()]);
      final drained = CreditQueue(store, flaky.call);
      await drained.drain();
      expect(flaky.sentInvoices, ['INV-1', 'INV-2']);
      expect([for (final e in drained.pending) e.invoiceNo],
          ['INV-2', 'INV-3']);

      // Second drain finishes the rest, still in order.
      await drained.drain();
      expect(flaky.sentInvoices, ['INV-1', 'INV-2', 'INV-2', 'INV-3']);
      expect(drained.entries, isEmpty);
    });

    test('a parked refusal skips the drain but retryParked re-queues it',
        () async {
      final store = MemorySecretStore();
      final offline = _FakeSender()..script.addAll([_network(), _network()]);
      final queue = CreditQueue(store, offline.call);
      final refused = _credit('INV-1');
      await queue.submit(refused);
      await queue.submit(_credit('INV-2'));

      // INV-1 is refused terminally; INV-2 lands — the drain continues past
      // a parked entry (later sales are independent).
      final mixed = _FakeSender()
        ..script.addAll([_refusal(ApiCode.merchantNotActive), _ok()]);
      final drained = CreditQueue(store, mixed.call);
      await drained.drain();
      expect(mixed.sentInvoices, ['INV-1', 'INV-2']);
      expect(drained.parked, hasLength(1));
      expect(drained.pending, isEmpty);

      // A later drain must NOT hammer the parked entry again on its own.
      await drained.drain();
      expect(mixed.sentInvoices, hasLength(2));

      // The human retries it — same key goes back out.
      mixed.script.add(_ok());
      await drained.retryParked(refused.key);
      expect(mixed.sentKeys.last, refused.key);
      expect(drained.entries, isEmpty);
    });

    test('discard drops a parked entry only', () async {
      final store = MemorySecretStore();
      final offline = _FakeSender()..script.add(_network());
      final queue = CreditQueue(store, offline.call);
      final entry = _credit('INV-1');
      await queue.submit(entry);

      // Pending entries cannot be discarded — only parked ones.
      await queue.discard(entry.key);
      expect(queue.pendingCount, 1);

      final refusing = _FakeSender()
        ..script.add(_refusal(ApiCode.duplicateInvoice, status: 409));
      final drained = CreditQueue(store, refusing.call);
      await drained.drain();
      await drained.discard(entry.key);
      expect(drained.entries, isEmpty);
    });

    test('the queued wire body round-trips through persistence', () async {
      final store = MemorySecretStore();
      final offline = _FakeSender()..script.add(_network());
      final queue = CreditQueue(store, offline.call);
      await queue.submit(QueuedCredit(
        key: 'key-1',
        customerCode: '374230',
        customerName: 'Ahmed Nazeeh',
        invoiceNo: 'INV-9',
        eligibleLaari: 100000,
        saleLaari: 120000,
        occurredAt: '2026-08-10T14:00:00+05:00',
        cashbackRatePercent: '3.00',
        lines: const [
          CreditLine(category: null, amountLaari: 60000),
          CreditLine(category: 'fruits', amountLaari: 40000),
        ],
        backdatedAcknowledged: true,
        queuedAt: '2026-08-17T10:00:00+05:00',
      ));

      final sender = _FakeSender();
      final rebooted = CreditQueue(store, sender.call);
      await rebooted.load();
      final entry = rebooted.entries.single;

      expect(entry.key, 'key-1');
      expect(entry.saleLaari, 120000);
      expect(entry.occurredAt, '2026-08-10T14:00:00+05:00');
      expect(entry.cashbackRatePercent, '3.00');
      expect(entry.backdatedAcknowledged, isTrue);
      expect(entry.lines, hasLength(2));
      expect(entry.lines.first.category, isNull);
      expect(entry.lines.last.category, 'fruits');
      expect(entry.lines.last.amountLaari, 40000);
    });
  });
}
