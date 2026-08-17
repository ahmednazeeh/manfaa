import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The build number the version gate compares against `minimum_build`.
/// Injected at build time (`--dart-define=BUILD_NUMBER=`) so CI stamps it;
/// MR6 wires it into the release pipeline properly.
const appBuildNumber = int.fromEnvironment('BUILD_NUMBER', defaultValue: 1);

/// Swappable in tests; the real one talks to Keychain/Keystore.
final secretStoreProvider = Provider<SecretStore>((_) => SecureSecretStore());

final sessionProvider = Provider<MerchantSession>(
  (ref) => MerchantSession(ref.watch(secretStoreProvider)),
);

/// Boot awaits this exactly once; everything else reads the session
/// synchronously afterwards.
final sessionInitProvider = FutureProvider<void>(
  (ref) => ref.watch(sessionProvider).init(),
);

final apiProvider = Provider<MerchantApi>(
  (ref) => MerchantApi(session: ref.watch(sessionProvider)),
);

/// GET /config — the version gate. Fetched at boot; conditional thereafter.
final configProvider = FutureProvider<MobileConfig>(
  (ref) => ref.watch(apiProvider).fetchConfig(),
);

/// The session revision as provider state, so widgets that draw from the
/// session's cached profile (the permission-gated nav, the top-bar avatar)
/// repaint the moment a fresh /merchant/me lands. The tab screens live
/// inside a StatefulShellRoute that never rebuilds them on its own.
class SessionTickController extends Notifier<int> {
  @override
  int build() {
    final session = ref.watch(sessionProvider);
    void sync() => state = session.revision.value;
    session.revision.addListener(sync);
    ref.onDispose(() => session.revision.removeListener(sync));
    return session.revision.value;
  }
}

final sessionTickProvider = NotifierProvider<SessionTickController, int>(
  SessionTickController.new,
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

ThemeMode _themeModeFrom(String v) => switch (v) {
      'dark' => ThemeMode.dark,
      'system' => ThemeMode.system,
      _ => ThemeMode.light,
    };

String _themeModeName(ThemeMode m) => switch (m) {
      ThemeMode.dark => 'dark',
      ThemeMode.system => 'system',
      ThemeMode.light => 'light',
    };

/// The theme mode the user chose. Light-first by default (see SessionBase),
/// persisted so it survives a restart — and, like locale, a sign-out.
class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => _themeModeFrom(ref.read(sessionProvider).themeMode);

  Future<void> set(ThemeMode mode) async {
    await ref.read(sessionProvider).setThemeMode(_themeModeName(mode));
    state = mode;
  }
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(
  ThemeModeController.new,
);
