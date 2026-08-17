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
      merchantStatus: merchant.status,
      permissions: [
        for (final slug in (payload['permissions'] as List? ?? const []))
          slug.toString(),
      ],
    );
  }

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
