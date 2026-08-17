import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/app.dart';
import 'firebase_options.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Push arrives with a later round, but Firebase initialises from day one
  // so the wiring is proven early. Guarded: a desktop dev run or a test host
  // has no Firebase platform, and the app must be an app first — messaging
  // failing to initialise must never keep a customer from their code.
  try {
    await Firebase.initializeApp(options: DefaultFirebaseOptions.currentPlatform);
  } catch (_) {
    // Deliberate: no Firebase ≠ no app.
  }

  runApp(const ProviderScope(child: ManfaaApp()));
}
