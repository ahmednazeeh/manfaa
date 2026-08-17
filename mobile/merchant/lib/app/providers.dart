import 'dart:async';
import 'dart:io' show Platform;

import 'package:connectivity_plus/connectivity_plus.dart';
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

/// The till's offline credit queue (manfaa_core CreditQueue) wired to the
/// real API: every credit — online or not — submits THROUGH it, so the
/// idempotency key minted at compose time is the one every retry carries.
/// A ChangeNotifierProvider so the Credit tab's pending-sync banner repaints
/// on every queue movement.
final creditQueueProvider = ChangeNotifierProvider<CreditQueue>((ref) {
  final api = ref.watch(apiProvider);
  final queue = CreditQueue(
    ref.watch(secretStoreProvider),
    (entry) => api.createCredit(
      idempotencyKey: entry.key,
      customerCode: entry.customerCode,
      invoiceNo: entry.invoiceNo,
      eligibleLaari: entry.eligibleLaari,
      saleLaari: entry.saleLaari,
      occurredAt: entry.occurredAt,
      cashbackRatePercent: entry.cashbackRatePercent,
      lines: entry.lines.isEmpty ? null : entry.lines,
      backdatedAcknowledged: entry.backdatedAcknowledged,
    ),
  );
  // Fire-and-forget: load() is idempotent and every queue method awaits it.
  queue.load();
  return queue;
});

/// Drains the queue when the world changes: the app returning to the
/// foreground, and connectivity coming back. Watched once from the shell —
/// alive exactly while a signed-in, trading merchant is inside the app.
final queueDrainDriverProvider = Provider<void>((ref) {
  final driver = _QueueDrainDriver(ref.read(creditQueueProvider.notifier));
  ref.onDispose(driver.dispose);
});

class _QueueDrainDriver with WidgetsBindingObserver {
  _QueueDrainDriver(this._queue) {
    WidgetsBinding.instance.addObserver(this);
    // Under `flutter test` there is no platform channel behind the plugin,
    // and the EventChannel activation failure is reported to FlutterError
    // (uncatchable from here) — so the wiring is gated on the test-runner
    // env; the resume path and the manual "Sync now" still drain in tests.
    if (Platform.environment.containsKey('FLUTTER_TEST')) return;
    try {
      _connectivity = Connectivity().onConnectivityChanged.listen(
        (results) {
          if (results.any((r) => r != ConnectivityResult.none)) {
            _queue.drain();
          }
        },
        // A platform without the plugin errors instead of emitting; the
        // resume path still drains.
        onError: (Object _, StackTrace _) {},
      );
    } catch (_) {
      // No connectivity events — foreground/resume remains the trigger.
    }
  }

  final CreditQueue _queue;
  StreamSubscription<List<ConnectivityResult>>? _connectivity;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _queue.drain();
  }

  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _connectivity?.cancel();
  }
}
