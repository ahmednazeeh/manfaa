import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// Money, rendered the ONE correct way.
///
/// Takes integer laari — never a double, never a pre-formatted string — so
/// the platform's money law (integers end to end) holds all the way into
/// the widget tree. Formatting follows the server's own rule: the currency
/// word goes BEFORE the figure in English and AFTER it in Dhivehi, and
/// "MVR" never appears inside a Thaana sentence.
class MoneyText extends StatelessWidget {
  const MoneyText(this.laari, {super.key, this.style});

  final int laari;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return Text(
      formatMoney(laari, dhivehi: dhivehi),
      style: (style ?? DefaultTextStyle.of(context).style).copyWith(
        // Tabular figures: amounts in a column align, and a balance that
        // ticks over does not jiggle.
        fontFeatures: const [FontFeature.tabularFigures()],
      ),
      // A Thaana amount inside an LTR screen (or vice versa) must not let
      // the bidi algorithm reorder "MVR" and the figure.
      textDirection: dhivehi ? TextDirection.rtl : TextDirection.ltr,
    );
  }
}
