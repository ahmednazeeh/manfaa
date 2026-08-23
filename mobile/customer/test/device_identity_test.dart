import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

import 'package:manfaa_customer/app/device_identity.dart';

/// The startup resolver (self-referral defence, owner 2026-08-24). The test
/// host has none of the platform plugins, which is exactly the point: every
/// path must degrade to "no header" rather than an error, and the iOS
/// Keychain fallback — the only leg that needs no plugin — must mint once
/// and then stick.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('iOS falls back to a persisted kc: uuid when the OS gives no IFV',
      () async {
    final identity = DeviceIdentity();
    final store = MemorySecretStore();

    await resolveDeviceIdentity(
      identity: identity,
      store: store,
      platform: TargetPlatform.iOS,
    );

    expect(identity.platform, 'ios');
    expect(identity.id, startsWith('kc:'));
    // A real v4 uuid rides behind the prefix.
    expect(
      RegExp(
        r'^kc:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
      ).hasMatch(identity.id!),
      isTrue,
    );
    // And it persisted where sign-out never wipes.
    expect(await store.read(kDeviceRefKey), identity.id!.substring(3));
    // kc: is already the primary here — no second identity to send.
    expect(identity.ref, isNull);
  });

  test('the keychain uuid is minted ONCE — a second resolve reuses it',
      () async {
    final store = MemorySecretStore();
    final first = DeviceIdentity();
    final second = DeviceIdentity();

    await resolveDeviceIdentity(
      identity: first,
      store: store,
      platform: TargetPlatform.iOS,
    );
    await resolveDeviceIdentity(
      identity: second,
      store: store,
      platform: TargetPlatform.iOS,
    );

    expect(second.id, first.id);
  });

  test('android without the plugin resolves to nothing — no id, no crash',
      () async {
    final identity = DeviceIdentity();

    await resolveDeviceIdentity(
      identity: identity,
      store: MemorySecretStore(),
      platform: TargetPlatform.android,
    );

    expect(identity.id, isNull);
  });

  test('desktop dev platforms resolve to nothing', () async {
    final identity = DeviceIdentity();

    await resolveDeviceIdentity(
      identity: identity,
      store: MemorySecretStore(),
      platform: TargetPlatform.linux,
    );

    expect(identity.id, isNull);
  });
}
