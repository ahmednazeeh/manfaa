import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import 'app/app.dart';
import 'firebase_options.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Push (MR4) initialises from day one, against the live Firebase config in
  // firebase_options.dart. Guarded:
  // a desktop dev run or a test host has no Firebase platform, and the app
  // must be a till first — messaging failing to initialise must never keep
  // a cashier from crediting a sale.
  try {
    await Firebase.initializeApp(options: DefaultFirebaseOptions.currentPlatform);
  } catch (_) {
    // Deliberate: no Firebase ≠ no app.
  }

  // The platform's marks, before the first frame: load() only reads what
  // the last run cached (fast, local) and lets the network refresh happen
  // after. Boot never waits on a logo.
  final brand = BrandAssetCache();
  BrandAssetCache.instance = brand;
  await brand.load();

  runApp(const ProviderScope(child: MerchantApp()));
}
