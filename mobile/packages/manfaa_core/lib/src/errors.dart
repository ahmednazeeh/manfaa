/// The API's error envelope, as a typed exception.
///
/// Every error the mobile tree emits is `{error: {code, message, meta}}`
/// (docs/mobile-api-guide.md §4). `code` is the contract the app switches on
/// and localises; `message` is the server's fallback sentence — ALWAYS prose,
/// shown verbatim whenever the app meets a code this build does not know.
/// A raw snake_case code on screen is a bug by project law.
library;

/// The codes the app is expected to know. Field messages inside
/// `meta.fields` are display text, not codes — `error.code` is the only
/// reliable machine-readable value (guide §4).
abstract final class ApiCode {
  static const unauthenticated = 'unauthenticated';
  static const forbidden = 'forbidden';
  static const notFound = 'not_found';
  static const conflict = 'conflict';
  static const validationFailed = 'validation_failed';
  static const rateLimited = 'rate_limited';
  static const serverError = 'server_error';
  static const cursorInvalid = 'cursor_invalid';
  static const customerNotFound = 'customer_not_found';
}

class MobileApiException implements Exception {
  MobileApiException({
    required this.code,
    required this.message,
    this.meta = const {},
    this.status,
  });

  /// Built from a response body that may or may not carry the envelope —
  /// a proxy error page, for instance, has no JSON at all. Anything
  /// unparseable degrades to a status-derived code with a generic sentence,
  /// never to a crash and never to raw payload on screen.
  factory MobileApiException.fromResponse(Object? body, int? status) {
    if (body is Map) {
      final error = body['error'];
      if (error is Map) {
        return MobileApiException(
          code: error['code'] as String? ?? ApiCode.serverError,
          message: error['message'] as String? ?? _fallbackFor(status),
          meta: (error['meta'] as Map?)?.cast<String, dynamic>() ?? const {},
          status: status,
        );
      }
    }

    return MobileApiException(
      code: _codeFor(status),
      message: _fallbackFor(status),
      status: status,
    );
  }

  factory MobileApiException.network() => MobileApiException(
        code: 'network_unreachable',
        message: 'No connection. Check your network and try again.',
      );

  final String code;

  /// Prose, safe to show. The server guarantees it for enveloped errors;
  /// the factories above guarantee it for everything else.
  final String message;

  final Map<String, dynamic> meta;
  final int? status;

  /// `validation_failed` carries per-field display text under meta.fields.
  List<String> fieldMessages(String field) {
    final fields = meta['fields'];
    if (fields is Map && fields[field] is List) {
      return (fields[field] as List).map((m) => m.toString()).toList();
    }
    return const [];
  }

  /// Guide §6: honour Retry-After before retrying a 429.
  int? get retryAfterSeconds => switch (meta['retry_after_seconds']) {
        final int s => s,
        final String s => int.tryParse(s),
        _ => null,
      };

  static String _codeFor(int? status) => switch (status) {
        401 => ApiCode.unauthenticated,
        403 => ApiCode.forbidden,
        404 => ApiCode.notFound,
        409 => ApiCode.conflict,
        422 => ApiCode.validationFailed,
        429 => ApiCode.rateLimited,
        _ => ApiCode.serverError,
      };

  static String _fallbackFor(int? status) => switch (status) {
        401 => 'Please sign in again.',
        429 => 'Too many attempts. Please wait a moment and try again.',
        _ => 'Something went wrong at our end. Please try again.',
      };

  @override
  String toString() => 'MobileApiException($status $code: $message)';
}
