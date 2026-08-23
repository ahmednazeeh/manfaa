import 'package:dio/dio.dart';

/// The client half of the self-referral defence (owner, 2026-08-24).
///
/// The customer app resolves its sanctioned per-OS identifiers at startup —
/// Android SSAID; iOS identifierForVendor PLUS a Keychain-persisted UUID
/// (the one that survives reinstalls) — and parks them here so
/// [DeviceIdentityInterceptor] can attach them to every request. The RAW ids
/// travel on the wire; the server stores only keyed hashes and compares for
/// equality, nothing more.
///
/// Resolution is async and never blocks a request: until [set] runs, requests
/// simply omit the headers, and the first request after resolution covers the
/// device (the server records on every authed customer call).
class DeviceIdentity {
  /// Header names are the SERVER's contract (RecordsCustomerDevice).
  static const headerId = 'X-Device-Id';
  static const headerPlatform = 'X-Device-Platform';

  /// iOS's SECOND identity: the Keychain-persisted `kc:` UUID rides here
  /// alongside the `ifv:` primary — it is the one identifier that survives
  /// a delete-and-reinstall (which rotates the IFV), so it MUST reach the
  /// server, not sit unread in the Keychain. The server records both as
  /// their own sightings.
  static const headerRef = 'X-Device-Ref';

  String? _id;
  String? _ref;
  String? _platform;

  /// The raw identifier to send, or null while unresolved (or on platforms
  /// with no sanctioned id — desktop dev runs, tests).
  String? get id => _id;

  /// The secondary identifier (iOS `kc:` UUID), or null when there is none
  /// or it would duplicate [id].
  String? get ref => _ref;

  /// 'android' | 'ios'; only meaningful while [id] is non-null.
  String? get platform => _platform;

  /// Called once by the app's startup resolver. An empty id is refused —
  /// no header at all beats a header that hashes every phone alike.
  void set({required String id, required String platform, String? ref}) {
    if (id.isEmpty) return;
    _id = id;
    _platform = platform;
    _ref = (ref != null && ref.isNotEmpty && ref != id) ? ref : null;
  }
}

/// Attaches the device identity headers, mirroring [AuthInterceptor]'s
/// shape: one place on the Dio, so no call can forget it.
class DeviceIdentityInterceptor extends Interceptor {
  DeviceIdentityInterceptor(this._identity);

  final DeviceIdentity _identity;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final id = _identity.id;
    if (id != null) {
      options.headers[DeviceIdentity.headerId] = id;
      options.headers[DeviceIdentity.headerPlatform] = _identity.platform;
      final ref = _identity.ref;
      if (ref != null) {
        options.headers[DeviceIdentity.headerRef] = ref;
      }
    }
    handler.next(options);
  }
}
