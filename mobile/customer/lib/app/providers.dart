import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The build number the version gate compares against `minimum_build`.
/// Injected at build time (`--dart-define=BUILD_NUMBER=`) so CI stamps it;
/// R7 wires it into the release pipeline properly.
const appBuildNumber = int.fromEnvironment('BUILD_NUMBER', defaultValue: 1);

/// Swappable in tests; the real one talks to Keychain/Keystore.
final secretStoreProvider = Provider<SecretStore>((_) => SecureSecretStore());

final sessionProvider = Provider<SessionStore>(
  (ref) => SessionStore(ref.watch(secretStoreProvider)),
);

/// Boot awaits this exactly once; everything else reads the session
/// synchronously afterwards.
final sessionInitProvider = FutureProvider<void>(
  (ref) => ref.watch(sessionProvider).init(),
);

final apiProvider = Provider<ManfaaApi>(
  (ref) => ManfaaApi(session: ref.watch(sessionProvider)),
);

/// GET /config — the version gate. Fetched at boot; conditional thereafter.
final configProvider = FutureProvider<MobileConfig>(
  (ref) => ref.watch(apiProvider).fetchConfig(),
);

/// GET /customer/home — the whole first screen in one request.
final homeProvider = FutureProvider.autoDispose<HomeData>(
  (ref) => ref.watch(apiProvider).home(),
);

/// The UI language. Persisted with the session store (and it survives
/// sign-out — losing your language because a token expired would be hostile).
class LocaleController extends Notifier<Locale> {
  @override
  Locale build() => Locale(ref.read(sessionProvider).locale);

  Future<void> set(String languageCode) async {
    await ref.read(sessionProvider).setLocale(languageCode);
    state = Locale(languageCode);
  }
}

final localeProvider = NotifierProvider<LocaleController, Locale>(
  LocaleController.new,
);
