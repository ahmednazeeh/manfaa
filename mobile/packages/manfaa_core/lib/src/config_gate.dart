import 'models.dart';

/// The version gate (guide §1): `minimum_build` is the API's only lever to
/// pull a defective build out of service, and the app must obey it
/// UNCONDITIONALLY — before sign-in, before anything.
enum UpdateVerdict { none, optional, required }

({UpdateVerdict verdict, String storeUrl}) resolveGate(
  MobileConfig config, {
  String app = 'customer', // 'customer' | 'merchant'
  required String platform, // 'android' | 'ios'
  required int build,
}) {
  final gate = config.gates[app]?[platform];

  if (gate == null) return (verdict: UpdateVerdict.none, storeUrl: '');

  if (build < gate.minimumBuild) {
    return (verdict: UpdateVerdict.required, storeUrl: gate.storeUrl);
  }

  if (build < gate.latestBuild) {
    return (verdict: UpdateVerdict.optional, storeUrl: gate.storeUrl);
  }

  return (verdict: UpdateVerdict.none, storeUrl: gate.storeUrl);
}
