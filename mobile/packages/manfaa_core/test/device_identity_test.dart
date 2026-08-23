import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The self-referral defence's client half (owner, 2026-08-24): the customer
/// app resolves one sanctioned device id at startup and EVERY request from
/// then on carries it as X-Device-Id/X-Device-Platform. Requests before
/// resolution omit the headers entirely — that is the contract that lets
/// boot never wait on a plugin.
class _RecordingAdapter implements HttpClientAdapter {
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return ResponseBody.fromString(
      jsonEncode({'data': <String, dynamic>{}}),
      200,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  group('device identity headers', () {
    test('a request made before resolution omits both headers', () async {
      final adapter = _RecordingAdapter();
      final api = ManfaaApi(session: CustomerSession(MemorySecretStore()));
      api.dio.httpClientAdapter = adapter;

      await api.me();

      final headers = adapter.requests.single.headers;
      expect(headers.containsKey(DeviceIdentity.headerId), isFalse);
      expect(headers.containsKey(DeviceIdentity.headerPlatform), isFalse);
    });

    test('every request after set() carries id and platform', () async {
      final adapter = _RecordingAdapter();
      final identity = DeviceIdentity();
      final api = ManfaaApi(
        session: CustomerSession(MemorySecretStore()),
        deviceIdentity: identity,
      );
      api.dio.httpClientAdapter = adapter;

      await api.me(); // Before resolution: nothing.
      identity.set(id: 'ffabc1234567890d', platform: 'android');
      await api.home(); // After: both headers, from here on.

      expect(
        adapter.requests.first.headers.containsKey(DeviceIdentity.headerId),
        isFalse,
      );
      final after = adapter.requests.last.headers;
      expect(after[DeviceIdentity.headerId], 'ffabc1234567890d');
      expect(after[DeviceIdentity.headerPlatform], 'android');
    });

    test('an empty id is refused — no header beats a shared blank one', () {
      final identity = DeviceIdentity();
      identity.set(id: '', platform: 'android');
      expect(identity.id, isNull);
      expect(identity.platform, isNull);
    });

    test('iOS ships BOTH identities — the kc: ref rides X-Device-Ref',
        () async {
      final adapter = _RecordingAdapter();
      final identity = DeviceIdentity();
      final api = ManfaaApi(
        session: CustomerSession(MemorySecretStore()),
        deviceIdentity: identity,
      );
      api.dio.httpClientAdapter = adapter;

      identity.set(
        id: 'ifv:AAAA-BBBB',
        platform: 'ios',
        ref: 'kc:3f2b8c44-9d1e-4e7a-8b2f-6a5d4c3b2a19',
      );
      await api.me();

      final headers = adapter.requests.single.headers;
      expect(headers[DeviceIdentity.headerId], 'ifv:AAAA-BBBB');
      expect(
        headers[DeviceIdentity.headerRef],
        'kc:3f2b8c44-9d1e-4e7a-8b2f-6a5d4c3b2a19',
      );
      expect(headers[DeviceIdentity.headerPlatform], 'ios');
    });

    test('a missing, empty, or duplicate ref sends no X-Device-Ref', () async {
      final adapter = _RecordingAdapter();
      final identity = DeviceIdentity();
      final api = ManfaaApi(
        session: CustomerSession(MemorySecretStore()),
        deviceIdentity: identity,
      );
      api.dio.httpClientAdapter = adapter;

      identity.set(id: 'kc:some-uuid', platform: 'ios', ref: 'kc:some-uuid');
      await api.me();

      final headers = adapter.requests.single.headers;
      expect(headers[DeviceIdentity.headerId], 'kc:some-uuid');
      expect(headers.containsKey(DeviceIdentity.headerRef), isFalse);

      expect(
        (DeviceIdentity()..set(id: 'x', platform: 'ios', ref: '')).ref,
        isNull,
      );
      expect(
        (DeviceIdentity()..set(id: 'x', platform: 'ios')).ref,
        isNull,
      );
    });
  });

  group('ReferralFriend.disqualified', () {
    test('parses true and forces the muted-state invariants', () {
      final friend = ReferralFriend.fromJson(const {
        'name': 'Aish***',
        'spent_laari': 0,
        'rewarded': false,
        'disqualified': true,
        'joined_at': '2026-08-20T10:00:00Z',
      });

      expect(friend.disqualified, isTrue);
      expect(friend.spentLaari, 0);
      expect(friend.rewarded, isFalse);
    });

    test('defaults false when absent — older servers parse unchanged', () {
      final friend = ReferralFriend.fromJson(const {
        'name': 'Aish***',
        'spent_laari': 500,
        'rewarded': false,
      });

      expect(friend.disqualified, isFalse);
    });

    test('anything unexpected parses as false, never a crash', () {
      expect(
        ReferralFriend.fromJson(const {'name': 'x', 'disqualified': 1})
            .disqualified,
        isFalse,
      );
      expect(
        ReferralFriend.fromJson(const {'name': 'x', 'disqualified': null})
            .disqualified,
        isFalse,
      );
    });
  });
}
