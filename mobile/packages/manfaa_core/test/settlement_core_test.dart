import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// MR3 wire shapes: the settlements suite exactly as the server emits it —
/// SettlementController + OutstandingSummary + SettlementPreview +
/// SettlementResource/WalletResource — and the exact request bodies the
/// three money writes send. Integer laari everywhere; percent strings kept
/// exact; the server's *_mvr display strings never parsed.
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

/// The exact GET /merchant/wallet shape (WalletResource, movements loaded).
const _walletFixture = {
  'data': {
    'balance_laari': 8175,
    'balance_mvr': '81.75',
    'currency': 'MVR',
    'transactions': [
      {
        'id': 9,
        'amount_laari': -11825,
        'amount_mvr': '-118.25',
        'balance_after_laari': 8175,
        'type': 'settlement',
        'reference_type': 'settlement',
        'reference_id': 44,
        'description': 'Settlement ST-2026-00044',
        'created_at': '2026-08-15T09:12:00+00:00',
      },
      {
        'id': 8,
        'amount_laari': 20000,
        'amount_mvr': '200.00',
        'balance_after_laari': 20000,
        'type': 'top_up',
        'reference_type': null,
        'reference_id': null,
        'description': 'Bank top-up (FT99231)',
        'created_at': '2026-08-10T10:00:00+00:00',
      },
    ],
  },
};

/// The exact GET /merchant/settlements/preview shape (SettlementPreview::for
/// + PromptDiscountResult::toArray), settle-all over one eligible row.
const _previewFixture = {
  'data': {
    'as_of': '2026-08-16T09:41:00+00:00',
    'transaction_ids': [61],
    'transaction_count': 1,
    'sale_total_laari': 100000,
    'cashback_total_laari': 2000,
    'fee_total_laari': 750,
    'fee_gst_total_laari': 0,
    'line_total_laari': 2750,
    'credit_applied_laari': 0,
    'credit_applied_mvr': '0.00',
    'discount_laari': 38,
    'discount_mvr': '0.38',
    'amount_due_before_discount_laari': 2750,
    'amount_due_laari': 2712,
    'amount_due_mvr': '27.12',
    'due_at': '2026-08-25T00:00:00+00:00',
    'discount': {
      'eligible': true,
      'reason_code': 'eligible',
      'rate_percent': '5.00',
      'max_age_days': 15,
      'discount_laari': 38,
      'discount_mvr': '0.38',
      'fee_discount_laari': 38,
      'gst_relief_laari': 0,
    },
    'transactions': [
      {
        'id': 61,
        'invoice_no': 'INV-2107',
        'occurred_at': '2026-08-15T10:00:00+05:00',
        'clock_start_at': '2026-08-10T00:00:00+00:00',
        'due_at': '2026-08-25T00:00:00+00:00',
        'age_days': 6,
        'overdue': false,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'due_laari': 2750,
        'due_mvr': '27.50',
        'selected': true,
      },
    ],
    'buckets': {
      'all': {
        'count': 1,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'due_laari': 2750,
        'due_mvr': '27.50',
        'transaction_ids': [61],
      },
      'older_than_5': {
        'count': 1,
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'due_laari': 2750,
        'due_mvr': '27.50',
        'transaction_ids': [61],
      },
      'older_than_10': {
        'count': 0,
        'cashback_laari': 0,
        'fee_laari': 0,
        'fee_gst_laari': 0,
        'due_laari': 0,
        'due_mvr': '0.00',
        'transaction_ids': <int>[],
      },
      'overdue': {
        'count': 0,
        'cashback_laari': 0,
        'fee_laari': 0,
        'fee_gst_laari': 0,
        'due_laari': 0,
        'due_mvr': '0.00',
        'transaction_ids': <int>[],
      },
    },
    'payment_instructions': {
      'reference_preview': 'ST-2026-00042',
      'reference_is_final': false,
      'amount_due_laari': 2712,
      'amount_due_mvr': '27.12',
      'bank_account': {
        'bank_name': 'bml',
        'account_no': '7730000123456',
        'account_name': 'Manfaa Pvt Ltd',
      },
      'bank_accounts': [
        {
          'id': 1,
          'bank_name': 'bml',
          'account_no': '7730000123456',
          'account_name': 'Manfaa Pvt Ltd',
          'currency': 'MVR',
          'is_primary': true,
        },
        {
          'id': 2,
          'bank_name': 'mib',
          'account_no': '9010000765432',
          'account_name': 'Manfaa Pvt Ltd',
          'currency': 'MVR',
          'is_primary': false,
        },
      ],
      'needs_configuration': false,
    },
  },
};

/// The exact SettlementResource shape (lines.transaction + payments loaded),
/// as POST /merchant/settlements answers it (201).
const _settlementFixture = {
  'data': {
    'id': 44,
    'reference': 'ST-2026-00042',
    'state': 'payment_review',
    'funding_method': 'bank',
    'currency': 'MVR',
    'sale_total_laari': 100000,
    'cashback_total_laari': 2000,
    'fee_total_laari': 750,
    'fee_gst_total_laari': 0,
    'discount_laari': 38,
    'discount_mvr': '0.38',
    'discount_rate_percent': '5.00',
    'discount_reason': 'eligible',
    'amount_due_laari': 2712,
    'amount_received_laari': 2712,
    'cashback_total_mvr': '20.00',
    'fee_total_mvr': '7.50',
    'amount_due_mvr': '27.12',
    'amount_received_mvr': '27.12',
    'due_at': '2026-08-25T00:00:00+00:00',
    'created_at': '2026-08-16T09:45:00+00:00',
    'payment_instructions': {
      'reference': 'ST-2026-00042',
      'amount_due_laari': 2712,
      'amount_due_mvr': '27.12',
      'bank_account': {
        'bank_name': 'bml',
        'account_no': '7730000123456',
        'account_name': 'Manfaa Pvt Ltd',
      },
      'bank_accounts': [
        {
          'id': 1,
          'bank_name': 'bml',
          'account_no': '7730000123456',
          'account_name': 'Manfaa Pvt Ltd',
          'currency': 'MVR',
          'is_primary': true,
        },
      ],
      'needs_configuration': false,
    },
    'merchant_status': {
      'code': 'verifying',
      'message': 'Manfaa is verifying your transfer.',
      'rejection': null,
    },
    'lines': [
      {
        'id': 71,
        'transaction_id': 61,
        'currency': 'MVR',
        'cashback_laari': 2000,
        'fee_laari': 750,
        'fee_gst_laari': 0,
        'due_laari': 2750,
        'due_mvr': '27.50',
        'allocated_at': null,
        'transaction': {
          'id': 61,
          'origin': 'manual',
          'invoice_no': 'INV-2107',
          'state': 'payable_unfunded',
          'reason_code': null,
          'backdated': false,
          'currency': 'MVR',
          'eligible_laari': 100000,
          'sale_laari': null,
          'cashback_rate_percent': '2.00',
          'platform_fee_percent': '0.75',
          'effective_cashback_rate_percent': '2.00',
          'effective_platform_fee_percent': '0.75',
          'cashback_laari': 2000,
          'fee_laari': 750,
          'fee_gst_laari': 0,
          'occurred_at': '2026-08-15T10:00:00+05:00',
          'received_at': '2026-08-15T10:00:01+05:00',
        },
      },
    ],
    'payments': [
      {
        'id': 12,
        'settlement_id': 44,
        'amount_laari': 2712,
        'amount_mvr': '27.12',
        'currency': 'MVR',
        'method': 'bank',
        'bank_ref': 'FT12345',
        'slip_path': 'slips/44/receipt.jpg',
        'has_slip': true,
        'slip_mime': 'image/jpeg',
        'slip_size_bytes': 204800,
        'uploaded_by': 7,
        'state': 'pending',
        'matched_by': null,
        'matched_at': null,
        'rejected_by': null,
        'rejected_at': null,
        'rejection_reason': null,
        'created_at': '2026-08-16T09:45:00+00:00',
      },
    ],
  },
};

void main() {
  group('slip pre-flight', () {
    test('size first, then extension; null when acceptable', () {
      expect(
        checkSlipFile(name: 'slip.jpg', sizeBytes: kMaxSlipBytes + 1),
        SlipRejection.tooLarge,
      );
      // Size wins even when the type is also wrong — one refusal at a time,
      // and the size one saves the read.
      expect(
        checkSlipFile(name: 'slip.svg', sizeBytes: kMaxSlipBytes + 1),
        SlipRejection.tooLarge,
      );
      expect(
        checkSlipFile(name: 'slip.svg', sizeBytes: 1024),
        SlipRejection.unsupportedType,
      );
      expect(
        checkSlipFile(name: 'slip.jpg', sizeBytes: 0),
        SlipRejection.unsupportedType,
      );
      expect(checkSlipFile(name: 'slip.PDF', sizeBytes: 1024), isNull);
      expect(
        checkSlipFile(name: 'slip.webp', sizeBytes: kMaxSlipBytes),
        isNull,
      );
    });
  });

  group('wallet', () {
    test('parses the exact WalletResource shape, movements signed', () async {
      final adapter = _RecordingAdapter((_) => _json(_walletFixture, 200));
      final wallet = await _api(adapter).wallet();

      expect(adapter.requests.single.path, '/merchant/wallet');
      expect(wallet.balanceLaari, 8175);
      expect(wallet.currency, 'MVR');
      expect(wallet.movements, hasLength(2));

      final draw = wallet.movements.first;
      expect(draw.amountLaari, -11825);
      expect(draw.isCredit, isFalse);
      expect(draw.balanceAfterLaari, 8175);
      expect(draw.type, 'settlement');
      expect(draw.referenceId, 44);
      expect(draw.description, 'Settlement ST-2026-00044');

      final topUp = wallet.movements.last;
      expect(topUp.amountLaari, 20000);
      expect(topUp.isCredit, isTrue);
      expect(topUp.type, 'top_up');
      expect(topUp.referenceType, isNull);
    });

    test('a first read answering 201 parses identically', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'balance_laari': 0,
            'balance_mvr': '0.00',
            'currency': 'MVR',
            'transactions': <Object>[],
          },
        }, 201),
      );
      final wallet = await _api(adapter).wallet();

      expect(wallet.balanceLaari, 0);
      expect(wallet.movements, isEmpty);
    });
  });

  group('outstanding', () {
    test('parses OutstandingSummary with as_of present', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'as_of': '2026-08-16T09:41:00+05:00',
            'total': {
              'count': 1,
              'cashback_laari': 2000,
              'fee_laari': 750,
              'fee_gst_laari': 0,
              'payable_laari': 2750,
              'payable_mvr': '27.50',
              'cashback_mvr': '20.00',
              'fee_mvr': '7.50',
            },
            'buckets': {
              '0_5': {
                'count': 1,
                'cashback_laari': 2000,
                'fee_laari': 750,
                'fee_gst_laari': 0,
                'payable_laari': 2750,
                'payable_mvr': '27.50',
              },
              '6_10': {
                'count': 0,
                'cashback_laari': 0,
                'fee_laari': 0,
                'fee_gst_laari': 0,
                'payable_laari': 0,
                'payable_mvr': '0.00',
              },
              '11_15': {
                'count': 0,
                'cashback_laari': 0,
                'fee_laari': 0,
                'fee_gst_laari': 0,
                'payable_laari': 0,
                'payable_mvr': '0.00',
              },
              'overdue': {
                'count': 0,
                'cashback_laari': 0,
                'fee_laari': 0,
                'fee_gst_laari': 0,
                'payable_laari': 0,
                'payable_mvr': '0.00',
              },
            },
            'pending_adjustments': {
              'count': 1,
              'credit_laari': -500,
              'credit_mvr': '-5.00',
            },
          },
        }, 200),
      );
      final outstanding = await _api(adapter).outstanding();

      expect(adapter.requests.single.path, '/merchant/outstanding');
      expect(outstanding.total.payableLaari, 2750);
      expect(outstanding.buckets['0_5']?.count, 1);
      expect(outstanding.buckets['overdue']?.payableLaari, 0);
      // §7 reversal credits are a NEGATIVE sum.
      expect(outstanding.pendingAdjustmentCreditLaari, -500);
    });
  });

  group('preview', () {
    test('settle_all travels as the mode flag, and every figure parses',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_previewFixture, 200));
      final preview = await _api(adapter).settlementPreview(settleAll: true);

      final request = adapter.requests.single;
      expect(request.path, '/merchant/settlements/preview');
      expect(request.queryParameters['settle_all'], 1);
      expect(request.queryParameters.containsKey('transaction_ids[]'), isFalse);

      expect(preview.transactionIds, [61]);
      expect(preview.transactionCount, 1);
      expect(preview.cashbackTotalLaari, 2000);
      expect(preview.feeTotalLaari, 750);
      expect(preview.feeGstTotalLaari, 0);
      expect(preview.lineTotalLaari, 2750);
      expect(preview.creditAppliedLaari, 0);
      expect(preview.discountLaari, 38);
      expect(preview.amountDueBeforeDiscountLaari, 2750);
      // due = line total − credit − discount, all server integers.
      expect(preview.amountDueLaari, 2712);
      expect(preview.dueAt, '2026-08-25T00:00:00+00:00');

      final discount = preview.discount!;
      expect(discount.eligible, isTrue);
      expect(discount.reasonCode, 'eligible');
      // The rate stays the EXACT percent string.
      expect(discount.ratePercent, '5.00');
      expect(discount.maxAgeDays, 15);
      expect(discount.feeDiscountLaari, 38);
      expect(discount.disabled, isFalse);

      final row = preview.transactions.single;
      expect(row.invoiceNo, 'INV-2107');
      expect(row.ageDays, 6);
      expect(row.overdue, isFalse);
      expect(row.dueLaari, 2750);
      expect(row.selected, isTrue);

      expect(preview.buckets.keys.toList(),
          ['all', 'older_than_5', 'older_than_10', 'overdue']);
      expect(preview.buckets['older_than_5']?.transactionIds, [61]);
      expect(preview.buckets['overdue']?.count, 0);

      final instructions = preview.paymentInstructions;
      expect(instructions.reference, 'ST-2026-00042');
      expect(instructions.referenceIsFinal, isFalse);
      expect(instructions.amountDueLaari, 2712);
      expect(instructions.bankAccount?.bankName, 'bml');
      expect(instructions.bankAccounts, hasLength(2));
      expect(instructions.bankAccounts.first.id, 1);
      expect(instructions.bankAccounts.first.isPrimary, isTrue);
      expect(instructions.bankAccounts.last.bankName, 'mib');
      expect(instructions.needsConfiguration, isFalse);
    });

    test('a named subset sends transaction_ids[] exactly', () async {
      final adapter = _RecordingAdapter((_) => _json(_previewFixture, 200));
      await _api(adapter).settlementPreview(transactionIds: const [61, 62]);

      final request = adapter.requests.single;
      expect(request.queryParameters['transaction_ids[]'], [61, 62]);
      expect(request.queryParameters.containsKey('settle_all'), isFalse);
      // The [] key is what PHP parses back into an array.
      expect(request.uri.query, contains('transaction_ids%5B%5D=61'));
      expect(request.uri.query, contains('transaction_ids%5B%5D=62'));
    });

    test('a missing discount block parses as null, never invented', () {
      final data =
          (_previewFixture['data'] as Map).cast<String, dynamic>();
      final stripped = Map<String, dynamic>.from(data)..remove('discount');

      final preview = SettlementPreviewData.fromJson(stripped);

      expect(preview.discount, isNull);
      expect(preview.amountDueLaari, 2712);
    });

    test('a disabled discount is recognised off the exact "0.00" string', () {
      final discount = SettlementDiscount.fromJson(const {
        'eligible': false,
        'reason_code': 'disabled',
        'rate_percent': '0.00',
        'max_age_days': 15,
        'discount_laari': 0,
        'fee_discount_laari': 0,
        'gst_relief_laari': 0,
      });

      expect(discount.disabled, isTrue);
      // And a live rate is not: "10.00" must never read as zero.
      final live = SettlementDiscount.fromJson(const {
        'eligible': true,
        'reason_code': 'eligible',
        'rate_percent': '10.00',
        'max_age_days': 15,
        'discount_laari': 75,
        'fee_discount_laari': 75,
        'gst_relief_laari': 0,
      });
      expect(live.disabled, isFalse);
    });
  });

  group('settlements list + detail', () {
    test('the list is PAGE pagination, not cursor', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': [
            (_settlementFixture['data'] as Map).cast<String, dynamic>(),
          ],
          'links': const {'first': 'x', 'last': 'x', 'prev': null, 'next': null},
          'meta': const {
            'current_page': 1,
            'from': 1,
            'last_page': 3,
            'per_page': 25,
            'to': 1,
            'total': 51,
          },
        }, 200),
      );
      final page = await _api(adapter).settlements(page: 1);

      expect(adapter.requests.single.path, '/merchant/settlements');
      expect(adapter.requests.single.queryParameters['page'], 1);
      expect(page.items, hasLength(1));
      expect(page.currentPage, 1);
      expect(page.lastPage, 3);
      expect(page.total, 51);
      expect(page.hasMore, isTrue);
    });

    test('parses the exact SettlementResource shape', () async {
      final adapter = _RecordingAdapter((_) => _json(_settlementFixture, 200));
      final settlement = await _api(adapter).settlement(44);

      expect(adapter.requests.single.path, '/merchant/settlements/44');
      expect(settlement.id, 44);
      expect(settlement.reference, 'ST-2026-00042');
      expect(settlement.state, 'payment_review');
      expect(settlement.fundingMethod, 'bank');
      expect(settlement.cashbackTotalLaari, 2000);
      expect(settlement.feeTotalLaari, 750);
      expect(settlement.discountLaari, 38);
      // The stamped rate stays the exact string the batch was priced at.
      expect(settlement.discountRatePercent, '5.00');
      expect(settlement.discountReason, 'eligible');
      expect(settlement.amountDueLaari, 2712);
      expect(settlement.amountReceivedLaari, 2712);
      expect(settlement.remainingLaari, 0);

      expect(settlement.merchantStatus.code, 'verifying');
      expect(settlement.merchantStatus.rejection, isNull);

      // The detail's payment_instructions reference IS final (no flag sent).
      expect(settlement.paymentInstructions.reference, 'ST-2026-00042');
      expect(settlement.paymentInstructions.referenceIsFinal, isTrue);

      final line = settlement.lines.single;
      expect(line.transactionId, 61);
      expect(line.dueLaari, 2750);
      expect(line.invoiceNo, 'INV-2107');
      expect(line.occurredAt, '2026-08-15T10:00:00+05:00');

      final payment = settlement.payments.single;
      expect(payment.amountLaari, 2712);
      expect(payment.method, 'bank');
      expect(payment.hasSlip, isTrue);
      expect(payment.slipMime, 'image/jpeg');
      expect(payment.state, 'pending');
    });

    test('a list row without loaded lines parses to empty lists', () {
      final data = Map<String, dynamic>.from(
        (_settlementFixture['data'] as Map).cast<String, dynamic>(),
      )
        ..remove('lines')
        ..remove('payments');

      final settlement = MerchantSettlement.fromJson(data);
      expect(settlement.lines, isEmpty);
      expect(settlement.payments, isEmpty);
    });

    test('a cancelled batch carries the admin rejection', () {
      final data = Map<String, dynamic>.from(
        (_settlementFixture['data'] as Map).cast<String, dynamic>(),
      )
        ..['state'] = 'cancelled'
        ..['merchant_status'] = {
          'code': 'rejected',
          'message':
              'Manfaa could not verify your transfer — see the reason, then submit a new settlement.',
          'rejection': {
            'reason': 'Amount on the slip does not match the transfer.',
            'rejected_at': '2026-08-17T08:00:00+00:00',
            'bank_ref': 'FT12345',
            'payment_id': 12,
          },
        };

      final settlement = MerchantSettlement.fromJson(data);
      expect(settlement.merchantStatus.code, 'rejected');
      expect(settlement.merchantStatus.rejection?.reason,
          'Amount on the slip does not match the transfer.');
      expect(settlement.merchantStatus.rejection?.paymentId, 12);
    });
  });

  group('money writes', () {
    test('createSettlement sends multipart with settle_all as a MODE',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_settlementFixture, 201));
      final settlement = await _api(adapter).createSettlement(
        settleAll: true,
        amountLaari: 2712,
        slipBytes: Uint8List.fromList(const [1, 2, 3]),
        slipFilename: 'slip.jpg',
        platformBankAccountId: 1,
      );

      final request = adapter.requests.single;
      expect(request.path, '/merchant/settlements');
      expect(request.method, 'POST');

      final form = request.data as FormData;
      final fields = {for (final f in form.fields) f.key: f.value};
      expect(fields['settle_all'], '1');
      expect(fields.containsKey('transaction_ids[]'), isFalse);
      // Integer laari as the string of the integer — never a decimal.
      expect(fields['amount'], '2712');
      expect(fields['platform_bank_account_id'], '1');
      // No bank_ref field when none was given — the slip carries it.
      expect(fields.containsKey('bank_ref'), isFalse);
      expect(form.files.single.key, 'slip');
      expect(form.files.single.value.filename, 'slip.jpg');

      expect(settlement.state, 'payment_review');
      expect(settlement.merchantStatus.code, 'verifying');
    });

    test('a named selection repeats transaction_ids[] per id', () async {
      final adapter = _RecordingAdapter((_) => _json(_settlementFixture, 201));
      await _api(adapter).createSettlement(
        transactionIds: const [61, 62],
        amountLaari: 5500,
        bankRef: 'FT12345',
        slipBytes: Uint8List.fromList(const [1]),
        slipFilename: 'slip.pdf',
      );

      final form = adapter.requests.single.data as FormData;
      final ids = [
        for (final f in form.fields)
          if (f.key == 'transaction_ids[]') f.value,
      ];
      expect(ids, ['61', '62']);
      final fields = {for (final f in form.fields) f.key: f.value};
      expect(fields['bank_ref'], 'FT12345');
      expect(fields.containsKey('settle_all'), isFalse);
    });

    test('walletSettle posts the JSON selection body', () async {
      final adapter = _RecordingAdapter((_) => _json(_settlementFixture, 201));
      await _api(adapter).walletSettle(settleAll: true);

      final request = adapter.requests.single;
      expect(request.path, '/merchant/settlements/wallet');
      expect(request.data, {'settle_all': true});

      await _api(adapter).walletSettle(transactionIds: const [61]);
      expect(adapter.requests.last.data, {
        'transaction_ids': [61],
      });
    });

    test('addSettlementReceipt posts to the batch, amount + slip only',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_settlementFixture, 201));
      await _api(adapter).addSettlementReceipt(
        id: 44,
        amountLaari: 1000,
        slipBytes: Uint8List.fromList(const [1]),
        slipFilename: 'rest.png',
      );

      final request = adapter.requests.single;
      expect(request.path, '/merchant/settlements/44/receipts');
      final form = request.data as FormData;
      final fields = {for (final f in form.fields) f.key: f.value};
      expect(fields, {'amount': '1000'});
      expect(form.files.single.key, 'slip');
    });

    test('an insufficient-wallet 422 surfaces the server prose', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'error': {
            'code': 'validation_failed',
            'message':
                'This settlement needs MVR 27.12 but the wallet holds MVR 0.00.',
          },
        }, 422),
      );

      try {
        await _api(adapter).walletSettle(settleAll: true);
        fail('expected MobileApiException');
      } on MobileApiException catch (e) {
        expect(e.code, ApiCode.validationFailed);
        expect(e.message, contains('wallet holds MVR 0.00'));
      }
    });

    test('duplicate_bank_ref and the slip refusals keep their codes',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'error': {
            'code': 'duplicate_bank_ref',
            'message': 'A slip with this bank reference was already submitted.',
          },
        }, 409),
      );

      try {
        await _api(adapter).createSettlement(
          settleAll: true,
          amountLaari: 2712,
          bankRef: 'FT12345',
          slipBytes: Uint8List.fromList(const [1]),
          slipFilename: 'slip.jpg',
        );
        fail('expected MobileApiException');
      } on MobileApiException catch (e) {
        expect(e.code, ApiCode.duplicateBankRef);
      }
    });
  });
}
