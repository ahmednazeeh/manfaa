import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';

/// Push wiring (R5), the client half of the M4 design.
///
/// Registration is bound server-side to the AUTH TOKEN this request rides
/// on — sign-out, device revocation and account deactivation all cascade the
/// push registration away without this code knowing. Our whole job is:
/// ask once, register, keep the token fresh, and route taps.
///
/// Permission is asked AFTER sign-in, from Home — never on first launch. A
/// cold-start permission dialog is the fastest "Don't allow" in mobile, and
/// the moment the code card is on screen is the moment notifications make
/// obvious sense ("we'll tell you when you earn").
///
/// Everything is guarded: tests and desktop dev runs have no Firebase, and
/// push failing must never touch the app's real work.
final pushRegistrarProvider = Provider<PushRegistrar>(PushRegistrar.new);

class PushRegistrar {
  PushRegistrar(this._ref);

  final Ref _ref;
  bool _wired = false;
  bool _tapsWired = false;
  bool _foregroundWired = false;

  bool get _available => !kIsWeb && Firebase.apps.isNotEmpty;

  /// Idempotent; called when Home first builds for a signed-in session.
  /// Respects the in-app preference — a user who opted out stays out.
  Future<void> ensureRegistered() async {
    if (!_available || _wired) return;
    if (!_ref.read(sessionProvider).pushEnabled) return;
    _wired = true;

    try {
      final messaging = FirebaseMessaging.instance;

      final settings = await messaging.requestPermission();
      if (settings.authorizationStatus == AuthorizationStatus.denied) {
        return;
      }

      final token = await messaging.getToken();
      if (token != null) await _register(token);

      // Providers rotate tokens whenever they like; a stale registration is
      // a phone that silently stops hearing about its own money.
      messaging.onTokenRefresh.listen(
        (fresh) {
          if (_ref.read(sessionProvider).pushEnabled) _register(fresh);
        },
        onError: (_) {},
      );
    } catch (_) {
      // Deliberate: no push ≠ no app.
    }
  }

  /// The in-app notifications toggle (R6). Turning OFF deletes the
  /// registration server-side so this phone stops receiving; turning ON
  /// registers afresh. The preference is the source of truth and survives
  /// even where there is no Firebase.
  Future<void> setEnabled(bool enabled) async {
    await _ref.read(sessionProvider).setPushEnabled(enabled);

    if (!_available) return;

    try {
      final messaging = FirebaseMessaging.instance;
      final token = await messaging.getToken();
      if (token == null) return;

      if (enabled) {
        await _register(token);
      } else {
        await _ref.read(apiProvider).deletePushToken(token);
      }
    } catch (_) {
      // The preference already changed; delivery wiring is best-effort.
    }
  }

  Future<void> _register(String token) async {
    try {
      await _ref.read(apiProvider).registerPushToken(
            token: token,
            platform: defaultTargetPlatform == TargetPlatform.iOS
                ? 'ios'
                : 'android',
            appBuild: appBuildNumber,
            locale: _ref.read(sessionProvider).locale,
          );
    } catch (_) {
      // Next launch retries; the till moment must never wait on this.
    }
  }

  /// Notification taps land the user where the message points. The payload's
  /// `template` key is the API's contract (SendPushNotification sends it).
  void wireTapRouting(GoRouter router) {
    if (!_available || _tapsWired) return;
    _tapsWired = true;

    void route(RemoteMessage message) {
      switch (message.data['template']) {
        case 'cashback_earned':
        case 'cashback_confirmed':
        case 'payout_paid':
          router.go('/activity');
        default:
          router.go('/home');
      }
    }

    FirebaseMessaging.instance.getInitialMessage().then((message) {
      if (message != null) route(message);
    }).catchError((_) {});

    FirebaseMessaging.onMessageOpenedApp.listen(route, onError: (_) {});
  }

  /// Foreground presentation, without which an OPEN app hears nothing.
  ///
  /// Android never shows a notification-payload message while the app is in
  /// the foreground — FCM hands it to `onMessage` and stays silent, which is
  /// exactly the "store credited me and my phone said nothing" report
  /// (2026-08-17): the customer was looking at the app. Surfaced as a
  /// SnackBar; iOS is told to keep its own system banner instead.
  void wireForeground(GlobalKey<ScaffoldMessengerState> messenger) {
    if (!_available || _foregroundWired) return;
    _foregroundWired = true;

    // iOS: let the system banner show in foreground, exactly as background.
    FirebaseMessaging.instance
        .setForegroundNotificationPresentationOptions(
          alert: true,
          badge: true,
          sound: true,
        )
        .catchError((_) {});

    FirebaseMessaging.onMessage.listen(
      (message) {
        // iOS already showed its banner via the options above.
        if (defaultTargetPlatform == TargetPlatform.iOS) return;

        final notification = message.notification;
        if (notification == null) return;

        final text = [notification.title, notification.body]
            .whereType<String>()
            .where((part) => part.trim().isNotEmpty)
            .join(' — ');
        if (text.isEmpty) return;

        messenger.currentState?.showSnackBar(
          SnackBar(
            content: Text(text, maxLines: 3, overflow: TextOverflow.ellipsis),
            duration: const Duration(seconds: 4),
            behavior: SnackBarBehavior.floating,
          ),
        );
      },
      onError: (_) {},
    );
  }
}
