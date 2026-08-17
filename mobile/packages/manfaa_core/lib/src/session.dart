import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the bearer token lives: the platform keystore, never preferences —
/// the token is a 365-day credential (guide §2).
///
/// The customer's code and name are cached beside it ON PURPOSE: the
/// fullscreen QR must work in a shop with no signal, and the code is the
/// customer's own public identity, not a secret the token protects.
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

class SessionStore {
  SessionStore(this._store);

  static const _kToken = 'token';
  static const _kCode = 'customer_code';
  static const _kName = 'customer_name';
  static const _kLocale = 'locale';
  static const _kPush = 'push_enabled';

  final SecretStore _store;

  String? _token;
  String? _customerCode;
  String? _customerName;
  String _locale = 'en';
  bool _pushEnabled = true;

  /// Bumped on every sign-in/out so the router can listen and redirect —
  /// including the guide's hard rule that ANY 401 means the session is over.
  final revision = ValueNotifier<int>(0);

  bool get signedIn => _token != null;
  String? get token => _token;
  String? get customerCode => _customerCode;
  String? get customerName => _customerName;
  String get locale => _locale;

  /// Whether this device WANTS push. Distinct from the OS permission: a user
  /// who turned notifications off here should stay off even if the system
  /// permission is granted. Default true — the value only turns false when a
  /// user deliberately opts out.
  bool get pushEnabled => _pushEnabled;

  Future<void> init() async {
    _token = await _store.read(_kToken);
    _customerCode = await _store.read(_kCode);
    _customerName = await _store.read(_kName);
    _locale = await _store.read(_kLocale) ?? 'en';
    _pushEnabled = (await _store.read(_kPush)) != 'false';
  }

  Future<void> setPushEnabled(bool enabled) async {
    _pushEnabled = enabled;
    await _store.write(_kPush, enabled ? 'true' : 'false');
  }

  Future<void> saveSession({
    required String token,
    required String customerCode,
    required String customerName,
  }) async {
    await _store.write(_kToken, token);
    await _store.write(_kCode, customerCode);
    await _store.write(_kName, customerName);
    _token = token;
    _customerCode = customerCode;
    _customerName = customerName;
    revision.value++;
  }

  Future<void> setLocale(String locale) async {
    _locale = locale;
    await _store.write(_kLocale, locale);
    revision.value++;
  }

  /// Sign-out and the 401 path both land here. The locale survives a wipe —
  /// losing your language because a token expired would be hostile.
  Future<void> wipe() async {
    await _store.delete(_kToken);
    await _store.delete(_kCode);
    await _store.delete(_kName);
    _token = null;
    _customerCode = null;
    _customerName = null;
    revision.value++;
  }
}
