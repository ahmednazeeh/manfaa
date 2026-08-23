import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'activity_models.dart';
import 'api_base.dart';
import 'cart_models.dart';
import 'device_identity.dart';
import 'market_models.dart';
import 'models.dart';
import 'order_models.dart';
import 'referral_models.dart';
import 'session.dart';

/// The customer app's client for /api/mobile/v1.
///
/// The transport itself (Dio construction, interceptors, envelope plumbing)
/// lives in [ManfaaApiBase]; this class is the customer endpoint surface
/// plus the public discovery reads. The name predates the merchant split
/// and stays: every screen and test in mobile/customer constructs
/// `ManfaaApi`, and renaming it would churn an installed, working app for
/// nothing.
class ManfaaApi extends ManfaaApiBase<CustomerSession> {
  /// [deviceIdentity] is the self-referral defence's holder: the app
  /// resolves it asynchronously at startup and every request from then on
  /// carries the id. Optional so tests (and the holder-less default)
  /// construct exactly as before — the interceptor sends nothing while the
  /// holder is empty. Customer client only: the server records the header
  /// solely on the authed customer tree, so the merchant app stays as it is.
  ManfaaApi({required super.session, super.dio, DeviceIdentity? deviceIdentity})
      : deviceIdentity = deviceIdentity ?? DeviceIdentity() {
    dio.interceptors.add(DeviceIdentityInterceptor(this.deviceIdentity));
  }

  final DeviceIdentity deviceIdentity;

  // ------------------------------------------------------------- reads

  Future<CustomerMe> me() async =>
      CustomerMe.fromJson(await getJson('/customer/me'));

  /// Correct the Thaana name the server wrote at registration.
  ///
  /// `null` clears it, which is how a customer says the English name is the
  /// one they want shown.
  Future<CustomerMe> setDhivehiName(String? nameDv) async {
    final res = await dio.patch<Map<String, dynamic>>(
      '/customer/profile',
      data: {'name_dv': nameDv},
    );

    return CustomerMe.fromJson(
      (res.data?['data'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }


  Future<HomeData> home() async {
    final data = HomeData.fromJson(await getJson('/customer/home'));

    // Keep the offline avatar cache honest: /home is the call every launch
    // makes, so a picture changed on the website lands here first.
    await session.setAvatarUrl(data.avatarUrl);
    // Same reason: /home is the call every launch makes, so a name written
    // (or corrected) since last launch lands here.
    await session.setCustomerNameDv(data.customerNameDv);

    return data;
  }

  /// Earnings history, cursor-paged (the list grows at the top).
  Future<CursorPage<TransactionEntry>> transactions({String? cursor}) =>
      getPage('/customer/transactions', cursor, TransactionEntry.fromJson);

  /// Payout history — pending excluded server-side, failed included.
  Future<CursorPage<PayoutEntry>> payouts({String? cursor}) =>
      getPage('/customer/payouts', cursor, PayoutEntry.fromJson);

  Future<PayoutDetail> payoutDetail(int id) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/payouts/$id'),
    );

    return PayoutDetail.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // ------------------------------------------------ public discovery (R4)

  /// The Discover landing feed. PUBLIC — no auth, served from the same
  /// origin outside /mobile/v1, cached 60s server-side and ETagged.
  Future<DiscoverFeed> discover({double? lat, double? lng, int? zone}) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/discover',
        queryParameters: {'lat': ?lat, 'lng': ?lng, 'zone': ?zone},
      ),
    );

    return DiscoverFeed.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // ------------------------------------------------------------ marketplace

  /// The storefronts a shopper can buy from today.
  ///
  /// Lists BRANCHES, not merchants: the branch is the shop, and its delivery
  /// terms are a property of *branch → your address*, which is why the
  /// address travels with the request.
  Future<List<MarketBranch>> marketBranches({int? addressId}) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/market/branches',
        queryParameters: {'address_id': ?addressId},
      ),
    );

    return ((data?['data'] as List?) ?? const [])
        .map(
          (row) => MarketBranch.fromJson((row as Map).cast<String, dynamic>()),
        )
        .toList(growable: false);
  }

  /// One shop's shelves, optionally narrowed to an aisle.
  Future<MarketStore> marketStore(
    int branchId, {
    String? category,
    int? addressId,
  }) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/market/branches/$branchId',
        queryParameters: {'category': ?category, 'address_id': ?addressId},
      ),
    );

    return MarketStore.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The priced cart. EVERY cart call answers with the whole thing, not a
  /// diff, so the floating bar, the subcart cards and the totals can never
  /// disagree after a tap.
  Future<Cart> cart({int? addressId}) async => _cart(
    () => dio.get<Map<String, dynamic>>(
      '/customer/cart',
      queryParameters: {'address_id': ?addressId},
    ),
  );

  Future<Cart> addToCart(int branchProductId, {int qty = 1}) async => _cart(
    () => dio.post<Map<String, dynamic>>(
      '/customer/cart/items',
      data: {'branch_product_id': branchProductId, 'qty': qty},
    ),
  );

  /// Zero removes the line, exactly as the stepper does.
  Future<Cart> setCartQty(int cartItemId, int qty) async => _cart(
    () => dio.patch<Map<String, dynamic>>(
      '/customer/cart/items/$cartItemId',
      data: {'qty': qty},
    ),
  );

  Future<Cart> removeFromCart(int cartItemId) async => _cart(
    () => dio.delete<Map<String, dynamic>>('/customer/cart/items/$cartItemId'),
  );

  Future<Cart> clearCart() async =>
      _cart(() => dio.delete<Map<String, dynamic>>('/customer/cart'));

  Future<Cart> _cart(
    Future<Response<Map<String, dynamic>>> Function() call,
  ) async {
    final data = await run(call);

    return Cart.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // -------------------------------------------------- orders and the wallet

  /// Where to send the money for a marketplace order.
  Future<List<Map<String, dynamic>>> paymentAccounts() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/payment-accounts'),
    );

    return ((data?['data'] as List?) ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList(growable: false);
  }

  /// Place the order. Refusals name the shop that is blocking it.
  Future<CustomerOrder> placeOrder({
    required String paymentMethod,
    int? addressId,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/orders',
        data: {'payment_method': paymentMethod, 'address_id': ?addressId},
      ),
    );

    return CustomerOrder.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The transfer receipt. Until it lands, nothing is confirmed.
  Future<CustomerOrder> uploadOrderReceipt(
    int orderId, {
    required Uint8List bytes,
    required String filename,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/orders/$orderId/receipt',
        data: FormData.fromMap({
          'receipt': MultipartFile.fromBytes(bytes, filename: filename),
        }),
      ),
    );

    return CustomerOrder.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<List<CustomerOrder>> orders() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/orders'),
    );

    return ((data?['data'] as List?) ?? const [])
        .map(
          (row) => CustomerOrder.fromJson((row as Map).cast<String, dynamic>()),
        )
        .toList(growable: false);
  }

  Future<CustomerOrder> order(int id) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/orders/$id'),
    );

    return CustomerOrder.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // -------------------------------------------------------------- activity

  /// The one timeline: marketplace orders AND cashback, newest first.
  ///
  /// `tab` is active / completed / cancelled / all — the same vocabulary the
  /// screen's segmented control uses, resolved server-side because "active"
  /// means different states for an order than for a transaction.
  Future<ActivityPage> activity({String tab = 'active', int page = 1}) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '/customer/activity',
        queryParameters: {'tab': tab, 'page': page},
      ),
    );

    return ActivityPage.fromJson(data ?? const {});
  }

  /// PRODUCT search across every shop.
  ///
  /// Public, like browse — nobody signs in to find out whether a
  /// marketplace is worth signing in for.
  Future<SearchResults> searchProducts({
    String query = '',
    String sort = 'relevance',
    int? addressId,
    int? storeId,
  }) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/market/search',
        queryParameters: <String, dynamic>{
          'q': query,
          'sort': sort,
          'address_id': ?addressId,
          'store': ?storeId,
        },
      ),
    );

    return SearchResults.fromJson(data ?? const {});
  }

  /// One product, opened on its own.
  Future<ProductDetail> product(int branchProductId) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/market/products/$branchProductId',
      ),
    );

    return ProductDetail.fromJson(
      ((data?['data'] as Map?) ?? const {}).cast<String, dynamic>(),
    );
  }

  /// Heart a shop, or un-heart it. A toggle because the client has two
  /// states and no third thing to say.
  Future<bool> toggleFavourite(int branchId) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>('/customer/favourites/$branchId'),
    );

    return ((data?['data'] as Map?)?['favourite'] as bool?) ?? false;
  }

  // ------------------------------------------------------------- addresses

  Future<List<CustomerAddressEntry>> addresses() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/addresses'),
    );

    return ((data?['data'] as List?) ?? const [])
        .map(
          (row) => CustomerAddressEntry.fromJson(
            (row as Map).cast<String, dynamic>(),
          ),
        )
        .toList(growable: false);
  }

  Future<CustomerAddressEntry> saveAddress(
    Map<String, dynamic> body, {
    int? id,
  }) async {
    final data = await run(
      () => id == null
          ? dio.post<Map<String, dynamic>>('/customer/addresses', data: body)
          : dio.patch<Map<String, dynamic>>(
              '/customer/addresses/$id',
              data: body,
            ),
    );

    return CustomerAddressEntry.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<void> deleteAddress(int id) async {
    await run(
      () => dio.delete<Map<String, dynamic>>('/customer/addresses/$id'),
    );
  }

  // ---------------------------------------------------------------- wallet

  /// A REAL balance, distinct from the derived cashback figure. Refunds land
  /// here instantly, and it is always withdrawable.
  Future<WalletState> wallet() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/wallet'),
    );

    return WalletState.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<void> requestWithdrawal(int amountLaari) async {
    await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/wallet/withdrawals',
        data: {'amount_laari': amountLaari},
      ),
    );
  }

  // ------------------------------------------------------------- referrals

  /// The referral page in one call: the customer's code, the programme's
  /// live figures, and every invited friend's (masked, capped) progress.
  Future<ReferralsSummary> referrals() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/referrals'),
    );

    return ReferralsSummary.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The islands the location picker offers (admin-drawn zones).
  Future<List<ZoneEntry>> zones() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/discover/zones',
      ),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        ZoneEntry.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  /// Directory search — name needle and/or curated category.
  Future<DirectoryPage> directory({
    String? q,
    String? category,
    int page = 1,
  }) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/discover/merchants',
        queryParameters: {'q': ?q, 'category': ?category, 'page': page},
      ),
    );

    return DirectoryPage.fromJson(
      data?['data'] as List? ?? const [],
      (data?['meta'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<StorePage> store(String slug) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/discover/merchants/$slug',
      ),
    );

    return StorePage.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<List<DeviceEntry>> devices() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/devices'),
    );

    return [
      for (final item in (data?['data'] as List? ?? const []))
        DeviceEntry.fromJson((item as Map).cast<String, dynamic>()),
    ];
  }

  // ------------------------------------------------------------- auth

  /// Password sign-in — the endpoint that exists TODAY. The product
  /// decision (2026-08-17) is passwordless OTP; those endpoints arrive with
  /// round R1 and this method then becomes debug-only scaffolding.
  Future<void> signInWithPassword({
    required String phone,
    required String password,
    required String deviceName,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/auth/token',
        data: {'phone': phone, 'password': password, 'device_name': deviceName},
      ),
    );

    await _saveSignedIn((data?['data'] as Map?)?.cast<String, dynamic>() ?? {});
  }

  // ----------------------------------------------------- OTP (R1 flow)

  /// Ask for a code. Enumeration-safe server-side: the answer is identical
  /// whether or not the phone has an account, so the UI never learns —
  /// or leaks — anything at this step.
  Future<void> requestOtp(String phone) async => run(
    () => dio.post<void>('/customer/auth/otp/request', data: {'phone': phone}),
  );

  /// Verify the code. An existing account is signed in HERE — the session
  /// is saved and the caller routes home; an unknown number gets a signup
  /// token and the caller collects a name.
  Future<OtpVerifyOutcome> verifyOtp({
    required String phone,
    required String code,
    required String deviceName,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/auth/otp/verify',
        data: {'phone': phone, 'code': code, 'device_name': deviceName},
      ),
    );

    final payload = (data?['data'] as Map?)?.cast<String, dynamic>() ?? {};

    if (payload['status'] == 'signed_in') {
      await _saveSignedIn(payload);

      return OtpVerifyOutcome.signedIn();
    }

    return OtpVerifyOutcome.registrationRequired(
      payload['signup_token'] as String? ?? '',
    );
  }

  /// Finish signup: redeem the signup token, get signed in.
  ///
  /// [referralCode] is a friend's 6-digit customer code, optional and
  /// IMMUTABLE after signup — this is the only moment it can ever be given.
  /// The server ignores an unknown or inactive code silently, so passing
  /// one can never fail a registration.
  Future<void> registerWithOtp({
    required String signupToken,
    required String name,
    required String deviceName,
    String? referralCode,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/auth/register',
        data: {
          'signup_token': signupToken,
          'name': name,
          'device_name': deviceName,
          'referral_code': ?referralCode,
        },
      ),
    );

    await _saveSignedIn((data?['data'] as Map?)?.cast<String, dynamic>() ?? {});
  }

  Future<void> _saveSignedIn(Map<String, dynamic> payload) async {
    final customer =
        (payload['customer'] as Map?)?.cast<String, dynamic>() ?? {};

    await session.saveSession(
      token: payload['token'] as String? ?? '',
      customerCode: customer['customer_code']?.toString() ?? '',
      customerName: customer['name']?.toString() ?? '',
      avatarUrl: customer['avatar_url'] as String?,
    );
  }

  /// Sign out THIS device. The local wipe happens regardless of whether the
  /// server call lands — a phone with no signal must still be able to sign
  /// out, and the token dies server-side at the latest when it expires or
  /// is revoked from the website's device list.
  Future<void> signOut() async {
    try {
      await dio.delete<void>('/customer/auth/token');
    } catch (_) {
      // Intentional: local sign-out must never be blocked by the network.
    }
    await session.wipe();
  }

  // ------------------------------------------------- payout account (R5)

  Future<PayoutAccount> payoutAccount() async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>('/customer/payout-account'),
    );

    return PayoutAccount.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Send the confirmation code to the number on file, so a stolen token
  /// alone cannot redirect payouts.
  Future<void> requestPayoutAccountOtp() async =>
      run(() => dio.post<void>('/customer/payout-account/otp'));

  /// Change the account — the code proves possession of the phone.
  Future<PayoutAccount> updatePayoutAccount({
    required String bankName,
    required String accountNo,
    required String accountName,
    required String otpCode,
  }) async {
    final data = await run(
      () => dio.put<Map<String, dynamic>>(
        '/customer/payout-account',
        data: {
          'bank_name': bankName,
          'account_no': accountNo,
          'account_name': accountName,
          'otp_code': otpCode,
        },
      ),
    );

    return PayoutAccount.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  // ---------------------------------------------------- profile picture

  /// Upload or replace the profile picture. The bytes come from the OS
  /// photo picker; the server validates type/size/dimensions and answers
  /// the new content-addressed URL, which is cached in the session so every
  /// avatar in the app repaints — offline included.
  Future<String?> uploadAvatar({
    required Uint8List bytes,
    required String filename,
  }) async {
    final data = await run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/avatar',
        data: FormData.fromMap({
          'avatar': MultipartFile.fromBytes(bytes, filename: filename),
        }),
      ),
    );

    final url = (data?['data'] as Map?)?['avatar_url'] as String?;
    await session.setAvatarUrl(url);

    return url;
  }

  /// Remove the profile picture — back to initials everywhere.
  Future<void> removeAvatar() async {
    await run(() => dio.delete<Map<String, dynamic>>('/customer/avatar'));
    await session.setAvatarUrl(null);
  }

  Future<void> revokeDevice(int id) async =>
      run(() => dio.delete<Map<String, dynamic>>('/customer/devices/$id'));

  Future<void> revokeAllDevices() async =>
      run(() => dio.delete<Map<String, dynamic>>('/customer/devices'));

  // ------------------------------------------------------------- push

  Future<void> registerPushToken({
    required String token,
    required String platform,
    int? appBuild,
    String? locale,
  }) async => run(
    () => dio.put<void>(
      '/customer/push-token',
      data: {
        'token': token,
        'platform': platform,
        'app_build': ?appBuild,
        'locale': ?locale,
      },
    ),
  );

  Future<void> deletePushToken(String token) async => run(
    () => dio.delete<void>('/customer/push-token', data: {'token': token}),
  );
}

/// Mirrors the server's Msisdn normalisation: seven local digits become the
/// stored E.164 shape, so "7712345" and "+9607712345" are one account on the
/// wire exactly as they are in the database.
String normalizePhone(String input) {
  final trimmed = input.replaceAll(RegExp(r'[\s-]'), '');

  if (RegExp(r'^[79]\d{6}$').hasMatch(trimmed)) return '+960$trimmed';

  return trimmed;
}
