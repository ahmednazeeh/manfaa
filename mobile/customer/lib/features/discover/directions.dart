import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:url_launcher/url_launcher.dart';

/// Handing a branch off to whatever map the phone prefers — WITH the store's
/// name attached wherever the platform will carry it.
///
/// Why this file exists (owner report 2026-08-18): our branches are a
/// coordinate the merchant dropped on our own map, not a listing in anyone's
/// place database. Google's web URL has no way to title a bare coordinate,
/// so it titles it with the coordinate itself — `4°10'30.4"N 73°30'34.6"E`,
/// which is what a customer was seeing. Tested before writing this: the
/// widely-cited `?q=lat,lng(Label)` form is IGNORED by Google Maps on the
/// web, and searching by business name returns a results page that can land
/// on the wrong shop, which is worse than a right coordinate.
///
/// So the name is carried where it CAN be carried:
///
///  * **Android** — the `geo:` intent does honour the parenthesised label,
///    and it is the native handoff besides: the chooser offers whatever map
///    apps the phone actually has.
///  * **iOS** — Apple Maps takes a `q` alongside `ll`, so the pin is titled
///    and still sits on our exact coordinate.
///  * **anything else** — Google Maps with the coordinate. The title will be
///    the coordinate; the DESTINATION is still exactly right, and the button
///    that got here already said the store's name.
///
/// Navigation was never broken — a coordinate navigates fine. It read as
/// unlabelled, and that is a presentation problem, so this is a presentation
/// fix. The label the customer actually reads is the one WE draw, on our own
/// map and on our own button.
Uri directionsUri({
  required double lat,
  required double lng,
  required String label,
}) {
  final coords = '$lat,$lng';

  if (!kIsWeb && Platform.isAndroid) {
    return Uri.parse('geo:$coords?q=${Uri.encodeComponent('$coords($label)')}');
  }

  if (!kIsWeb && Platform.isIOS) {
    return Uri.parse(
      'https://maps.apple.com/?ll=$coords&q=${Uri.encodeComponent(label)}',
    );
  }

  return Uri.parse(
    'https://www.google.com/maps/search/?api=1&query=${Uri.encodeComponent(coords)}',
  );
}

/// Open it. A phone with no map app is not the customer's mistake, so a
/// failure is silent — the address is on the screen either way.
Future<void> openDirections({
  required double lat,
  required double lng,
  required String label,
}) async {
  final uri = directionsUri(lat: lat, lng: lng, label: label);

  try {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {
    // Deliberately silent — see above.
  }
}
