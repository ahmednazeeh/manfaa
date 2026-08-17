import 'dart:math';

import 'package:dio/dio.dart';

import '../session.dart';

/// Attaches the bearer token, and enforces the guide's hardest rule:
/// **any 401 means the session is over** — wipe token and local state,
/// return to sign-in (docs/mobile-api-guide.md §4). One place, so no screen
/// can forget it.
class AuthInterceptor extends Interceptor {
  AuthInterceptor(this._session);

  final SessionStore _session;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final token = _session.token;
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (err.response?.statusCode == 401 && _session.signedIn) {
      // The wipe bumps the session revision; the router listens and lands
      // the user on sign-in. Screens never handle 401 individually.
      await _session.wipe();
    }
    handler.next(err);
  }
}

/// Conditional GET (guide §5). The API shapes /config, /me and /home for
/// exactly this: an unchanged answer costs a ~200-byte 304 instead of the
/// body, which is what makes refreshing on every resume affordable on a
/// Maldives connection.
///
/// Memory-only by design: the cache exists to serve revalidation within a
/// session; nothing here needs to survive a process restart, and disk would
/// mean encrypting per-account payloads.
class EtagCacheInterceptor extends Interceptor {
  final _cache = <String, ({String etag, Object? data})>{};

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    if (options.method == 'GET') {
      final hit = _cache[options.uri.toString()];
      if (hit != null) {
        options.headers['If-None-Match'] = hit.etag;
      }
      // Dio treats 304 as an error by default; accept it here and translate
      // it back into the cached 200 in onResponse.
      final previous = options.validateStatus;
      options.validateStatus = (s) => s == 304 || previous(s);
    }
    handler.next(options);
  }

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    final key = response.requestOptions.uri.toString();

    if (response.statusCode == 304) {
      final hit = _cache[key];
      if (hit != null) {
        handler.next(
          Response(
            requestOptions: response.requestOptions,
            statusCode: 200,
            data: hit.data,
            headers: response.headers..set('x-manfaa-revalidated', 'true'),
          ),
        );
        return;
      }
      // A 304 with no cache entry means our entry was evicted — fall through
      // and let the caller treat it as empty; the next plain GET repopulates.
    }

    final etag = response.headers.value('etag');
    if (response.statusCode == 200 &&
        response.requestOptions.method == 'GET' &&
        etag != null) {
      _cache[key] = (etag: etag, data: response.data);
    }

    handler.next(response);
  }

  void clear() => _cache.clear();
}

/// Retry (guide §6): network failures and 5xx retry with backoff and jitter;
/// a 429 waits out a short Retry-After once; documented 4xx are terminal and
/// never retried. GETs only — the customer app's writes are few and none of
/// them may be replayed blindly.
class RetryInterceptor extends Interceptor {
  RetryInterceptor(this._dio, {Random? random}) : _random = random ?? Random();

  static const _maxAttempts = 3;

  final Dio _dio;
  final Random _random;

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final options = err.requestOptions;
    final attempt = (options.extra['attempt'] as int? ?? 1);

    if (options.method != 'GET' ||
        attempt >= _maxAttempts ||
        !_shouldRetry(err)) {
      handler.next(err);
      return;
    }

    final delay = _delayFor(err, attempt);
    if (delay == null) {
      handler.next(err);
      return;
    }

    await Future<void>.delayed(delay);
    options.extra['attempt'] = attempt + 1;

    try {
      handler.resolve(await _dio.fetch<Object?>(options));
    } on DioException catch (retryErr) {
      handler.next(retryErr);
    }
  }

  bool _shouldRetry(DioException err) => switch (err.type) {
        DioExceptionType.connectionTimeout ||
        DioExceptionType.receiveTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.connectionError =>
          true,
        DioExceptionType.badResponse =>
          (err.response?.statusCode ?? 0) >= 500 ||
              err.response?.statusCode == 429,
        _ => false,
      };

  Duration? _delayFor(DioException err, int attempt) {
    if (err.response?.statusCode == 429) {
      final after =
          int.tryParse(err.response?.headers.value('retry-after') ?? '');
      // A long wait is the server saying STOP — surface it, don't spin.
      if (after == null || after > 8) return null;
      return Duration(seconds: after);
    }

    final base = 350 * (1 << (attempt - 1));
    return Duration(milliseconds: base + _random.nextInt(250));
  }
}
