import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';

import 'errors.dart';
import 'merchant_models.dart';
import 'session.dart';

/// The till's OFFLINE CREDIT QUEUE (docs/mobile-api-guide.md §6).
///
/// A shop with no signal must still ring up sales: a credit composed offline
/// is persisted locally with a client-minted UUID idempotency key — minted at
/// COMPOSE time, stored beside the sale, and reused verbatim on every retry.
/// That key is the whole safety story: a request that timed out AFTER the
/// server committed replays its original answer (`Idempotency-Replay: true`,
/// a 200) instead of booking a second sale, so the queue clears an entry on
/// ANY 2xx and never keys off 201.
///
/// Draining is FIFO and stops at the first retryable failure (if the network
/// just died, everything behind it would fail the same way — and replay
/// order is part of the contract with the receipt roll). A PERMANENT 4xx met
/// during a drain parks the entry as `needs attention` instead: the human
/// who composed it was not watching, and silently dropping a refused sale is
/// the one thing a till must never do.

/// One recently credited customer — the Credit screen's "Recent" tab.
class RecentCustomer {
  RecentCustomer({required this.code, required this.name, required this.at});

  factory RecentCustomer.fromJson(Map<String, dynamic> json) => RecentCustomer(
        code: json['code']?.toString() ?? '',
        name: json['name']?.toString() ?? '',
        at: json['at']?.toString() ?? '',
      );

  final String code;
  final String name;

  /// ISO timestamp of the last credit to this customer.
  final String at;

  Map<String, dynamic> toJson() => {'code': code, 'name': name, 'at': at};
}

/// One composed sale, exactly as it will go to the wire — plus the queue's
/// own bookkeeping (the key, attempts, and the parked-refusal state).
class QueuedCredit {
  QueuedCredit({
    required this.key,
    required this.customerCode,
    required this.customerName,
    required this.invoiceNo,
    required this.eligibleLaari,
    this.saleLaari,
    this.occurredAt,
    this.cashbackRatePercent,
    this.lines = const [],
    this.backdatedAcknowledged = false,
    required this.queuedAt,
    this.attempts = 0,
    this.status = statusPending,
    this.failCode,
    this.failMessage,
  });

  factory QueuedCredit.fromJson(Map<String, dynamic> json) => QueuedCredit(
        key: json['key']?.toString() ?? '',
        customerCode: json['customer_code']?.toString() ?? '',
        customerName: json['customer_name']?.toString() ?? '',
        invoiceNo: json['invoice_no']?.toString() ?? '',
        eligibleLaari: json['eligible_laari'] as int? ?? 0,
        saleLaari: json['sale_laari'] as int?,
        occurredAt: json['occurred_at'] as String?,
        cashbackRatePercent: json['cashback_rate_percent'] as String?,
        lines: [
          for (final line in (json['lines'] as List? ?? const []))
            CreditLine(
              category: (line as Map)['category'] as String?,
              amountLaari: line['amount_laari'] as int? ?? 0,
            ),
        ],
        backdatedAcknowledged: json['backdated_acknowledged'] as bool? ?? false,
        queuedAt: json['queued_at']?.toString() ?? '',
        attempts: json['attempts'] as int? ?? 0,
        status: json['status']?.toString() ?? statusPending,
        failCode: json['fail_code'] as String?,
        failMessage: json['fail_message'] as String?,
      );

  /// Waiting to (re)play with its original key.
  static const statusPending = 'pending';

  /// A permanent refusal met while draining — held for a human, never
  /// silently dropped.
  static const statusParked = 'parked';

  /// The idempotency key, minted ONCE at compose time. Every retry of this
  /// sale — this launch or the next — carries exactly this key.
  final String key;

  final String customerCode;

  /// The looked-up full name at compose time, for the Recent tab and the
  /// queue's own rows (the lookup may be unreachable when this replays).
  final String customerName;

  final String invoiceNo;
  final int eligibleLaari;
  final int? saleLaari;

  /// ISO 8601 with the +05:00 business offset — null means "record as now",
  /// which for a queued sale is the moment it finally lands; the cashier
  /// chose not to pin a time, and inventing one client-side is worse.
  final String? occurredAt;
  final String? cashbackRatePercent;
  final List<CreditLine> lines;
  final bool backdatedAcknowledged;
  final String queuedAt;

  int attempts;
  String status;

  /// The refusal that parked this entry, for the needs-attention card.
  String? failCode;
  String? failMessage;

  bool get pending => status == statusPending;
  bool get parked => status == statusParked;

  Map<String, dynamic> toJson() => {
        'key': key,
        'customer_code': customerCode,
        'customer_name': customerName,
        'invoice_no': invoiceNo,
        'eligible_laari': eligibleLaari,
        'sale_laari': saleLaari,
        'occurred_at': occurredAt,
        'cashback_rate_percent': cashbackRatePercent,
        'lines': [for (final line in lines) line.toJson()],
        'backdated_acknowledged': backdatedAcknowledged,
        'queued_at': queuedAt,
        'attempts': attempts,
        'status': status,
        'fail_code': failCode,
        'fail_message': failMessage,
      };
}

/// A RFC 4122 v4 UUID from a cryptographic source — the idempotency key the
/// queue mints at compose time. No package needed for 16 random bytes.
String mintIdempotencyKey({Random? random}) {
  final rng = random ?? Random.secure();
  final bytes = List<int>.generate(16, (_) => rng.nextInt(256));
  bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant 10xx

  final hex =
      bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-'
      '${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
}

/// How a queued entry reaches the server — in the app this closes over
/// `MerchantApi.createCredit(idempotencyKey: entry.key, …)`; in tests it is
/// a fake. It MUST throw [MobileApiException] for anything but a 2xx.
typedef CreditSender = Future<MerchantCreditResult> Function(
  QueuedCredit entry,
);

class CreditQueue extends ChangeNotifier {
  CreditQueue(this._store, this._send);

  static const storageKey = 'credit_queue';
  static const recentsKey = 'credit_recent_customers';
  static const _recentLimit = 8;

  final SecretStore _store;
  final CreditSender _send;

  final _entries = <QueuedCredit>[];
  final _recents = <RecentCustomer>[];
  var _loaded = false;
  var _draining = false;

  List<QueuedCredit> get entries => List.unmodifiable(_entries);
  List<QueuedCredit> get pending =>
      List.unmodifiable(_entries.where((e) => e.pending));
  List<QueuedCredit> get parked =>
      List.unmodifiable(_entries.where((e) => e.parked));
  int get pendingCount => _entries.where((e) => e.pending).length;
  bool get draining => _draining;

  /// Most-recent-first, deduped by code, capped at [_recentLimit].
  List<RecentCustomer> get recents => List.unmodifiable(_recents);

  /// Idempotent; every public method awaits it, so callers never race a
  /// half-read store.
  Future<void> load() async {
    if (_loaded) return;
    _loaded = true;
    _entries.addAll(_decodeList(await _store.read(storageKey),
        QueuedCredit.fromJson));
    _recents.addAll(_decodeList(await _store.read(recentsKey),
        RecentCustomer.fromJson));
    notifyListeners();
  }

  /// The Credit screen's submit: enqueue the composed sale, then try to
  /// land it right now.
  ///
  ///  - 2xx → the entry clears and the [MerchantCreditResult] comes back
  ///    (replay or first commit — both mean recorded).
  ///  - retryable failure (network, 5xx, 429, key-in-flight, any
  ///    UNDOCUMENTED 4xx per guide §6) → stays queued with its original
  ///    key; returns null and the screen shows the pending-sync state.
  ///  - documented-terminal 4xx → removed and RETHROWN: the cashier is
  ///    watching, the refusal renders inline, and a fixed-up resubmit is a
  ///    new compose with a new key (same key + different body would be
  ///    `idempotency_key_reuse_mismatch`).
  Future<MerchantCreditResult?> submit(QueuedCredit entry) async {
    await load();
    _entries.add(entry);
    await _persist();
    notifyListeners();

    try {
      final result = await _send(entry);
      await _clear(entry);
      return result;
    } on MobileApiException catch (e) {
      if (_isRetryable(e)) {
        entry.attempts += 1;
        await _persist();
        notifyListeners();
        return null;
      }
      _entries.removeWhere((x) => x.key == entry.key);
      await _persist();
      notifyListeners();
      rethrow;
    } catch (_) {
      // An undiagnosed local failure: the sale stays queued — a composed
      // sale is never dropped on an exception.
      entry.attempts += 1;
      await _persist();
      notifyListeners();
      return null;
    }
  }

  /// Replay every pending entry IN ORDER, each with its ORIGINAL key.
  /// Wired to connectivity-regained and app-foreground in the app shell.
  Future<void> drain() async {
    await load();
    if (_draining) return;
    _draining = true;
    notifyListeners();

    try {
      for (final entry in List.of(_entries)) {
        if (!entry.pending || !_entries.contains(entry)) continue;
        try {
          await _send(entry);
          await _clear(entry);
        } on MobileApiException catch (e) {
          if (_isRetryable(e)) {
            // Still unreachable (or throttled): later entries would fail
            // the same way, and they must not land out of order.
            entry.attempts += 1;
            await _persist();
            notifyListeners();
            break;
          }
          entry.status = QueuedCredit.statusParked;
          entry.failCode = e.code;
          entry.failMessage = e.message;
          await _persist();
          notifyListeners();
        } catch (_) {
          entry.attempts += 1;
          await _persist();
          notifyListeners();
          break;
        }
      }
    } finally {
      _draining = false;
      notifyListeners();
    }
  }

  /// Put a parked entry back in line (same key — the compose is unchanged)
  /// and try again.
  Future<void> retryParked(String key) async {
    await load();
    final entry = _entries.where((e) => e.key == key).firstOrNull;
    if (entry == null || !entry.parked) return;
    entry.status = QueuedCredit.statusPending;
    entry.failCode = null;
    entry.failMessage = null;
    await _persist();
    notifyListeners();
    await drain();
  }

  /// Drop a parked entry the user has seen and dismissed. Only a HUMAN
  /// decision reaches here — the queue itself never discards a sale.
  Future<void> discard(String key) async {
    await load();
    _entries.removeWhere((e) => e.key == key && e.parked);
    await _persist();
    notifyListeners();
  }

  Future<void> _clear(QueuedCredit entry) async {
    _entries.removeWhere((x) => x.key == entry.key);
    _noteRecent(entry);
    await _persist();
    notifyListeners();
  }

  void _noteRecent(QueuedCredit entry) {
    if (entry.customerCode.isEmpty) return;
    _recents.removeWhere((r) => r.code == entry.customerCode);
    _recents.insert(
      0,
      RecentCustomer(
        code: entry.customerCode,
        name: entry.customerName,
        at: DateTime.now().toIso8601String(),
      ),
    );
    if (_recents.length > _recentLimit) {
      _recents.removeRange(_recentLimit, _recents.length);
    }
  }

  /// Guide §6's retry law for the credit path. Retryable: network (no
  /// status), 5xx, 429, `idempotency_key_in_flight` (a concurrent attempt
  /// holds the key — not a failure), 401 (the session died mid-queue; the
  /// sale replays after sign-in), and any UNDOCUMENTED 4xx — codes are
  /// added by server deploys, and treating an unknown refusal as terminal
  /// would silently drop a sale. Everything in the documented-terminal set
  /// parks instead.
  static bool _isRetryable(MobileApiException e) {
    final status = e.status;
    if (status == null || status >= 500 || status == 429) return true;
    if (e.code == ApiCode.idempotencyKeyInFlight) return true;
    if (e.code == ApiCode.unauthenticated) return true;
    return !_terminalCodes.contains(e.code);
  }

  static const _terminalCodes = {
    ApiCode.validationFailed,
    ApiCode.duplicateInvoice,
    ApiCode.conflict,
    ApiCode.notFound,
    ApiCode.forbidden,
    ApiCode.permissionRequired,
    ApiCode.customerNotFound,
    ApiCode.merchantNotActive,
    ApiCode.futureDated,
    ApiCode.noEffectiveRate,
    ApiCode.rateBelowAdvertised,
    ApiCode.rateNotPriced,
    ApiCode.backdatedConfirmationRequired,
    ApiCode.idempotencyKeyRequired,
    ApiCode.idempotencyKeyReuseMismatch,
    ApiCode.linesSumMismatch,
    ApiCode.saleBelowEligible,
    ApiCode.storeNotApproved,
    ApiCode.storeNotTrading,
    'method_not_allowed',
  };

  Future<void> _persist() async {
    await _store.write(
      storageKey,
      jsonEncode([for (final e in _entries) e.toJson()]),
    );
    await _store.write(
      recentsKey,
      jsonEncode([for (final r in _recents) r.toJson()]),
    );
  }

  static List<T> _decodeList<T>(
    String? raw,
    T Function(Map<String, dynamic>) parse,
  ) {
    if (raw == null || raw.isEmpty) return const [];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return [
          for (final item in decoded)
            if (item is Map) parse(item.cast<String, dynamic>()),
        ];
      }
    } on FormatException {
      // A corrupt cache reads as empty; the entries were also the server's
      // the moment they landed, so nothing money-critical lives only here
      // once drained — and an undrained corrupt entry cannot be replayed
      // safely anyway.
    }
    return const [];
  }
}
