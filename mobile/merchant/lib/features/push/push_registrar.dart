import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';

/// Where a tapped push lands, by the API's `template` data key —
/// SendPushNotification sends `{template: <NotificationTemplateKey>}` and
/// nothing else today, so the key IS the whole contract.
///
/// A settlement's own verdict (`settlement_accepted` / `settlement_rejected`)
/// opens that settlement's detail the moment the server names it with a
/// `settlement_id` data key; until that key ships, the verdicts land on the
/// Settlements list — which still shows the verdict at the top.
///
/// Returns null for a template we don't know: an unknown message must never
/// yank the till somewhere surprising mid-credit. The destinations are
/// permission-guarded by the router's own prefix redirect, so routing a
/// cashier without `settlements.view` simply lands them home.
String? routeForPushData(Map<String, Object?> data) {
  final settlementId = int.tryParse('${data['settlement_id'] ?? ''}');

  return switch (data['template']) {
    'settlement_accepted' || 'settlement_rejected' =>
      settlementId != null && settlementId > 0
          ? '/settlements/$settlementId'
          : '/settlements',
    // Every deadline moment — batch created, discount expiring, due soon,
    // and the §7 ladder's three rungs — is about the same pile of
    // outstanding money, and Settlements is where it gets settled.
    'settlement_due' ||
    'prompt_discount_expiring' ||
    'settlement_due_soon' ||
    'reminder_day10' ||
    'urgent_day13' ||
    'due_day15' =>
      '/settlements',
    // MR9 — a store change was approved or refused. The message names WHICH
    // change ("store profile change", "new branch"…) but the wire carries no
    // kind key, so the tap lands on the estate hub rather than guessing
    // between Profile and Manage Branches: both are one tap from there, and
    // neither destination can be the wrong one.
    'store_change_approved' || 'store_change_rejected' => '/more',
    _ => null,
  };
}

/// Push wiring (MR4), cloned from the customer app's R5 registrar.
///
/// Registration is bound server-side to the AUTH TOKEN this request rides
/// on — sign-out, device revocation and staff deactivation all cascade the
/// push registration away without this code knowing (PushTokenController's
/// personal_access_token FK). Our whole job is: ask once, register, keep
/// the token fresh, and route taps.
///
/// Permission is asked AFTER sign-in, from the Dashboard — never on first
/// launch. A cold-start permission dialog is the fastest "Don't allow" in
/// mobile, and the moment the outstanding-to-settle hero is on screen is
/// the moment notifications make obvious sense ("we'll remind you before
/// the discount expires").
///
/// Everything is guarded: tests and desktop dev runs have no Firebase, the
/// SHIPPED build runs on PLACEHOLDER config until mv.manfaa.merchant is
/// registered (firebase_options.dart), and push failing must never touch
/// the till's real work.
final pushRegistrarProvider = Provider<PushRegistrar>(PushRegistrar.new);

class PushRegistrar {
  PushRegistrar(this._ref);

  final Ref _ref;
  bool _wired = false;
  bool _tapsWired = false;
  bool _foregroundWired = false;

  bool get _available => !kIsWeb && Firebase.apps.isNotEmpty;

  /// Idempotent; called when the Dashboard first builds for a signed-in
  /// session. Respects the in-app preference — always true today; the MR5
  /// More estate ships the toggle (and a setEnabled twin of the customer's)
  /// that can turn it false.
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
      // a till that silently stops hearing about its own deadlines.
      messaging.onTokenRefresh.listen(
        (fresh) {
          if (_ref.read(sessionProvider).pushEnabled) _register(fresh);
        },
        onError: (_) {},
      );
    } catch (_) {
      // Deliberate: no push ≠ no till. With the placeholder config this is
      // the LIVE path — getToken() fails against the unregistered app.
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

  /// Notification taps land the user where the message points — cold start
  /// (getInitialMessage) and background tap (onMessageOpenedApp) both.
  void wireTapRouting(GoRouter router) {
    if (!_available || _tapsWired) return;
    _tapsWired = true;

    void route(RemoteMessage message) {
      final destination = routeForPushData(message.data);
      if (destination != null) router.go(destination);
    }

    FirebaseMessaging.instance.getInitialMessage().then((message) {
      if (message != null) route(message);
    }).catchError((_) {});

    FirebaseMessaging.onMessageOpenedApp.listen(route, onError: (_) {});
  }

  /// Foreground presentation, without which an OPEN till hears nothing.
  ///
  /// Android never shows a notification-payload message while the app is in
  /// the foreground — FCM hands it to `onMessage` and stays silent. Surfaced
  /// as a SnackBar in the app theme (the violet system), with a View action
  /// that rides the same routing as a tap; iOS is told to keep its own
  /// system banner instead.
  void wireForeground(
    GlobalKey<ScaffoldMessengerState> messenger,
    GoRouter router,
  ) {
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

        final destination = routeForPushData(message.data);
        // No BuildContext down here; the session's locale is the same one
        // MaterialApp is drawing with.
        final l10n = lookupAppLocalizations(
          Locale(_ref.read(sessionProvider).locale),
        );

        messenger.currentState?.showSnackBar(
          SnackBar(
            content: Text(text, maxLines: 3, overflow: TextOverflow.ellipsis),
            duration: const Duration(seconds: 4),
            behavior: SnackBarBehavior.floating,
            action: destination == null
                ? null
                : SnackBarAction(
                    label: l10n.pushOpen,
                    onPressed: () => router.go(destination),
                  ),
          ),
        );
      },
      onError: (_) {},
    );
  }
}
