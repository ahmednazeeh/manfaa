import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;

import 'api_base.dart';
import 'errors.dart';
import 'marketplace_merchant_models.dart';
import 'merchant_models.dart';
import 'models.dart';
import 'session.dart';
import 'settlement_models.dart';

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

  /// MR9 — the queued half of a gated write, or null.
  ///
  /// The STATUS LINE is the signal: 202 means "submitted for review", any
  /// other success means it landed. Reading the code rather than sniffing
  /// the body is deliberate — it is the contract the server documents, and
  /// it keeps a 200 whose body happens to gain a key one day from being
  /// mistaken for a queue.
  static MerchantChangeRequest? _queued(
    Response<Map<String, dynamic>> response,
  ) {
    if (response.statusCode != 202) return null;

    final data = (response.data?['data'] as Map?)?.cast<String, dynamic>();
    final queued = (data?['change_request'] as Map?)?.cast<String, dynamic>();

    return queued == null ? null : MerchantChangeRequest.fromJson(queued);
  }

  static Map<String, dynamic> _data(Response<Map<String, dynamic>> response) =>
      (response.data?['data'] as Map?)?.cast<String, dynamic>() ?? {};

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

  /// The profile step's save: category + channel + the store's own
  /// description + contacts, all keys sent every time (an explicit null
  /// CLEARS server-side — that is how "same as contact" stores the support
  /// phone). Gate: `setup.edit`; refused with `setup_not_editable` outside
  /// draft|rejected. Answers the full state.
  Future<MerchantSetupState> saveSetupProfile({
    required String category,
    required String channel,
    String? description,
    String? contactEmail,
    String? contactPhone,
    String? supportPhone,
    String? websiteUrl,
  }) async =>
      _setupState(await run(
        () => dio.patch<Map<String, dynamic>>('/merchant/setup/profile', data: {
          'category': category,
          'channel': channel,
          // The store's own words (≤180 WORDS server-side). The wizard
          // writes it DIRECTLY — a draft store is about to be reviewed
          // whole, so there is nothing to gate.
          'description': description,
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
  /// The pin AND the words that go with it: `address` is required by the
  /// server (owner decision 2026-08-18), because a pin with no address
  /// reads as a raw coordinate in every map app we hand a customer off to.
  Future<MerchantSetupState> saveSetupLocation({
    required double lat,
    required double lng,
    required String address,
  }) async =>
      _setupState(await run(
        () => dio.patch<Map<String, dynamic>>('/merchant/setup/location',
            data: {'lat': lat, 'lng': lng, 'address': address}),
      ));

  /// Turn a dropped pin into a written address, so meeting the address
  /// requirement is a tap rather than typing.
  ///
  /// Returns null when the geocoder knows nothing about that spot or could
  /// not be reached — an ordinary answer, not an error: the merchant types
  /// the address instead, and the field is the authority either way.
  Future<String?> reverseGeocodePin({
    required double lat,
    required double lng,
  }) async {
    final response = await send(
      () => dio.get<Map<String, dynamic>>(
        '/merchant/branches/reverse-geocode',
        queryParameters: {'lat': lat, 'lng': lng},
      ),
    );

    return _data(response)['address'] as String?;
  }

  /// Take the store off the app, or put it back. No review either way.
  ///
  /// Returns the fresh publication state. `customersNotified` reports
  /// whether THIS call reached anyone: at most one pause and one resume
  /// message goes out per store per day, so a second toggle is honoured but
  /// silent — and a merchant who assumed otherwise would be misled.
  Future<StorePublication> setPublished(bool published) async {
    final response = await send(
      () => dio.post<Map<String, dynamic>>('/merchant/publication',
          data: {'published': published}),
    );

    return StorePublication.fromJson(_data(response));
  }

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
  /// 64..4096 px). Gate: `branding.update`.
  /// `logo_write_failed` (503) is a storage hiccup — retryable as-is.
  ///
  /// MR9: a LIVE store's logo is a public claim, so the file is STAGED and
  /// the answer is 202 with the change request. `logo_url` in that answer is
  /// the logo the store is STILL SERVING — the caller must keep showing it,
  /// because nothing a shopper sees has moved yet.
  Future<LogoUploadResult> uploadSetupLogo({
    required Uint8List bytes,
    required String filename,
  }) async {
    final response = await send(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/setup/logo',
        data: FormData.fromMap({
          'logo': MultipartFile.fromBytes(bytes, filename: filename),
        }),
      ),
    );

    return LogoUploadResult(
      logoUrl: _data(response)['logo_url'] as String?,
      queued: _queued(response),
    );
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

  // --------------------------------------------------- settlements (MR3)

  /// The dashboard's ageing summary (OutstandingSummary::forMerchant, with
  /// as_of — unlike /merchant/home's projection). Gate: `settlements.view`.
  Future<MerchantOutstanding> outstanding() async =>
      MerchantOutstanding.fromJson(await getJson('/merchant/outstanding'));

  /// Balance + movements, newest first. The wallet row is created on the
  /// first read (which answers 201 — same body). Gate: `wallet.view`.
  Future<MerchantWalletState> wallet() async =>
      MerchantWalletState.fromJson(await getJson('/merchant/wallet'));

  /// Settlement history, newest first, PAGE-numbered (paginate(25) — not
  /// the cursor shape transactions use). Gate: `settlements.view`.
  Future<SettlementPage> settlements({int page = 1}) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '/merchant/settlements',
        queryParameters: {'page': page},
      ),
    );

    return SettlementPage.fromJson(data ?? const {});
  }

  /// One batch with its frozen lines (transaction loaded) and payments.
  /// Another merchant's id is a plain 404. Gate: `settlements.view`.
  Future<MerchantSettlement> settlement(int id) async =>
      MerchantSettlement.fromJson(await getJson('/merchant/settlements/$id'));

  /// Price a selection BEFORE any commitment: totals, §7 credit netting,
  /// the advisory discount verdict, every eligible row, the preset buckets
  /// and the platform's bank instructions. Reservation-free — nothing is
  /// claimed. [settleAll] previews everything eligible; otherwise
  /// [transactionIds] names the subset exactly (a named row that is not
  /// eligible is a 422 refusal, never a silent drop).
  /// Gate: `settlements.preview`.
  Future<SettlementPreviewData> settlementPreview({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '/merchant/settlements/preview',
        queryParameters: settleAll
            ? {'settle_all': 1}
            : {'transaction_ids[]': transactionIds ?? const <int>[]},
      ),
    );

    return SettlementPreviewData.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The one shared body for the two selection-carrying writes: settle-all
  /// is a MODE (`settle_all`), never an id list — a sale that becomes
  /// payable between preview and submit then joins the batch instead of
  /// silently costing the merchant the discount.
  Map<String, dynamic> _selectionBody({
    required bool settleAll,
    List<int>? transactionIds,
  }) =>
      settleAll
          ? {'settle_all': true}
          : {'transaction_ids': transactionIds ?? const <int>[]};

  /// The receipt-first submission (PLAN §1): the selection, the laari
  /// actually transferred, the slip, and optionally the bank's reference
  /// and WHICH platform account was paid. One server transaction builds the
  /// batch in payment_review — or not at all. 201 with the settlement.
  ///
  /// Refusals: `slip_too_large` / `slip_unsupported_type` (422, decided by
  /// magic bytes), `duplicate_bank_ref` (409), `validation_failed` with the
  /// server's prose when the selection is no longer eligible, `conflict`
  /// (409) when the rows are locked or moved on.
  /// Gate: `settlements.create` (+ approved store; suspended still settles).
  Future<MerchantSettlement> createSettlement({
    bool settleAll = false,
    List<int>? transactionIds,
    required int amountLaari,
    String? bankRef,
    required Uint8List slipBytes,
    required String slipFilename,
    int? platformBankAccountId,
  }) async {
    final form = FormData();
    if (settleAll) {
      form.fields.add(const MapEntry('settle_all', '1'));
    } else {
      for (final id in transactionIds ?? const <int>[]) {
        form.fields.add(MapEntry('transaction_ids[]', '$id'));
      }
    }
    form.fields.add(MapEntry('amount', '$amountLaari'));
    if (bankRef != null && bankRef.isNotEmpty) {
      form.fields.add(MapEntry('bank_ref', bankRef));
    }
    if (platformBankAccountId != null) {
      form.fields.add(
        MapEntry('platform_bank_account_id', '$platformBankAccountId'),
      );
    }
    form.files.add(
      MapEntry('slip', MultipartFile.fromBytes(slipBytes, filename: slipFilename)),
    );

    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/settlements', data: form),
    );

    return MerchantSettlement.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// §7 wallet settlement — build and settle in one call, no receipt (the
  /// top-up that funded the wallet is the evidence). Also the ONLY honest
  /// path for a batch §7 credits netted to zero: it draws nothing.
  ///
  /// Refusals: `validation_failed` (422) with the server's prose when the
  /// balance cannot cover the batch. Gate: `wallet.settle`.
  Future<MerchantSettlement> walletSettle({
    bool settleAll = false,
    List<int>? transactionIds,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/settlements/wallet',
        data: _selectionBody(
          settleAll: settleAll,
          transactionIds: transactionIds,
        ),
      ),
    );

    return MerchantSettlement.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// A FURTHER receipt against a batch still owed money — the remainder
  /// after a §7 partial payment, or the transfer for an admin-built
  /// awaiting_payment batch. No destination: the batch's account was chosen
  /// once, at creation. Gate: `settlements.receipt_add`.
  Future<MerchantSettlement> addSettlementReceipt({
    required int id,
    required int amountLaari,
    String? bankRef,
    required Uint8List slipBytes,
    required String slipFilename,
  }) async {
    final form = FormData();
    form.fields.add(MapEntry('amount', '$amountLaari'));
    if (bankRef != null && bankRef.isNotEmpty) {
      form.fields.add(MapEntry('bank_ref', bankRef));
    }
    form.files.add(
      MapEntry('slip', MultipartFile.fromBytes(slipBytes, filename: slipFilename)),
    );

    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/settlements/$id/receipts',
        data: form,
      ),
    );

    return MerchantSettlement.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // ----------------------------------------------- settings estate (MR5)

  /// The store's public identity (MerchantProfileResource, verbatim).
  /// Gate: `profile.view`.
  Future<MerchantProfile> profile() async =>
      MerchantProfile.fromJson(await getJson('/merchant/profile'));

  /// Rewrite the public identity. Wire semantics are the web panel's
  /// exactly: every editable key is SENT (an explicit null CLEARS — the
  /// server's rules are `sometimes|nullable`), except `category`, which
  /// travels only when it actually changed — a retired category the store
  /// still holds is accepted unchanged, and an edit to a phone number
  /// should not carry a category write at all.
  ///
  /// "Same as contact" is a COMPARISON: the caller sends the CONTACT number
  /// itself as [supportPhone] — the support field is always materialised
  /// server-side so the storefront always has a real number to show.
  /// Gate: `profile.edit` + approved store.
  ///
  /// MR9 splits the answer in two. For a LIVE store the public claims (name,
  /// Dhivehi name, category, channel, the terms text, the description,
  /// website, logo) QUEUE
  /// for admin review — 202, with the change request — while the contact
  /// details apply on the spot. Both halves can happen in the one request,
  /// which is why the result carries the fresh profile AS WELL AS the queue:
  /// the phone number really did change, the shop name really did not.
  /// A save whose gated keys match the live row queues nothing (the form
  /// PATCHes everything, so most saves are exactly that) and answers 200.
  Future<ProfileSaveResult> updateProfile({
    required String name,
    String? nameDv,
    required String channel,
    String? eligibilityBasis,
    String? description,
    String? contactEmail,
    String? contactPhone,
    String? supportPhone,
    String? websiteUrl,
    bool categoryChanged = false,
    String? category,
  }) async {
    final response = await send(
      () => dio.patch<Map<String, dynamic>>('/merchant/profile', data: {
        'name': name,
        'name_dv': nameDv,
        'channel': channel,
        'eligibility_basis': eligibilityBasis,
        // A public claim: on a LIVE store this key is part of the GATED
        // half, so a changed description comes back as a 202 change
        // request, exactly like the name or the category.
        'description': description,
        'contact_email': contactEmail,
        'contact_phone': contactPhone,
        'support_phone': supportPhone,
        'website_url': websiteUrl,
        if (categoryChanged) 'category': category,
      }),
    );

    final data = _data(response);
    final queued = _queued(response);

    return ProfileSaveResult(
      // 200 answers the profile resource itself; 202 nests it beside the
      // change request, because the instant keys are already applied.
      profile: MerchantProfile.fromJson(
        queued == null
            ? data
            : (data['profile'] as Map?)?.cast<String, dynamic>() ?? const {},
      ),
      queued: queued,
    );
  }

  /// Change the standing rate — sent as the exact percent STRING typed
  /// (§11, never a float). The answer carries the fresh current/pending
  /// windows AND the server's change summary: WHEN the rate takes hold is
  /// the server's call (§7 — increases now, decreases at the next business
  /// midnight), rendered from `change`, never re-derived.
  ///
  /// Refusals: `rate_not_priced` (above the live fee-schedule ceiling),
  /// `validation_failed` (shape/bounds). Gate: `rate.update` + trading.
  Future<RateChangeResult> changeRate(String percent) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/rate', data: {
        'cashback_rate_percent': percent,
      }),
    );

    return RateChangeResult(
      rate: MerchantRate.fromJson(
        (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
      ),
      change: data?['change'] is Map
          ? RateChangeSummary.fromJson(
              (data!['change'] as Map).cast<String, dynamic>(),
            )
          : null,
    );
  }

  /// READ the preferences. The API exposes no GET: an empty PATCH validates
  /// cleanly (`sometimes` rules), changes nothing (`fill({})` issues no
  /// UPDATE) and answers the current values — the same read model the web
  /// panel uses. Gate: `preferences.update`.
  Future<MerchantPreferences> preferences() => updatePreferences();

  /// Change the earning knobs. Absent keys are untouched (never cleared);
  /// [minEligibleLaari] is INTEGER LAARI end to end. Both knobs apply to
  /// FUTURE credits only — terms freeze onto each sale at occurred_at (§4).
  /// [autoSettleFromWallet] is the wallet screen's switch (owner,
  /// 2026-08-24): the ONE write path for it — there is no wallet-scoped
  /// PATCH. Gate: `preferences.update` (no approval gate — deliberately).
  Future<MerchantPreferences> updatePreferences({
    String? settlementMethod,
    int? minEligibleLaari,
    int? validationWindowDays,
    bool? autoSettleFromWallet,
  }) async {
    final data = await run(
      () => dio.patch<Map<String, dynamic>>('/merchant/preferences', data: {
        'settlement_method': ?settlementMethod,
        'min_eligible_laari': ?minEligibleLaari,
        'validation_window_days': ?validationWindowDays,
        'auto_settle_from_wallet': ?autoSettleFromWallet,
      }),
    );

    return MerchantPreferences.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Switch the hourly wallet auto-settlement on or off — the wallet
  /// screen's toggle, written through the documented preferences path.
  /// Gate: `preferences.update` (owner preset; staff answer 403).
  Future<MerchantPreferences> setAutoSettle(bool on) =>
      updatePreferences(autoSettleFromWallet: on);

  /// Claim a wallet top-up (owner, 2026-08-24): the same receipt-first
  /// shape as [createSettlement] — the laari transferred, WHICH platform
  /// account it went to (REQUIRED here: the claim is matched against that
  /// bank's history), the slip, and the bank's reference if the merchant
  /// has it. 201 with the pending claim; the balance moves only once the
  /// transfer is matched (auto, or by an admin).
  ///
  /// Refusals: `top_up_below_minimum` (422 — under the platform floor the
  /// wallet payload reports as `top_up_min_laari`; a Laravel `min` refusal
  /// on the same amount arrives as `validation_failed` with the field
  /// under meta.fields.amount), `slip_too_large` /
  /// `slip_unsupported_type` (422, by magic bytes), `duplicate_bank_ref`
  /// (409), `store_not_approved` (409). Gate: `wallet.top_up` + approved
  /// store (suspended still tops up, exactly as it still settles).
  Future<WalletTopUpClaim> createWalletTopUp({
    required int amountLaari,
    required int platformBankAccountId,
    required Uint8List slipBytes,
    required String slipFilename,
    String? bankRef,
  }) async {
    final form = FormData();
    form.fields.add(MapEntry('amount', '$amountLaari'));
    form.fields.add(
      MapEntry('platform_bank_account_id', '$platformBankAccountId'),
    );
    final ref = bankRef?.trim() ?? '';
    if (ref.isNotEmpty) {
      form.fields.add(MapEntry('bank_ref', ref));
    }
    form.files.add(
      MapEntry('slip', MultipartFile.fromBytes(slipBytes, filename: slipFilename)),
    );

    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/wallet/top-ups', data: form),
    );

    return WalletTopUpClaim.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Create a per-store product category rule. [mode] is `rate` (carries
  /// [cashbackRatePercent], the exact string) or `excluded` (the percent key
  /// must be ABSENT — the server prohibits it). The Dhivehi name is REQUIRED
  /// by the server: it is what a Dhivehi customer reads on their receipt.
  /// Refusals: `rate_not_priced`. Gate: `product_categories.create` +
  /// trading.
  Future<ProductCategory> createProductCategory({
    required String nameEn,
    required String nameDv,
    required String mode,
    String? cashbackRatePercent,
    int? sort,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/product-categories',
          data: {
            'name_en': nameEn,
            'name_dv': nameDv,
            'mode': mode,
            'cashback_rate_percent': ?cashbackRatePercent,
            'sort': ?sort,
          }),
    );

    return ProductCategory.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Edit a rule — partial: absent keys stay as they are, and the slug NEVER
  /// changes (it is the immutable line key). Switching to `excluded` omits
  /// the percent (the server derives null); switching to `rate` must carry
  /// one. Deactivation is soft ([active] false) — there is no delete.
  /// Gate: `product_categories.edit` + trading.
  Future<ProductCategory> updateProductCategory(
    int id, {
    String? nameEn,
    String? nameDv,
    String? mode,
    String? cashbackRatePercent,
    int? sort,
    bool? active,
  }) async {
    final data = await run(
      () => dio.patch<Map<String, dynamic>>('/merchant/product-categories/$id',
          data: {
            'name_en': ?nameEn,
            'name_dv': ?nameDv,
            'mode': ?mode,
            'cashback_rate_percent': ?cashbackRatePercent,
            'sort': ?sort,
            'active': ?active,
          }),
    );

    return ProductCategory.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // ------------------------------------- management estate (MR5, agent B)

  /// The store's panel accounts, id order (MerchantStaffResource).
  /// Gate: `staff.view`.
  Future<List<MerchantStaff>> staff() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/merchant/staff'),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        MerchantStaff.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  /// Invite a staff member — creates the account with a generated temporary
  /// password returned EXACTLY ONCE, beside (not inside) the resource; only
  /// its hash survives server-side. The role is REQUIRED: with per-store
  /// roles there is no tier to default to.
  ///
  /// Refusals: `validation_failed` (a taken email among them), and the
  /// delegation family — `owner_role_not_delegable`, `permission_not_held`
  /// (you cannot hand out a role holding permissions you lack).
  /// Gate: `staff.invite` + approved store.
  Future<StaffInviteResult> inviteStaff({
    required String name,
    required String email,
    required int merchantRoleId,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/staff', data: {
        'name': name,
        'email': email,
        'merchant_role_id': merchantRoleId,
      }),
    );

    return StaffInviteResult(
      staff: MerchantStaff.fromJson(
        (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
      ),
      tempPassword: (data?['temp_password'])?.toString() ?? '',
    );
  }

  /// Repoint, rename or (de)activate an account — partial: only the given
  /// keys travel (`sometimes` server rules). MR8 extends the PATCH with
  /// [name] and [email] (a taken email refuses 422 exactly like a duplicate
  /// invite). Deactivation is the ONLY removal (no staff DELETE), and it
  /// destroys the account's app tokens.
  ///
  /// Refusals: the delegation family above, plus the guards as 422
  /// sentences — the last active OWNER can be neither deactivated nor
  /// demoted, and nobody demotes or deactivates themselves.
  /// Gate: `staff.edit`.
  Future<MerchantStaff> updateStaff(
    int id, {
    String? name,
    String? email,
    int? merchantRoleId,
    bool? isActive,
  }) async {
    final data = await run(
      () => dio.patch<Map<String, dynamic>>('/merchant/staff/$id', data: {
        'name': ?name,
        'email': ?email,
        'merchant_role_id': ?merchantRoleId,
        'is_active': ?isActive,
      }),
    );

    return MerchantStaff.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Reset a staff member's password (MR8) — answers a fresh generated
  /// temporary password EXACTLY ONCE, beside (not inside) the resource:
  /// the same handover shape as [inviteStaff], so the same reveal dialog
  /// serves both. Server-side the reset rotates the remember token, kills
  /// web sessions and deletes every app token the target holds — their
  /// phone signs out. Self-reset is allowed (this device's own token dies
  /// with the rest). Gate: `staff.edit`.
  Future<StaffInviteResult> resetStaffPassword(int id) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/staff/$id/reset-password'),
    );

    return StaffInviteResult(
      staff: MerchantStaff.fromJson(
        (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
      ),
      tempPassword: (data?['temp_password'])?.toString() ?? '',
    );
  }

  /// The SERVED permission catalogue, grouped as the roles screen stacks it
  /// (D8: published so a permission added by a later deploy renders in this
  /// build too). Gate: `roles.view` — the roles screen's own gate.
  Future<PermissionCatalogue> permissionCatalogue() async =>
      PermissionCatalogue.fromJson(await getJson('/merchant/permissions'));

  /// The merchant's own roles, id order, each carrying its RESOLVED
  /// permission set and staff count. Gate: `roles.view`.
  Future<List<MerchantRole>> roles() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/merchant/roles'),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        MerchantRole.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  /// Create a role. `permissions` is PRESENT even when empty (a role
  /// holding nothing yet is a legitimate start); every slug in it is a
  /// grant, so the whole set must be held by the caller (D5).
  ///
  /// Refusals: `permission_not_held` (403, `meta.permissions` names the
  /// slugs), `role_cap_reached` (422). Gate: `roles.manage`.
  Future<MerchantRole> createRole({
    required String name,
    String? nameDv,
    required List<String> permissions,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/roles', data: {
        'name': name,
        'name_dv': ?nameDv,
        'permissions': permissions,
      }),
    );

    return MerchantRole.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Edit a role — partial: only the given keys travel. `name_dv` is
  /// nullable AND optional, so "clear it" and "leave it alone" are
  /// different requests: [nameDvChanged] puts the key on the wire (with
  /// [nameDv], null clearing), exactly the categoryChanged idiom. On an
  /// edit only the ADDED permissions are a grant — unticking hands nobody
  /// anything.
  ///
  /// Refusals: `owner_role_frozen` (409 — the owner role is renameable
  /// only), `cannot_edit_own_role` (403, non-owners), `permission_not_held`
  /// (403). Gate: `roles.manage`.
  Future<MerchantRole> updateRole(
    int id, {
    String? name,
    bool nameDvChanged = false,
    String? nameDv,
    List<String>? permissions,
  }) async {
    final data = await run(
      () => dio.patch<Map<String, dynamic>>('/merchant/roles/$id', data: {
        'name': ?name,
        if (nameDvChanged) 'name_dv': nameDv,
        'permissions': ?permissions,
      }),
    );

    return MerchantRole.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Delete a role nobody stands on (deactivated accounts count — they
  /// keep resolving in audit trails). Refusals: `role_in_use` (409,
  /// `meta.staff_count`), `owner_role_undeletable` (409).
  /// Gate: `roles.manage`.
  Future<void> deleteRole(int id) async =>
      run(() => dio.delete<Map<String, dynamic>>('/merchant/roles/$id'));

  /// The store's branches, id order, WITH the branch changes waiting on a
  /// reviewer (`meta.pending_changes`, MR9). Gate: `branches.view`.
  Future<MerchantBranchEstate> branches() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/merchant/branches'),
    );

    return MerchantBranchEstate.fromJson(data ?? const {});
  }

  /// Create a branch. The pin travels as a nullable PAIR — both halves or
  /// neither (the server refuses a lone coordinate); every key is sent so
  /// the write is explicit. Gate: `branches.create` + approved store.
  ///
  /// MR9: for a LIVE store this QUEUES (202) — a branch is an address a
  /// shopper travels to, so no row is created until an admin approves.
  Future<BranchSaveResult> createBranch({
    required String name,
    required String address,
    double? lat,
    double? lng,
  }) async {
    final response = await send(
      () => dio.post<Map<String, dynamic>>('/merchant/branches', data: {
        'name': name,
        'address': address,
        'lat': lat,
        'lng': lng,
      }),
    );

    final queued = _queued(response);

    return BranchSaveResult(
      branch: queued == null
          ? MerchantBranch.fromJson(_data(response))
          : null,
      queued: queued,
    );
  }

  /// Rewrite a branch — same full-send semantics as create (the web
  /// panel's exactly): explicit nulls clear the address and take the pin
  /// away, which this surface is the ONLY one able to do.
  /// Gate: `branches.edit` + approved store.
  ///
  /// MR9: for a LIVE store this QUEUES (202) unless nothing actually moved,
  /// which answers 200 with the branch unchanged — the dialog PATCHes every
  /// key, so re-saving an untouched branch must not park a request.
  Future<BranchSaveResult> updateBranch(
    int id, {
    required String name,
    String? address,
    double? lat,
    double? lng,
  }) async {
    final response = await send(
      () => dio.patch<Map<String, dynamic>>('/merchant/branches/$id', data: {
        'name': name,
        'address': address,
        'lat': lat,
        'lng': lng,
      }),
    );

    final queued = _queued(response);

    return BranchSaveResult(
      branch: queued == null
          ? MerchantBranch.fromJson(_data(response))
          : null,
      queued: queued,
    );
  }

  /// Delete a branch nothing references. A branch with transactions or
  /// branch-scoped promotions is history that must keep resolving — the
  /// server answers 409 `branch_referenced` and the soft alternative is
  /// simply to stop using it. Gate: `branches.delete` + approved store.
  ///
  /// MR9: for a LIVE store the removal QUEUES (202) and the branch STAYS on
  /// the list until an admin approves. The `branch_referenced` refusal is
  /// still raised at submit — a removal that can never be honoured is
  /// refused to the person asking, not parked for an admin to discover.
  Future<BranchDeleteResult> deleteBranch(int id) async {
    final response = await send(
      () => dio.delete<Map<String, dynamic>>('/merchant/branches/$id'),
    );

    return BranchDeleteResult(queued: _queued(response));
  }

  /// The store's promotions, newest first, optionally filtered by
  /// [status] (draft|published|ended|cancelled). Gate: `promotions.view`
  /// (seeds to every role — the till can see what is running).
  Future<List<Promotion>> promotions({String? status}) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '/merchant/promotions',
        queryParameters: {'status': ?status},
      ),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        Promotion.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  /// Draft a promotion. The rate is the EXACT 2-decimal percent string
  /// typed (§11, never a float) and must BOOST above the standing rate;
  /// both window ends are ISO 8601 WITH an explicit UTC offset; amounts are
  /// integer laari. The answer carries the server's `cost_preview` at the
  /// root beside `data` — the §4 all-in picture with the tier-cliff
  /// warning, rendered verbatim.
  ///
  /// Refusals: `rate_not_priced` (422, the active fee schedule does not
  /// price the rate), `validation_failed` with the domain's prose (window,
  /// boost, foreign branch). Gate: `promotions.create` + trading.
  Future<PromotionWriteResult> createPromotion({
    required String cashbackRatePercent,
    required String startsAt,
    required String endsAt,
    int? minPurchaseLaari,
    int? maxCashbackPerCustomerLaari,
    int? branchId,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/promotions', data: {
        'cashback_rate_percent': cashbackRatePercent,
        'starts_at': startsAt,
        'ends_at': endsAt,
        'min_purchase_laari': ?minPurchaseLaari,
        'max_cashback_per_customer_laari': ?maxCashbackPerCustomerLaari,
        'branch_id': ?branchId,
      }),
    );

    return _promotionWrite(data);
  }

  /// Publish a draft — the irreversible step: once live, a promotion is
  /// immutable for its stated window (PLAN §7 — no edit, no early end).
  /// Refusals: 409 `conflict` (not a draft), `rate_not_priced` (a stale
  /// draft the schedule no longer prices). Gate: `promotions.publish` +
  /// trading.
  Future<PromotionWriteResult> publishPromotion(int id) async =>
      _promotionWrite(await run(
        () => dio.post<Map<String, dynamic>>('/merchant/promotions/$id/publish'),
      ));

  /// Cancel a DRAFT — the only cancellable state; a published promotion can
  /// never be ended early (409). Gate: `promotions.cancel`.
  Future<Promotion> cancelPromotion(int id) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/merchant/promotions/$id/cancel'),
    );

    return Promotion.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  PromotionWriteResult _promotionWrite(Map<String, dynamic>? data) =>
      PromotionWriteResult(
        promotion: Promotion.fromJson(
          (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
        ),
        costPreview: data?['cost_preview'] is Map
            ? PromotionCostPreview.fromJson(
                (data!['cost_preview'] as Map).cast<String, dynamic>(),
              )
            : null,
      );

  // ------------------------------------------- account closure (MR8)
  //
  // The PUBLIC /api/merchant/account-closure endpoints — the same flow the
  // merchant.manfaa.app/account-deletion page rides. Deliberately
  // UNAUTHENTICATED: possession of the store's CONTACT phone (proven by
  // OTP) is the credential, exactly as the server mounts them outside both
  // auth stacks. They live one level above the mobile tree, so refusals
  // arrive in Laravel's standard `{message, errors:{field:[code]}}` shape,
  // not the mobile envelope — [_closureError] lifts the first field code
  // into a [MobileApiException] so screens switch on `code` as everywhere
  // else (otp_invalid, otp_attempts_exceeded, no_store, outstanding_balance,
  // closure_token_invalid, phone_invalid).

  /// Ask for a closure code on the store's CONTACT number. Enumeration-safe:
  /// the answer is identical for known and unknown phones; the SMS budget
  /// (3/hour per phone, shared with signup) refuses 429 with Retry-After.
  Future<void> requestClosureOtp(String phone) async => _closureRun(
        () => dio.post<Map<String, dynamic>>(
          '${ApiEnv.publicBaseUrl}/merchant/account-closure/request-otp',
          data: {'phone': phone},
        ),
      );

  /// Redeem the code for a single-use closure token (15-minute life) and
  /// the list of every non-closed store on the number, each carrying the
  /// server's own settle-first verdict (`can_close`).
  Future<ClosureVerification> verifyClosureOtp({
    required String phone,
    required String code,
  }) async {
    final data = await _closureRun(
      () => dio.post<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/merchant/account-closure/verify',
        data: {'phone': phone, 'code': code},
      ),
    );

    return ClosureVerification.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The irreversible step: close ONE store on the proven number. The
  /// server re-checks the outstanding balance at this moment —
  /// `outstanding_balance` refuses a store that started owing between
  /// verify and confirm. Success shuts every staff door and deletes their
  /// tokens; the caller signs the local session out when it just closed
  /// its own store.
  Future<void> confirmClosure({
    required String closureToken,
    required int merchantId,
  }) async =>
      _closureRun(
        () => dio.post<Map<String, dynamic>>(
          '${ApiEnv.publicBaseUrl}/merchant/account-closure/confirm',
          data: {'closure_token': closureToken, 'merchant_id': merchantId},
        ),
      );

  /// [run], but for the closure endpoints' NON-envelope errors: a Laravel
  /// validation refusal carries its machine code as the first `errors`
  /// entry — surface that as [MobileApiException.code] so the UI localises
  /// it; everything else (429 prose, proxy pages) falls through to the
  /// standard status-derived mapping.
  Future<Map<String, dynamic>?> _closureRun(
    Future<Response<Map<String, dynamic>>> Function() call,
  ) async {
    try {
      return (await call()).data;
    } on DioException catch (e) {
      if (e.type == DioExceptionType.badResponse) {
        throw closureError(e.response?.data, e.response?.statusCode);
      }
      throw MobileApiException.network();
    }
  }

  /// Map a Laravel-standard error body onto the app's coded exception.
  /// Visible for the wire tests; screens never call it.
  @visibleForTesting
  static MobileApiException closureError(Object? body, int? status) {
    if (body is Map) {
      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final Object? first = errors.values.first;
        final raw = first is List
            ? first.firstOrNull?.toString()
            : first?.toString();
        if (raw != null && RegExp(r'^[a-z0-9_]+$').hasMatch(raw)) {
          // A machine code (otp_invalid, no_store, outstanding_balance…):
          // the screens localise it — an empty message keeps a raw
          // snake_case token off every screen by construction.
          return MobileApiException(code: raw, message: '', status: status);
        }
      }
      final message = body['message'];
      if (message is String && message.trim().contains(' ')) {
        // Prose (the OTP limiter's 429 sentence) — safe to show verbatim.
        return MobileApiException(
          code: status == 429 ? ApiCode.rateLimited : ApiCode.validationFailed,
          message: message,
          meta: {
            if (body['retry_after_seconds'] != null)
              'retry_after_seconds': body['retry_after_seconds'] as Object,
          },
          status: status,
        );
      }
    }
    return MobileApiException.fromResponse(body, status);
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

  // ------------------------------------------------------------ marketplace
  //
  // The shop's order queue and shelf (PLAN-marketplace.md §4.1, §4.2).
  // Every route here is gated `marketplace.manage` server-side and sits
  // behind the platform switch, so a store that does not sell online gets a
  // 404 rather than an empty screen pretending to be one.

  /// Whether this store sells on the marketplace at all.
  ///
  /// Read before the Orders tab is drawn: `not_enrolled` is a perfectly
  /// ordinary answer and means the tab has no business existing.
  Future<String> marketplaceState() async {
    // getJson already unwraps `data`, so this IS the profile row.
    final row = await getJson('/merchant/marketplace/enrolment');

    return (row['state'] as String?) ?? 'not_enrolled';
  }

  /// Apply to sell on the marketplace.
  ///
  /// The application itself is four answers and belongs on a phone; the KYB
  /// papers are photographs, which a phone is BETTER at than a desktop.
  Future<String> applyToMarketplace({
    required String businessType,
    required String fulfilment,
    int? prepTimeMin,
    int? prepTimeMax,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/marketplace/enrolment',
        data: <String, dynamic>{
          'business_type': businessType,
          'fulfilment': fulfilment,
          'prep_time_min': ?prepTimeMin,
          'prep_time_max': ?prepTimeMax,
        },
      ),
    );

    return ((data?['data'] as Map?)?['state'] as String?) ?? 'draft';
  }

  /// Hand the application in. Answers the MISSING papers rather than a bare
  /// refusal, so a merchant knows exactly what is left.
  Future<List<String>> submitMarketplaceApplication() async {
    try {
      await run(
        () => dio.post<Map<String, dynamic>>('/merchant/marketplace/submit'),
      );

      return const [];
    } on MobileApiException catch (e) {
      if (e.status == 422) return e.missingRequirements;

      rethrow;
    }
  }

  /// One tab of the queue, plus the two tiles above it.
  ///
  /// `tab` is the server's vocabulary — new / preparing / ready / completed —
  /// which maps several states onto one heading (ready covers
  /// out_for_delivery, completed covers rejected and cancelled).
  Future<ShopOrderPage> shopOrders({String tab = 'new'}) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '/merchant/marketplace/orders',
        queryParameters: {'tab': tab},
      ),
    );

    return ShopOrderPage.fromJson(data ?? const {});
  }

  Future<ShopOrder> shopOrder(int suborderId) async {
    // Already unwrapped by getJson.
    return ShopOrder.fromJson(
      await getJson('/merchant/marketplace/orders/$suborderId'),
    );
  }

  /// Accepting says the shop can fulfil EVERY line. Editing comes after.
  Future<ShopOrder> acceptShopOrder(int suborderId) =>
      _orderAction('$suborderId/accept');

  /// A reason is required — the customer is told it, verbatim.
  Future<ShopOrder> rejectShopOrder(int suborderId, String reason) =>
      _orderAction('$suborderId/reject', {'reason': reason});

  /// One step along: accepted → preparing → ready → out_for_delivery →
  /// delivered. The server decides what "next" means for this fulfilment.
  Future<ShopOrder> advanceShopOrder(int suborderId) =>
      _orderAction('$suborderId/advance');

  /// Editing while picking (§2.7): reduce or drop lines the shelf cannot
  /// fill. The difference refunds to the customer's wallet, and the service
  /// charge follows it down.
  Future<ShopOrder> amendShopOrder(
    int suborderId, {
    required Map<int, int> quantities,
    required String reason,
  }) =>
      _orderAction('$suborderId/amend', {
        'reason': reason,
        'items': [
          for (final entry in quantities.entries)
            {'id': entry.key, 'fulfilled_qty': entry.value},
        ],
      });

  Future<ShopOrder> _orderAction(
    String path, [
    Map<String, dynamic>? body,
  ]) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/merchant/marketplace/orders/$path',
        data: body ?? const <String, dynamic>{},
      ),
    );

    return ShopOrder.fromJson(
      ((data?['data'] as Map?) ?? const {}).cast<String, dynamic>(),
    );
  }

  /// The shelf. Reading is the whole catalogue; editing from the app is
  /// deliberately narrow (see [updateShopListing]).
  Future<List<ShopProduct>> shopProducts() async {
    // NOT getJson: that helper unwraps `data` and casts it to a MAP, which
    // throws outright on a list payload — and this endpoint returns a list.
    // That single wrong helper was the whole of the Products screen's
    // failure, and it failed before any request result could explain itself.
    final body = await run(
      () => dio.get<Map<String, dynamic>>('/merchant/marketplace/products'),
    );

    return (((body ?? const {})['data'] as List?) ?? const [])
        .whereType<Map>()
        .map((row) => ShopProduct.fromJson(row.cast<String, dynamic>()))
        .toList(growable: false);
  }

  /// QUICK edits only: price, stock, visibility (§4.2).
  ///
  /// Everything else about a product — name, words, pictures, category —
  /// goes through review and belongs on desktop, which the screen says out
  /// loud rather than hiding behind a disabled field.
  Future<void> updateShopListing(
    int productId, {
    required int branchId,
    int? priceLaari,
    int? stockQty,
    String? state,
  }) async {
    await run(
      () => dio.put<Map<String, dynamic>>(
        '/merchant/marketplace/products/$productId/listing',
        // Only what the caller actually changed — an omitted field means
        // "leave it alone", never "clear it".
        data: <String, dynamic>{
          'branch_id': branchId,
          'price_laari': ?priceLaari,
          'stock_qty': ?stockQty,
          'state': ?state,
        },
      ),
    );
  }
}
