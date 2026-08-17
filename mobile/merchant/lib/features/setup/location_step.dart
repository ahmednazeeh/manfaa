import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'setup_common.dart';

/// Step 2 — the primary branch's pin: drag the map UNDER a fixed centre pin
/// (the standard mobile pin-picker), my-location via geolocator, OSM tiles
/// with no API key in the APK — the customer app's decision, kept.
///
/// Continue saves the centre (7 decimal places — sub-centimetre, and the
/// same precision the panels render). Skippable only for an online-only
/// store, read from the channel the SERVER holds, not the profile step's
/// unsaved radio (web parity).
class LocationStep extends ConsumerStatefulWidget {
  const LocationStep({
    super.key,
    required this.state,
    required this.onSaved,
    required this.onSkip,
    required this.onBack,
  });

  final MerchantSetupState state;
  final void Function(MerchantSetupState state) onSaved;
  final VoidCallback onSkip;
  final VoidCallback onBack;

  @override
  ConsumerState<LocationStep> createState() => _LocationStepState();
}

class _LocationStepState extends ConsumerState<LocationStep> {
  /// Malé — where most first pins are a short drag from.
  static const _fallbackCenter = LatLng(4.1755, 73.5093);

  final _map = MapController();

  late final LatLng? _saved = widget.state.pinned
      ? LatLng(widget.state.values.primaryBranch!.lat!,
          widget.state.values.primaryBranch!.lng!)
      : null;

  late LatLng _center = _saved ?? _fallbackCenter;
  var _busy = false;
  var _locating = false;

  double _round7(double value) => double.parse(value.toStringAsFixed(7));

  Future<void> _useMyLocation() async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _locating = true);

    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        messenger.showSnackBar(SnackBar(content: Text(l10n.locationDenied)));
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );

      final here = LatLng(position.latitude, position.longitude);
      _map.move(here, 17);
      setState(() => _center = here);
    } catch (_) {
      messenger.showSnackBar(SnackBar(content: Text(l10n.locationFailed)));
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _save() async {
    // Nothing moved since the last visit — passing back through the step,
    // not re-pinning. Plain navigation, no write (web parity).
    final saved = _saved;
    if (saved != null &&
        _round7(_center.latitude) == _round7(saved.latitude) &&
        _round7(_center.longitude) == _round7(saved.longitude)) {
      widget.onSaved(widget.state);
      return;
    }

    setState(() => _busy = true);
    try {
      final fresh = await ref.read(apiProvider).saveSetupLocation(
            lat: _round7(_center.latitude),
            lng: _round7(_center.longitude),
          );
      if (mounted) widget.onSaved(fresh);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(describeSetupError(context, e))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final required = widget.state.locationRequired;

    return SetupStepCard(
      title: l10n.locationTitle,
      subtitle: required ? l10n.locationSubtitle : l10n.locationOnlineNote,
      actionLabel: l10n.continueLabel,
      onAction: _busy ? null : _save,
      onBack: widget.onBack,
      busy: _busy,
      secondary: required
          ? null
          : TextButton(
              onPressed: _busy ? null : widget.onSkip,
              child: Text(l10n.skipForNow),
            ),
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(Corner.tile),
          child: SizedBox(
            height: 260,
            child: Stack(
              children: [
                FlutterMap(
                  mapController: _map,
                  options: MapOptions(
                    initialCenter: _center,
                    initialZoom: _saved != null ? 17 : 15,
                    onPositionChanged: (camera, _) =>
                        setState(() => _center = camera.center),
                  ),
                  children: [
                    TileLayer(
                      urlTemplate:
                          'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                      userAgentPackageName: 'mv.manfaa.merchant',
                    ),
                    // OSM's attribution requirement — small, but present.
                    const Align(
                      alignment: Alignment.bottomRight,
                      child: Padding(
                        padding: EdgeInsets.all(2),
                        child: Text(
                          '© OpenStreetMap',
                          style:
                              TextStyle(fontSize: 9, color: Color(0x99000000)),
                        ),
                      ),
                    ),
                  ],
                ),
                // The fixed centre pin the map drags under. Lifted by half
                // its height so the TIP marks the centre, not the glyph's
                // middle; ignores pointers so it never eats a drag.
                IgnorePointer(
                  child: Center(
                    child: Transform.translate(
                      offset: const Offset(0, -18),
                      child: const Icon(
                        Icons.place_rounded,
                        size: 40,
                        color: ManfaaColors.coral,
                        shadows: [
                          Shadow(color: Color(0x40000000), blurRadius: 6),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: Gap.md),
        Row(
          children: [
            Expanded(
              child: Text(
                '${_center.latitude.toStringAsFixed(7)}, '
                '${_center.longitude.toStringAsFixed(7)}',
                textDirection: TextDirection.ltr,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
            ),
            TextButton.icon(
              onPressed: _locating ? null : _useMyLocation,
              icon: _locating
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.my_location_rounded, size: 18),
              label: Text(l10n.myLocation),
              style: TextButton.styleFrom(
                foregroundColor: theme.colorScheme.secondary,
              ),
            ),
          ],
        ),
      ],
    );
  }
}
