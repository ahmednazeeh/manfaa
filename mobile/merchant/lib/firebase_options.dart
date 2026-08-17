// ═══════════════════════════════════════════════════════════════════════════
// PLACEHOLDER FIREBASE CONFIG — mv.manfaa.merchant IS NOT REGISTERED YET.
//
// The Firebase app `mv.manfaa.merchant` does not exist in console project
// `manfaa-6e1b4` yet (the MR4 blocker). These values keep the build and the
// whole push stack COMPILED AND WIRED today: projectId / messagingSenderId /
// storageBucket are the REAL project values (the merchant app joins the SAME
// project the customer app lives in), while apiKey and appId are
// syntactically valid FAKES. Firebase.initializeApp() succeeds with them;
// getToken() fails and is swallowed by the PushRegistrar's guards — the app
// never crashes, push is simply silent.
//
// SWAP PROCEDURE (after the owner registers the Android app
// `mv.manfaa.merchant` in Firebase console project `manfaa-6e1b4`):
//   1. Download the real google-services.json for mv.manfaa.merchant and
//      replace android/app/google-services.json with it.
//   2. Copy that file's values into `android` below — api_key.current_key →
//      apiKey, mobilesdk_app_id → appId; the rest already match the project.
//      (Or run `flutterfire configure` if an interactive Google login is
//      available — it rewrites this file wholesale, which is fine.)
//   3. iOS later (blocked on APNs anyway): register mv.manfaa.merchant for
//      iOS, drop GoogleService-Info.plist into ios/Runner/, fill `ios` below.
// That is the ENTIRE procedure — zero Dart changes; push lights up on the
// next build.
//
// Like the customer app's twin file: these are CLIENT identifiers that ship
// inside the binary by design. The server's FCM service-account key is a
// different thing entirely and never appears here.
// ═══════════════════════════════════════════════════════════════════════════
import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;
import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, TargetPlatform;

abstract final class DefaultFirebaseOptions {
  static FirebaseOptions get currentPlatform => switch (defaultTargetPlatform) {
        TargetPlatform.android => android,
        TargetPlatform.iOS => ios,
        _ => throw UnsupportedError(
            'Firebase is configured for Android and iOS only.',
          ),
      };

  // PLACEHOLDER: apiKey + appId are fakes of the right shape (see header).
  static const android = FirebaseOptions(
    apiKey: 'AIzaSyPLACEHOLDER0000000000000000000000',
    appId: '1:932894903426:android:0000000000000000c0ffee',
    messagingSenderId: '932894903426',
    projectId: 'manfaa-6e1b4',
    storageBucket: 'manfaa-6e1b4.firebasestorage.app',
  );

  // PLACEHOLDER: iOS is additionally blocked on the APNs .p8 (inherited).
  static const ios = FirebaseOptions(
    apiKey: 'AIzaSyPLACEHOLDER0000000000000000000000',
    appId: '1:932894903426:ios:0000000000000000c0ffee',
    messagingSenderId: '932894903426',
    projectId: 'manfaa-6e1b4',
    storageBucket: 'manfaa-6e1b4.firebasestorage.app',
    iosBundleId: 'mv.manfaa.merchant',
  );
}
