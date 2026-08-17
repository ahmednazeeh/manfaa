import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_dv.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'gen/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('dv'),
    Locale('en'),
  ];

  /// No description provided for @appTitle.
  ///
  /// In en, this message translates to:
  /// **'Manfaa Merchant'**
  String get appTitle;

  /// No description provided for @tabDashboard.
  ///
  /// In en, this message translates to:
  /// **'Dashboard'**
  String get tabDashboard;

  /// No description provided for @tabCredit.
  ///
  /// In en, this message translates to:
  /// **'Credit'**
  String get tabCredit;

  /// No description provided for @tabTransactions.
  ///
  /// In en, this message translates to:
  /// **'Transactions'**
  String get tabTransactions;

  /// No description provided for @tabSettlements.
  ///
  /// In en, this message translates to:
  /// **'Settlements'**
  String get tabSettlements;

  /// No description provided for @tabMore.
  ///
  /// In en, this message translates to:
  /// **'More'**
  String get tabMore;

  /// No description provided for @loginTitle.
  ///
  /// In en, this message translates to:
  /// **'Merchant login'**
  String get loginTitle;

  /// No description provided for @loginSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Sign in to manage cashback, settlements, and customer credits.'**
  String get loginSubtitle;

  /// No description provided for @emailLabel.
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get emailLabel;

  /// No description provided for @emailHint.
  ///
  /// In en, this message translates to:
  /// **'Enter your email'**
  String get emailHint;

  /// No description provided for @passwordLabel.
  ///
  /// In en, this message translates to:
  /// **'Password'**
  String get passwordLabel;

  /// No description provided for @passwordHint.
  ///
  /// In en, this message translates to:
  /// **'Enter your password'**
  String get passwordHint;

  /// No description provided for @logIn.
  ///
  /// In en, this message translates to:
  /// **'Log in'**
  String get logIn;

  /// No description provided for @showPassword.
  ///
  /// In en, this message translates to:
  /// **'Show password'**
  String get showPassword;

  /// No description provided for @hidePassword.
  ///
  /// In en, this message translates to:
  /// **'Hide password'**
  String get hidePassword;

  /// No description provided for @securityTitle.
  ///
  /// In en, this message translates to:
  /// **'Secure merchant access'**
  String get securityTitle;

  /// No description provided for @securityBody.
  ///
  /// In en, this message translates to:
  /// **'Your business data is protected with enterprise-grade security.'**
  String get securityBody;

  /// No description provided for @protectedBadge.
  ///
  /// In en, this message translates to:
  /// **'Protected'**
  String get protectedBadge;

  /// No description provided for @errBadCredentials.
  ///
  /// In en, this message translates to:
  /// **'That email or password isn\'t right. Check them and try again.'**
  String get errBadCredentials;

  /// No description provided for @errMissingCredentials.
  ///
  /// In en, this message translates to:
  /// **'Enter your email and password.'**
  String get errMissingCredentials;

  /// No description provided for @errTooManyTries.
  ///
  /// In en, this message translates to:
  /// **'Too many attempts. Please wait a moment and try again.'**
  String get errTooManyTries;

  /// No description provided for @errorGeneric.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong. Please try again.'**
  String get errorGeneric;

  /// No description provided for @retry.
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get retry;

  /// No description provided for @updateRequiredTitle.
  ///
  /// In en, this message translates to:
  /// **'Update needed'**
  String get updateRequiredTitle;

  /// No description provided for @updateRequiredBody.
  ///
  /// In en, this message translates to:
  /// **'This version of Manfaa Merchant can no longer be used. Please update to continue.'**
  String get updateRequiredBody;

  /// No description provided for @setupPendingTitle.
  ///
  /// In en, this message translates to:
  /// **'Your store is under review'**
  String get setupPendingTitle;

  /// No description provided for @setupPendingBody.
  ///
  /// In en, this message translates to:
  /// **'We\'re checking your details. You can start crediting customers the moment Manfaa approves your store.'**
  String get setupPendingBody;

  /// No description provided for @signOut.
  ///
  /// In en, this message translates to:
  /// **'Sign out'**
  String get signOut;

  /// No description provided for @comingSoonTitle.
  ///
  /// In en, this message translates to:
  /// **'On its way'**
  String get comingSoonTitle;

  /// No description provided for @dashboardComingBody.
  ///
  /// In en, this message translates to:
  /// **'Outstanding to settle, aging buckets and your settlement deadline will live here.'**
  String get dashboardComingBody;

  /// No description provided for @creditComingBody.
  ///
  /// In en, this message translates to:
  /// **'Credit a customer\'s cashback in seconds at the counter — the till lands here next.'**
  String get creditComingBody;

  /// No description provided for @transactionsComingBody.
  ///
  /// In en, this message translates to:
  /// **'Every sale you\'ve credited will live here.'**
  String get transactionsComingBody;

  /// No description provided for @settlementsComingBody.
  ///
  /// In en, this message translates to:
  /// **'Amounts due, the prompt-payment discount and your receipts will live here.'**
  String get settlementsComingBody;

  /// No description provided for @moreComingBody.
  ///
  /// In en, this message translates to:
  /// **'Profile, employees, roles, branches and cashback settings will live here.'**
  String get moreComingBody;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['dv', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'dv':
      return AppLocalizationsDv();
    case 'en':
      return AppLocalizationsEn();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
