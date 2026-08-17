import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show protected;

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

/// The shared transport for /api/mobile/v1 — everything the two apps' typed
/// clients ([ManfaaApi], [MerchantApi]) have in common.
///
/// Thin by design: every business rule lives on the server, and this class
/// only speaks the transport contract (docs/mobile-api-guide.md) — bearer
/// auth, the error envelope, conditional GETs, the retry law, per-request
/// idempotency keys. Subclasses add typed endpoint methods and nothing else;
/// screens receive models or a MobileApiException whose `message` is always
/// safe to show.
///
/// Generic over the session so each app's client sees its own profile
/// fields; the transport itself needs only the [SessionBase] contract
/// (token, signedIn, wipe) — which is all the AuthInterceptor reads.
abstract class ManfaaApiBase<S extends SessionBase> {
  ManfaaApiBase({required this.session, Dio? dio})
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
  final S session;

  /// The header a write that must drain safely from an offline queue
  /// carries (guide §6). `POST /merchant/credits` REQUIRES it.
  static const idempotencyHeader = 'Idempotency-Key';

  // ------------------------------------------------------------- shared

  Future<MobileConfig> fetchConfig() async => MobileConfig.fromJson(
        await getJson('/config'),
      );

  // ------------------------------------------------------------ plumbing

  /// GET a path and unwrap the success envelope's `data` object.
  @protected
  Future<Map<String, dynamic>> getJson(String path) async {
    final data = await run(() => dio.get<Map<String, dynamic>>(path));

    return (data?['data'] as Map?)?.cast<String, dynamic>() ?? {};
  }

  /// One page of a cursor-paged list (guide §6 shape). [query] carries any
  /// extra filters (state, per_page); nulls must not be put in it.
  @protected
  Future<CursorPage<T>> getPage<T>(
    String path,
    String? cursor,
    T Function(Map<String, dynamic>) parse, {
    Map<String, Object?> query = const {},
  }) async {
    final data = await run(
      () => dio.get<Map<String, dynamic>>(
        path,
        queryParameters: {...query, 'cursor': ?cursor},
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

  /// Request options carrying a per-request [idempotencyHeader]. The caller
  /// generates one key PER SALE, stores it beside the queued write, and
  /// reuses the SAME key on every retry of that write — that is what lets a
  /// request that timed out after the server committed replay its original
  /// answer instead of booking a second sale.
  @protected
  Options idempotent(String key) => Options(headers: {idempotencyHeader: key});

  /// Like [run] but hands back the whole [Response] — for the rare caller
  /// that must read status or headers (the idempotent-replay signal).
  @protected
  Future<Response<T>> send<T>(Future<Response<T>> Function() call) async {
    try {
      return await call();
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

  /// Every call funnels through here so the envelope mapping exists once.
  @protected
  Future<T?> run<T>(Future<Response<T>> Function() call) async =>
      (await send(call)).data;
}
