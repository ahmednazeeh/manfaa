import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// Canned-response adapter that records every request it serves, so a test
/// can assert on the exact wire shape (headers above all) without a server.
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

ResponseBody _json(Object payload, int status, {Map<String, List<String>>? headers}) =>
    ResponseBody.fromString(
      jsonEncode(payload),
      status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
        ...?headers,
      },
    );

/// The exact /merchant/me shape (SessionController::merchant).
const _meFixture = {
  'data': {
    'user': {'id': 7, 'name': 'Aminath Shifa', 'email': 'shifa@corner.mv'},
    'merchant': {
      'id': 3,
      'name': 'Corner Mart',
      'slug': 'corner-mart',
      'status': 'active',
    },
    'permissions': ['credits.create', 'transactions.view', 'settlements.view'],
  },
};

/// The exact /merchant/home shape (HomeController::merchant) for an account
/// holding settlements.view — outstanding straight off
/// OutstandingSummary::forMerchant minus `as_of`.
const _homeFixture = {
  'data': {
    'merchant': {'name': 'Corner Mart', 'status': 'active'},
    'today': {
      'credit_count': 12,
      'eligible_laari': 4560000,
      'cashback_laari': 228000,
    },
    'outstanding': {
      'total': {
        'count': 31,
        'cashback_laari': 302000,
        'fee_laari': 60400,
        'fee_gst_laari': 4832,
        'payable_laari': 367232,
        'payable_mvr': 'MVR 3,672.32',
        'cashback_mvr': 'MVR 3,020.00',
        'fee_mvr': 'MVR 604.00',
      },
      'buckets': {
        '0_5': {
          'count': 20,
          'cashback_laari': 200000,
          'fee_laari': 40000,
          'fee_gst_laari': 3200,
          'payable_laari': 243200,
          'payable_mvr': 'MVR 2,432.00',
        },
        '6_10': {
          'count': 11,
          'cashback_laari': 102000,
          'fee_laari': 20400,
          'fee_gst_laari': 1632,
          'payable_laari': 124032,
          'payable_mvr': 'MVR 1,240.32',
        },
        '11_15': {
          'count': 0,
          'cashback_laari': 0,
          'fee_laari': 0,
          'fee_gst_laari': 0,
          'payable_laari': 0,
          'payable_mvr': 'MVR 0.00',
        },
        'overdue': {
          'count': 0,
          'cashback_laari': 0,
          'fee_laari': 0,
          'fee_gst_laari': 0,
          'payable_laari': 0,
          'payable_mvr': 'MVR 0.00',
        },
      },
      'pending_adjustments': {
        'count': 1,
        'credit_laari': -1500,
        'credit_mvr': 'MVR -15.00',
      },
    },
    'open_settlement': {
      'id': 44,
      'reference': 'STL-2026-000044',
      'state': 'awaiting_review',
      'amount_due_laari': 124032,
      'due_at': '2026-08-30T00:00:00Z',
    },
  },
};

/// The credit answer: TransactionResource under `data` (a lined sale).
const _creditFixture = {
  'data': {
    'id': 901,
    'origin': 'manual',
    'invoice_no': 'INV-1009',
    'state': 'awaiting_validation',
    'reason_code': null,
    'backdated': false,
    'currency': 'MVR',
    'eligible_laari': 100000,
    'sale_laari': 130000,
    'cashback_rate_percent': '5.00',
    'platform_fee_percent': '1.00',
    'effective_cashback_rate_percent': '2.75',
    'effective_platform_fee_percent': '0.55',
    'cashback_laari': 2750,
    'fee_laari': 550,
    'fee_gst_laari': 44,
    'occurred_at': '2026-08-17T14:05:00+05:00',
    'received_at': '2026-08-17T14:05:02+05:00',
    'lines': [
      {
        'category': null,
        'category_name_en': null,
        'amount_laari': 45000,
        'cashback_rate_percent': '5.00',
        'platform_fee_percent': '1.00',
        'cashback_laari': 2250,
        'fee_laari': 450,
        'priced_by': 'standing_rate',
        'sort': 0,
      },
      {
        'category': 'staples',
        'category_name_en': 'Staples',
        'amount_laari': 25000,
        'cashback_rate_percent': '2.00',
        'platform_fee_percent': '0.40',
        'cashback_laari': 500,
        'fee_laari': 100,
        'priced_by': 'category_rule',
        'sort': 1,
      },
    ],
  },
};

/// The exact GET /merchant/setup shape (the wizard's whole state).
const _setupFixture = {
  'data': {
    'status': 'draft',
    'steps': {'profile': true, 'location': true, 'logo': false, 'rate': true},
    'values': {
      'name': 'Fresh Mart',
      'slug': 'fresh-mart',
      'category': 'grocery',
      'channel': 'in_store',
      'eligibility_basis': 'All items except tobacco.',
      'description': 'A neighbourhood grocery on Majeedhee Magu.',
      'contact_email': 'owner@freshmart.mv',
      'contact_phone': '+9607712345',
      'support_phone': null,
      'website_url': null,
      'primary_branch': {
        'id': 12,
        'name': 'Fresh Mart',
        'address': null,
        'lat': 4.1755354,
        'lng': 73.5093474,
      },
      'logo_url': null,
      'cashback_rate_percent': '2.00',
    },
    'rate_bounds': {'min_percent': '0.50', 'max_percent': '10.00'},
    'categories': [
      {'slug': 'grocery', 'name_en': 'Grocery / Supermarket', 'name_dv': 'ފިހާރަ'},
      {'slug': 'dining', 'name_en': 'Dining', 'name_dv': null},
    ],
    'submitted_at': null,
    'rejected_reason': null,
  },
};

/// The register answer — the EXACT sign-in shape (one parser server-side
/// and client-side), minus any merchant status.
const _registerFixture = {
  'data': {
    'token': '17|zzz',
    'expires_at': '2026-11-15T09:41:00Z',
    'device_name': "Owner's phone",
    'user': {'id': 9, 'name': 'Fresh Mart', 'email': 'owner@freshmart.mv'},
    'merchant': {'id': 5, 'name': 'Fresh Mart', 'slug': 'fresh-mart'},
    'permissions': ['setup.view', 'setup.edit', 'setup.submit', 'branding.update'],
  },
};

MerchantApi _api(_RecordingAdapter adapter, {MerchantSession? session}) {
  final api = MerchantApi(session: session ?? MerchantSession(MemorySecretStore()));
  api.dio.httpClientAdapter = adapter;
  return api;
}

void main() {
  group('merchant session', () {
    test('round-trips through the store, including the permission list', () async {
      final store = MemorySecretStore();
      final session = MerchantSession(store);
      await session.init();
      expect(session.signedIn, isFalse);

      await session.saveSession(
        token: 'mt-1',
        userName: 'Aminath Shifa',
        userEmail: 'shifa@corner.mv',
        merchantId: 3,
        merchantName: 'Corner Mart',
        merchantSlug: 'corner-mart',
        merchantStatus: 'active',
        permissions: const ['credits.create', 'transactions.view'],
      );

      // A cold start reads everything back — permissions survive as a
      // JSON-encoded string beside the token.
      final rebooted = MerchantSession(store);
      await rebooted.init();

      expect(rebooted.signedIn, isTrue);
      expect(rebooted.userName, 'Aminath Shifa');
      expect(rebooted.userEmail, 'shifa@corner.mv');
      expect(rebooted.merchantId, 3);
      expect(rebooted.merchantName, 'Corner Mart');
      expect(rebooted.merchantSlug, 'corner-mart');
      expect(rebooted.merchantStatus, 'active');
      expect(rebooted.permissions, ['credits.create', 'transactions.view']);
    });

    test('can() is a flat includes over the resolved slugs', () async {
      final session = MerchantSession(MemorySecretStore());
      await session.saveSession(
        token: 't',
        userName: 'n',
        userEmail: 'e',
        permissions: const ['credits.create'],
      );

      expect(session.can('credits.create'), isTrue);
      expect(session.can('settlements.create'), isFalse);
      // Never prefix-matched: holding credits.create is not credits.*.
      expect(session.can('credits'), isFalse);
    });

    test('a fresh /merchant/me overwrites the cache and bumps revision', () async {
      final session = MerchantSession(MemorySecretStore());
      await session.saveSession(
        token: 't',
        userName: 'n',
        userEmail: 'e',
        permissions: const ['credits.create', 'settlements.view'],
      );

      var seen = 0;
      session.revision.addListener(() => seen++);

      // The demoted-cashier case: the narrowed role must land.
      await session.saveProfile(
        userName: 'n',
        userEmail: 'e',
        merchantStatus: 'suspended',
        permissions: const ['credits.create'],
      );

      expect(seen, 1);
      expect(session.merchantStatus, 'suspended');
      expect(session.can('settlements.view'), isFalse);
    });

    test('wipe clears the profile but keeps locale and theme', () async {
      final session = MerchantSession(MemorySecretStore());
      await session.saveSession(
        token: 't',
        userName: 'n',
        userEmail: 'e',
        merchantId: 3,
        permissions: const ['credits.create'],
      );
      await session.setLocale('dv');

      await session.wipe();

      expect(session.signedIn, isFalse);
      expect(session.userName, isNull);
      expect(session.merchantId, isNull);
      expect(session.permissions, isEmpty);
      expect(session.can('credits.create'), isFalse);
      // Losing your language because a token expired would be hostile.
      expect(session.locale, 'dv');
    });
  });

  group('per-app gates', () {
    MobileConfig config() => MobileConfig.fromJson(const {
          'apps': {
            'customer': {
              'android': {
                'minimum_build': 3,
                'latest_build': 5,
                'store_url': 'https://manfaa.app/app',
              },
            },
            'merchant': {
              'android': {
                'minimum_build': 7,
                'latest_build': 9,
                'store_url': 'https://manfaa.app/app#merchant',
              },
            },
          },
          'features': {'customer_claims': false},
        });

    test('the merchant gate is parsed and resolves independently', () {
      final gate = resolveGate(
        config(),
        app: 'merchant',
        platform: 'android',
        build: 6,
      );

      expect(gate.verdict, UpdateVerdict.required);
      expect(gate.storeUrl, 'https://manfaa.app/app#merchant');

      // The same build number is fine for the customer app: the gates do
      // not bleed across apps.
      expect(
        resolveGate(config(), platform: 'android', build: 6).verdict,
        UpdateVerdict.none,
      );
    });

    test('the app parameter defaults to customer (existing callers)', () {
      expect(
        resolveGate(config(), platform: 'android', build: 2).verdict,
        UpdateVerdict.required,
      );
      expect(config().customerGates['android']?.minimumBuild, 3);
    });

    test('an app the config does not carry never blocks', () {
      expect(
        resolveGate(config(), app: 'courier', platform: 'android', build: 1)
            .verdict,
        UpdateVerdict.none,
      );
    });
  });

  group('merchant models', () {
    test('MerchantMe parses the exact SessionController shape', () {
      final me = MerchantMe.fromJson(
        (_meFixture['data'] as Map).cast<String, dynamic>(),
      );

      expect(me.user.id, 7);
      expect(me.user.name, 'Aminath Shifa');
      expect(me.user.email, 'shifa@corner.mv');
      expect(me.merchant.id, 3);
      expect(me.merchant.slug, 'corner-mart');
      expect(me.merchant.status, 'active');
      expect(me.permissions, hasLength(3));
      expect(me.can('settlements.view'), isTrue);
      expect(me.can('settlements.create'), isFalse);
    });

    test('MerchantHome parses the exact HomeController shape', () {
      final home = MerchantHome.fromJson(
        (_homeFixture['data'] as Map).cast<String, dynamic>(),
      );

      expect(home.merchantName, 'Corner Mart');
      expect(home.merchantStatus, 'active');
      expect(home.today.creditCount, 12);
      expect(home.today.eligibleLaari, 4560000);
      expect(home.today.cashbackLaari, 228000);

      final outstanding = home.outstanding!;
      expect(outstanding.total.count, 31);
      expect(outstanding.total.payableLaari, 367232);
      expect(outstanding.total.feeGstLaari, 4832);
      expect(
        outstanding.buckets.keys.toList(),
        ['0_5', '6_10', '11_15', 'overdue'],
      );
      expect(outstanding.buckets['6_10']?.payableLaari, 124032);
      expect(outstanding.buckets['overdue']?.count, 0);
      expect(outstanding.pendingAdjustmentCount, 1);
      // §7 reversal credits are a NEGATIVE sum.
      expect(outstanding.pendingAdjustmentCreditLaari, -1500);

      final open = home.openSettlement!;
      expect(open.id, 44);
      expect(open.reference, 'STL-2026-000044');
      expect(open.amountDueLaari, 124032);
      expect(open.dueAt, '2026-08-30T00:00:00Z');
    });

    test('a credits-only cashier gets nulls, not zeros', () {
      final home = MerchantHome.fromJson(const {
        'merchant': {'name': 'Corner Mart', 'status': 'active'},
        'today': {'credit_count': 0, 'eligible_laari': 0, 'cashback_laari': 0},
        'outstanding': null,
        'open_settlement': null,
      });

      expect(home.outstanding, isNull);
      expect(home.openSettlement, isNull);
    });
  });

  group('merchant api', () {
    test('createCredit carries the Idempotency-Key header and wire names',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_creditFixture, 201));
      final api = _api(adapter);

      final result = await api.createCredit(
        idempotencyKey: 'sale-6f9d1c',
        customerCode: '374230',
        invoiceNo: 'INV-1009',
        eligibleLaari: 100000,
        saleLaari: 130000,
        lines: const [
          CreditLine(category: null, amountLaari: 75000),
          CreditLine(category: 'staples', amountLaari: 25000),
        ],
      );

      final request = adapter.requests.single;
      expect(request.path, '/merchant/credits');
      expect(request.headers['Idempotency-Key'], 'sale-6f9d1c');

      final body = request.data as Map;
      expect(body['customer_code'], '374230');
      expect(body['eligible_amount'], 100000);
      expect(body['sale_amount'], 130000);
      // Omitted means NOW — never a client-invented timestamp.
      expect(body.containsKey('occurred_at'), isFalse);
      expect(body.containsKey('backdated_acknowledged'), isFalse);
      // The category key must be PRESENT even for the null bucket.
      expect((body['lines'] as List).first, {
        'category': null,
        'amount_laari': 75000,
      });

      expect(result.replayed, isFalse);
      expect(result.transaction.id, 901);
      expect(result.transaction.cashbackLaari, 2750);
      expect(result.transaction.effectiveCashbackRatePercent, '2.75');
      expect(result.transaction.lines, hasLength(2));
      expect(result.transaction.lines.last.category, 'staples');
      expect(result.transaction.lines.last.cashbackRatePercent, '2.00');
    });

    test('a 200 replay is surfaced as replayed, not a new sale', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(_creditFixture, 200, headers: {
          'idempotency-replay': ['true'],
        }),
      );
      final api = _api(adapter);

      final result = await api.createCredit(
        idempotencyKey: 'sale-6f9d1c',
        customerCode: '374230',
        invoiceNo: 'INV-1009',
        eligibleLaari: 100000,
      );

      expect(result.replayed, isTrue);
      expect(result.transaction.id, 901);
    });

    test('me() refreshes the session cache from the wire answer', () async {
      final adapter = _RecordingAdapter((_) => _json(_meFixture, 200));
      final session = MerchantSession(MemorySecretStore());
      await session.saveSession(
        token: 'mt-1',
        userName: 'stale',
        userEmail: 'stale@corner.mv',
        permissions: const ['credits.create', 'settlements.create'],
      );
      final api = _api(adapter, session: session);

      final me = await api.me();

      expect(me.merchant.status, 'active');
      // The guide §3 rule, end to end: the session now holds the RESOLVED
      // permissions, not the sign-in snapshot.
      expect(session.userName, 'Aminath Shifa');
      expect(session.merchantStatus, 'active');
      expect(session.can('settlements.create'), isFalse);
      expect(session.can('settlements.view'), isTrue);
      // And the bearer token went out with the request.
      expect(
        adapter.requests.single.headers['Authorization'],
        'Bearer mt-1',
      );
    });

    test('home() parses through the full transport stack', () async {
      final adapter = _RecordingAdapter((_) => _json(_homeFixture, 200));
      final api = _api(adapter);

      final home = await api.home();

      expect(home.today.creditCount, 12);
      expect(home.outstanding?.total.payableLaari, 367232);
    });

    test('signup: request/verify speak the wire shapes', () async {
      final adapter = _RecordingAdapter((options) => switch (options.path) {
            '/merchant/signup/request-otp' => _json(
                {'data': {'sent': true, 'expires_in_minutes': 10}}, 200),
            _ => _json(
                {'data': {'signup_token': 'st-48chars', 'expires_in_minutes': 15}},
                200),
          });
      final api = _api(adapter);

      await api.requestSignupOtp('+9607712345');
      final token = await api.verifySignupOtp(
        phone: '+9607712345',
        code: '123456',
      );

      expect(token, 'st-48chars');
      expect(adapter.requests.first.data, {'phone': '+9607712345'});
      expect(adapter.requests.last.data, {
        'phone': '+9607712345',
        'code': '123456',
      });
      // Public routes: no Authorization header goes out.
      expect(adapter.requests.first.headers['Authorization'], isNull);
    });

    test('registerMerchant persists a USABLE session, status draft', () async {
      final adapter = _RecordingAdapter((options) =>
          options.path == '/merchant/signup/register'
              ? _json(_registerFixture, 201)
              : _json(_setupFixture, 200));
      final session = MerchantSession(MemorySecretStore());
      await session.init();
      final api = _api(adapter, session: session);

      await api.registerMerchant(
        signupToken: 'st-48chars',
        businessName: 'Fresh Mart',
        email: 'owner@freshmart.mv',
        password: 'hunter22',
        deviceName: "Owner's phone",
      );

      final body = adapter.requests.first.data as Map;
      expect(body['signup_token'], 'st-48chars');
      expect(body['business_name'], 'Fresh Mart');
      // Omitted, never null-sent, when the owner skipped the Thaana name.
      expect(body.containsKey('business_name_dv'), isFalse);

      expect(session.signedIn, isTrue);
      expect(session.merchantName, 'Fresh Mart');
      // The payload has no status; the register contract guarantees draft —
      // this is what routes the fresh account straight into the wizard.
      expect(session.merchantStatus, 'draft');
      expect(session.can('setup.submit'), isTrue);

      // And the minted token authenticates the very next call.
      await api.getSetup();
      expect(adapter.requests.last.headers['Authorization'], 'Bearer 17|zzz');
    });

    test('the Thaana name is sent when given', () async {
      final adapter = _RecordingAdapter((_) => _json(_registerFixture, 201));
      final api = _api(adapter);

      await api.registerMerchant(
        signupToken: 'st',
        businessName: 'Fresh Mart',
        businessNameDv: 'ފްރެޝް މާޓް',
        email: 'owner@freshmart.mv',
        password: 'hunter22',
        deviceName: 'd',
      );

      expect((adapter.requests.single.data as Map)['business_name_dv'],
          'ފްރެޝް މާޓް');
    });

    test('MerchantSetupState parses the exact wizard shape', () async {
      final adapter = _RecordingAdapter((_) => _json(_setupFixture, 200));
      final api = _api(adapter);

      final state = await api.getSetup();

      expect(state.status, 'draft');
      expect(state.editable, isTrue);
      expect(state.steps.profile, isTrue);
      expect(state.steps.logo, isFalse);
      expect(state.values.name, 'Fresh Mart');
      expect(state.values.category, 'grocery');
      expect(state.values.channel, 'in_store');
      expect(
        state.values.description,
        'A neighbourhood grocery on Majeedhee Magu.',
      );
      expect(state.values.supportPhone, isNull);
      expect(state.values.cashbackRatePercent, '2.00');
      expect(state.values.primaryBranch?.lat, 4.1755354);
      expect(state.pinned, isTrue);
      expect(state.locationRequired, isTrue);
      expect(state.rateBounds.minPercent, '0.50');
      expect(state.rateBounds.maxPercent, '10.00');
      expect(state.categories, hasLength(2));
      expect(state.categories.first.label(dhivehi: true), 'ފިހާރަ');
      // A category without a Thaana name falls back to English, not blank.
      expect(state.categories.last.label(dhivehi: true), 'Dining');
      expect(state.submittedAt, isNull);
      expect(state.rejectedReason, isNull);
    });

    test('the profile save sends every key — explicit nulls clear', () async {
      final adapter = _RecordingAdapter((_) => _json(_setupFixture, 200));
      final api = _api(adapter);

      await api.saveSetupProfile(
        category: 'grocery',
        channel: 'both',
        description: 'A neighbourhood grocery on Majeedhee Magu.',
        contactEmail: 'owner@freshmart.mv',
      );

      final request = adapter.requests.single;
      expect(request.method, 'PATCH');
      expect(request.data, {
        'category': 'grocery',
        'channel': 'both',
        'description': 'A neighbourhood grocery on Majeedhee Magu.',
        'contact_email': 'owner@freshmart.mv',
        'contact_phone': null,
        'support_phone': null,
        'website_url': null,
      });
    });

    test('an unwritten description travels as an explicit null', () async {
      final adapter = _RecordingAdapter((_) => _json(_setupFixture, 200));
      final api = _api(adapter);

      await api.saveSetupProfile(category: 'grocery', channel: 'in_store');

      expect(
        (adapter.requests.single.data as Map).containsKey('description'),
        isTrue,
      );
      expect((adapter.requests.single.data as Map)['description'], isNull);
    });

    test('the terms save touches ONLY eligibility_basis', () async {
      final adapter = _RecordingAdapter((_) => _json(_setupFixture, 200));
      final api = _api(adapter);

      await api.saveSetupTerms('All items except tobacco.');

      expect(adapter.requests.single.data, {
        'eligibility_basis': 'All items except tobacco.',
      });
    });

    test('rate goes out as the exact percent STRING typed', () async {
      final adapter = _RecordingAdapter((_) => _json(_setupFixture, 200));
      final api = _api(adapter);

      final state = await api.saveSetupRate('2.50');

      expect(adapter.requests.single.data, {'cashback_rate_percent': '2.50'});
      expect(state.values.cashbackRatePercent, '2.00');
    });

    test('the logo upload is multipart under the field `logo`', () async {
      final adapter = _RecordingAdapter((_) => _json({
            'data': {
              'logo_url': 'https://manfaa.app/api/merchants/fresh-mart/logo?v=abc',
            },
          }, 200));
      final api = _api(adapter);

      final result = await api.uploadSetupLogo(
        bytes: Uint8List.fromList([1, 2, 3]),
        filename: 'logo.png',
      );

      expect(
        result.logoUrl,
        'https://manfaa.app/api/merchants/fresh-mart/logo?v=abc',
      );
      // A draft store's logo goes live on the spot — nothing queued.
      expect(result.queued, isNull);
      expect(result.pending, isFalse);
      final data = adapter.requests.single.data;
      expect(data, isA<FormData>());
      expect((data as FormData).files.single.key, 'logo');
      expect(data.files.single.value.filename, 'logo.png');
    });

    test('setup_incomplete surfaces the missing[] keys typed', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'error': {
            'code': 'setup_incomplete',
            'message': 'The store setup is not complete: category, terms.',
            'meta': {
              'missing': ['category', 'terms'],
            },
          },
        }, 422),
      );
      final api = _api(adapter);

      await expectLater(
        api.submitSetup(),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', ApiCode.setupIncomplete)
              .having((e) => e.missingRequirements, 'missing',
                  ['category', 'terms']),
        ),
      );
    });

    test('a credit refusal surfaces the envelope code and meta', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'error': {
            'code': 'backdated_confirmation_required',
            'message':
                'This sale is older than your refund window. Once credited it cannot be reversed and is payable immediately.',
            'meta': {'server_time': '2026-08-17T09:00:00Z'},
          },
        }, 422),
      );
      final api = _api(adapter);

      await expectLater(
        api.createCredit(
          idempotencyKey: 'sale-old',
          customerCode: '374230',
          invoiceNo: 'INV-1',
          eligibleLaari: 100,
          occurredAt: '2026-07-01T10:00:00+05:00',
        ),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', ApiCode.backdatedConfirmationRequired)
              .having((e) => e.meta['server_time'], 'meta.server_time',
                  '2026-08-17T09:00:00Z'),
        ),
      );
    });
  });
}
