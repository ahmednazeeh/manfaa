import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the bearer token lives: the platform keystore, never preferences —
/// the token is a long-lived credential (365 days customer, 90 merchant;
/// guide §2).
///
/// Profile fields are cached beside it ON PURPOSE: the customer's fullscreen
/// QR and the merchant till's navigation must both work in a shop with no
/// signal, and none of the cached fields are secrets the token protects.
abstract class SecretStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class SecureSecretStore implements SecretStore {
  SecureSecretStore([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

/// In-memory store for tests and for platforms with no keychain.
class MemorySecretStore implements SecretStore {
  final _values = <String, String>{};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async => _values[key] = value;

  @override
  Future<void> delete(String key) async => _values.remove(key);
}

/// The app-agnostic half of a session: token, locale, theme and push
/// preference, and the revision the router listens to. This is everything
/// the transport (AuthInterceptor) and the app chrome need; the per-app
/// profile fields live in [CustomerSession] and [MerchantSession].
///
/// KEY NAMES ARE AN INSTALLED-BASE CONTRACT. Each app has its own keystore
/// namespace (its own applicationId), so the two apps cannot collide — but
/// within an app, renaming a key silently signs every existing install out.
/// The customer names below shipped in 1.0.0 and must never change.
abstract class SessionBase {
  SessionBase(this._store);

  static const _kToken = 'token';
  static const _kLocale = 'locale';
  static const _kPush = 'push_enabled';
  static const _kThemeMode = 'theme_mode';

  final SecretStore _store;

  String? _token;
  String _locale = 'en';
  bool _pushEnabled = true;
  // Light-first by decision: a fresh install shows the light theme rather
  // than inheriting a dark phone. Values: 'system' | 'light' | 'dark'.
  String _themeMode = 'light';

  /// Bumped on every sign-in/out so the router can listen and redirect —
  /// including the guide's hard rule that ANY 401 means the session is over.
  final revision = ValueNotifier<int>(0);

  bool get signedIn => _token != null;
  String? get token => _token;
  String get locale => _locale;

  /// The theme mode the user picked: 'system', 'light', or 'dark'. Survives
  /// sign-out for the same reason locale does — a preference, not a secret.
  String get themeMode => _themeMode;

  /// Whether this device WANTS push. Distinct from the OS permission: a user
  /// who turned notifications off here should stay off even if the system
  /// permission is granted. Default true — the value only turns false when a
  /// user deliberately opts out.
  bool get pushEnabled => _pushEnabled;

  Future<void> init() async {
    _token = await _store.read(_kToken);
    _locale = await _store.read(_kLocale) ?? 'en';
    _pushEnabled = (await _store.read(_kPush)) != 'false';
    _themeMode = await _store.read(_kThemeMode) ?? 'light';
    await _initProfile();
  }

  /// Load the app-specific profile fields cached beside the token.
  Future<void> _initProfile();

  /// Delete the app-specific profile fields — the other half of [wipe].
  Future<void> _wipeProfile();

  /// Persist the token. Subclass saveSession methods call this, then write
  /// their profile fields, then bump [revision] once.
  Future<void> _saveToken(String token) async {
    await _store.write(_kToken, token);
    _token = token;
  }

  Future<void> setPushEnabled(bool enabled) async {
    _pushEnabled = enabled;
    await _store.write(_kPush, enabled ? 'true' : 'false');
  }

  Future<void> setThemeMode(String mode) async {
    _themeMode = mode;
    await _store.write(_kThemeMode, mode);
    revision.value++;
  }

  Future<void> setLocale(String locale) async {
    _locale = locale;
    await _store.write(_kLocale, locale);
    revision.value++;
  }

  /// Sign-out and the 401 path both land here. The locale, theme and push
  /// preferences survive a wipe — losing your language because a token
  /// expired would be hostile.
  Future<void> wipe() async {
    await _store.delete(_kToken);
    _token = null;
    await _wipeProfile();
    revision.value++;
  }
}

/// The customer app's session: code, name and avatar cached for offline
/// paint (the fullscreen QR at a till with no signal is the app's whole
/// job). Key names are frozen — see [SessionBase].
class CustomerSession extends SessionBase {
  CustomerSession(super.store);

  static const _kCode = 'customer_code';
  static const _kName = 'customer_name';
  static const _kAvatar = 'avatar_url';

  String? _customerCode;
  String? _customerName;
  String? _avatarUrl;

  String? get customerCode => _customerCode;
  String? get customerName => _customerName;

  /// The profile picture URL, cached beside the name and code for the same
  /// reason they are: the top bar must render offline. Content-addressed
  /// server-side (a new uuid URL per upload), so it never goes stale — a
  /// changed picture is a changed URL.
  String? get avatarUrl => _avatarUrl;

  @override
  Future<void> _initProfile() async {
    _customerCode = await _store.read(_kCode);
    _customerName = await _store.read(_kName);
    _avatarUrl = await _store.read(_kAvatar);
  }

  Future<void> saveSession({
    required String token,
    required String customerCode,
    required String customerName,
    String? avatarUrl,
  }) async {
    await _saveToken(token);
    await _store.write(_kCode, customerCode);
    await _store.write(_kName, customerName);
    if (avatarUrl != null && avatarUrl.isNotEmpty) {
      await _store.write(_kAvatar, avatarUrl);
    } else {
      await _store.delete(_kAvatar);
    }
    _customerCode = customerCode;
    _customerName = customerName;
    _avatarUrl = (avatarUrl?.isEmpty ?? true) ? null : avatarUrl;
    revision.value++;
  }

  /// Update the cached picture alone — after an upload/remove, or when a
  /// fresh /home shows the picture changed on another surface. Bumps
  /// [revision] so anything painting the avatar repaints.
  Future<void> setAvatarUrl(String? url) async {
    final normalized = (url?.isEmpty ?? true) ? null : url;
    if (normalized == _avatarUrl) return;
    if (normalized == null) {
      await _store.delete(_kAvatar);
    } else {
      await _store.write(_kAvatar, normalized);
    }
    _avatarUrl = normalized;
    revision.value++;
  }

  @override
  Future<void> _wipeProfile() async {
    await _store.delete(_kCode);
    await _store.delete(_kName);
    await _store.delete(_kAvatar);
    _customerCode = null;
    _customerName = null;
    _avatarUrl = null;
  }
}

/// The customer app predates the session split and constructs `SessionStore`
/// on every surface; the alias keeps that code — and its installed
/// sessions — exactly as they are.
typedef SessionStore = CustomerSession;

/// The merchant app's session: the staff identity, the store, and the
/// resolved permission slugs, cached so the till can paint its (permission-
/// driven) navigation before the first network answer arrives.
///
/// THE CACHE IS A SNAPSHOT, NOT AN AUTHORITY: `GET /merchant/me` re-resolves
/// permissions and merchant status on every launch and resume (guide §3) and
/// the fresh answer lands here via [saveProfile] — the server enforces
/// regardless, so a stale cache can only ever mis-draw, never over-permit.
class MerchantSession extends SessionBase {
  MerchantSession(super.store);

  static const _kUserName = 'user_name';
  static const _kUserEmail = 'user_email';
  static const _kMerchantId = 'merchant_id';
  static const _kMerchantName = 'merchant_name';
  static const _kMerchantSlug = 'merchant_slug';
  static const _kMerchantStatus = 'merchant_status';
  static const _kPermissions = 'permissions';

  String? _userName;
  String? _userEmail;
  int? _merchantId;
  String? _merchantName;
  String? _merchantSlug;
  String? _merchantStatus;
  List<String> _permissions = const [];

  String? get userName => _userName;
  String? get userEmail => _userEmail;
  int? get merchantId => _merchantId;
  String? get merchantName => _merchantName;
  String? get merchantSlug => _merchantSlug;

  /// draft | pending_review | active | suspended | rejected — the router's
  /// status gate. Null until the first /merchant/me lands (sign-in does not
  /// return it).
  String? get merchantStatus => _merchantStatus;

  /// The resolved permission slugs, exactly as the panels use them.
  List<String> get permissions => _permissions;

  /// The UI gate, same semantics as the web panel's `can()`: a flat
  /// includes check. Drawing is gated here; DOING is gated by the server.
  bool can(String slug) => _permissions.contains(slug);

  @override
  Future<void> _initProfile() async {
    _userName = await _store.read(_kUserName);
    _userEmail = await _store.read(_kUserEmail);
    _merchantId = int.tryParse(await _store.read(_kMerchantId) ?? '');
    _merchantName = await _store.read(_kMerchantName);
    _merchantSlug = await _store.read(_kMerchantSlug);
    _merchantStatus = await _store.read(_kMerchantStatus);
    _permissions = _decodePermissions(await _store.read(_kPermissions));
  }

  /// Sign-in (POST /merchant/auth/token). The payload carries user,
  /// merchant and permissions but NOT the merchant's status — the app calls
  /// /merchant/me immediately after, which fills it via [saveProfile].
  Future<void> saveSession({
    required String token,
    required String userName,
    required String userEmail,
    int? merchantId,
    String? merchantName,
    String? merchantSlug,
    String? merchantStatus,
    required List<String> permissions,
  }) async {
    await _saveToken(token);
    await _writeProfile(
      userName: userName,
      userEmail: userEmail,
      merchantId: merchantId,
      merchantName: merchantName,
      merchantSlug: merchantSlug,
      merchantStatus: merchantStatus,
      permissions: permissions,
    );
    revision.value++;
  }

  /// A fresh /merchant/me answer. Bumps [revision] so navigation built from
  /// [can] re-renders the moment a narrowed role or a status change lands.
  Future<void> saveProfile({
    required String userName,
    required String userEmail,
    int? merchantId,
    String? merchantName,
    String? merchantSlug,
    String? merchantStatus,
    required List<String> permissions,
  }) async {
    await _writeProfile(
      userName: userName,
      userEmail: userEmail,
      merchantId: merchantId,
      merchantName: merchantName,
      merchantSlug: merchantSlug,
      merchantStatus: merchantStatus,
      permissions: permissions,
    );
    revision.value++;
  }

  Future<void> _writeProfile({
    required String userName,
    required String userEmail,
    int? merchantId,
    String? merchantName,
    String? merchantSlug,
    String? merchantStatus,
    required List<String> permissions,
  }) async {
    await _store.write(_kUserName, userName);
    await _store.write(_kUserEmail, userEmail);
    await _writeOrDelete(_kMerchantId, merchantId?.toString());
    await _writeOrDelete(_kMerchantName, merchantName);
    await _writeOrDelete(_kMerchantSlug, merchantSlug);
    await _writeOrDelete(_kMerchantStatus, merchantStatus);
    await _store.write(_kPermissions, jsonEncode(permissions));
    _userName = userName;
    _userEmail = userEmail;
    _merchantId = merchantId;
    _merchantName = merchantName;
    _merchantSlug = merchantSlug;
    _merchantStatus = merchantStatus;
    _permissions = List.unmodifiable(permissions);
  }

  Future<void> _writeOrDelete(String key, String? value) async {
    if (value == null || value.isEmpty) {
      await _store.delete(key);
    } else {
      await _store.write(key, value);
    }
  }

  static List<String> _decodePermissions(String? raw) {
    if (raw == null || raw.isEmpty) return const [];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return List.unmodifiable(decoded.map((p) => p.toString()));
      }
    } on FormatException {
      // A corrupt cache renders as "no permissions" until the next
      // /merchant/me — under-drawing, never over-drawing.
    }
    return const [];
  }

  @override
  Future<void> _wipeProfile() async {
    await _store.delete(_kUserName);
    await _store.delete(_kUserEmail);
    await _store.delete(_kMerchantId);
    await _store.delete(_kMerchantName);
    await _store.delete(_kMerchantSlug);
    await _store.delete(_kMerchantStatus);
    await _store.delete(_kPermissions);
    _userName = null;
    _userEmail = null;
    _merchantId = null;
    _merchantName = null;
    _merchantSlug = null;
    _merchantStatus = null;
    _permissions = const [];
  }
}
