import 'package:flutter/cupertino.dart' show CupertinoLocalizations;
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

/// Divehi support for the FRAMEWORK's own strings.
///
/// Flutter's GlobalMaterialLocalizations does not include `dv`, so without
/// these delegates a `Locale('dv')` app throws at startup. Our own strings
/// translate through the app's ARB files; the framework's dialog buttons and
/// tooltips fall back to English — the honest compromise until someone
/// contributes dv upstream.
///
/// The one thing that must NOT fall back is direction: Thaana is RTL, and
/// the English WidgetsLocalizations would lay the whole app out LTR. So the
/// widgets delegate overrides textDirection while everything else delegates
/// to the English implementations.
const dvFallbackDelegates = <LocalizationsDelegate<dynamic>>[
  _DvMaterialDelegate(),
  _DvCupertinoDelegate(),
  _DvWidgetsDelegate(),
];

bool _isDv(Locale locale) => locale.languageCode == 'dv';

class _DvMaterialDelegate extends LocalizationsDelegate<MaterialLocalizations> {
  const _DvMaterialDelegate();

  @override
  bool isSupported(Locale locale) => _isDv(locale);

  @override
  Future<MaterialLocalizations> load(Locale locale) =>
      GlobalMaterialLocalizations.delegate.load(const Locale('en'));

  @override
  bool shouldReload(covariant LocalizationsDelegate old) => false;
}

class _DvCupertinoDelegate extends LocalizationsDelegate<CupertinoLocalizations> {
  const _DvCupertinoDelegate();

  @override
  bool isSupported(Locale locale) => _isDv(locale);

  @override
  Future<CupertinoLocalizations> load(Locale locale) =>
      GlobalCupertinoLocalizations.delegate.load(const Locale('en'));

  @override
  bool shouldReload(covariant LocalizationsDelegate old) => false;
}

class _DvWidgetsDelegate extends LocalizationsDelegate<WidgetsLocalizations> {
  const _DvWidgetsDelegate();

  @override
  bool isSupported(Locale locale) => _isDv(locale);

  @override
  Future<WidgetsLocalizations> load(Locale locale) =>
      SynchronousFuture(const _DvWidgetsLocalizations());

  @override
  bool shouldReload(covariant LocalizationsDelegate old) => false;
}

class _DvWidgetsLocalizations extends DefaultWidgetsLocalizations {
  const _DvWidgetsLocalizations();

  /// THE line this file exists for: Thaana lays out right-to-left.
  @override
  TextDirection get textDirection => TextDirection.rtl;
}
