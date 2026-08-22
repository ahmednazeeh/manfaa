import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

import 'api_base.dart';

/// The platform's own marks, which a superadmin can replace without shipping
/// a new build.
enum BrandSlot {
  landscapeLight('landscape_light'),
  landscapeDark('landscape_dark'),
  squareLight('square_light'),
  squareDark('square_dark');

  const BrandSlot(this.slug);

  /// The API path segment, and the bundled asset's filename — deliberately
  /// the same string, so a slot cannot drift between the two.
  final String slug;

  String get assetPath => 'assets/brand/$slug.png';
}

/// Downloads, caches and hands out the platform's brand marks.
///
/// Three layers, in the order a widget gets them:
///
///  1. **Bundled** — `assets/brand/*.png`, shipped in the APK. A first launch
///     on a plane still has a logo, and there is never a blank header.
///  2. **Cached** — bytes on disk from a previous run, held in memory after
///     [load] so a widget reads them SYNCHRONOUSLY. That is what stops the
///     header flashing the bundled mark and then swapping.
///  3. **Fetched** — refreshed from the API when the cache is older than
///     [ttl].
///
/// The refresh is deliberately off the critical path: [load] returns as soon
/// as the disk cache is in memory, and the network call runs after, updating
/// [version] when something actually changed. Boot never waits on it, and a
/// dead network costs nothing.
class BrandAssetCache {
  BrandAssetCache({Dio? dio, this.ttl = const Duration(hours: 24)})
      : _dio = dio ?? Dio(BaseOptions(connectTimeout: const Duration(seconds: 8), receiveTimeout: const Duration(seconds: 12)));

  final Dio _dio;

  /// The cache this process uses, set once at boot.
  ///
  /// A static because the marks are drawn in a dozen headers and boot
  /// screens across two apps, and threading one immutable service through
  /// every one of them would be plumbing with no decision in it. Widgets
  /// read it through [BrandLogo], which also works when it is null — that
  /// is simply a first launch, and the bundled mark is what it shows.
  static BrandAssetCache? instance;

  /// How long a downloaded mark is trusted. A logo changes rarely; a day
  /// means a replacement reaches every phone within one, without every app
  /// launch spending four requests on something that almost never moves.
  final Duration ttl;

  final Map<BrandSlot, Uint8List> _bytes = {};

  /// Bumped whenever the bytes behind any slot change, so widgets listening
  /// to it repaint. A plain counter rather than a stream: there is one
  /// consumer shape (a widget rebuilding) and nothing to cancel.
  final ValueNotifier<int> version = ValueNotifier(0);

  Directory? _dir;
  bool _loaded = false;

  /// The cached bytes for a slot, or null when only the bundled asset is
  /// available. Synchronous on purpose — see the class note.
  Uint8List? bytesFor(BrandSlot slot) => _bytes[slot];

  /// Reads whatever is already on disk, then refreshes in the background if
  /// it has gone stale. Safe to call more than once; the disk read happens
  /// once per process.
  Future<void> load() async {
    if (!_loaded) {
      _loaded = true;
      await _readDisk();
    }

    // Not awaited: boot must not wait on the network for a logo it can
    // already draw.
    unawaited(refreshIfStale());
  }

  Future<void> _readDisk() async {
    try {
      final dir = await _cacheDir();

      for (final slot in BrandSlot.values) {
        final file = File('${dir.path}/${slot.slug}.png');
        if (await file.exists()) {
          _bytes[slot] = await file.readAsBytes();
        }
      }

      if (_bytes.isNotEmpty) version.value++;
    } on Object {
      // A cache that cannot be read is not an error: the bundled marks are
      // still there, and the next refresh will write a fresh copy.
    }
  }

  /// Refetches every slot when the cache has aged past [ttl]. Call on app
  /// resume as well as at boot — a phone left open for days would otherwise
  /// keep yesterday's mark until it was killed.
  Future<void> refreshIfStale() async {
    if (!await _isStale()) return;
    await refresh();
  }

  Future<bool> _isStale() async {
    try {
      final stamp = File('${(await _cacheDir()).path}/fetched_at');
      if (!await stamp.exists()) return true;

      final at = DateTime.tryParse(await stamp.readAsString());
      if (at == null) return true;

      return DateTime.now().difference(at) >= ttl;
    } on Object {
      return true;
    }
  }

  /// Fetches all four slots. A slot that fails keeps whatever it had — a
  /// half-refreshed brand is still a brand, and one failed request must not
  /// blank a header.
  Future<void> refresh() async {
    var changed = false;

    for (final slot in BrandSlot.values) {
      final bytes = await _fetch(slot);
      if (bytes == null) continue;

      if (!_sameBytes(_bytes[slot], bytes)) {
        _bytes[slot] = bytes;
        changed = true;
      }

      await _writeDisk(slot, bytes);
    }

    // Stamped even when nothing changed: the point of the stamp is "we
    // asked recently", not "the logo changed recently".
    await _stamp();

    if (changed) version.value++;
  }

  Future<Uint8List?> _fetch(BrandSlot slot) async {
    try {
      final response = await _dio.get<List<int>>(
        '${ApiEnv.publicBaseUrl}/brand/${slot.slug}',
        options: Options(responseType: ResponseType.bytes),
      );

      final data = response.data;
      if (data == null || data.isEmpty) return null;

      // The endpoint always answers an image; anything else means we are
      // talking to a captive portal rather than to Manfaa, and storing it
      // would put a login page in the header.
      final type = response.headers.value('content-type') ?? '';
      if (!type.startsWith('image/')) return null;

      return Uint8List.fromList(data);
    } on Object {
      return null;
    }
  }

  Future<void> _writeDisk(BrandSlot slot, Uint8List bytes) async {
    try {
      await File('${(await _cacheDir()).path}/${slot.slug}.png')
          .writeAsBytes(bytes, flush: true);
    } on Object {
      // Cache write failures are not worth surfacing: the bytes are already
      // in memory for this run, and the next run refetches.
    }
  }

  Future<void> _stamp() async {
    try {
      await File('${(await _cacheDir()).path}/fetched_at')
          .writeAsString(DateTime.now().toIso8601String(), flush: true);
    } on Object {
      // As above — a missing stamp only means the next launch refetches.
    }
  }

  Future<Directory> _cacheDir() async {
    final existing = _dir;
    if (existing != null) return existing;

    final base = await getApplicationSupportDirectory();
    final dir = Directory('${base.path}/brand');
    if (!await dir.exists()) await dir.create(recursive: true);

    return _dir = dir;
  }

  static bool _sameBytes(Uint8List? a, Uint8List? b) {
    if (identical(a, b)) return true;
    if (a == null || b == null || a.length != b.length) return false;

    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) return false;
    }

    return true;
  }

  /// Test seam: pretend the cache directory is somewhere else.
  @visibleForTesting
  void useDirectory(Directory dir) => _dir = dir;
}
