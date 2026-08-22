import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// The brand cache: bundled marks always work, downloads supersede them, and
/// a day-old copy is refetched rather than trusted.
void main() {
  late Directory dir;

  setUp(() {
    dir = Directory.systemTemp.createTempSync('brand_test');
  });

  tearDown(() {
    if (dir.existsSync()) dir.deleteSync(recursive: true);
  });

  /// A cache pointed at a temp dir, backed by a dio whose every request is
  /// answered by [respond].
  BrandAssetCache cacheWith(
    Response<dynamic> Function(RequestOptions) respond, {
    Duration ttl = const Duration(hours: 24),
    List<String>? log,
  }) {
    final dio = Dio();
    dio.httpClientAdapter = _StubAdapter(respond, log);

    return BrandAssetCache(dio: dio, ttl: ttl)..useDirectory(dir);
  }

  Response<dynamic> image(RequestOptions options, {String body = 'PNGBYTES'}) =>
      Response<dynamic>(
        requestOptions: options,
        statusCode: 200,
        data: Uint8List.fromList(body.codeUnits),
        headers: Headers.fromMap({
          'content-type': ['image/png'],
        }),
      );

  test('has nothing cached before the first fetch, so the bundled mark shows',
      () {
    final cache = cacheWith(image);

    for (final slot in BrandSlot.values) {
      expect(cache.bytesFor(slot), isNull);
    }
  });

  test('caches every slot after a refresh', () async {
    final cache = cacheWith(image);

    await cache.refresh();

    for (final slot in BrandSlot.values) {
      expect(cache.bytesFor(slot), isNotNull, reason: slot.slug);
    }
    expect(cache.version.value, greaterThan(0));
  });

  test('reads what a previous run cached, without touching the network',
      () async {
    final log = <String>[];
    await cacheWith(image, log: log).refresh();
    final downloads = log.length;

    // A second process, same directory.
    final reopened = cacheWith(image, log: log);
    await reopened.load();

    expect(reopened.bytesFor(BrandSlot.landscapeLight), isNotNull);
    // Fresh cache — load() must not have refetched.
    expect(log.length, downloads);
  });

  test('refetches once the cache is older than its life', () async {
    final log = <String>[];
    // A life of zero is "already stale", which is what a day-old cache is.
    final cache = cacheWith(image, ttl: Duration.zero, log: log);

    await cache.refresh();
    final first = log.length;

    await cache.refreshIfStale();

    expect(log.length, greaterThan(first));
  });

  test('leaves a fresh cache alone', () async {
    final log = <String>[];
    final cache = cacheWith(image, ttl: const Duration(hours: 24), log: log);

    await cache.refresh();
    final first = log.length;

    await cache.refreshIfStale();

    expect(log.length, first);
  });

  test('picks up a replaced logo and tells widgets to repaint', () async {
    var body = 'FIRST';
    final cache = cacheWith(
      (options) => image(options, body: body),
      ttl: Duration.zero,
    );

    await cache.refresh();
    final before = cache.version.value;
    expect(String.fromCharCodes(cache.bytesFor(BrandSlot.squareLight)!), 'FIRST');

    body = 'SECOND';
    await cache.refresh();

    expect(String.fromCharCodes(cache.bytesFor(BrandSlot.squareLight)!), 'SECOND');
    expect(cache.version.value, greaterThan(before));
  });

  test('does not repaint when the logo has not changed', () async {
    final cache = cacheWith(image, ttl: Duration.zero);

    await cache.refresh();
    final settled = cache.version.value;

    await cache.refresh();

    // Same bytes: no version bump, so no needless rebuild of every header.
    expect(cache.version.value, settled);
  });

  test('keeps the previous mark when the network fails', () async {
    var fail = false;
    final cache = cacheWith(
      (options) => fail
          ? Response<dynamic>(requestOptions: options, statusCode: 503)
          : image(options),
      ttl: Duration.zero,
    );

    await cache.refresh();
    expect(cache.bytesFor(BrandSlot.landscapeDark), isNotNull);

    fail = true;
    await cache.refresh();

    // A failed refresh must never blank a header.
    expect(cache.bytesFor(BrandSlot.landscapeDark), isNotNull);
  });

  test('refuses a response that is not an image', () async {
    // A captive portal answers 200 with a login page. Storing that would put
    // a hotel wifi form in the app header.
    final cache = cacheWith(
      (options) => Response<dynamic>(
        requestOptions: options,
        statusCode: 200,
        data: Uint8List.fromList('<html>sign in</html>'.codeUnits),
        headers: Headers.fromMap({
          'content-type': ['text/html'],
        }),
      ),
    );

    await cache.refresh();

    for (final slot in BrandSlot.values) {
      expect(cache.bytesFor(slot), isNull, reason: slot.slug);
    }
  });

  test('every slot names the asset the apps actually bundle', () {
    for (final slot in BrandSlot.values) {
      expect(slot.assetPath, 'assets/brand/${slot.slug}.png');
      expect(File('../../customer/${slot.assetPath}').existsSync(), isTrue,
          reason: 'customer is missing ${slot.assetPath}');
      expect(File('../../merchant/${slot.assetPath}').existsSync(), isTrue,
          reason: 'merchant is missing ${slot.assetPath}');
    }
  });
}

/// Answers every request from a closure, recording the paths asked for.
class _StubAdapter implements HttpClientAdapter {
  _StubAdapter(this.respond, this.log);

  final Response<dynamic> Function(RequestOptions) respond;
  final List<String>? log;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    log?.add(options.path);
    final response = respond(options);

    if (response.statusCode != 200) {
      return ResponseBody.fromBytes(const [], response.statusCode!);
    }

    return ResponseBody.fromBytes(
      (response.data as Uint8List).toList(),
      200,
      headers: response.headers.map,
    );
  }

  @override
  void close({bool force = false}) {}
}
