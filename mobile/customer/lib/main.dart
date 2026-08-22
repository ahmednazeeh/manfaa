import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import 'app/app.dart';
import 'firebase_options.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Push arrives with a later round, but Firebase initialises from day one
  // so the wiring is proven early. Guarded: a desktop dev run or a test host
  // has no Firebase platform, and the app must be an app first — messaging
  // failing to initialise must never keep a customer from their code.
  try {
    await Firebase.initializeApp(
      options: DefaultFirebaseOptions.currentPlatform,
    );
  } catch (_) {
    // Deliberate: no Firebase ≠ no app.
  }

  // The platform's marks, before the first frame: load() only reads what
  // the last run cached (fast, local) and lets the network refresh happen
  // after. Boot never waits on a logo.
  final brand = BrandAssetCache();
  BrandAssetCache.instance = brand;
  await brand.load();

  runApp(const ProviderScope(child: ManfaaApp()));
}
