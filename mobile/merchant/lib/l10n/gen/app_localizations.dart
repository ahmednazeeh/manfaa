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

  /// No description provided for @continueLabel.
  ///
  /// In en, this message translates to:
  /// **'Continue'**
  String get continueLabel;

  /// No description provided for @back.
  ///
  /// In en, this message translates to:
  /// **'Back'**
  String get back;

  /// No description provided for @skip.
  ///
  /// In en, this message translates to:
  /// **'Skip'**
  String get skip;

  /// No description provided for @skipForNow.
  ///
  /// In en, this message translates to:
  /// **'Skip for now'**
  String get skipForNow;

  /// No description provided for @notSet.
  ///
  /// In en, this message translates to:
  /// **'Not set'**
  String get notSet;

  /// No description provided for @newMerchantPrompt.
  ///
  /// In en, this message translates to:
  /// **'New merchant?'**
  String get newMerchantPrompt;

  /// No description provided for @registerStore.
  ///
  /// In en, this message translates to:
  /// **'Register your store'**
  String get registerStore;

  /// No description provided for @signupTitle.
  ///
  /// In en, this message translates to:
  /// **'Register your store'**
  String get signupTitle;

  /// No description provided for @signupSubtitle.
  ///
  /// In en, this message translates to:
  /// **'A few minutes of setup and your store starts rewarding customers.'**
  String get signupSubtitle;

  /// No description provided for @phoneLabel.
  ///
  /// In en, this message translates to:
  /// **'Phone number'**
  String get phoneLabel;

  /// No description provided for @phoneHint.
  ///
  /// In en, this message translates to:
  /// **'7XXX XXXX'**
  String get phoneHint;

  /// No description provided for @otpDeliveryNote.
  ///
  /// In en, this message translates to:
  /// **'We\'ll send a code by SMS to verify your number.'**
  String get otpDeliveryNote;

  /// No description provided for @codeTitle.
  ///
  /// In en, this message translates to:
  /// **'Enter the code'**
  String get codeTitle;

  /// No description provided for @codeSentTo.
  ///
  /// In en, this message translates to:
  /// **'We sent a 6-digit code to {phone}.'**
  String codeSentTo(String phone);

  /// No description provided for @verifyLabel.
  ///
  /// In en, this message translates to:
  /// **'Verify'**
  String get verifyLabel;

  /// No description provided for @resendCode.
  ///
  /// In en, this message translates to:
  /// **'Resend code'**
  String get resendCode;

  /// No description provided for @resendCountdown.
  ///
  /// In en, this message translates to:
  /// **'Resend in {seconds}s'**
  String resendCountdown(int seconds);

  /// No description provided for @detailsTitle.
  ///
  /// In en, this message translates to:
  /// **'Tell us about your store'**
  String get detailsTitle;

  /// No description provided for @detailsSubtitle.
  ///
  /// In en, this message translates to:
  /// **'This is how customers will see you on Manfaa.'**
  String get detailsSubtitle;

  /// No description provided for @businessNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Store name'**
  String get businessNameLabel;

  /// No description provided for @businessNameHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. Fresh Mart'**
  String get businessNameHint;

  /// No description provided for @businessNameDvLabel.
  ///
  /// In en, this message translates to:
  /// **'Store name in Dhivehi (optional)'**
  String get businessNameDvLabel;

  /// No description provided for @passwordRule.
  ///
  /// In en, this message translates to:
  /// **'At least 8 characters.'**
  String get passwordRule;

  /// No description provided for @createStore.
  ///
  /// In en, this message translates to:
  /// **'Create my store'**
  String get createStore;

  /// No description provided for @alreadyRegistered.
  ///
  /// In en, this message translates to:
  /// **'Already have an account?'**
  String get alreadyRegistered;

  /// No description provided for @errOtpInvalid.
  ///
  /// In en, this message translates to:
  /// **'That code isn\'t right or has expired. Check it, or request a fresh one.'**
  String get errOtpInvalid;

  /// No description provided for @errOtpAttempts.
  ///
  /// In en, this message translates to:
  /// **'Too many wrong codes. Request a fresh one and try again.'**
  String get errOtpAttempts;

  /// No description provided for @errSignupExpired.
  ///
  /// In en, this message translates to:
  /// **'That verification expired. Start again with your phone number.'**
  String get errSignupExpired;

  /// No description provided for @errEmailRegistered.
  ///
  /// In en, this message translates to:
  /// **'That email already has a merchant account. Log in instead.'**
  String get errEmailRegistered;

  /// No description provided for @errSignupDetailsMissing.
  ///
  /// In en, this message translates to:
  /// **'Enter the store name, an email, and a password of at least 8 characters.'**
  String get errSignupDetailsMissing;

  /// No description provided for @setupTitle.
  ///
  /// In en, this message translates to:
  /// **'Set up your store'**
  String get setupTitle;

  /// No description provided for @setupStepOf.
  ///
  /// In en, this message translates to:
  /// **'Step {current} of {total}'**
  String setupStepOf(int current, int total);

  /// No description provided for @stepProfile.
  ///
  /// In en, this message translates to:
  /// **'Profile'**
  String get stepProfile;

  /// No description provided for @stepLocation.
  ///
  /// In en, this message translates to:
  /// **'Location'**
  String get stepLocation;

  /// No description provided for @stepLogo.
  ///
  /// In en, this message translates to:
  /// **'Logo'**
  String get stepLogo;

  /// No description provided for @stepRate.
  ///
  /// In en, this message translates to:
  /// **'Rate'**
  String get stepRate;

  /// No description provided for @stepTerms.
  ///
  /// In en, this message translates to:
  /// **'Terms'**
  String get stepTerms;

  /// No description provided for @stepReview.
  ///
  /// In en, this message translates to:
  /// **'Review'**
  String get stepReview;

  /// No description provided for @profileTitle.
  ///
  /// In en, this message translates to:
  /// **'Store profile'**
  String get profileTitle;

  /// No description provided for @categoryLabel.
  ///
  /// In en, this message translates to:
  /// **'Category'**
  String get categoryLabel;

  /// No description provided for @categoryHint.
  ///
  /// In en, this message translates to:
  /// **'Pick the closest match — customers browse by category.'**
  String get categoryHint;

  /// No description provided for @categoryRequired.
  ///
  /// In en, this message translates to:
  /// **'Pick a category to continue.'**
  String get categoryRequired;

  /// No description provided for @channelLabel.
  ///
  /// In en, this message translates to:
  /// **'Where do you sell?'**
  String get channelLabel;

  /// No description provided for @channelInStore.
  ///
  /// In en, this message translates to:
  /// **'In-store'**
  String get channelInStore;

  /// No description provided for @channelOnline.
  ///
  /// In en, this message translates to:
  /// **'Online'**
  String get channelOnline;

  /// No description provided for @channelBoth.
  ///
  /// In en, this message translates to:
  /// **'Both'**
  String get channelBoth;

  /// No description provided for @channelInStoreHint.
  ///
  /// In en, this message translates to:
  /// **'Customers walk in to buy.'**
  String get channelInStoreHint;

  /// No description provided for @channelOnlineHint.
  ///
  /// In en, this message translates to:
  /// **'Orders come in online.'**
  String get channelOnlineHint;

  /// No description provided for @channelBothHint.
  ///
  /// In en, this message translates to:
  /// **'A counter and online orders.'**
  String get channelBothHint;

  /// No description provided for @contactEmailLabel.
  ///
  /// In en, this message translates to:
  /// **'Contact email'**
  String get contactEmailLabel;

  /// No description provided for @contactPhoneLabel.
  ///
  /// In en, this message translates to:
  /// **'Contact phone'**
  String get contactPhoneLabel;

  /// No description provided for @supportPhoneLabel.
  ///
  /// In en, this message translates to:
  /// **'Customer support number'**
  String get supportPhoneLabel;

  /// No description provided for @supportSameAsContact.
  ///
  /// In en, this message translates to:
  /// **'Same as contact number'**
  String get supportSameAsContact;

  /// No description provided for @websiteLabel.
  ///
  /// In en, this message translates to:
  /// **'Website (optional)'**
  String get websiteLabel;

  /// No description provided for @locationTitle.
  ///
  /// In en, this message translates to:
  /// **'Store location'**
  String get locationTitle;

  /// No description provided for @locationSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Drag the map until the pin sits on your storefront.'**
  String get locationSubtitle;

  /// No description provided for @locationOnlineNote.
  ///
  /// In en, this message translates to:
  /// **'You sell online only — you can skip the pin.'**
  String get locationOnlineNote;

  /// No description provided for @myLocation.
  ///
  /// In en, this message translates to:
  /// **'Use my location'**
  String get myLocation;

  /// No description provided for @locationDenied.
  ///
  /// In en, this message translates to:
  /// **'Location permission was denied.'**
  String get locationDenied;

  /// No description provided for @locationFailed.
  ///
  /// In en, this message translates to:
  /// **'Couldn\'t get your location.'**
  String get locationFailed;

  /// No description provided for @logoTitle.
  ///
  /// In en, this message translates to:
  /// **'Store logo'**
  String get logoTitle;

  /// No description provided for @logoSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Optional — a logo makes your store easy to spot.'**
  String get logoSubtitle;

  /// No description provided for @chooseLogo.
  ///
  /// In en, this message translates to:
  /// **'Choose logo'**
  String get chooseLogo;

  /// No description provided for @replaceLogo.
  ///
  /// In en, this message translates to:
  /// **'Replace logo'**
  String get replaceLogo;

  /// No description provided for @logoUploading.
  ///
  /// In en, this message translates to:
  /// **'Uploading…'**
  String get logoUploading;

  /// No description provided for @logoHint.
  ///
  /// In en, this message translates to:
  /// **'JPG, PNG or WebP, up to 2 MB.'**
  String get logoHint;

  /// No description provided for @logoUploaded.
  ///
  /// In en, this message translates to:
  /// **'Logo uploaded.'**
  String get logoUploaded;

  /// No description provided for @errLogoType.
  ///
  /// In en, this message translates to:
  /// **'Use a JPG, PNG or WebP image.'**
  String get errLogoType;

  /// No description provided for @errLogoSize.
  ///
  /// In en, this message translates to:
  /// **'That image is over 2 MB. Pick a smaller one.'**
  String get errLogoSize;

  /// No description provided for @rateTitle.
  ///
  /// In en, this message translates to:
  /// **'Cashback rate'**
  String get rateTitle;

  /// No description provided for @rateSubtitle.
  ///
  /// In en, this message translates to:
  /// **'What customers earn on eligible spending at your store.'**
  String get rateSubtitle;

  /// No description provided for @rateLabel.
  ///
  /// In en, this message translates to:
  /// **'Cashback rate'**
  String get rateLabel;

  /// No description provided for @rateRangeHint.
  ///
  /// In en, this message translates to:
  /// **'Between {min} and {max}.'**
  String rateRangeHint(String min, String max);

  /// No description provided for @ratePreviewCashback.
  ///
  /// In en, this message translates to:
  /// **'Customer earns'**
  String get ratePreviewCashback;

  /// No description provided for @ratePreviewFee.
  ///
  /// In en, this message translates to:
  /// **'Platform fee'**
  String get ratePreviewFee;

  /// No description provided for @ratePreviewAllIn.
  ///
  /// In en, this message translates to:
  /// **'Your all-in cost'**
  String get ratePreviewAllIn;

  /// No description provided for @ratePreviewNote.
  ///
  /// In en, this message translates to:
  /// **'Estimate — the platform fee follows the published fee schedule.'**
  String get ratePreviewNote;

  /// No description provided for @errRateFormat.
  ///
  /// In en, this message translates to:
  /// **'Enter a rate like 2 or 2.5.'**
  String get errRateFormat;

  /// No description provided for @errRateMin.
  ///
  /// In en, this message translates to:
  /// **'The minimum is {min}.'**
  String errRateMin(String min);

  /// No description provided for @errRateMax.
  ///
  /// In en, this message translates to:
  /// **'The maximum is {max}.'**
  String errRateMax(String max);

  /// No description provided for @rateNotPricedTitle.
  ///
  /// In en, this message translates to:
  /// **'That rate isn\'t available'**
  String get rateNotPricedTitle;

  /// No description provided for @termsTitle.
  ///
  /// In en, this message translates to:
  /// **'What earns cashback'**
  String get termsTitle;

  /// No description provided for @termsLabel.
  ///
  /// In en, this message translates to:
  /// **'Eligible spending'**
  String get termsLabel;

  /// No description provided for @termsPlaceholder.
  ///
  /// In en, this message translates to:
  /// **'e.g. Everything in store except tobacco and top-ups.'**
  String get termsPlaceholder;

  /// No description provided for @termsGuidance.
  ///
  /// In en, this message translates to:
  /// **'Customers see this before they shop — be specific.'**
  String get termsGuidance;

  /// No description provided for @termsRequired.
  ///
  /// In en, this message translates to:
  /// **'Describe what earns cashback to continue.'**
  String get termsRequired;

  /// No description provided for @reviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Review & submit'**
  String get reviewTitle;

  /// No description provided for @reviewSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Check everything — Manfaa reviews your store before it goes live.'**
  String get reviewSubtitle;

  /// No description provided for @reviewName.
  ///
  /// In en, this message translates to:
  /// **'Store name'**
  String get reviewName;

  /// No description provided for @reviewCategory.
  ///
  /// In en, this message translates to:
  /// **'Category'**
  String get reviewCategory;

  /// No description provided for @reviewChannel.
  ///
  /// In en, this message translates to:
  /// **'Channel'**
  String get reviewChannel;

  /// No description provided for @reviewLocation.
  ///
  /// In en, this message translates to:
  /// **'Location'**
  String get reviewLocation;

  /// No description provided for @reviewLogo.
  ///
  /// In en, this message translates to:
  /// **'Logo'**
  String get reviewLogo;

  /// No description provided for @reviewRate.
  ///
  /// In en, this message translates to:
  /// **'Cashback rate'**
  String get reviewRate;

  /// No description provided for @reviewTerms.
  ///
  /// In en, this message translates to:
  /// **'What earns cashback'**
  String get reviewTerms;

  /// No description provided for @rateSummary.
  ///
  /// In en, this message translates to:
  /// **'{rate} · {fee} fee · {allIn} all-in'**
  String rateSummary(String rate, String fee, String allIn);

  /// No description provided for @noLocation.
  ///
  /// In en, this message translates to:
  /// **'No pin'**
  String get noLocation;

  /// No description provided for @noLogo.
  ///
  /// In en, this message translates to:
  /// **'No logo'**
  String get noLogo;

  /// No description provided for @submitForReview.
  ///
  /// In en, this message translates to:
  /// **'Submit for review'**
  String get submitForReview;

  /// No description provided for @missingTitle.
  ///
  /// In en, this message translates to:
  /// **'Still needed before review'**
  String get missingTitle;

  /// No description provided for @missingCategory.
  ///
  /// In en, this message translates to:
  /// **'Pick a category'**
  String get missingCategory;

  /// No description provided for @missingChannel.
  ///
  /// In en, this message translates to:
  /// **'Choose where you sell'**
  String get missingChannel;

  /// No description provided for @missingRate.
  ///
  /// In en, this message translates to:
  /// **'Set your cashback rate'**
  String get missingRate;

  /// No description provided for @missingTerms.
  ///
  /// In en, this message translates to:
  /// **'Describe what earns cashback'**
  String get missingTerms;

  /// No description provided for @edit.
  ///
  /// In en, this message translates to:
  /// **'Edit'**
  String get edit;

  /// No description provided for @errSetupNotEditable.
  ///
  /// In en, this message translates to:
  /// **'The store setup can\'t be edited while it\'s under review.'**
  String get errSetupNotEditable;

  /// No description provided for @rejectedTitle.
  ///
  /// In en, this message translates to:
  /// **'Changes requested'**
  String get rejectedTitle;

  /// No description provided for @rejectedBody.
  ///
  /// In en, this message translates to:
  /// **'Manfaa reviewed your store and asked for changes:'**
  String get rejectedBody;

  /// No description provided for @editAndResubmit.
  ///
  /// In en, this message translates to:
  /// **'Edit and resubmit'**
  String get editAndResubmit;

  /// No description provided for @pendingSubmittedAt.
  ///
  /// In en, this message translates to:
  /// **'Submitted {date}'**
  String pendingSubmittedAt(String date);

  /// No description provided for @staffSetupTitle.
  ///
  /// In en, this message translates to:
  /// **'Setup needs the owner'**
  String get staffSetupTitle;

  /// No description provided for @staffSetupBody.
  ///
  /// In en, this message translates to:
  /// **'This store hasn\'t finished setup, and your role can\'t edit it. Ask the store owner to finish it on their account.'**
  String get staffSetupBody;
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
