// ═══════════════════════════════════════════════════════════════════════════
// Hand-written from the Firebase config files the owner supplied on
// 2026-08-20 (google-services.json / GoogleService-Info.plist, project
// manfaa-6e1b4) — the flutterfire CLI needs an interactive Google login this
// server does not have, and these values are CLIENT identifiers that ship
// inside the binary by design.
//
// The server's FCM service-account key is a different thing entirely and
// never appears here.
//
// NOTE the two platforms carry DIFFERENT ids, which is deliberate only in the
// sense that it is what is registered: Android is `mv.manfaa.merchant`, iOS is
// `mv.manfaa.manfaaMerchant` (Flutter's generated default, and what the Xcode
// project has always used). Changing either now means re-registering the app
// in Firebase, so they are left as they are.
//
// Keep in step with android/app/google-services.json and
// ios/Runner/GoogleService-Info.plist — these three must agree.
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

  static const android = FirebaseOptions(
    apiKey: 'AIzaSyBhxd62YIEyOLUopyOst4p4EZ8Q-Ojjt1k',
    appId: '1:932894903426:android:9dd4ed6541066c76fcf89b',
    messagingSenderId: '932894903426',
    projectId: 'manfaa-6e1b4',
    storageBucket: 'manfaa-6e1b4.firebasestorage.app',
  );

  static const ios = FirebaseOptions(
    apiKey: 'AIzaSyBHjIg1DDx0e-jjWLQh8tBrfuqs08GzImM',
    appId: '1:932894903426:ios:53f811785b3f720afcf89b',
    messagingSenderId: '932894903426',
    projectId: 'manfaa-6e1b4',
    storageBucket: 'manfaa-6e1b4.firebasestorage.app',
    iosBundleId: 'mv.manfaa.manfaaMerchant',
  );
}
