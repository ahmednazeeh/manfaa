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

  /// No description provided for @missingContact.
  ///
  /// In en, this message translates to:
  /// **'Add a contact phone number'**
  String get missingContact;

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

  /// No description provided for @cancel.
  ///
  /// In en, this message translates to:
  /// **'Cancel'**
  String get cancel;

  /// No description provided for @save.
  ///
  /// In en, this message translates to:
  /// **'Save'**
  String get save;

  /// No description provided for @optionalLabel.
  ///
  /// In en, this message translates to:
  /// **'optional'**
  String get optionalLabel;

  /// No description provided for @creditTitle.
  ///
  /// In en, this message translates to:
  /// **'Credit customer'**
  String get creditTitle;

  /// No description provided for @creditSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Record a sale and credit the customer\'s cashback.'**
  String get creditSubtitle;

  /// No description provided for @modeEnterCode.
  ///
  /// In en, this message translates to:
  /// **'Enter code'**
  String get modeEnterCode;

  /// No description provided for @modeScanQr.
  ///
  /// In en, this message translates to:
  /// **'Scan QR'**
  String get modeScanQr;

  /// No description provided for @modeRecent.
  ///
  /// In en, this message translates to:
  /// **'Recent'**
  String get modeRecent;

  /// No description provided for @codeAskHint.
  ///
  /// In en, this message translates to:
  /// **'Ask the customer for the 6-digit code shown in their Manfaa app.'**
  String get codeAskHint;

  /// No description provided for @lookupChecking.
  ///
  /// In en, this message translates to:
  /// **'Checking code…'**
  String get lookupChecking;

  /// No description provided for @lookupVerified.
  ///
  /// In en, this message translates to:
  /// **'Verified'**
  String get lookupVerified;

  /// No description provided for @lookupNotFoundTitle.
  ///
  /// In en, this message translates to:
  /// **'We don\'t recognise this code'**
  String get lookupNotFoundTitle;

  /// No description provided for @lookupNotFoundBody.
  ///
  /// In en, this message translates to:
  /// **'Check the digits with the customer before trying again — a typo here credits a stranger.'**
  String get lookupNotFoundBody;

  /// No description provided for @lookupFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not check this code — try again.'**
  String get lookupFailed;

  /// No description provided for @scanPrompt.
  ///
  /// In en, this message translates to:
  /// **'Point the camera at the QR code in the customer\'s Manfaa app.'**
  String get scanPrompt;

  /// No description provided for @scanDenied.
  ///
  /// In en, this message translates to:
  /// **'Camera access is blocked. Allow the camera in your phone\'s settings, or type the 6-digit code.'**
  String get scanDenied;

  /// No description provided for @scanFallbackHint.
  ///
  /// In en, this message translates to:
  /// **'You can always type the code by hand.'**
  String get scanFallbackHint;

  /// No description provided for @recentEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No recent customers'**
  String get recentEmptyTitle;

  /// No description provided for @recentEmptyBody.
  ///
  /// In en, this message translates to:
  /// **'Customers you credit appear here for quick reuse.'**
  String get recentEmptyBody;

  /// No description provided for @invoiceLabel.
  ///
  /// In en, this message translates to:
  /// **'Invoice number'**
  String get invoiceLabel;

  /// No description provided for @invoiceHint.
  ///
  /// In en, this message translates to:
  /// **'Required — one credit per invoice, exactly as printed on the receipt.'**
  String get invoiceHint;

  /// No description provided for @saleDateTimeLabel.
  ///
  /// In en, this message translates to:
  /// **'Sale date & time'**
  String get saleDateTimeLabel;

  /// No description provided for @saleDateTimeNow.
  ///
  /// In en, this message translates to:
  /// **'Recorded as right now unless you change it.'**
  String get saleDateTimeNow;

  /// No description provided for @saleDateTimeEdited.
  ///
  /// In en, this message translates to:
  /// **'Will be recorded at the time shown.'**
  String get saleDateTimeEdited;

  /// No description provided for @saleDateTimeSetNow.
  ///
  /// In en, this message translates to:
  /// **'Set to now'**
  String get saleDateTimeSetNow;

  /// No description provided for @eligibleLabel.
  ///
  /// In en, this message translates to:
  /// **'Eligible amount'**
  String get eligibleLabel;

  /// No description provided for @eligibleHint.
  ///
  /// In en, this message translates to:
  /// **'The part of the bill cashback is computed on, per your agreement.'**
  String get eligibleHint;

  /// No description provided for @eligibleInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter a valid amount, e.g. 1,250.00.'**
  String get eligibleInvalid;

  /// No description provided for @fullSaleLabel.
  ///
  /// In en, this message translates to:
  /// **'Full sale amount'**
  String get fullSaleLabel;

  /// No description provided for @fullSaleHint.
  ///
  /// In en, this message translates to:
  /// **'The whole invoice total — reference only, never used in computation.'**
  String get fullSaleHint;

  /// No description provided for @fullSaleInvalid.
  ///
  /// In en, this message translates to:
  /// **'Must be a valid amount, at least the eligible amount.'**
  String get fullSaleInvalid;

  /// No description provided for @customRateToggle.
  ///
  /// In en, this message translates to:
  /// **'Custom cashback for this sale'**
  String get customRateToggle;

  /// No description provided for @customRateToggleHint.
  ///
  /// In en, this message translates to:
  /// **'Pay this customer more than your usual rate.'**
  String get customRateToggleHint;

  /// No description provided for @customRateLabel.
  ///
  /// In en, this message translates to:
  /// **'Cashback for this sale'**
  String get customRateLabel;

  /// No description provided for @customRateHint.
  ///
  /// In en, this message translates to:
  /// **'Must be {rate} or higher — you can only raise your advertised rate, never lower it. The customer is told the higher figure.'**
  String customRateHint(String rate);

  /// No description provided for @customRateHintNoRate.
  ///
  /// In en, this message translates to:
  /// **'Your store has no cashback rate in effect yet — contact Manfaa before crediting customers.'**
  String get customRateHintNoRate;

  /// No description provided for @customRateFormat.
  ///
  /// In en, this message translates to:
  /// **'Enter a percent with up to two decimal places, e.g. 7.50.'**
  String get customRateFormat;

  /// No description provided for @customRateRange.
  ///
  /// In en, this message translates to:
  /// **'Cashback runs from {min} to {max}.'**
  String customRateRange(String min, String max);

  /// No description provided for @customRateTooLow.
  ///
  /// In en, this message translates to:
  /// **'This sale already earns {rate}. A custom rate can only be higher — your advertised rate is a promise to the customer.'**
  String customRateTooLow(String rate);

  /// No description provided for @customRateRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'That rate is lower than this sale already earns.'**
  String get customRateRefusedTitle;

  /// No description provided for @customRateRefusedBody.
  ///
  /// In en, this message translates to:
  /// **'This sale earns {rate} — your standing rate, or a promotion running right now. Enter a higher rate, or switch the custom rate off.'**
  String customRateRefusedBody(String rate);

  /// No description provided for @customRateRefusedBodyNoRate.
  ///
  /// In en, this message translates to:
  /// **'A custom rate can only raise what this sale already earns. Enter a higher rate, or switch the custom rate off.'**
  String get customRateRefusedBodyNoRate;

  /// No description provided for @customRateNotPricedTitle.
  ///
  /// In en, this message translates to:
  /// **'That rate isn\'t available yet.'**
  String get customRateNotPricedTitle;

  /// No description provided for @splitToggle.
  ///
  /// In en, this message translates to:
  /// **'Split by category'**
  String get splitToggle;

  /// No description provided for @splitToggleHint.
  ///
  /// In en, this message translates to:
  /// **'Split this sale into product categories.'**
  String get splitToggleHint;

  /// No description provided for @splitBreakdownTitle.
  ///
  /// In en, this message translates to:
  /// **'Category breakdown'**
  String get splitBreakdownTitle;

  /// No description provided for @splitAddCategory.
  ///
  /// In en, this message translates to:
  /// **'Add category'**
  String get splitAddCategory;

  /// No description provided for @splitEverythingElse.
  ///
  /// In en, this message translates to:
  /// **'Everything else'**
  String get splitEverythingElse;

  /// No description provided for @splitEditLine.
  ///
  /// In en, this message translates to:
  /// **'Edit line'**
  String get splitEditLine;

  /// No description provided for @splitRemoveLine.
  ///
  /// In en, this message translates to:
  /// **'Remove line'**
  String get splitRemoveLine;

  /// No description provided for @splitDialogTitleAdd.
  ///
  /// In en, this message translates to:
  /// **'Add category amount'**
  String get splitDialogTitleAdd;

  /// No description provided for @splitDialogTitleEdit.
  ///
  /// In en, this message translates to:
  /// **'Edit category amount'**
  String get splitDialogTitleEdit;

  /// No description provided for @splitCategoryLabel.
  ///
  /// In en, this message translates to:
  /// **'Category'**
  String get splitCategoryLabel;

  /// No description provided for @splitAmountLabel.
  ///
  /// In en, this message translates to:
  /// **'Amount'**
  String get splitAmountLabel;

  /// No description provided for @splitAmountInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter a valid amount of at least MVR 0.01.'**
  String get splitAmountInvalid;

  /// No description provided for @splitExcludedNote.
  ///
  /// In en, this message translates to:
  /// **'No cashback — still part of the total.'**
  String get splitExcludedNote;

  /// No description provided for @splitEmptyHint.
  ///
  /// In en, this message translates to:
  /// **'Add the sale\'s parts — the lines must add up to the eligible amount, excluded categories included.'**
  String get splitEmptyHint;

  /// No description provided for @splitOtherChip.
  ///
  /// In en, this message translates to:
  /// **'Other'**
  String get splitOtherChip;

  /// No description provided for @splitLinesTotal.
  ///
  /// In en, this message translates to:
  /// **'Lines total'**
  String get splitLinesTotal;

  /// No description provided for @splitMismatchTitle.
  ///
  /// In en, this message translates to:
  /// **'The lines don\'t add up to the eligible amount.'**
  String get splitMismatchTitle;

  /// No description provided for @splitMismatchBody.
  ///
  /// In en, this message translates to:
  /// **'Difference: {difference}. Every part of the bill goes in, including the excluded ones — the credit can\'t be recorded until the two match.'**
  String splitMismatchBody(String difference);

  /// No description provided for @costPreviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Cost preview'**
  String get costPreviewTitle;

  /// No description provided for @previewCashback.
  ///
  /// In en, this message translates to:
  /// **'Customer cashback ({rate})'**
  String previewCashback(String rate);

  /// No description provided for @previewFee.
  ///
  /// In en, this message translates to:
  /// **'Platform fee ({rate})'**
  String previewFee(String rate);

  /// No description provided for @previewYouPay.
  ///
  /// In en, this message translates to:
  /// **'You pay ({rate})'**
  String previewYouPay(String rate);

  /// No description provided for @previewPerLine.
  ///
  /// In en, this message translates to:
  /// **'per line'**
  String get previewPerLine;

  /// No description provided for @previewEstimateNote.
  ///
  /// In en, this message translates to:
  /// **'Estimate — final amounts use the rate at the sale time.'**
  String get previewEstimateNote;

  /// No description provided for @previewNoRate.
  ///
  /// In en, this message translates to:
  /// **'No cashback rate is in effect yet — contact Manfaa before crediting customers.'**
  String get previewNoRate;

  /// No description provided for @previewUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Your current rate is unavailable right now — the credit still uses the correct rate on the server.'**
  String get previewUnavailable;

  /// No description provided for @backdatedTitle.
  ///
  /// In en, this message translates to:
  /// **'Backdated sale — this credit is final'**
  String get backdatedTitle;

  /// No description provided for @backdatedBody.
  ///
  /// In en, this message translates to:
  /// **'This sale is older than your refund window — once credited it cannot be reversed, and it becomes payable to Manfaa immediately.'**
  String get backdatedBody;

  /// No description provided for @backdatedMaybeTitle.
  ///
  /// In en, this message translates to:
  /// **'This sale may be backdated'**
  String get backdatedMaybeTitle;

  /// No description provided for @backdatedMaybeBody.
  ///
  /// In en, this message translates to:
  /// **'This sale may be older than your refund window. If it is, once credited it cannot be reversed, and it becomes payable to Manfaa immediately.'**
  String get backdatedMaybeBody;

  /// No description provided for @backdatedConfirm.
  ///
  /// In en, this message translates to:
  /// **'I understand: this credit cannot be reversed and is payable now.'**
  String get backdatedConfirm;

  /// No description provided for @backdatedConfirmRequired.
  ///
  /// In en, this message translates to:
  /// **'Confirm you understand before recording this credit.'**
  String get backdatedConfirmRequired;

  /// No description provided for @backdatedResultTitle.
  ///
  /// In en, this message translates to:
  /// **'Credited — final and payable now'**
  String get backdatedResultTitle;

  /// No description provided for @backdatedResultBody.
  ///
  /// In en, this message translates to:
  /// **'This backdated sale skipped the refund window. The customer\'s cashback is payable to Manfaa immediately, and neither you nor your POS can reverse it — contact Manfaa if it needs correcting.'**
  String get backdatedResultBody;

  /// No description provided for @pendingNote.
  ///
  /// In en, this message translates to:
  /// **'Credited cashback will appear as pending. It will be validated and released to the customer after settlement.'**
  String get pendingNote;

  /// No description provided for @creditCta.
  ///
  /// In en, this message translates to:
  /// **'Credit customer'**
  String get creditCta;

  /// No description provided for @errDuplicateInvoiceTitle.
  ///
  /// In en, this message translates to:
  /// **'This invoice is already credited.'**
  String get errDuplicateInvoiceTitle;

  /// No description provided for @errDuplicateInvoiceBody.
  ///
  /// In en, this message translates to:
  /// **'Each invoice can be credited once. If this is a different sale, check the invoice number on the receipt.'**
  String get errDuplicateInvoiceBody;

  /// No description provided for @errSuspendedTitle.
  ///
  /// In en, this message translates to:
  /// **'Your store is suspended — new cashback is paused.'**
  String get errSuspendedTitle;

  /// No description provided for @errSuspendedBody.
  ///
  /// In en, this message translates to:
  /// **'Settle your outstanding balance to resume crediting customers, or contact Manfaa if you believe this is wrong.'**
  String get errSuspendedBody;

  /// No description provided for @errCreditFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not record the credit.'**
  String get errCreditFailed;

  /// No description provided for @queuedBannerTitle.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 sale waiting to sync} other{{count} sales waiting to sync}}'**
  String queuedBannerTitle(int count);

  /// No description provided for @queuedBannerBody.
  ///
  /// In en, this message translates to:
  /// **'They\'ll be sent automatically when you\'re back online.'**
  String get queuedBannerBody;

  /// No description provided for @queuedBannerAction.
  ///
  /// In en, this message translates to:
  /// **'Sync now'**
  String get queuedBannerAction;

  /// No description provided for @queuedSnack.
  ///
  /// In en, this message translates to:
  /// **'No connection — the sale is saved and will sync automatically.'**
  String get queuedSnack;

  /// No description provided for @attentionTitle.
  ///
  /// In en, this message translates to:
  /// **'Needs attention'**
  String get attentionTitle;

  /// No description provided for @attentionBody.
  ///
  /// In en, this message translates to:
  /// **'These queued sales were refused when they synced — review each one.'**
  String get attentionBody;

  /// No description provided for @attentionRetry.
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get attentionRetry;

  /// No description provided for @attentionDiscard.
  ///
  /// In en, this message translates to:
  /// **'Dismiss'**
  String get attentionDiscard;

  /// No description provided for @resultRecordedTitle.
  ///
  /// In en, this message translates to:
  /// **'Cashback recorded'**
  String get resultRecordedTitle;

  /// No description provided for @resultBelowMinTitle.
  ///
  /// In en, this message translates to:
  /// **'Recorded — no reward'**
  String get resultBelowMinTitle;

  /// No description provided for @resultBelowMinBody.
  ///
  /// In en, this message translates to:
  /// **'This sale is below your store\'s minimum eligible amount. It was recorded with zero cashback — the customer earns nothing on it and nothing is payable.'**
  String get resultBelowMinBody;

  /// No description provided for @resultOnHoldTitle.
  ///
  /// In en, this message translates to:
  /// **'Recorded — under review'**
  String get resultOnHoldTitle;

  /// No description provided for @resultOnHoldBody.
  ///
  /// In en, this message translates to:
  /// **'The sale is on hold while Manfaa checks it. It counts for the customer once the review clears it; nothing else is needed from you.'**
  String get resultOnHoldBody;

  /// No description provided for @resultReplayedNote.
  ///
  /// In en, this message translates to:
  /// **'This sale was already recorded earlier — this is the original result.'**
  String get resultReplayedNote;

  /// No description provided for @resultCustomer.
  ///
  /// In en, this message translates to:
  /// **'Customer'**
  String get resultCustomer;

  /// No description provided for @resultInvoice.
  ///
  /// In en, this message translates to:
  /// **'Invoice'**
  String get resultInvoice;

  /// No description provided for @resultState.
  ///
  /// In en, this message translates to:
  /// **'State'**
  String get resultState;

  /// No description provided for @resultEligible.
  ///
  /// In en, this message translates to:
  /// **'Eligible amount'**
  String get resultEligible;

  /// No description provided for @resultYouPay.
  ///
  /// In en, this message translates to:
  /// **'You pay'**
  String get resultYouPay;

  /// No description provided for @resultLinesTitle.
  ///
  /// In en, this message translates to:
  /// **'Priced lines'**
  String get resultLinesTitle;

  /// No description provided for @creditAnother.
  ///
  /// In en, this message translates to:
  /// **'Credit another customer'**
  String get creditAnother;

  /// No description provided for @stateTracked.
  ///
  /// In en, this message translates to:
  /// **'Recorded'**
  String get stateTracked;

  /// No description provided for @stateAwaitingValidation.
  ///
  /// In en, this message translates to:
  /// **'In refund window'**
  String get stateAwaitingValidation;

  /// No description provided for @statePayableUnfunded.
  ///
  /// In en, this message translates to:
  /// **'Payable to Manfaa'**
  String get statePayableUnfunded;

  /// No description provided for @stateOnHold.
  ///
  /// In en, this message translates to:
  /// **'Under review'**
  String get stateOnHold;

  /// No description provided for @stateConfirmed.
  ///
  /// In en, this message translates to:
  /// **'Confirmed'**
  String get stateConfirmed;

  /// No description provided for @statePaid.
  ///
  /// In en, this message translates to:
  /// **'Paid to customer'**
  String get statePaid;

  /// No description provided for @stateReversed.
  ///
  /// In en, this message translates to:
  /// **'Reversed'**
  String get stateReversed;

  /// No description provided for @stateWrittenOff.
  ///
  /// In en, this message translates to:
  /// **'Written off — unpaid'**
  String get stateWrittenOff;

  /// No description provided for @stateOther.
  ///
  /// In en, this message translates to:
  /// **'Updated'**
  String get stateOther;

  /// No description provided for @reasonAutoValidation.
  ///
  /// In en, this message translates to:
  /// **'Validated automatically'**
  String get reasonAutoValidation;

  /// No description provided for @reasonBackdatedFinal.
  ///
  /// In en, this message translates to:
  /// **'Backdated — cannot be reversed'**
  String get reasonBackdatedFinal;

  /// No description provided for @reasonBelowMinimum.
  ///
  /// In en, this message translates to:
  /// **'Below minimum sale'**
  String get reasonBelowMinimum;

  /// No description provided for @reasonMerchantSuspended.
  ///
  /// In en, this message translates to:
  /// **'Store suspended'**
  String get reasonMerchantSuspended;

  /// No description provided for @reasonSettlementAllocated.
  ///
  /// In en, this message translates to:
  /// **'Paid by store'**
  String get reasonSettlementAllocated;

  /// No description provided for @reasonPayoutCompleted.
  ///
  /// In en, this message translates to:
  /// **'Paid out'**
  String get reasonPayoutCompleted;

  /// No description provided for @reasonCustomerRefund.
  ///
  /// In en, this message translates to:
  /// **'Refunded'**
  String get reasonCustomerRefund;

  /// No description provided for @reasonTillVoid.
  ///
  /// In en, this message translates to:
  /// **'Voided at the till'**
  String get reasonTillVoid;

  /// No description provided for @reasonDuplicate.
  ///
  /// In en, this message translates to:
  /// **'Duplicate sale'**
  String get reasonDuplicate;

  /// No description provided for @reasonOtherCorrected.
  ///
  /// In en, this message translates to:
  /// **'Corrected — other reason'**
  String get reasonOtherCorrected;

  /// No description provided for @reasonStaleTimestamp.
  ///
  /// In en, this message translates to:
  /// **'Held — sale was backdated'**
  String get reasonStaleTimestamp;

  /// No description provided for @reasonUnknown.
  ///
  /// In en, this message translates to:
  /// **'Updated by Manfaa'**
  String get reasonUnknown;

  /// No description provided for @transactionsTitle.
  ///
  /// In en, this message translates to:
  /// **'Transactions'**
  String get transactionsTitle;

  /// No description provided for @filterAll.
  ///
  /// In en, this message translates to:
  /// **'All states'**
  String get filterAll;

  /// No description provided for @txEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No transactions yet'**
  String get txEmptyTitle;

  /// No description provided for @txEmptyBody.
  ///
  /// In en, this message translates to:
  /// **'Sales you credit appear here.'**
  String get txEmptyBody;

  /// No description provided for @txEmptyFiltered.
  ///
  /// In en, this message translates to:
  /// **'No transactions match this filter.'**
  String get txEmptyFiltered;

  /// No description provided for @backdatedChip.
  ///
  /// In en, this message translates to:
  /// **'Backdated'**
  String get backdatedChip;

  /// No description provided for @amendAction.
  ///
  /// In en, this message translates to:
  /// **'Correct amount'**
  String get amendAction;

  /// No description provided for @cancelAction.
  ///
  /// In en, this message translates to:
  /// **'Cancel sale'**
  String get cancelAction;

  /// No description provided for @amendTitle.
  ///
  /// In en, this message translates to:
  /// **'Correct the amount'**
  String get amendTitle;

  /// No description provided for @amendBody.
  ///
  /// In en, this message translates to:
  /// **'Fix what was rung up on {invoiceNo}. The cashback is recalculated at the same rate the sale was recorded under.'**
  String amendBody(String invoiceNo);

  /// No description provided for @amendEligibleLabel.
  ///
  /// In en, this message translates to:
  /// **'Eligible amount'**
  String get amendEligibleLabel;

  /// No description provided for @amendHint.
  ///
  /// In en, this message translates to:
  /// **'The part of the bill cashback is computed on, as it should have been.'**
  String get amendHint;

  /// No description provided for @amendLinesLabel.
  ///
  /// In en, this message translates to:
  /// **'Category amounts'**
  String get amendLinesLabel;

  /// No description provided for @amendLinesHint.
  ///
  /// In en, this message translates to:
  /// **'The eligible amount adds up from these lines:'**
  String get amendLinesHint;

  /// No description provided for @amendCurrent.
  ///
  /// In en, this message translates to:
  /// **'Currently {amount} · cashback {cashback}'**
  String amendCurrent(String amount, String cashback);

  /// No description provided for @amendSubmit.
  ///
  /// In en, this message translates to:
  /// **'Save correction'**
  String get amendSubmit;

  /// No description provided for @amendDone.
  ///
  /// In en, this message translates to:
  /// **'Amount corrected.'**
  String get amendDone;

  /// No description provided for @amendFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not correct the amount.'**
  String get amendFailed;

  /// No description provided for @cancelTitle.
  ///
  /// In en, this message translates to:
  /// **'Cancel this sale'**
  String get cancelTitle;

  /// No description provided for @cancelBody.
  ///
  /// In en, this message translates to:
  /// **'{invoiceNo} comes off entirely and the cashback is taken back.'**
  String cancelBody(String invoiceNo);

  /// No description provided for @cancelReasonLabel.
  ///
  /// In en, this message translates to:
  /// **'Reason'**
  String get cancelReasonLabel;

  /// No description provided for @cancelNoteLabel.
  ///
  /// In en, this message translates to:
  /// **'Note (optional)'**
  String get cancelNoteLabel;

  /// No description provided for @cancelWarning.
  ///
  /// In en, this message translates to:
  /// **'The customer has already been told they earned this. Cancelling removes it from their balance.'**
  String get cancelWarning;

  /// No description provided for @cancelSubmit.
  ///
  /// In en, this message translates to:
  /// **'Cancel sale'**
  String get cancelSubmit;

  /// No description provided for @cancelDone.
  ///
  /// In en, this message translates to:
  /// **'Sale cancelled.'**
  String get cancelDone;

  /// No description provided for @cancelFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not cancel the sale.'**
  String get cancelFailed;

  /// No description provided for @keepSale.
  ///
  /// In en, this message translates to:
  /// **'Keep sale'**
  String get keepSale;

  /// No description provided for @cancelReasonRefund.
  ///
  /// In en, this message translates to:
  /// **'Refunded'**
  String get cancelReasonRefund;

  /// No description provided for @cancelReasonVoid.
  ///
  /// In en, this message translates to:
  /// **'Voided at the till'**
  String get cancelReasonVoid;

  /// No description provided for @cancelReasonDuplicate.
  ///
  /// In en, this message translates to:
  /// **'Duplicate entry'**
  String get cancelReasonDuplicate;

  /// No description provided for @cancelReasonError.
  ///
  /// In en, this message translates to:
  /// **'Keyed in error'**
  String get cancelReasonError;

  /// No description provided for @errNotAmendable.
  ///
  /// In en, this message translates to:
  /// **'This sale can no longer be changed — its window has closed.'**
  String get errNotAmendable;

  /// No description provided for @errBackdatedIrreversible.
  ///
  /// In en, this message translates to:
  /// **'Backdated credits can never be reversed.'**
  String get errBackdatedIrreversible;

  /// No description provided for @todayTitle.
  ///
  /// In en, this message translates to:
  /// **'Today'**
  String get todayTitle;

  /// No description provided for @todayCredits.
  ///
  /// In en, this message translates to:
  /// **'Credits'**
  String get todayCredits;

  /// No description provided for @todayEligible.
  ///
  /// In en, this message translates to:
  /// **'Eligible sales'**
  String get todayEligible;

  /// No description provided for @todayCashback.
  ///
  /// In en, this message translates to:
  /// **'Cashback'**
  String get todayCashback;
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
