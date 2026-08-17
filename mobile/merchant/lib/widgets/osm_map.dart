import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../app/app.dart';

/// The ONE OSM pin-picker map (MR8) — the setup location step and the
/// branches sheet render exactly this widget, so the tile transport rules
/// exist once and can never drift between the two:
///
///  * `userAgentPackageName: 'mv.manfaa.merchant'` — OSM's tile policy
///    blocks anonymous default agents, the reported cause of grey maps in
///    release builds; the identified agent is required on EVERY TileLayer.
///  * the https tile template (cleartext would be silently dropped by the
///    release APK's network config);
///  * a graceful tile-error state instead of the silent grey screen the
///    owner met: when tiles fail and none have landed, the map says so and
///    offers a retry that rebuilds the tile layer from scratch.
///
/// The fixed centre pin (the standard mobile pin-picker: drag the map UNDER
/// the pin) and the OSM attribution ride along for the same reason.
class PinPickerMap extends StatefulWidget {
  const PinPickerMap({
    super.key,
    this.controller,
    required this.initialCenter,
    required this.initialZoom,
    required this.onCenterChanged,
  });

  final MapController? controller;
  final LatLng initialCenter;
  final double initialZoom;
  final ValueChanged<LatLng> onCenterChanged;

  @override
  State<PinPickerMap> createState() => _PinPickerMapState();
}

class _PinPickerMapState extends State<PinPickerMap> {
  /// Bumped by the retry button: a new key tears the TileLayer (and its
  /// error-tile cache) down and starts the fetches over.
  var _epoch = 0;

  /// Tiles failed while nothing has rendered — the would-be grey screen.
  var _failed = false;
  var _anyTileLoaded = false;

  void _noteTileError() {
    if (_failed || _anyTileLoaded || !mounted) return;
    // Tile callbacks fire mid-paint; defer the setState.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && !_anyTileLoaded) setState(() => _failed = true);
    });
  }

  void _noteTileLoaded() {
    _anyTileLoaded = true;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;

    return Stack(
      children: [
        FlutterMap(
          mapController: widget.controller,
          options: MapOptions(
            initialCenter: widget.initialCenter,
            initialZoom: widget.initialZoom,
            onPositionChanged: (camera, _) =>
                widget.onCenterChanged(camera.center),
          ),
          children: [
            TileLayer(
              key: ValueKey('tiles-$_epoch'),
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'mv.manfaa.merchant',
              errorTileCallback: (_, _, _) => _noteTileError(),
              tileBuilder: (context, tileWidget, tile) {
                if (tile.loadFinishedAt != null && tile.loadError == false) {
                  _noteTileLoaded();
                }
                return tileWidget;
              },
            ),
            // OSM's attribution requirement — small, but present.
            const Align(
              alignment: Alignment.bottomRight,
              child: Padding(
                padding: EdgeInsets.all(2),
                child: Text(
                  '© OpenStreetMap',
                  style: TextStyle(fontSize: 9, color: Color(0x99000000)),
                ),
              ),
            ),
          ],
        ),
        // The fixed centre pin the map drags under. Lifted by half its
        // height so the TIP marks the centre, not the glyph's middle;
        // ignores pointers so it never eats a drag.
        IgnorePointer(
          child: Center(
            child: Transform.translate(
              offset: const Offset(0, -18),
              child: const Icon(
                Icons.place_rounded,
                size: 40,
                color: ManfaaColors.coral,
                shadows: [Shadow(color: Color(0x40000000), blurRadius: 6)],
              ),
            ),
          ),
        ),
        if (_failed)
          Positioned.fill(
            child: ColoredBox(
              color: theme.colorScheme.surfaceContainerLow,
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(Gap.lg),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const IconTile(
                        Icons.map_outlined,
                        tint: ManfaaTint.neutral,
                        size: 44,
                        iconSize: 22,
                      ),
                      const SizedBox(height: Gap.sm),
                      Text(
                        l10n.mapTilesFailed,
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: Gap.sm),
                      OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size(0, 36),
                          padding: const EdgeInsets.symmetric(
                            horizontal: Gap.lg,
                          ),
                        ),
                        onPressed: () => setState(() {
                          _failed = false;
                          _epoch++;
                        }),
                        child: Text(l10n.retry),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
