import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'errors.dart';
import 'http/interceptors.dart';
import 'models.dart';
import 'session.dart';

/// The API base. Overridable per build (`--dart-define=API_BASE_URL=`);
/// defaults to production — there is no staging environment, and dev runs
/// against live with test accounts exactly as the web panels are developed.
abstract final class ApiEnv {
  static const baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://manfaa.app/api/mobile/v1',
  );

  /// The PUBLIC api root (discovery, storefront) — the same origin one level
  /// up from the mobile tree. Derived, so one --dart-define moves both.
  static final publicBaseUrl = baseUrl.replaceFirst(RegExp(r'/mobile/v1/?$'), '');
}

/// The one client for /api/mobile/v1.
///
/// Thin by design: every business rule lives on the server, and this class
/// only speaks the transport contract (docs/mobile-api-guide.md) — bearer
/// auth, the error envelope, conditional GETs, the retry law. Screens call
/// typed methods and receive models or a MobileApiException whose `message`
/// is always safe to show.
class ManfaaApi {
  ManfaaApi({required this.session, Dio? dio})
      : dio = dio ??
            Dio(
              BaseOptions(
                baseUrl: ApiEnv.baseUrl,
                connectTimeout: const Duration(seconds: 10),
                receiveTimeout: const Duration(seconds: 20),
                headers: {'Accept': 'application/json'},
              ),
            ) {
    this.dio.interceptors.addAll([
      AuthInterceptor(session),
      EtagCacheInterceptor(),
      RetryInterceptor(this.dio),
    ]);
  }

  final Dio dio;
  final SessionStore session;

  // ------------------------------------------------------------- reads

  Future<MobileConfig> fetchConfig() async => MobileConfig.fromJson(
        await _get('/config'),
      );

  Future<CustomerMe> me() async => CustomerMe.fromJson(
        await _get('/customer/me'),
      );

  Future<HomeData> home() async {
    final data = HomeData.fromJson(await _get('/customer/home'));

    // Keep the offline avatar cache honest: /home is the call every launch
    // makes, so a picture changed on the website lands here first.
    await session.setAvatarUrl(data.avatarUrl);

    return data;
  }

  /// Earnings history, cursor-paged (the list grows at the top).
  Future<CursorPage<TransactionEntry>> transactions({String? cursor}) =>
      _page('/customer/transactions', cursor, TransactionEntry.fromJson);

  /// Payout history — pending excluded server-side, failed included.
  Future<CursorPage<PayoutEntry>> payouts({String? cursor}) =>
      _page('/customer/payouts', cursor, PayoutEntry.fromJson);

  Future<PayoutDetail> payoutDetail(int id) async {
    final data = await _run(
      () => dio.get<Map<String, dynamic>>('/customer/payouts/$id'),
    );

    return PayoutDetail.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<CursorPage<T>> _page<T>(
    String path,
    String? cursor,
    T Function(Map<String, dynamic>) parse,
  ) async {
    final data = await _run(
      () => dio.get<Map<String, dynamic>>(
        path,
        queryParameters: {'cursor': ?cursor},
      ),
    );

    final page = (data?['page'] as Map?)?.cast<String, dynamic>() ?? {};

    return CursorPage(
      items: [
        for (final item in (data?['data'] as List? ?? const []))
          parse((item as Map).cast<String, dynamic>()),
      ],
      nextCursor: page['next_cursor'] as String?,
      hasMore: page['has_more'] as bool? ?? false,
    );
  }

  // ------------------------------------------------ public discovery (R4)

  /// The Discover landing feed. PUBLIC — no auth, served from the same
  /// origin outside /mobile/v1, cached 60s server-side and ETagged.
  Future<DiscoverFeed> discover({double? lat, double? lng, int? zone}) async {
    final data = await _run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/discover',
        queryParameters: {'lat': ?lat, 'lng': ?lng, 'zone': ?zone},
      ),
    );

    return DiscoverFeed.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// The islands the location picker offers (admin-drawn zones).
  Future<List<ZoneEntry>> zones() async {
    final data = await _run(
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
    final data = await _run(
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
    final data = await _run(
      () => dio.get<Map<String, dynamic>>(
        '${ApiEnv.publicBaseUrl}/discover/merchants/$slug',
      ),
    );

    return StorePage.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  Future<List<DeviceEntry>> devices() async {
    final data = await _run(
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
    final data = await _run(
      () => dio.post<Map<String, dynamic>>(
        '/customer/auth/token',
        data: {
          'phone': phone,
          'password': password,
          'device_name': deviceName,
        },
      ),
    );

    await _saveSignedIn((data?['data'] as Map?)?.cast<String, dynamic>() ?? {});
  }

  // ----------------------------------------------------- OTP (R1 flow)

  /// Ask for a code. Enumeration-safe server-side: the answer is identical
  /// whether or not the phone has an account, so the UI never learns —
  /// or leaks — anything at this step.
  Future<void> requestOtp(String phone) async =>
      _run(() => dio.post<void>('/customer/auth/otp/request', data: {
            'phone': phone,
          }));

  /// Verify the code. An existing account is signed in HERE — the session
  /// is saved and the caller routes home; an unknown number gets a signup
  /// token and the caller collects a name.
  Future<OtpVerifyOutcome> verifyOtp({
    required String phone,
    required String code,
    required String deviceName,
  }) async {
    final data = await _run(
      () => dio.post<Map<String, dynamic>>('/customer/auth/otp/verify', data: {
        'phone': phone,
        'code': code,
        'device_name': deviceName,
      }),
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
  Future<void> registerWithOtp({
    required String signupToken,
    required String name,
    required String deviceName,
  }) async {
    final data = await _run(
      () => dio.post<Map<String, dynamic>>('/customer/auth/register', data: {
        'signup_token': signupToken,
        'name': name,
        'device_name': deviceName,
      }),
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
    final data = await _run(
      () => dio.get<Map<String, dynamic>>('/customer/payout-account'),
    );

    return PayoutAccount.fromJson(
      (data?['data'] as Map?)?.cast<String, dynamic>() ?? {},
    );
  }

  /// Send the confirmation code to the number on file, so a stolen token
  /// alone cannot redirect payouts.
  Future<void> requestPayoutAccountOtp() async =>
      _run(() => dio.post<void>('/customer/payout-account/otp'));

  /// Change the account — the code proves possession of the phone.
  Future<PayoutAccount> updatePayoutAccount({
    required String bankName,
    required String accountNo,
    required String accountName,
    required String otpCode,
  }) async {
    final data = await _run(
      () => dio.put<Map<String, dynamic>>('/customer/payout-account', data: {
        'bank_name': bankName,
        'account_no': accountNo,
        'account_name': accountName,
        'otp_code': otpCode,
      }),
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
    final data = await _run(
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
    await _run(() => dio.delete<Map<String, dynamic>>('/customer/avatar'));
    await session.setAvatarUrl(null);
  }

  Future<void> revokeDevice(int id) async =>
      _run(() => dio.delete<Map<String, dynamic>>('/customer/devices/$id'));

  Future<void> revokeAllDevices() async =>
      _run(() => dio.delete<Map<String, dynamic>>('/customer/devices'));

  // ------------------------------------------------------------- push

  Future<void> registerPushToken({
    required String token,
    required String platform,
    int? appBuild,
    String? locale,
  }) async =>
      _run(
        () => dio.put<void>('/customer/push-token', data: {
          'token': token,
          'platform': platform,
          'app_build': ?appBuild,
          'locale': ?locale,
        }),
      );

  Future<void> deletePushToken(String token) async => _run(
        () => dio.delete<void>('/customer/push-token', data: {'token': token}),
      );

  // ------------------------------------------------------------ plumbing

  Future<Map<String, dynamic>> _get(String path) async {
    final data = await _run(() => dio.get<Map<String, dynamic>>(path));

    return (data?['data'] as Map?)?.cast<String, dynamic>() ?? {};
  }

  /// Every call funnels through here so the envelope mapping exists once.
  Future<T?> _run<T>(Future<Response<T>> Function() call) async {
    try {
      return (await call()).data;
    } on DioException catch (e) {
      if (e.type == DioExceptionType.badResponse) {
        throw MobileApiException.fromResponse(
          e.response?.data,
          e.response?.statusCode,
        );
      }
      throw MobileApiException.network();
    }
  }
}

/// Mirrors the server's Msisdn normalisation: seven local digits become the
/// stored E.164 shape, so "7712345" and "+9607712345" are one account on the
/// wire exactly as they are in the database.
String normalizePhone(String input) {
  final trimmed = input.replaceAll(RegExp(r'[\s-]'), '');

  if (RegExp(r'^[79]\d{6}$').hasMatch(trimmed)) return '+960$trimmed';

  return trimmed;
}
