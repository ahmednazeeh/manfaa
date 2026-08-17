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

  // ------------------------------------------------------- merchant till
  // The credit path's refusals (guide §4/§6; HandlesCreditRequests and
  // IdempotencyMiddleware server-side). All terminal except the two the
  // guide singles out: `idempotency_key_in_flight` retries with the SAME
  // key, and `backdated_confirmation_required` resends with the flag only
  // after a human confirms (meta carries `server_time` — check the device
  // clock first).
  static const merchantNotActive = 'merchant_not_active';
  static const futureDated = 'future_dated';
  static const noEffectiveRate = 'no_effective_rate';
  static const rateBelowAdvertised = 'rate_below_advertised';
  static const rateNotPriced = 'rate_not_priced';
  static const backdatedConfirmationRequired = 'backdated_confirmation_required';
  static const duplicateInvoice = 'duplicate_invoice';

  /// 403 with the missing slug in `meta.permission` — refresh /merchant/me;
  /// the role may have narrowed since the navigation was drawn.
  static const permissionRequired = 'permission_required';

  static const idempotencyKeyRequired = 'idempotency_key_required';
  static const idempotencyKeyReuseMismatch = 'idempotency_key_reuse_mismatch';
  static const idempotencyKeyInFlight = 'idempotency_key_in_flight';

  // ------------------------------------------------- amend / cancel (MR2)
  // The transactions tab's correction refusals. All terminal: the sale's
  // state moved on, or the platform owns it now — retyping cannot fix any
  // of them.
  static const notAmendableState = 'not_amendable_state';
  static const backdatedIrreversible = 'backdated_irreversible';
  static const invalidState = 'invalid_state';
  static const adjustmentCreated = 'adjustment_created';
  static const storeNotApproved = 'store_not_approved';
  static const storeNotTrading = 'store_not_trading';
  static const saleBelowEligible = 'sale_below_eligible';
  static const linesSumMismatch = 'lines_sum_mismatch';

  // ------------------------------------------------------ settlements (MR3)
  // The receipt-first flow's refusals. `duplicate_bank_ref` (409) means a
  // slip with this bank reference was already submitted; the slip pair are
  // decided by MAGIC BYTES server-side, so a renamed .svg fails there even
  // after passing the client pre-flight. Insufficient wallet balance and a
  // no-longer-eligible selection arrive as `validation_failed` with the
  // server's own prose sentence.
  static const duplicateBankRef = 'duplicate_bank_ref';
  static const slipTooLarge = 'slip_too_large';
  static const slipUnsupportedType = 'slip_unsupported_type';

  // -------------------------------------------------- signup + setup wizard
  // The merchant onboarding path (mobile signup/setup mounting, MR1).
  static const otpInvalid = 'otp_invalid';
  static const otpAttemptsExceeded = 'otp_attempts_exceeded';
  static const signupTokenInvalid = 'signup_token_invalid';

  /// Only ever sent AFTER the OTP proved possession of the phone — safe to
  /// route to sign-in without leaking who is registered to strangers.
  static const emailAlreadyRegistered = 'email_already_registered';

  /// 409 — the wizard writes are refused unless status is draft|rejected.
  static const setupNotEditable = 'setup_not_editable';

  /// 422 on submit — `meta.missing` lists the requirement keys
  /// (category|channel|rate|terms) still unmet; see [MobileApiException.missingRequirements].
  static const setupIncomplete = 'setup_incomplete';

  /// 503 on the logo upload — storage hiccup, retryable as-is.
  static const logoWriteFailed = 'logo_write_failed';
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

  /// `setup_incomplete` carries the unmet requirement keys under
  /// `meta.missing` (category|channel|rate|terms) — the review step turns
  /// each into a fix-this jump-back.
  List<String> get missingRequirements => switch (meta['missing']) {
        final List keys => [for (final key in keys) key.toString()],
        _ => const [],
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
