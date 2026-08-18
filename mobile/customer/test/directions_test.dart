import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_customer/features/discover/directions.dart';

void main() {
  // The bug this guards: a bare coordinate handed to Google Maps comes back
  // titled `4°10'30.4"N 73°30'34.6"E`, because there is no place identity
  // behind our pins. The destination was always right; only the label was
  // missing. These pin the contract that the coordinate is never lost.
  test('the desktop/web handoff sends the exact pin, never a name search', () {
    final uri = directionsUri(lat: 4.1725895, lng: 73.5099866, label: 'Tea Plus');

    expect(uri.host, contains('google.com'));
    // `query=<lat,lng>` — a name search could resolve to a DIFFERENT shop,
    // which is worse than an unlabelled pin at the right door.
    expect(uri.queryParameters['query'], '4.1725895,73.5099866');
  });

  test('a store name with spaces and punctuation survives encoding', () {
    final uri = directionsUri(lat: 4.1, lng: 73.5, label: 'Tea Plus & Co, Malé');

    // Whatever the platform, the URI must be parseable and keep the pin.
    expect(uri.toString(), isNot(contains(' ')));
    expect(uri.toString(), contains('4.1'));
    expect(uri.toString(), contains('73.5'));
  });
}
