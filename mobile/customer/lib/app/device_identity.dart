import 'dart:math';

import 'package:android_id/android_id.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import 'providers.dart';

/// Resolves the sanctioned per-OS device identifier ONCE per launch and
/// parks it in the [DeviceIdentity] holder the API client's interceptor
/// reads (self-referral defence, owner 2026-08-24).
///
/// THE SCHEME (the server only ever compares keyed hashes for equality):
/// - Android: the SSAID, exactly as the android_id plugin returns it.
/// - iOS: `ifv:<identifierForVendor>` as the primary AND `kc:<uuid>` — a
///   UUID minted once and persisted in the Keychain via the same
///   [SecretStore] the session token lives in — as `X-Device-Ref` on the
///   same requests. BOTH must travel: deleting the last vendor app rotates
///   the IFV, and the Keychain UUID is the only identifier that survives a
///   reinstall — kept locally but never sent, it would convict nobody. When
///   the OS declines to give an IFV, `kc:` is sent as the primary instead.
///   The Keychain UUID is minted on the first resolve either way (and
///   `wipe()` never deletes it), so it is stable from the install's first
///   day and survives sign-outs.
///
/// Boot kicks this off without awaiting it: first paint never waits, and a
/// request made before resolution simply omits the header — the server
/// records on every authed call, so the very next request covers the device.
final deviceIdentityInitProvider = FutureProvider<void>((ref) async {
  await resolveDeviceIdentity(
    identity: ref.watch(deviceIdentityProvider),
    store: ref.watch(secretStoreProvider),
  );
});

/// Where the iOS fallback UUID lives in the Keychain. Never wiped — like
/// every [SessionBase] key name, this is an installed-base contract.
const kDeviceRefKey = 'device_ref';

@visibleForTesting
Future<void> resolveDeviceIdentity({
  required DeviceIdentity identity,
  required SecretStore store,
  TargetPlatform? platform,
}) async {
  try {
    switch (platform ?? defaultTargetPlatform) {
      case TargetPlatform.android:
        final ssaid = await const AndroidId().getId();
        if (ssaid != null && ssaid.isNotEmpty) {
          identity.set(id: ssaid, platform: 'android');
        }

      case TargetPlatform.iOS:
        // Mint (or read back) the Keychain UUID first, so it exists from
        // day one even while identifierForVendor is the value sent.
        final keychainRef = await _ensureKeychainRef(store);

        String? ifv;
        try {
          ifv = (await DeviceInfoPlugin().iosInfo).identifierForVendor;
        } catch (_) {
          ifv = null; // No plugin (tests) or the OS refused — fall back.
        }

        if (ifv != null && ifv.isNotEmpty) {
          identity.set(
            id: 'ifv:$ifv',
            platform: 'ios',
            ref: keychainRef != null ? 'kc:$keychainRef' : null,
          );
        } else if (keychainRef != null) {
          identity.set(id: 'kc:$keychainRef', platform: 'ios');
        }

      default:
        // Desktop dev runs and tests have no sanctioned id; requests omit
        // the header, exactly as every build before this feature did.
        break;
    }
  } catch (_) {
    // Deliberate: no device id ≠ no app.
  }
}

Future<String?> _ensureKeychainRef(SecretStore store) async {
  try {
    final existing = await store.read(kDeviceRefKey);
    if (existing != null && existing.isNotEmpty) return existing;

    final minted = _uuidV4();
    await store.write(kDeviceRefKey, minted);
    return minted;
  } catch (_) {
    return null; // A broken Keychain must never take the app down.
  }
}

/// A random v4 UUID — the one place the app needs one, so no package.
String _uuidV4() {
  final rng = Random.secure();
  final bytes = List<int>.generate(16, (_) => rng.nextInt(256));
  bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant 10xx

  final hex =
      bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-'
      '${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
}
