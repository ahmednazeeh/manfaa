import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'api_base.dart';
import 'merchant_models.dart';
import 'models.dart';
import 'session.dart';

/// The merchant app's client for /api/mobile/v1 — the till in the pocket.
///
/// Same transport as the customer client ([ManfaaApiBase]): bearer auth,
/// the error envelope, conditional GETs, the retry law. What is different
/// is the money path: `POST /merchant/credits` REQUIRES an Idempotency-Key
/// per sale, because a till that loses signal queues sales locally and
/// drains them on reconnect — the key is what lets a request that timed out
/// AFTER the server committed replay its original answer instead of
/// booking a second sale.
class MerchantApi extends ManfaaApiBase<MerchantSession> {
  MerchantApi({required super.session, super.dio});

  // ------------------------------------------------------------- auth

  /// Password sign-in (guide §2: merchants are email+password; 90-day
  /// token, 5-device cap). The payload's user/merchant/permissions land in
  /// the session — but WITHOUT the merchant's status, which sign-in does
  /// not return: call [me] immediately after, as on every launch/resume.
  Future<void> signIn({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/auth/token',
        data: {
          'email': email,
          'password': password,
          'device_name': deviceName,
        },
      ),
    );

    await _saveAuthPayload(data);
  }

  /// The one parser for the token-minting answers — sign-in and signup
  /// register share the EXACT response shape, so they share this. The
  /// payload never carries the merchant's status; [statusOverride] lets
  /// register write the status its contract guarantees.
  Future<void> _saveAuthPayload(
    Map<String, dynamic>? data, {
    String? statusOverride,
  }) async {
    final payload = (data?['data'] as Map?)?.cast<String, dynamic>() ?? {};
    final user = MerchantUserInfo.fromJson(
      (payload['user'] as Map?)?.cast<String, dynamic>() ?? {},
    );
    final merchant = MerchantInfo.fromJson(
      (payload['merchant'] as Map?)?.cast<String, dynamic>() ?? {},
    );

    await session.saveSession(
      token: payload['token'] as String? ?? '',
      userName: user.name,
      userEmail: user.email,
      merchantId: merchant.id == 0 ? null : merchant.id,
      merchantName: merchant.name,
      merchantSlug: merchant.slug,
      merchantStatus: merchant.status ?? statusOverride,
      permissions: [
        for (final slug in (payload['permissions'] as List? ?? const []))
          slug.toString(),
      ],
    );
  }

  // ------------------------------------------------------------- signup

  /// Ask for a signup code. Enumeration-safe server-side: the answer is
  /// identical for known and unknown phones. The SMS budget is 3/hour per
  /// phone (SHARED with the web signup) — a 429 here still leaves an
  /// earlier code redeemable for its 10-minute life.
  Future<void> requestSignupOtp(String phone) async => run(
        () => dio.post<void>('/merchant/signup/request-otp', data: {
          'phone': phone,
        }),
      );

  /// Redeem the code for a signup token (15-minute life, single use).
  /// Refusals: `otp_invalid` (retype or resend), `otp_attempts_exceeded`
  /// (five wrong guesses killed the code — request a fresh one).
  Future<String> verifySignupOtp({
    required String phone,
    required String code,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/signup/verify-otp',
          data: {'phone': phone, 'code': code}),
    );

    return ((data?['data'] as Map?)?['signup_token'])?.toString() ?? '';
  }

  /// Finish signup — the mobile difference from the web: this mints a
  /// normal 90-day merchant token instead of a session, and the answer is
  /// the EXACT sign-in shape, so the app is signed in the moment it lands.
  /// The merchant is created `draft`, which the payload does not carry —
  /// saved here so the router walks straight into the wizard, and
  /// /merchant/me refreshes it as usual afterwards.
  ///
  /// Refusals: `signup_token_invalid` (expired/consumed — restart from the
  /// phone step), `email_already_registered` (route to sign-in; only sent
  /// after the OTP proved phone possession).
  Future<void> registerMerchant({
    required String signupToken,
    required String businessName,
    String? businessNameDv,
    required String email,
    required String password,
    required String deviceName,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/signup/register', data: {
        'signup_token': signupToken,
        'business_name': businessName,
        'business_name_dv': ?businessNameDv,
        'email': email,
        'password': password,
        'device_name': deviceName,
      }),
    );

    await _saveAuthPayload(data, statusOverride: 'draft');
  }

  // ------------------------------------------------------- setup wizard

  MerchantSetupState _setupState(Map<String, dynamic>? data) =>
      MerchantSetupState.fromJson(
        (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
      );

  /// The whole resumable wizard state — readable in EVERY status (the
  /// waiting and rejected screens render from it too). Gate: `setup.view`.
  Future<MerchantSetupState> getSetup() async =>
      MerchantSetupState.fromJson(await getJson('/merchant/setup'));

  /// The profile step's save: category + channel + contacts, all keys sent
  /// every time (an explicit null CLEARS server-side — that is how "same as
  /// contact" stores the support phone). Gate: `setup.edit`; refused with
  /// `setup_not_editable` outside draft|rejected. Answers the full state.
  Future<MerchantSetupState> saveSetupProfile({
    required String category,
    required String channel,
    String? contactEmail,
    String? contactPhone,
    String? supportPhone,
    String? websiteUrl,
  }) async =>
      _setupState(await run(
        () => dio.patch<Map<String, dynamic>>('/merchant/setup/profile', data: {
          'category': category,
          'channel': channel,
          'contact_email': contactEmail,
          'contact_phone': contactPhone,
          'support_phone': supportPhone,
          'website_url': websiteUrl,
        }),
      ));

  /// The terms step — same endpoint as the profile, ONLY the eligibility
  /// text: absent keys are untouched, so the two steps never clobber each
  /// other's fields.
  Future<MerchantSetupState> saveSetupTerms(String eligibilityBasis) async =>
      _setupState(await run(
        () => dio.patch<Map<String, dynamic>>('/merchant/setup/profile', data: {
          'eligibility_basis': eligibilityBasis,
        }),
      ));

  /// Pin (or move) the primary branch. Creates it named after the store on
  /// the first pin.
  Future<MerchantSetupState> saveSetupLocation({
    required double lat,
    required double lng,
  }) async =>
      _setupState(await run(
        () => dio.patch<Map<String, dynamic>>('/merchant/setup/location',
            data: {'lat': lat, 'lng': lng}),
      ));

  /// Save the advertised cashback rate as the exact percent STRING typed
  /// (§11 — never a float). Refusals: `validation_failed` (shape/bounds),
  /// `rate_not_priced` (above the live tier ceiling).
  Future<MerchantSetupState> saveSetupRate(String percent) async =>
      _setupState(await run(
        () => dio.patch<Map<String, dynamic>>('/merchant/setup/rate', data: {
          'cashback_rate_percent': percent,
        }),
      ));

  /// Upload the store logo (multipart field `logo`; jpg/png/webp, ≤2048 KB,
  /// 64..4096 px). Gate: `branding.update`. Answers the new versioned URL.
  /// `logo_write_failed` (503) is a storage hiccup — retryable as-is.
  Future<String?> uploadSetupLogo({
    required Uint8List bytes,
    required String filename,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/setup/logo',
        data: FormData.fromMap({
          'logo': MultipartFile.fromBytes(bytes, filename: filename),
        }),
      ),
    );

    return (data?['data'] as Map?)?['logo_url'] as String?;
  }

  /// Submit for review. Success answers the full state with
  /// `status: pending_review`; `setup_incomplete` (422) carries the unmet
  /// keys in `meta.missing`. Gate: `setup.submit`.
  Future<MerchantSetupState> submitSetup() async => _setupState(
        await run(
          () => dio.post<Map<String, dynamic>>('/merchant/setup/submit'),
        ),
      );

  /// Sign out THIS device. The local wipe happens regardless of whether
  /// the server call lands — a till with no signal must still be able to
  /// sign out; the token dies server-side at the latest when it expires or
  /// is revoked from the device list.
  Future<void> signOut() async {
    try {
      await dio.delete<void>('/merchant/auth/token');
    } catch (_) {
      // Intentional: local sign-out must never be blocked by the network.
    }
    await session.wipe();
  }

  /// Sign out EVERY device this staff account holds (guide §7).
  Future<void> signOutEverywhere() async {
    try {
      await dio.delete<void>('/merchant/auth/tokens');
    } catch (_) {
      // Same stance as signOut: the local wipe must always land.
    }
    await session.wipe();
  }

  // ------------------------------------------------------------- reads

  /// Fresh identity AND fresh permissions — call on every launch and
  /// resume (guide §3; cheap via ETag). The answer is written straight into
  /// the session, so navigation built from `session.can()` re-renders the
  /// moment a narrowed role or a status change lands.
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson(await getJson('/merchant/me'));

    await session.saveProfile(
      userName: me.user.name,
      userEmail: me.user.email,
      merchantId: me.merchant.id == 0 ? null : me.merchant.id,
      merchantName: me.merchant.name,
      merchantSlug: me.merchant.slug,
      merchantStatus: me.merchant.status,
      permissions: me.permissions,
    );

    return me;
  }

  /// The till's first screen in ONE request: today's tally, and — only for
  /// accounts holding `settlements.view` — the outstanding summary and the
  /// open settlement batch.
  Future<MerchantHome> home() async => MerchantHome.fromJson(
        await getJson('/merchant/home'),
      );

  /// The store's credit history, cursor-paged, newest first. Requires
  /// `transactions.view` (the server refuses with `permission_required`).
  Future<CursorPage<MerchantTransaction>> transactions({
    String? cursor,
    String? state,
    int? perPage,
  }) =>
      getPage(
        '/merchant/transactions',
        cursor,
        MerchantTransaction.fromJson,
        query: {'state': ?state, 'per_page': ?perPage},
      );

  /// Confirm who a 6-digit code belongs to BEFORE crediting — the name
  /// check the till shows beside the Verified chip. `{valid:false}` covers
  /// unknown and non-active codes identically (no membership oracle), and
  /// the endpoint has two budgets: 30/min per user, and a per-MERCHANT
  /// 60-misses/day budget shared with the web — once tripped, even valid
  /// codes answer 429 until the day rolls.
  Future<CustomerLookup> lookupCustomer(String code) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '/merchant/customers/lookup',
        queryParameters: {'code': code},
      ),
    );

    // The one mobile answer WITHOUT a `data` wrapper — parsed off the root.
    return CustomerLookup.fromJson(data ?? const {});
  }

  /// The standing terms (current + any §7 pending decrease) the credit
  /// screen's cost preview estimates from. Gate: `rate.view` — seeds to
  /// every role, so the cashier's preview never 403s.
  Future<MerchantRate> merchantRate() async =>
      MerchantRate.fromJson(await getJson('/merchant/rate'));

  /// The split editor's category vocabulary, ordered by sort then id, and
  /// INCLUDING inactive rows (old lines still name them) — filter on
  /// `active` for the editor. Gate: `product_categories.view` (every role).
  Future<List<ProductCategory>> productCategories() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/merchant/product-categories'),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        ProductCategory.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  /// Fix what was rung up while the sale still sits in its refund window
  /// (`awaiting_validation` && !backdated — the same gate the actions
  /// render behind). The cashback is repriced at the sale's FROZEN terms;
  /// [lines] (when the sale was split) replace the split wholesale and must
  /// sum to [eligibleLaari]. Gate: `transactions.amend` (Manager+).
  Future<MerchantTransaction> amendTransaction({
    required int id,
    required int eligibleLaari,
    int? saleLaari,
    List<CreditLine>? lines,
  }) async {
    final data = await run(
      () => dio.patch<Map<String, dynamic>>(
        '/merchant/transactions/$id',
        data: {
          'eligible_amount': eligibleLaari,
          'sale_amount': ?saleLaari,
          if (lines != null)
            'lines': [for (final line in lines) line.toJson()],
        },
      ),
    );

    return MerchantTransaction.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The till void: take the whole sale off and claw the cashback back.
  /// Same window as amend, plus the hard rule the server proves in tests —
  /// a BACKDATED credit can never be cancelled (`backdated_irreversible`).
  /// [reason] is one of refund | void | duplicate | error.
  /// Gate: `transactions.cancel`.
  Future<MerchantTransaction> cancelTransaction({
    required int id,
    required String reason,
    String? note,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/transactions/$id/cancel',
        data: {'reason': reason, 'note': ?note},
      ),
    );

    return MerchantTransaction.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // ------------------------------------------------------------- credits

  /// Record a sale — the till's whole reason to be signed in.
  ///
  /// [idempotencyKey] is REQUIRED by the server: generate one per sale (a
  /// UUID is fine), store it with the queued sale, and reuse the SAME key
  /// for every retry of that sale. Key completion off [MerchantCreditResult]
  /// (2xx range), never off 201 alone — a replay of a committed sale
  /// arrives as 200 with `replayed: true`.
  ///
  /// Amounts are integer laari. [occurredAt] is omitted for "now" (what a
  /// till ringing up a sale means); a sale older than the refund window is
  /// refused with `backdated_confirmation_required` unless
  /// [backdatedAcknowledged] is set — only after a HUMAN confirmed, and
  /// after checking the device clock against `meta.server_time`.
  /// [cashbackRatePercent] (raise-only, needs `credits.custom_rate`) and
  /// [lines] (split-by-category; amounts must sum to [eligibleLaari]) are
  /// optional.
  Future<MerchantCreditResult> createCredit({
    required String idempotencyKey,
    required String customerCode,
    required String invoiceNo,
    required int eligibleLaari,
    int? saleLaari,
    String? occurredAt,
    String? cashbackRatePercent,
    List<CreditLine>? lines,
    bool backdatedAcknowledged = false,
  }) async {
    final response = await send(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/credits',
        data: {
          'customer_code': customerCode,
          'invoice_no': invoiceNo,
          'eligible_amount': eligibleLaari,
          'sale_amount': ?saleLaari,
          'occurred_at': ?occurredAt,
          'cashback_rate_percent': ?cashbackRatePercent,
          if (lines != null) 'lines': [for (final line in lines) line.toJson()],
          if (backdatedAcknowledged) 'backdated_acknowledged': true,
        },
        options: idempotent(idempotencyKey),
      ),
    );

    return MerchantCreditResult(
      transaction: MerchantTransaction.fromJson(
        (response.data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
      ),
      replayed:
          response.headers.value('idempotency-replay') == 'true' ||
          response.statusCode == 200,
    );
  }

  // ------------------------------------------------------------- devices

  Future<List<DeviceEntry>> devices() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/merchant/devices'),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        DeviceEntry.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  Future<void> revokeDevice(int id) async =>
      run(() => dio.delete<Map<String, dynamic>>('/merchant/devices/$id'));

  Future<void> revokeAllDevices() async =>
      run(() => dio.delete<Map<String, dynamic>>('/merchant/devices'));

  // ------------------------------------------------------------- push

  Future<void> registerPushToken({
    required String token,
    required String platform,
    int? appBuild,
    String? locale,
  }) async =>
      run(
        () => dio.put<void>('/merchant/push-token', data: {
          'token': token,
          'platform': platform,
          'app_build': ?appBuild,
          'locale': ?locale,
        }),
      );

  Future<void> deletePushToken(String token) async => run(
        () => dio.delete<void>('/merchant/push-token', data: {'token': token}),
      );
}
