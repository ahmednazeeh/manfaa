// Hand-written from the Firebase config files the owner supplied
// (google-services.json / GoogleService-Info.plist, project manfaa-6e1b4) —
// the flutterfire CLI needs an interactive Google login this server does not
// have, and these values are CLIENT identifiers that ship inside the binary
// by design. The server's service-account key is a different thing entirely
// and never appears here.
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
    appId: '1:932894903426:android:ac9c4b19d959a85dfcf89b',
    messagingSenderId: '932894903426',
    projectId: 'manfaa-6e1b4',
    storageBucket: 'manfaa-6e1b4.firebasestorage.app',
  );

  static const ios = FirebaseOptions(
    apiKey: 'AIzaSyBHjIg1DDx0e-jjWLQh8tBrfuqs08GzImM',
    appId: '1:932894903426:ios:ce33dba732b4ce94fcf89b',
    messagingSenderId: '932894903426',
    projectId: 'manfaa-6e1b4',
    storageBucket: 'manfaa-6e1b4.firebasestorage.app',
    iosBundleId: 'mv.manfaa.app',
  );
}
