import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/app.dart';

/// No Firebase here yet ON PURPOSE: push is MR4, blocked on registering
/// `mv.manfaa.merchant` in the Firebase console. The app must be an app
/// first — it boots, signs in and runs the till with no messaging stack.
void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ProviderScope(child: MerchantApp()));
}
