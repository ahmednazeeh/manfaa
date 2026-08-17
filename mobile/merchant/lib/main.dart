import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/app.dart';
import 'firebase_options.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Push (MR4) initialises from day one — against PLACEHOLDER config until
  // mv.manfaa.merchant is registered (see firebase_options.dart). Guarded:
  // a desktop dev run or a test host has no Firebase platform, and the app
  // must be a till first — messaging failing to initialise must never keep
  // a cashier from crediting a sale.
  try {
    await Firebase.initializeApp(options: DefaultFirebaseOptions.currentPlatform);
  } catch (_) {
    // Deliberate: no Firebase ≠ no app.
  }

  runApp(const ProviderScope(child: MerchantApp()));
}
