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

  /// No description provided for @descriptionLabel.
  ///
  /// In en, this message translates to:
  /// **'Store description'**
  String get descriptionLabel;

  /// No description provided for @descriptionPlaceholder.
  ///
  /// In en, this message translates to:
  /// **'e.g. A neighbourhood grocery stocking fresh produce, household basics and the daily essentials.'**
  String get descriptionPlaceholder;

  /// No description provided for @descriptionHint.
  ///
  /// In en, this message translates to:
  /// **'Up to 180 words — shoppers read this on your store page.'**
  String get descriptionHint;

  /// No description provided for @descriptionRequired.
  ///
  /// In en, this message translates to:
  /// **'Describe your store to continue.'**
  String get descriptionRequired;

  /// No description provided for @descriptionTooLong.
  ///
  /// In en, this message translates to:
  /// **'That is over 180 words. Shorten it to continue.'**
  String get descriptionTooLong;

  /// No description provided for @descriptionWordCount.
  ///
  /// In en, this message translates to:
  /// **'{count} / {max} words'**
  String descriptionWordCount(int count, int max);

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

  /// No description provided for @reviewDescription.
  ///
  /// In en, this message translates to:
  /// **'Description'**
  String get reviewDescription;

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

  /// No description provided for @missingDescription.
  ///
  /// In en, this message translates to:
  /// **'Describe your store'**
  String get missingDescription;

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

  /// No description provided for @eligibleLabel.
  ///
  /// In en, this message translates to:
  /// **'Eligible amount'**
  String get eligibleLabel;

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
  /// **'Estimate — priced at the sale time.'**
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
  /// **'Cashback stays pending until settlement.'**
  String get pendingNote;

  /// No description provided for @creditCta.
  ///
  /// In en, this message translates to:
  /// **'Credit customer'**
  String get creditCta;

  /// No description provided for @creditCtaShort.
  ///
  /// In en, this message translates to:
  /// **'Credit now'**
  String get creditCtaShort;

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

  /// No description provided for @dashOutstandingTitle.
  ///
  /// In en, this message translates to:
  /// **'Outstanding to settle'**
  String get dashOutstandingTitle;

  /// No description provided for @dashOutstandingSub.
  ///
  /// In en, this message translates to:
  /// **'Cashback owed to platform'**
  String get dashOutstandingSub;

  /// No description provided for @settleNow.
  ///
  /// In en, this message translates to:
  /// **'Settle now'**
  String get settleNow;

  /// No description provided for @discountDeadlineTitle.
  ///
  /// In en, this message translates to:
  /// **'Your oldest sale stops earning the {rate} prompt-payment discount on {date}'**
  String discountDeadlineTitle(String rate, String date);

  /// No description provided for @discountDeadlineBody.
  ///
  /// In en, this message translates to:
  /// **'Settle everything outstanding before then and save {amount}'**
  String discountDeadlineBody(String amount);

  /// No description provided for @bucketOverdue.
  ///
  /// In en, this message translates to:
  /// **'Overdue'**
  String get bucketOverdue;

  /// No description provided for @bucketTransactions.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 transaction} other{{count} transactions}}'**
  String bucketTransactions(int count);

  /// No description provided for @payableBreakdownTitle.
  ///
  /// In en, this message translates to:
  /// **'Payable breakdown'**
  String get payableBreakdownTitle;

  /// No description provided for @payableCashback.
  ///
  /// In en, this message translates to:
  /// **'Customer cashback'**
  String get payableCashback;

  /// No description provided for @payableFee.
  ///
  /// In en, this message translates to:
  /// **'Platform fee'**
  String get payableFee;

  /// No description provided for @payableGst.
  ///
  /// In en, this message translates to:
  /// **'GST on fee'**
  String get payableGst;

  /// No description provided for @payableOutstandingCount.
  ///
  /// In en, this message translates to:
  /// **'Outstanding transactions'**
  String get payableOutstandingCount;

  /// No description provided for @payablePendingCredit.
  ///
  /// In en, this message translates to:
  /// **'Pending adjustment credit'**
  String get payablePendingCredit;

  /// No description provided for @walletCardTitle.
  ///
  /// In en, this message translates to:
  /// **'Wallet'**
  String get walletCardTitle;

  /// No description provided for @walletCardHint.
  ///
  /// In en, this message translates to:
  /// **'Available to fund settlements instead of a bank transfer.'**
  String get walletCardHint;

  /// No description provided for @walletViewMovements.
  ///
  /// In en, this message translates to:
  /// **'View movements'**
  String get walletViewMovements;

  /// No description provided for @walletUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Wallet unavailable right now.'**
  String get walletUnavailable;

  /// No description provided for @creditCtaTitle.
  ///
  /// In en, this message translates to:
  /// **'Credit customer cashback'**
  String get creditCtaTitle;

  /// No description provided for @creditCtaBody.
  ///
  /// In en, this message translates to:
  /// **'Issue cashback directly to your customers.'**
  String get creditCtaBody;

  /// No description provided for @discountReasonNotAll.
  ///
  /// In en, this message translates to:
  /// **'Settle everything outstanding to earn {rate} off the platform fee.'**
  String discountReasonNotAll(String rate);

  /// No description provided for @discountReasonTooOld.
  ///
  /// In en, this message translates to:
  /// **'One of these sales is {days} days or older, so no prompt-payment discount applies.'**
  String discountReasonTooOld(int days);

  /// No description provided for @discountReasonClockNotStarted.
  ///
  /// In en, this message translates to:
  /// **'One of these sales has no settlement date on record, so no prompt-payment discount applies — contact Manfaa to fix it.'**
  String get discountReasonClockNotStarted;

  /// No description provided for @discountNudgeTitle.
  ///
  /// In en, this message translates to:
  /// **'Settle everything outstanding for {rate} off the platform fee'**
  String discountNudgeTitle(String rate);

  /// No description provided for @discountNudgeSaving.
  ///
  /// In en, this message translates to:
  /// **'You would save {amount}'**
  String discountNudgeSaving(String amount);

  /// No description provided for @settlementsTitle.
  ///
  /// In en, this message translates to:
  /// **'Settlements'**
  String get settlementsTitle;

  /// No description provided for @settlementsSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Pay outstanding cashback and fees to Manfaa.'**
  String get settlementsSubtitle;

  /// No description provided for @amountDueNow.
  ///
  /// In en, this message translates to:
  /// **'Amount due now'**
  String get amountDueNow;

  /// No description provided for @outstandingTxCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 outstanding transaction} other{{count} outstanding transactions}}'**
  String outstandingTxCount(int count);

  /// No description provided for @payNow.
  ///
  /// In en, this message translates to:
  /// **'Pay now'**
  String get payNow;

  /// No description provided for @discountKeepTitle.
  ///
  /// In en, this message translates to:
  /// **'Pay within {days} days to keep your {rate} prompt-payment discount.'**
  String discountKeepTitle(int days, String rate);

  /// No description provided for @oldestDueDate.
  ///
  /// In en, this message translates to:
  /// **'Oldest due date: {date}'**
  String oldestDueDate(String date);

  /// No description provided for @paymentMethodTitle.
  ///
  /// In en, this message translates to:
  /// **'Payment method'**
  String get paymentMethodTitle;

  /// No description provided for @methodWalletTitle.
  ///
  /// In en, this message translates to:
  /// **'Wallet balance'**
  String get methodWalletTitle;

  /// No description provided for @methodBankTitle.
  ///
  /// In en, this message translates to:
  /// **'Bank transfer'**
  String get methodBankTitle;

  /// No description provided for @methodRecommended.
  ///
  /// In en, this message translates to:
  /// **'Recommended'**
  String get methodRecommended;

  /// No description provided for @totalDueLabel.
  ///
  /// In en, this message translates to:
  /// **'Total due'**
  String get totalDueLabel;

  /// No description provided for @includedTitle.
  ///
  /// In en, this message translates to:
  /// **'Included transactions'**
  String get includedTitle;

  /// No description provided for @pickerTitle.
  ///
  /// In en, this message translates to:
  /// **'Choose transactions'**
  String get pickerTitle;

  /// No description provided for @pickerLead.
  ///
  /// In en, this message translates to:
  /// **'Tick the sales you are paying for. Every amount here is what Manfaa has recorded against that sale.'**
  String get pickerLead;

  /// No description provided for @pickerRepriceTitle.
  ///
  /// In en, this message translates to:
  /// **'Changing the selection re-prices the batch'**
  String get pickerRepriceTitle;

  /// No description provided for @pickerRepriceBody.
  ///
  /// In en, this message translates to:
  /// **'Manfaa prices the new selection on its own server. A narrower batch can lose the prompt-payment discount.'**
  String get pickerRepriceBody;

  /// No description provided for @pickerSelectAll.
  ///
  /// In en, this message translates to:
  /// **'Select all shown'**
  String get pickerSelectAll;

  /// No description provided for @pickerNoneInFilter.
  ///
  /// In en, this message translates to:
  /// **'No transactions in this filter.'**
  String get pickerNoneInFilter;

  /// No description provided for @pickerPastWindow.
  ///
  /// In en, this message translates to:
  /// **'Too old for the discount'**
  String get pickerPastWindow;

  /// No description provided for @pickerAgeDays.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 day old} other{{count} days old}}'**
  String pickerAgeDays(int count);

  /// No description provided for @pickerSelectedCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 transaction selected} other{{count} transactions selected}}'**
  String pickerSelectedCount(int count);

  /// No description provided for @pickerTotalHint.
  ///
  /// In en, this message translates to:
  /// **'Credits and any discount are applied when Manfaa prices the batch.'**
  String get pickerTotalHint;

  /// No description provided for @pickerApplyCta.
  ///
  /// In en, this message translates to:
  /// **'Use this selection'**
  String get pickerApplyCta;

  /// No description provided for @awaitingSettlementChip.
  ///
  /// In en, this message translates to:
  /// **'Awaiting settlement'**
  String get awaitingSettlementChip;

  /// No description provided for @feeShort.
  ///
  /// In en, this message translates to:
  /// **'Fee'**
  String get feeShort;

  /// No description provided for @discountRow.
  ///
  /// In en, this message translates to:
  /// **'Prompt payment discount ({rate})'**
  String discountRow(String rate);

  /// No description provided for @discountAdvisoryNote.
  ///
  /// In en, this message translates to:
  /// **'Confirmed when you submit the slip. If a sale reaches {days} days before then, the full fee is due.'**
  String discountAdvisoryNote(int days);

  /// No description provided for @creditAppliedRow.
  ///
  /// In en, this message translates to:
  /// **'Credit applied'**
  String get creditAppliedRow;

  /// No description provided for @creditAppliedHint.
  ///
  /// In en, this message translates to:
  /// **'Credit from earlier adjustments, netted off this batch.'**
  String get creditAppliedHint;

  /// No description provided for @recentTitle.
  ///
  /// In en, this message translates to:
  /// **'Recent settlements'**
  String get recentTitle;

  /// No description provided for @payAmountCta.
  ///
  /// In en, this message translates to:
  /// **'Pay {amount}'**
  String payAmountCta(String amount);

  /// No description provided for @confirmNothingDueCta.
  ///
  /// In en, this message translates to:
  /// **'Confirm settlement'**
  String get confirmNothingDueCta;

  /// No description provided for @nothingDueTitle.
  ///
  /// In en, this message translates to:
  /// **'Your credit covers this fully'**
  String get nothingDueTitle;

  /// No description provided for @nothingDueBody.
  ///
  /// In en, this message translates to:
  /// **'There is nothing to transfer. Confirming closes these transactions against your credit.'**
  String get nothingDueBody;

  /// No description provided for @presetOlder5.
  ///
  /// In en, this message translates to:
  /// **'Older than 5 days'**
  String get presetOlder5;

  /// No description provided for @presetOlder10.
  ///
  /// In en, this message translates to:
  /// **'Older than 10 days'**
  String get presetOlder10;

  /// No description provided for @settleEverythingCta.
  ///
  /// In en, this message translates to:
  /// **'Settle everything outstanding'**
  String get settleEverythingCta;

  /// No description provided for @emptySettleTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing to settle right now'**
  String get emptySettleTitle;

  /// No description provided for @emptySettleBody.
  ///
  /// In en, this message translates to:
  /// **'Transactions appear here when their validation window ends.'**
  String get emptySettleBody;

  /// No description provided for @noSettlementsYet.
  ///
  /// In en, this message translates to:
  /// **'No settlements yet.'**
  String get noSettlementsYet;

  /// No description provided for @settlePreviewFailed.
  ///
  /// In en, this message translates to:
  /// **'Couldn\'t price this selection.'**
  String get settlePreviewFailed;

  /// No description provided for @payBankTitle.
  ///
  /// In en, this message translates to:
  /// **'Transfer exactly this amount'**
  String get payBankTitle;

  /// No description provided for @amountToTransfer.
  ///
  /// In en, this message translates to:
  /// **'Amount to transfer'**
  String get amountToTransfer;

  /// No description provided for @referenceLabel.
  ///
  /// In en, this message translates to:
  /// **'Payment reference'**
  String get referenceLabel;

  /// No description provided for @referenceFinalNote.
  ///
  /// In en, this message translates to:
  /// **'Quote this reference with your transfer — it is how we recognise your money.'**
  String get referenceFinalNote;

  /// No description provided for @transferToLabel.
  ///
  /// In en, this message translates to:
  /// **'Transfer to'**
  String get transferToLabel;

  /// No description provided for @bankNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Bank'**
  String get bankNameLabel;

  /// No description provided for @accountNoLabel.
  ///
  /// In en, this message translates to:
  /// **'Account number'**
  String get accountNoLabel;

  /// No description provided for @accountNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Account name'**
  String get accountNameLabel;

  /// No description provided for @copyTooltip.
  ///
  /// In en, this message translates to:
  /// **'Copy'**
  String get copyTooltip;

  /// No description provided for @copiedToast.
  ///
  /// In en, this message translates to:
  /// **'Copied'**
  String get copiedToast;

  /// No description provided for @chooseBankLabel.
  ///
  /// In en, this message translates to:
  /// **'Choose the bank you\'ll transfer to'**
  String get chooseBankLabel;

  /// No description provided for @chooseBankFirst.
  ///
  /// In en, this message translates to:
  /// **'Pick a bank above to see where to send the money.'**
  String get chooseBankFirst;

  /// No description provided for @noAccountTitle.
  ///
  /// In en, this message translates to:
  /// **'Transfer details not published yet'**
  String get noAccountTitle;

  /// No description provided for @noAccountBody.
  ///
  /// In en, this message translates to:
  /// **'Contact Manfaa for the account to send to. Nothing is lost — come back with the slip after you transfer.'**
  String get noAccountBody;

  /// No description provided for @uploadSlipTitle.
  ///
  /// In en, this message translates to:
  /// **'Upload the transfer slip'**
  String get uploadSlipTitle;

  /// No description provided for @slipTakePhoto.
  ///
  /// In en, this message translates to:
  /// **'Take photo'**
  String get slipTakePhoto;

  /// No description provided for @slipChooseFile.
  ///
  /// In en, this message translates to:
  /// **'Choose file'**
  String get slipChooseFile;

  /// No description provided for @slipHint.
  ///
  /// In en, this message translates to:
  /// **'JPEG, PNG, WebP or PDF — up to 5 MB'**
  String get slipHint;

  /// No description provided for @slipTooLarge.
  ///
  /// In en, this message translates to:
  /// **'That file is over 5 MB. Take a smaller photo of the slip, or upload the bank\'s PDF.'**
  String get slipTooLarge;

  /// No description provided for @slipUnsupported.
  ///
  /// In en, this message translates to:
  /// **'The slip must be a JPEG, PNG, WebP or PDF file.'**
  String get slipUnsupported;

  /// No description provided for @slipRequired.
  ///
  /// In en, this message translates to:
  /// **'Attach the transfer slip.'**
  String get slipRequired;

  /// No description provided for @slipReplace.
  ///
  /// In en, this message translates to:
  /// **'Replace'**
  String get slipReplace;

  /// No description provided for @slipRemove.
  ///
  /// In en, this message translates to:
  /// **'Remove'**
  String get slipRemove;

  /// No description provided for @transferredAmountLabel.
  ///
  /// In en, this message translates to:
  /// **'Amount transferred'**
  String get transferredAmountLabel;

  /// No description provided for @transferredAmountHint.
  ///
  /// In en, this message translates to:
  /// **'The exact amount that left your account — change it only if you sent something different.'**
  String get transferredAmountHint;

  /// No description provided for @transferredAmountInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter the transferred amount, e.g. 1,250.00.'**
  String get transferredAmountInvalid;

  /// No description provided for @amountUnderNote.
  ///
  /// In en, this message translates to:
  /// **'Less than the amount due. Money is applied oldest first: uncovered transactions stay payable on this settlement.'**
  String get amountUnderNote;

  /// No description provided for @amountOverNote.
  ///
  /// In en, this message translates to:
  /// **'More than the amount due. The excess becomes wallet credit for your next settlement.'**
  String get amountOverNote;

  /// No description provided for @submitSlipCta.
  ///
  /// In en, this message translates to:
  /// **'Submit slip'**
  String get submitSlipCta;

  /// No description provided for @duplicateBankRefMsg.
  ///
  /// In en, this message translates to:
  /// **'A slip with this bank reference was already submitted. Check the slip\'s reference, or open that settlement.'**
  String get duplicateBankRefMsg;

  /// No description provided for @selectionNotEligibleMsg.
  ///
  /// In en, this message translates to:
  /// **'Some of these transactions are no longer payable. Go back and reselect.'**
  String get selectionNotEligibleMsg;

  /// No description provided for @submitSlipFailed.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong submitting the slip.'**
  String get submitSlipFailed;

  /// No description provided for @successVerifyingTitle.
  ///
  /// In en, this message translates to:
  /// **'Manfaa is verifying your transfer'**
  String get successVerifyingTitle;

  /// No description provided for @successVerifyingBody.
  ///
  /// In en, this message translates to:
  /// **'Settlement {reference} reached our team. Customer cashback confirms once the slip is matched to your transfer. Nothing more is needed from you.'**
  String successVerifyingBody(String reference);

  /// No description provided for @settledOutrightTitle.
  ///
  /// In en, this message translates to:
  /// **'Settled — this batch\'s rewards are confirmed'**
  String get settledOutrightTitle;

  /// No description provided for @settledOutrightBody.
  ///
  /// In en, this message translates to:
  /// **'Settlement {reference} is closed and paid from your wallet. Nothing more is needed from you.'**
  String settledOutrightBody(String reference);

  /// No description provided for @discountSavedNote.
  ///
  /// In en, this message translates to:
  /// **'You saved {amount}'**
  String discountSavedNote(String amount);

  /// No description provided for @viewSettlementCta.
  ///
  /// In en, this message translates to:
  /// **'View settlement'**
  String get viewSettlementCta;

  /// No description provided for @doneCta.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get doneCta;

  /// No description provided for @walletSettleConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Settle from wallet?'**
  String get walletSettleConfirmTitle;

  /// No description provided for @walletSettleConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'{amount} will be drawn from your wallet and this batch settles immediately. No transfer, no slip.'**
  String walletSettleConfirmBody(String amount);

  /// No description provided for @walletSettleCta.
  ///
  /// In en, this message translates to:
  /// **'Settle from wallet'**
  String get walletSettleCta;

  /// No description provided for @walletSettleFailed.
  ///
  /// In en, this message translates to:
  /// **'Couldn\'t settle from the wallet.'**
  String get walletSettleFailed;

  /// No description provided for @detailCreated.
  ///
  /// In en, this message translates to:
  /// **'Created {date}'**
  String detailCreated(String date);

  /// No description provided for @setlStateDraft.
  ///
  /// In en, this message translates to:
  /// **'Draft'**
  String get setlStateDraft;

  /// No description provided for @setlStateAwaitingPayment.
  ///
  /// In en, this message translates to:
  /// **'Awaiting your transfer'**
  String get setlStateAwaitingPayment;

  /// No description provided for @setlStatePaymentReview.
  ///
  /// In en, this message translates to:
  /// **'Slip in review'**
  String get setlStatePaymentReview;

  /// No description provided for @setlStateSettled.
  ///
  /// In en, this message translates to:
  /// **'Settled'**
  String get setlStateSettled;

  /// No description provided for @setlStatePartiallySettled.
  ///
  /// In en, this message translates to:
  /// **'Partially settled'**
  String get setlStatePartiallySettled;

  /// No description provided for @setlStateCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get setlStateCancelled;

  /// No description provided for @statusVerifyingBody.
  ///
  /// In en, this message translates to:
  /// **'The slip is being matched to your transfer. Customer cashback confirms the moment it matches.'**
  String get statusVerifyingBody;

  /// No description provided for @statusSettledTitle.
  ///
  /// In en, this message translates to:
  /// **'Settled — this batch\'s rewards are confirmed.'**
  String get statusSettledTitle;

  /// No description provided for @statusPartialTitle.
  ///
  /// In en, this message translates to:
  /// **'Part of this batch is settled; the rest is still due.'**
  String get statusPartialTitle;

  /// No description provided for @statusAwaitingTitle.
  ///
  /// In en, this message translates to:
  /// **'Awaiting your transfer'**
  String get statusAwaitingTitle;

  /// No description provided for @statusAwaitingBody.
  ///
  /// In en, this message translates to:
  /// **'Manfaa prepared this batch. Transfer the amount due and upload the slip here.'**
  String get statusAwaitingBody;

  /// No description provided for @statusRejectedTitle.
  ///
  /// In en, this message translates to:
  /// **'Manfaa could not verify your transfer'**
  String get statusRejectedTitle;

  /// No description provided for @statusRejectedBody.
  ///
  /// In en, this message translates to:
  /// **'This settlement was cancelled and its transactions are payable again. Start a new settlement with the correct slip.'**
  String get statusRejectedBody;

  /// No description provided for @statusRejectedReason.
  ///
  /// In en, this message translates to:
  /// **'Manfaa\'s reason'**
  String get statusRejectedReason;

  /// No description provided for @statusRejectedNoReason.
  ///
  /// In en, this message translates to:
  /// **'No reason recorded.'**
  String get statusRejectedNoReason;

  /// No description provided for @statusCancelledTitle.
  ///
  /// In en, this message translates to:
  /// **'This settlement was cancelled; its transactions are payable again.'**
  String get statusCancelledTitle;

  /// No description provided for @statusDraftTitle.
  ///
  /// In en, this message translates to:
  /// **'Draft — not yet submitted.'**
  String get statusDraftTitle;

  /// No description provided for @remainderTitle.
  ///
  /// In en, this message translates to:
  /// **'Pay the remainder'**
  String get remainderTitle;

  /// No description provided for @remainderBody.
  ///
  /// In en, this message translates to:
  /// **'This batch is still {amount} short. Its transactions stay held here until it is fully paid — transfer the rest and upload that slip.'**
  String remainderBody(String amount);

  /// No description provided for @uploadReceiptCta.
  ///
  /// In en, this message translates to:
  /// **'Upload a slip'**
  String get uploadReceiptCta;

  /// No description provided for @receiptAddedToast.
  ///
  /// In en, this message translates to:
  /// **'Slip submitted — Manfaa is checking it.'**
  String get receiptAddedToast;

  /// No description provided for @linesTitle.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 line} other{{count} lines}}'**
  String linesTitle(int count);

  /// No description provided for @totalsLabel.
  ///
  /// In en, this message translates to:
  /// **'Total'**
  String get totalsLabel;

  /// No description provided for @summaryTitle.
  ///
  /// In en, this message translates to:
  /// **'Summary'**
  String get summaryTitle;

  /// No description provided for @summaryDue.
  ///
  /// In en, this message translates to:
  /// **'Amount due'**
  String get summaryDue;

  /// No description provided for @summaryReceived.
  ///
  /// In en, this message translates to:
  /// **'Received so far'**
  String get summaryReceived;

  /// No description provided for @summaryMethod.
  ///
  /// In en, this message translates to:
  /// **'Paid via'**
  String get summaryMethod;

  /// No description provided for @methodBank.
  ///
  /// In en, this message translates to:
  /// **'Bank transfer'**
  String get methodBank;

  /// No description provided for @methodWallet.
  ///
  /// In en, this message translates to:
  /// **'Wallet'**
  String get methodWallet;

  /// No description provided for @discountAppliedHint.
  ///
  /// In en, this message translates to:
  /// **'Already subtracted from the amount due. Nothing is taken from customers\' cashback.'**
  String get discountAppliedHint;

  /// No description provided for @paymentsTitle.
  ///
  /// In en, this message translates to:
  /// **'Payments'**
  String get paymentsTitle;

  /// No description provided for @paymentSlipAttached.
  ///
  /// In en, this message translates to:
  /// **'Slip attached'**
  String get paymentSlipAttached;

  /// No description provided for @paymentNoSlip.
  ///
  /// In en, this message translates to:
  /// **'No slip'**
  String get paymentNoSlip;

  /// No description provided for @paymentPending.
  ///
  /// In en, this message translates to:
  /// **'Awaiting review'**
  String get paymentPending;

  /// No description provided for @paymentMatched.
  ///
  /// In en, this message translates to:
  /// **'Verified'**
  String get paymentMatched;

  /// No description provided for @paymentRejected.
  ///
  /// In en, this message translates to:
  /// **'Refused'**
  String get paymentRejected;

  /// No description provided for @settlementNotFound.
  ///
  /// In en, this message translates to:
  /// **'Settlement not found.'**
  String get settlementNotFound;

  /// No description provided for @startNewSettlement.
  ///
  /// In en, this message translates to:
  /// **'Start a new settlement'**
  String get startNewSettlement;

  /// No description provided for @walletScreenTitle.
  ///
  /// In en, this message translates to:
  /// **'Wallet'**
  String get walletScreenTitle;

  /// No description provided for @walletBalanceLabel.
  ///
  /// In en, this message translates to:
  /// **'Balance'**
  String get walletBalanceLabel;

  /// No description provided for @walletTopUpHint.
  ///
  /// In en, this message translates to:
  /// **'Top-ups are recorded by our team when your transfer arrives.'**
  String get walletTopUpHint;

  /// No description provided for @movementsTitle.
  ///
  /// In en, this message translates to:
  /// **'Movements'**
  String get movementsTitle;

  /// No description provided for @movementsEmpty.
  ///
  /// In en, this message translates to:
  /// **'No wallet movements yet.'**
  String get movementsEmpty;

  /// No description provided for @movementTopUp.
  ///
  /// In en, this message translates to:
  /// **'Top-up'**
  String get movementTopUp;

  /// No description provided for @movementSettlement.
  ///
  /// In en, this message translates to:
  /// **'Spent on a settlement'**
  String get movementSettlement;

  /// No description provided for @movementSettlementCredit.
  ///
  /// In en, this message translates to:
  /// **'Credit from a settlement'**
  String get movementSettlementCredit;

  /// No description provided for @movementOther.
  ///
  /// In en, this message translates to:
  /// **'Adjustment'**
  String get movementOther;

  /// No description provided for @balanceAfterLabel.
  ///
  /// In en, this message translates to:
  /// **'Balance after: {amount}'**
  String balanceAfterLabel(String amount);

  /// No description provided for @presetAllLabel.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get presetAllLabel;

  /// No description provided for @pushOpen.
  ///
  /// In en, this message translates to:
  /// **'View'**
  String get pushOpen;

  /// No description provided for @moreTitle.
  ///
  /// In en, this message translates to:
  /// **'More'**
  String get moreTitle;

  /// No description provided for @moreSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Manage your business and account settings.'**
  String get moreSubtitle;

  /// No description provided for @merchantAccount.
  ///
  /// In en, this message translates to:
  /// **'Merchant account'**
  String get merchantAccount;

  /// No description provided for @verifiedChip.
  ///
  /// In en, this message translates to:
  /// **'Verified'**
  String get verifiedChip;

  /// No description provided for @viewProfile.
  ///
  /// In en, this message translates to:
  /// **'View profile'**
  String get viewProfile;

  /// No description provided for @menuEmployees.
  ///
  /// In en, this message translates to:
  /// **'Manage Employees'**
  String get menuEmployees;

  /// No description provided for @menuRoles.
  ///
  /// In en, this message translates to:
  /// **'Roles'**
  String get menuRoles;

  /// No description provided for @menuBranches.
  ///
  /// In en, this message translates to:
  /// **'Manage Branches'**
  String get menuBranches;

  /// No description provided for @menuCashback.
  ///
  /// In en, this message translates to:
  /// **'Cashback Settings'**
  String get menuCashback;

  /// No description provided for @menuPromotions.
  ///
  /// In en, this message translates to:
  /// **'Promotions'**
  String get menuPromotions;

  /// No description provided for @logOut.
  ///
  /// In en, this message translates to:
  /// **'Log out'**
  String get logOut;

  /// No description provided for @logOutConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Log out?'**
  String get logOutConfirmTitle;

  /// No description provided for @logOutConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'You can log back in any time.'**
  String get logOutConfirmBody;

  /// No description provided for @comingSoonBody.
  ///
  /// In en, this message translates to:
  /// **'This screen is on its way.'**
  String get comingSoonBody;

  /// No description provided for @profileScreenTitle.
  ///
  /// In en, this message translates to:
  /// **'Profile'**
  String get profileScreenTitle;

  /// No description provided for @profileScreenSubtitle.
  ///
  /// In en, this message translates to:
  /// **'View and manage your merchant profile.'**
  String get profileScreenSubtitle;

  /// No description provided for @storeLogoLabel.
  ///
  /// In en, this message translates to:
  /// **'Store logo'**
  String get storeLogoLabel;

  /// No description provided for @storeNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Store name'**
  String get storeNameLabel;

  /// No description provided for @storeNameDvLabel.
  ///
  /// In en, this message translates to:
  /// **'Store name in Dhivehi'**
  String get storeNameDvLabel;

  /// No description provided for @storeNameHint.
  ///
  /// In en, this message translates to:
  /// **'Your public name. Your store link never changes with it, so anything already shared keeps working.'**
  String get storeNameHint;

  /// No description provided for @storeNameRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter the store name.'**
  String get storeNameRequired;

  /// No description provided for @channelRowLabel.
  ///
  /// In en, this message translates to:
  /// **'Channel'**
  String get channelRowLabel;

  /// No description provided for @channelBothDisplay.
  ///
  /// In en, this message translates to:
  /// **'In Store & Online'**
  String get channelBothDisplay;

  /// No description provided for @categoryRetiredHint.
  ///
  /// In en, this message translates to:
  /// **'This category was retired — please choose a new one. Your other changes still save.'**
  String get categoryRetiredHint;

  /// No description provided for @editProfileTitle.
  ///
  /// In en, this message translates to:
  /// **'Edit profile'**
  String get editProfileTitle;

  /// No description provided for @saveProfile.
  ///
  /// In en, this message translates to:
  /// **'Save profile'**
  String get saveProfile;

  /// No description provided for @profileSaved.
  ///
  /// In en, this message translates to:
  /// **'Profile saved'**
  String get profileSaved;

  /// No description provided for @profileSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the profile.'**
  String get profileSaveFailed;

  /// No description provided for @cashbackTitle.
  ///
  /// In en, this message translates to:
  /// **'Cashback Settings'**
  String get cashbackTitle;

  /// No description provided for @cashbackSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Configure how customers earn cashback and how settlements work.'**
  String get cashbackSubtitle;

  /// No description provided for @generalRateTitle.
  ///
  /// In en, this message translates to:
  /// **'General cashback rate'**
  String get generalRateTitle;

  /// No description provided for @generalRateHint.
  ///
  /// In en, this message translates to:
  /// **'Applies to all eligible sales unless category rules override it.'**
  String get generalRateHint;

  /// No description provided for @noRateYet.
  ///
  /// In en, this message translates to:
  /// **'No standing rate yet — set one to start offering cashback.'**
  String get noRateYet;

  /// No description provided for @scheduledChangeTitle.
  ///
  /// In en, this message translates to:
  /// **'Scheduled change: {rate} cashback'**
  String scheduledChangeTitle(String rate);

  /// No description provided for @scheduledChangeBody.
  ///
  /// In en, this message translates to:
  /// **'Takes effect {date}. Submitting a new change replaces it.'**
  String scheduledChangeBody(String date);

  /// No description provided for @editRateTitle.
  ///
  /// In en, this message translates to:
  /// **'Change the rate'**
  String get editRateTitle;

  /// No description provided for @newRateLabel.
  ///
  /// In en, this message translates to:
  /// **'New customer cashback rate'**
  String get newRateLabel;

  /// No description provided for @rateBoundsHint.
  ///
  /// In en, this message translates to:
  /// **'Percent of the eligible amount, up to two decimal places. Allowed range {min} to {max}.'**
  String rateBoundsHint(String min, String max);

  /// No description provided for @rateSameAsCurrent.
  ///
  /// In en, this message translates to:
  /// **'This is already your current rate.'**
  String get rateSameAsCurrent;

  /// No description provided for @rateCancelsPending.
  ///
  /// In en, this message translates to:
  /// **'This matches your current rate — submitting cancels the scheduled change and keeps the rate as it is.'**
  String get rateCancelsPending;

  /// No description provided for @rateIncreaseNote.
  ///
  /// In en, this message translates to:
  /// **'Increases apply immediately — the higher rate starts with the next recorded sale.'**
  String get rateIncreaseNote;

  /// No description provided for @rateDecreaseNote.
  ///
  /// In en, this message translates to:
  /// **'Decreases apply at 00:00 tonight (Maldives time) — an advertised rate is never cut mid-day.'**
  String get rateDecreaseNote;

  /// No description provided for @changeRateCta.
  ///
  /// In en, this message translates to:
  /// **'Change rate'**
  String get changeRateCta;

  /// No description provided for @setRateCta.
  ///
  /// In en, this message translates to:
  /// **'Set rate'**
  String get setRateCta;

  /// No description provided for @cancelPendingCta.
  ///
  /// In en, this message translates to:
  /// **'Cancel scheduled change'**
  String get cancelPendingCta;

  /// No description provided for @rateUpdatedToast.
  ///
  /// In en, this message translates to:
  /// **'Cashback rate updated'**
  String get rateUpdatedToast;

  /// No description provided for @rateChangedNowTitle.
  ///
  /// In en, this message translates to:
  /// **'Cashback rate is now {rate}'**
  String rateChangedNowTitle(String rate);

  /// No description provided for @rateChangedLaterTitle.
  ///
  /// In en, this message translates to:
  /// **'Cashback rate will be {rate}'**
  String rateChangedLaterTitle(String rate);

  /// No description provided for @rateChangedNowBody.
  ///
  /// In en, this message translates to:
  /// **'Applied immediately. Platform fee {fee} — all-in cost {allIn}.'**
  String rateChangedNowBody(String fee, String allIn);

  /// No description provided for @rateChangedLaterBody.
  ///
  /// In en, this message translates to:
  /// **'Takes effect {date} — the advertised rate is honoured until then.'**
  String rateChangedLaterBody(String date);

  /// No description provided for @categoryRulesTitle.
  ///
  /// In en, this message translates to:
  /// **'Cashback by category'**
  String get categoryRulesTitle;

  /// No description provided for @categoryRulesHint.
  ///
  /// In en, this message translates to:
  /// **'Override the general rate for selected product categories.'**
  String get categoryRulesHint;

  /// No description provided for @optionalBadge.
  ///
  /// In en, this message translates to:
  /// **'Optional'**
  String get optionalBadge;

  /// No description provided for @excludedBadge.
  ///
  /// In en, this message translates to:
  /// **'Excluded'**
  String get excludedBadge;

  /// No description provided for @inactiveBadge.
  ///
  /// In en, this message translates to:
  /// **'Inactive'**
  String get inactiveBadge;

  /// No description provided for @addCategoryRule.
  ///
  /// In en, this message translates to:
  /// **'Add category rule'**
  String get addCategoryRule;

  /// No description provided for @editRuleTitle.
  ///
  /// In en, this message translates to:
  /// **'Edit category rule'**
  String get editRuleTitle;

  /// No description provided for @ruleNameEnLabel.
  ///
  /// In en, this message translates to:
  /// **'Name (English)'**
  String get ruleNameEnLabel;

  /// No description provided for @ruleNameDvLabel.
  ///
  /// In en, this message translates to:
  /// **'Name (Dhivehi)'**
  String get ruleNameDvLabel;

  /// No description provided for @ruleNameDvHint.
  ///
  /// In en, this message translates to:
  /// **'Required — this is what a Dhivehi customer sees on their receipt.'**
  String get ruleNameDvHint;

  /// No description provided for @ruleNamesRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter both names — the Dhivehi name is what customers read on their receipt.'**
  String get ruleNamesRequired;

  /// No description provided for @ruleModeLabel.
  ///
  /// In en, this message translates to:
  /// **'How does this category earn?'**
  String get ruleModeLabel;

  /// No description provided for @modeRateOption.
  ///
  /// In en, this message translates to:
  /// **'Custom rate'**
  String get modeRateOption;

  /// No description provided for @modeRateOptionHint.
  ///
  /// In en, this message translates to:
  /// **'Sales in this category earn their own cashback percent.'**
  String get modeRateOptionHint;

  /// No description provided for @modeExcludedOption.
  ///
  /// In en, this message translates to:
  /// **'Excluded'**
  String get modeExcludedOption;

  /// No description provided for @modeExcludedOptionHint.
  ///
  /// In en, this message translates to:
  /// **'Sales in this category never earn cashback — even during promotions.'**
  String get modeExcludedOptionHint;

  /// No description provided for @ruleRateLabel.
  ///
  /// In en, this message translates to:
  /// **'Category cashback rate'**
  String get ruleRateLabel;

  /// No description provided for @ruleActiveLabel.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get ruleActiveLabel;

  /// No description provided for @categorySaved.
  ///
  /// In en, this message translates to:
  /// **'Category saved'**
  String get categorySaved;

  /// No description provided for @categorySaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the category.'**
  String get categorySaveFailed;

  /// No description provided for @earningTitle.
  ///
  /// In en, this message translates to:
  /// **'Earning & settlement'**
  String get earningTitle;

  /// No description provided for @minEligibleTitle.
  ///
  /// In en, this message translates to:
  /// **'Minimum eligible sale'**
  String get minEligibleTitle;

  /// No description provided for @minEligibleHint.
  ///
  /// In en, this message translates to:
  /// **'Sales below this amount do not earn cashback.'**
  String get minEligibleHint;

  /// No description provided for @minEligibleInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter an amount between MVR 0 and MVR 1,000.'**
  String get minEligibleInvalid;

  /// No description provided for @validationWindowTitle.
  ///
  /// In en, this message translates to:
  /// **'Validation window'**
  String get validationWindowTitle;

  /// No description provided for @validationWindowHint.
  ///
  /// In en, this message translates to:
  /// **'Cashback remains pending until the validation period ends or the sale is confirmed.'**
  String get validationWindowHint;

  /// No description provided for @validationWindowInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter a whole number of days between 0 and {max}.'**
  String validationWindowInvalid(int max);

  /// No description provided for @daysSuffix.
  ///
  /// In en, this message translates to:
  /// **'days'**
  String get daysSuffix;

  /// No description provided for @visibilityTitle.
  ///
  /// In en, this message translates to:
  /// **'Customer visibility'**
  String get visibilityTitle;

  /// No description provided for @visibilityValue.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get visibilityValue;

  /// No description provided for @visibilityHint.
  ///
  /// In en, this message translates to:
  /// **'Shown to customers as pending until validated.'**
  String get visibilityHint;

  /// No description provided for @saveChanges.
  ///
  /// In en, this message translates to:
  /// **'Save changes'**
  String get saveChanges;

  /// No description provided for @preferencesSaved.
  ///
  /// In en, this message translates to:
  /// **'Preferences saved'**
  String get preferencesSaved;

  /// No description provided for @preferencesSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the preferences.'**
  String get preferencesSaveFailed;

  /// No description provided for @employeesTitle.
  ///
  /// In en, this message translates to:
  /// **'Manage Employees'**
  String get employeesTitle;

  /// No description provided for @employeesSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Add, view and control staff access to your store.'**
  String get employeesSubtitle;

  /// No description provided for @totalEmployeesLabel.
  ///
  /// In en, this message translates to:
  /// **'Total employees'**
  String get totalEmployeesLabel;

  /// No description provided for @activeEmployeesLabel.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get activeEmployeesLabel;

  /// No description provided for @addEmployeeCta.
  ///
  /// In en, this message translates to:
  /// **'Add employee'**
  String get addEmployeeCta;

  /// No description provided for @rolesCta.
  ///
  /// In en, this message translates to:
  /// **'Roles'**
  String get rolesCta;

  /// No description provided for @searchEmployeesHint.
  ///
  /// In en, this message translates to:
  /// **'Search employees'**
  String get searchEmployeesHint;

  /// No description provided for @employeesEmpty.
  ///
  /// In en, this message translates to:
  /// **'No staff accounts yet.'**
  String get employeesEmpty;

  /// No description provided for @noRoleLabel.
  ///
  /// In en, this message translates to:
  /// **'No role'**
  String get noRoleLabel;

  /// No description provided for @statusActive.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get statusActive;

  /// No description provided for @statusSuspended.
  ///
  /// In en, this message translates to:
  /// **'Suspended'**
  String get statusSuspended;

  /// No description provided for @youMarker.
  ///
  /// In en, this message translates to:
  /// **'(you)'**
  String get youMarker;

  /// No description provided for @permissionsOverviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Permissions overview'**
  String get permissionsOverviewTitle;

  /// No description provided for @manageRolesLink.
  ///
  /// In en, this message translates to:
  /// **'Manage roles'**
  String get manageRolesLink;

  /// No description provided for @inviteTitle.
  ///
  /// In en, this message translates to:
  /// **'Add employee'**
  String get inviteTitle;

  /// No description provided for @inviteNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Name'**
  String get inviteNameLabel;

  /// No description provided for @inviteEmailLabel.
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get inviteEmailLabel;

  /// No description provided for @inviteEmailHint.
  ///
  /// In en, this message translates to:
  /// **'They sign in with this email.'**
  String get inviteEmailHint;

  /// No description provided for @invitePickRoleLabel.
  ///
  /// In en, this message translates to:
  /// **'Role'**
  String get invitePickRoleLabel;

  /// No description provided for @inviteCta.
  ///
  /// In en, this message translates to:
  /// **'Create account'**
  String get inviteCta;

  /// No description provided for @inviteFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not create the account.'**
  String get inviteFailed;

  /// No description provided for @staffNeedsRolesView.
  ///
  /// In en, this message translates to:
  /// **'Picking a role needs the view-roles permission, which your role does not hold. Ask an owner for it.'**
  String get staffNeedsRolesView;

  /// No description provided for @roleNotAssignableHint.
  ///
  /// In en, this message translates to:
  /// **'A role holding a permission you do not hold yourself cannot be handed out.'**
  String get roleNotAssignableHint;

  /// No description provided for @tempPasswordTitle.
  ///
  /// In en, this message translates to:
  /// **'One-time password for {name}'**
  String tempPasswordTitle(String name);

  /// No description provided for @tempPasswordOnceTitle.
  ///
  /// In en, this message translates to:
  /// **'Shown only once.'**
  String get tempPasswordOnceTitle;

  /// No description provided for @tempPasswordOnceBody.
  ///
  /// In en, this message translates to:
  /// **'Only a hashed copy is kept, so this password can never be shown again. Hand it to {name} now and ask them to change it after their first sign-in.'**
  String tempPasswordOnceBody(String name);

  /// No description provided for @tempPasswordLoginEmail.
  ///
  /// In en, this message translates to:
  /// **'Sign-in email'**
  String get tempPasswordLoginEmail;

  /// No description provided for @copyPassword.
  ///
  /// In en, this message translates to:
  /// **'Copy password'**
  String get copyPassword;

  /// No description provided for @passwordCopied.
  ///
  /// In en, this message translates to:
  /// **'Password copied'**
  String get passwordCopied;

  /// No description provided for @tempPasswordAck.
  ///
  /// In en, this message translates to:
  /// **'I have passed this password on securely and understand it cannot be shown again.'**
  String get tempPasswordAck;

  /// No description provided for @doneLabel.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get doneLabel;

  /// No description provided for @employeeRoleLabel.
  ///
  /// In en, this message translates to:
  /// **'Role'**
  String get employeeRoleLabel;

  /// No description provided for @employeeActiveLabel.
  ///
  /// In en, this message translates to:
  /// **'Active account'**
  String get employeeActiveLabel;

  /// No description provided for @employeeActiveHint.
  ///
  /// In en, this message translates to:
  /// **'Switching off is the only removal — history keeps its names, and the account can be switched back on.'**
  String get employeeActiveHint;

  /// No description provided for @staffLastOwnerLocked.
  ///
  /// In en, this message translates to:
  /// **'This is the store\'s last active owner. Hand the owner role to another account first — otherwise nobody could reach the bank account or manage staff.'**
  String get staffLastOwnerLocked;

  /// No description provided for @staffSelfDemoteLocked.
  ///
  /// In en, this message translates to:
  /// **'You cannot move your own account off the owner role — another owner has to.'**
  String get staffSelfDemoteLocked;

  /// No description provided for @staffSelfActiveLocked.
  ///
  /// In en, this message translates to:
  /// **'You cannot deactivate your own account.'**
  String get staffSelfActiveLocked;

  /// No description provided for @staffSaved.
  ///
  /// In en, this message translates to:
  /// **'Employee updated'**
  String get staffSaved;

  /// No description provided for @staffSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not update the account.'**
  String get staffSaveFailed;

  /// No description provided for @rolesTitle.
  ///
  /// In en, this message translates to:
  /// **'Roles'**
  String get rolesTitle;

  /// No description provided for @rolesSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Create roles and set permissions to control what employees can access and do.'**
  String get rolesSubtitle;

  /// No description provided for @addRoleCta.
  ///
  /// In en, this message translates to:
  /// **'Add role'**
  String get addRoleCta;

  /// No description provided for @rolesReadOnlyHint.
  ///
  /// In en, this message translates to:
  /// **'You can view roles. Changing them needs the manage-roles permission.'**
  String get rolesReadOnlyHint;

  /// No description provided for @fullAccessBadge.
  ///
  /// In en, this message translates to:
  /// **'Full Access'**
  String get fullAccessBadge;

  /// No description provided for @ownerFrozenHint.
  ///
  /// In en, this message translates to:
  /// **'The owner role always holds every permission, so it cannot be edited or deleted.'**
  String get ownerFrozenHint;

  /// No description provided for @employeeCountLabel.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 employee} other{{count} employees}}'**
  String employeeCountLabel(int count);

  /// No description provided for @permissionCountChip.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 permission} other{{count} permissions}}'**
  String permissionCountChip(int count);

  /// No description provided for @roleEditorCreateTitle.
  ///
  /// In en, this message translates to:
  /// **'New role'**
  String get roleEditorCreateTitle;

  /// No description provided for @roleEditorEditTitle.
  ///
  /// In en, this message translates to:
  /// **'Edit role'**
  String get roleEditorEditTitle;

  /// No description provided for @roleNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Name'**
  String get roleNameLabel;

  /// No description provided for @roleNameRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter a role name.'**
  String get roleNameRequired;

  /// No description provided for @roleNameDvLabel.
  ///
  /// In en, this message translates to:
  /// **'Name (Dhivehi, optional)'**
  String get roleNameDvLabel;

  /// No description provided for @roleNameDvHint.
  ///
  /// In en, this message translates to:
  /// **'Shown when the app is in Dhivehi. Leave empty to use the name above.'**
  String get roleNameDvHint;

  /// No description provided for @rolePermissionsLabel.
  ///
  /// In en, this message translates to:
  /// **'Permissions'**
  String get rolePermissionsLabel;

  /// No description provided for @delegationHint.
  ///
  /// In en, this message translates to:
  /// **'Greyed permissions are ones you do not hold yourself — a role can only be given what you hold.'**
  String get delegationHint;

  /// No description provided for @createRoleCta.
  ///
  /// In en, this message translates to:
  /// **'Create role'**
  String get createRoleCta;

  /// No description provided for @saveRoleCta.
  ///
  /// In en, this message translates to:
  /// **'Save role'**
  String get saveRoleCta;

  /// No description provided for @deleteRoleCta.
  ///
  /// In en, this message translates to:
  /// **'Delete role'**
  String get deleteRoleCta;

  /// No description provided for @roleInUseHint.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 account stands on this role — move it to another role first.} other{{count} accounts stand on this role — move them to another role first.}}'**
  String roleInUseHint(int count);

  /// No description provided for @roleDeleteConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Delete {name}?'**
  String roleDeleteConfirmTitle(String name);

  /// No description provided for @roleDeleteConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'The role is removed for good. Nobody stands on it, so nobody loses access.'**
  String get roleDeleteConfirmBody;

  /// No description provided for @deleteLabel.
  ///
  /// In en, this message translates to:
  /// **'Delete'**
  String get deleteLabel;

  /// No description provided for @roleSaved.
  ///
  /// In en, this message translates to:
  /// **'Role saved'**
  String get roleSaved;

  /// No description provided for @roleDeleted.
  ///
  /// In en, this message translates to:
  /// **'Role deleted'**
  String get roleDeleted;

  /// No description provided for @roleSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the role.'**
  String get roleSaveFailed;

  /// No description provided for @roleDeleteFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not delete the role.'**
  String get roleDeleteFailed;

  /// No description provided for @branchesTitle.
  ///
  /// In en, this message translates to:
  /// **'Manage Branches'**
  String get branchesTitle;

  /// No description provided for @branchesSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Your locations — a pinned branch appears in the customer app\'s Nearby.'**
  String get branchesSubtitle;

  /// No description provided for @totalBranchesLabel.
  ///
  /// In en, this message translates to:
  /// **'Total'**
  String get totalBranchesLabel;

  /// No description provided for @pinnedBranchesLabel.
  ///
  /// In en, this message translates to:
  /// **'Pinned'**
  String get pinnedBranchesLabel;

  /// No description provided for @unpinnedBranchesLabel.
  ///
  /// In en, this message translates to:
  /// **'No pin'**
  String get unpinnedBranchesLabel;

  /// No description provided for @addBranchCta.
  ///
  /// In en, this message translates to:
  /// **'Add branch'**
  String get addBranchCta;

  /// No description provided for @searchBranchesHint.
  ///
  /// In en, this message translates to:
  /// **'Search branches'**
  String get searchBranchesHint;

  /// No description provided for @branchesEmpty.
  ///
  /// In en, this message translates to:
  /// **'No branches yet — add your first location to appear in Nearby.'**
  String get branchesEmpty;

  /// No description provided for @branchNotPinned.
  ///
  /// In en, this message translates to:
  /// **'Not on the map'**
  String get branchNotPinned;

  /// No description provided for @editBranchTitle.
  ///
  /// In en, this message translates to:
  /// **'Edit {name}'**
  String editBranchTitle(String name);

  /// No description provided for @branchNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Name'**
  String get branchNameLabel;

  /// No description provided for @branchNameRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter a branch name.'**
  String get branchNameRequired;

  /// No description provided for @branchAddressLabel.
  ///
  /// In en, this message translates to:
  /// **'Address (optional)'**
  String get branchAddressLabel;

  /// No description provided for @branchPinLabel.
  ///
  /// In en, this message translates to:
  /// **'Map pin (optional)'**
  String get branchPinLabel;

  /// No description provided for @branchPinHint.
  ///
  /// In en, this message translates to:
  /// **'With a pin, customers near this location find you first in the Nearby tab. Without one, this branch is not shown there.'**
  String get branchPinHint;

  /// No description provided for @clearPinCta.
  ///
  /// In en, this message translates to:
  /// **'Remove pin'**
  String get clearPinCta;

  /// No description provided for @setPinCta.
  ///
  /// In en, this message translates to:
  /// **'Set a map pin'**
  String get setPinCta;

  /// No description provided for @branchAdded.
  ///
  /// In en, this message translates to:
  /// **'Branch added'**
  String get branchAdded;

  /// No description provided for @branchSaved.
  ///
  /// In en, this message translates to:
  /// **'Branch saved'**
  String get branchSaved;

  /// No description provided for @branchDeleted.
  ///
  /// In en, this message translates to:
  /// **'Branch deleted'**
  String get branchDeleted;

  /// No description provided for @branchSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the branch.'**
  String get branchSaveFailed;

  /// No description provided for @branchDeleteFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not delete the branch.'**
  String get branchDeleteFailed;

  /// No description provided for @branchDeleteConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Delete {name}?'**
  String branchDeleteConfirmTitle(String name);

  /// No description provided for @branchDeleteConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'The branch disappears from discovery and new sales. A branch with recorded sales or promotions cannot be deleted — history must keep resolving.'**
  String get branchDeleteConfirmBody;

  /// No description provided for @branchReferencedError.
  ///
  /// In en, this message translates to:
  /// **'This branch has recorded sales or promotions, so it stays on file. Stop using it instead.'**
  String get branchReferencedError;

  /// No description provided for @promotionsTitle.
  ///
  /// In en, this message translates to:
  /// **'Promotions'**
  String get promotionsTitle;

  /// No description provided for @promotionsSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Time-boxed cashback boosts above your standing rate.'**
  String get promotionsSubtitle;

  /// No description provided for @newPromotionCta.
  ///
  /// In en, this message translates to:
  /// **'New promotion'**
  String get newPromotionCta;

  /// No description provided for @promotionsEmpty.
  ///
  /// In en, this message translates to:
  /// **'No promotions yet. Create one to boost your cashback for a while.'**
  String get promotionsEmpty;

  /// No description provided for @promoStatusDraft.
  ///
  /// In en, this message translates to:
  /// **'Draft'**
  String get promoStatusDraft;

  /// No description provided for @promoStatusLive.
  ///
  /// In en, this message translates to:
  /// **'Live'**
  String get promoStatusLive;

  /// No description provided for @promoStatusPublished.
  ///
  /// In en, this message translates to:
  /// **'Published'**
  String get promoStatusPublished;

  /// No description provided for @promoStatusEnded.
  ///
  /// In en, this message translates to:
  /// **'Ended'**
  String get promoStatusEnded;

  /// No description provided for @promoStatusCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get promoStatusCancelled;

  /// No description provided for @promoRateTitle.
  ///
  /// In en, this message translates to:
  /// **'{rate} cashback'**
  String promoRateTitle(String rate);

  /// No description provided for @promoYouPay.
  ///
  /// In en, this message translates to:
  /// **'You pay {allIn} (fee {fee})'**
  String promoYouPay(String allIn, String fee);

  /// No description provided for @promoYouPayDash.
  ///
  /// In en, this message translates to:
  /// **'Fee not priced right now'**
  String get promoYouPayDash;

  /// No description provided for @promoMinPurchase.
  ///
  /// In en, this message translates to:
  /// **'Min purchase {amount}'**
  String promoMinPurchase(String amount);

  /// No description provided for @promoPerCustomerCap.
  ///
  /// In en, this message translates to:
  /// **'Per-customer cap {amount}'**
  String promoPerCustomerCap(String amount);

  /// No description provided for @promoAllBranches.
  ///
  /// In en, this message translates to:
  /// **'All branches'**
  String get promoAllBranches;

  /// No description provided for @promoBranchFallback.
  ///
  /// In en, this message translates to:
  /// **'Branch #{id}'**
  String promoBranchFallback(int id);

  /// No description provided for @publishCta.
  ///
  /// In en, this message translates to:
  /// **'Publish'**
  String get publishCta;

  /// No description provided for @cancelDraftCta.
  ///
  /// In en, this message translates to:
  /// **'Cancel draft'**
  String get cancelDraftCta;

  /// No description provided for @keepDraftCta.
  ///
  /// In en, this message translates to:
  /// **'Keep as draft'**
  String get keepDraftCta;

  /// No description provided for @publishConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Publish the {rate} promotion?'**
  String publishConfirmTitle(String rate);

  /// No description provided for @publishConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'It runs {start} to {end}. Once published it cannot be changed or ended early — customers can rely on the advertised boost for the whole window.'**
  String publishConfirmBody(String start, String end);

  /// No description provided for @promotionPublished.
  ///
  /// In en, this message translates to:
  /// **'Promotion published'**
  String get promotionPublished;

  /// No description provided for @draftCancelled.
  ///
  /// In en, this message translates to:
  /// **'Draft cancelled'**
  String get draftCancelled;

  /// No description provided for @publishFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not publish the promotion.'**
  String get publishFailed;

  /// No description provided for @cancelDraftFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not cancel the draft.'**
  String get cancelDraftFailed;

  /// No description provided for @promoRateLabel.
  ///
  /// In en, this message translates to:
  /// **'Boosted cashback rate'**
  String get promoRateLabel;

  /// No description provided for @promoRateHint.
  ///
  /// In en, this message translates to:
  /// **'Percent of the eligible amount, up to two decimal places. A higher rate can land on a higher platform fee tier — you see the exact cost before publishing.'**
  String get promoRateHint;

  /// No description provided for @promoMustBoost.
  ///
  /// In en, this message translates to:
  /// **'A promotion must boost above your standing rate of {rate}.'**
  String promoMustBoost(String rate);

  /// No description provided for @promoStartsLabel.
  ///
  /// In en, this message translates to:
  /// **'Starts'**
  String get promoStartsLabel;

  /// No description provided for @promoEndsLabel.
  ///
  /// In en, this message translates to:
  /// **'Ends'**
  String get promoEndsLabel;

  /// No description provided for @promoWindowHint.
  ///
  /// In en, this message translates to:
  /// **'Maldives time. Once published, the promotion runs for exactly this window — it cannot be edited or ended early.'**
  String get promoWindowHint;

  /// No description provided for @promoWindowOrderError.
  ///
  /// In en, this message translates to:
  /// **'The promotion must end after it starts.'**
  String get promoWindowOrderError;

  /// No description provided for @promoMinLabel.
  ///
  /// In en, this message translates to:
  /// **'Minimum purchase (optional)'**
  String get promoMinLabel;

  /// No description provided for @promoCapLabel.
  ///
  /// In en, this message translates to:
  /// **'Per-customer cashback cap (optional)'**
  String get promoCapLabel;

  /// No description provided for @promoAmountInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter an MVR amount, e.g. 100.00, or leave empty.'**
  String get promoAmountInvalid;

  /// No description provided for @promoBranchLabel.
  ///
  /// In en, this message translates to:
  /// **'Branch'**
  String get promoBranchLabel;

  /// No description provided for @createDraftCta.
  ///
  /// In en, this message translates to:
  /// **'Create draft'**
  String get createDraftCta;

  /// No description provided for @draftCreated.
  ///
  /// In en, this message translates to:
  /// **'Draft promotion created'**
  String get draftCreated;

  /// No description provided for @createDraftFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not create the promotion.'**
  String get createDraftFailed;

  /// No description provided for @promoCostTitle.
  ///
  /// In en, this message translates to:
  /// **'During the promotion you pay {allIn} of each eligible sale'**
  String promoCostTitle(String allIn);

  /// No description provided for @promoCostBody.
  ///
  /// In en, this message translates to:
  /// **'{rate} cashback + {fee} platform fee.'**
  String promoCostBody(Object fee, Object rate);

  /// No description provided for @promoCostDelta.
  ///
  /// In en, this message translates to:
  /// **'That is {delta} versus your standing all-in cost of {standing}.'**
  String promoCostDelta(String delta, String standing);

  /// No description provided for @promoCostTier.
  ///
  /// In en, this message translates to:
  /// **'Boosting from {from} to {to} moves your fee tier from {feeFrom} to {feeTo}.'**
  String promoCostTier(String from, String to, String feeFrom, String feeTo);

  /// MR7 expanded two-pane: the empty detail pane on the Transactions screen
  ///
  /// In en, this message translates to:
  /// **'Select a sale'**
  String get paneTxHintTitle;

  /// No description provided for @paneTxHintBody.
  ///
  /// In en, this message translates to:
  /// **'Tap a sale in the list to read its full detail — corrections stay available while the sale can still be amended.'**
  String get paneTxHintBody;

  /// MR7 expanded two-pane: the empty detail pane on the Settlements tab
  ///
  /// In en, this message translates to:
  /// **'Select a settlement'**
  String get paneSettlementHintTitle;

  /// No description provided for @paneSettlementHintBody.
  ///
  /// In en, this message translates to:
  /// **'Tap a settlement in the list to read its status, lines and payments here.'**
  String get paneSettlementHintBody;

  /// MR7 expanded two-pane: the empty editor pane on Manage Employees
  ///
  /// In en, this message translates to:
  /// **'Select an employee'**
  String get paneEmployeesHintTitle;

  /// No description provided for @paneEmployeesHintBody.
  ///
  /// In en, this message translates to:
  /// **'Tap a person to change their role or active state, or add a new account.'**
  String get paneEmployeesHintBody;

  /// MR7 expanded two-pane: the empty editor pane on Roles
  ///
  /// In en, this message translates to:
  /// **'Select a role'**
  String get paneRolesHintTitle;

  /// No description provided for @paneRolesHintBody.
  ///
  /// In en, this message translates to:
  /// **'Tap a role to edit its name and permissions, or create a new one.'**
  String get paneRolesHintBody;

  /// MR7 expanded two-pane: the empty editor pane on Manage Branches
  ///
  /// In en, this message translates to:
  /// **'Select a branch'**
  String get paneBranchesHintTitle;

  /// No description provided for @paneBranchesHintBody.
  ///
  /// In en, this message translates to:
  /// **'Tap a branch to edit its details and pin, or add a new location.'**
  String get paneBranchesHintBody;

  /// MR7 expanded two-pane: the empty builder pane on Promotions
  ///
  /// In en, this message translates to:
  /// **'Draft a promotion'**
  String get panePromoHintTitle;

  /// No description provided for @panePromoHintBody.
  ///
  /// In en, this message translates to:
  /// **'The builder opens here. A published promotion cannot be changed — drafts can.'**
  String get panePromoHintBody;

  /// No description provided for @menuPrivacy.
  ///
  /// In en, this message translates to:
  /// **'Privacy Policy'**
  String get menuPrivacy;

  /// No description provided for @menuTerms.
  ///
  /// In en, this message translates to:
  /// **'Terms of Service'**
  String get menuTerms;

  /// No description provided for @closeStoreRowHint.
  ///
  /// In en, this message translates to:
  /// **'Settle everything, then close the store for good.'**
  String get closeStoreRowHint;

  /// No description provided for @closeStoreTitle.
  ///
  /// In en, this message translates to:
  /// **'Close store'**
  String get closeStoreTitle;

  /// No description provided for @closeStoreSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Verify the store\'s contact number, then close a settled store.'**
  String get closeStoreSubtitle;

  /// No description provided for @closeStoreIrreversibleTitle.
  ///
  /// In en, this message translates to:
  /// **'Closing cannot be undone'**
  String get closeStoreIrreversibleTitle;

  /// No description provided for @closeStoreIrreversibleBody.
  ///
  /// In en, this message translates to:
  /// **'The store leaves Manfaa, crediting stops and every staff account is signed out. Your records stay with us as financial records.'**
  String get closeStoreIrreversibleBody;

  /// No description provided for @closeStorePhoneLabel.
  ///
  /// In en, this message translates to:
  /// **'Store contact number'**
  String get closeStorePhoneLabel;

  /// No description provided for @closeStorePhoneHint.
  ///
  /// In en, this message translates to:
  /// **'We\'ll text a 6-digit code to this number.'**
  String get closeStorePhoneHint;

  /// No description provided for @closeStoreSendCode.
  ///
  /// In en, this message translates to:
  /// **'Send code'**
  String get closeStoreSendCode;

  /// No description provided for @closeStoreVerify.
  ///
  /// In en, this message translates to:
  /// **'Verify'**
  String get closeStoreVerify;

  /// No description provided for @closeStoreStoresLead.
  ///
  /// In en, this message translates to:
  /// **'Stores on this number'**
  String get closeStoreStoresLead;

  /// No description provided for @closeStoreSettled.
  ///
  /// In en, this message translates to:
  /// **'Fully settled — can be closed.'**
  String get closeStoreSettled;

  /// No description provided for @closeStoreOwes.
  ///
  /// In en, this message translates to:
  /// **'Outstanding {amount} — settle before closing.'**
  String closeStoreOwes(String amount);

  /// No description provided for @closeStoreCta.
  ///
  /// In en, this message translates to:
  /// **'Close store'**
  String get closeStoreCta;

  /// No description provided for @closeStoreConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Close {store}?'**
  String closeStoreConfirmTitle(String store);

  /// No description provided for @closeStoreConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'This is permanent. The store leaves Manfaa immediately and every staff account is signed out.'**
  String get closeStoreConfirmBody;

  /// No description provided for @closeStoreConfirmCta.
  ///
  /// In en, this message translates to:
  /// **'Close permanently'**
  String get closeStoreConfirmCta;

  /// No description provided for @closeStoreClosed.
  ///
  /// In en, this message translates to:
  /// **'{store} is closed'**
  String closeStoreClosed(String store);

  /// No description provided for @closeStoreSignedOutNote.
  ///
  /// In en, this message translates to:
  /// **'You\'ll be signed out now.'**
  String get closeStoreSignedOutNote;

  /// No description provided for @closeStoreOtherStoreNote.
  ///
  /// In en, this message translates to:
  /// **'Your other stores are untouched.'**
  String get closeStoreOtherStoreNote;

  /// No description provided for @closeStoreNoStore.
  ///
  /// In en, this message translates to:
  /// **'No store is registered with this contact number.'**
  String get closeStoreNoStore;

  /// No description provided for @closeStorePhoneInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter the store\'s contact number — a Maldivian mobile starting with 7 or 9.'**
  String get closeStorePhoneInvalid;

  /// No description provided for @closeStoreOutstandingRefusal.
  ///
  /// In en, this message translates to:
  /// **'This store still owes a settlement. Settle it first, then close.'**
  String get closeStoreOutstandingRefusal;

  /// No description provided for @closeStoreExpired.
  ///
  /// In en, this message translates to:
  /// **'That verification expired. Start again.'**
  String get closeStoreExpired;

  /// No description provided for @staffNameLabel.
  ///
  /// In en, this message translates to:
  /// **'Name'**
  String get staffNameLabel;

  /// No description provided for @resetPasswordLabel.
  ///
  /// In en, this message translates to:
  /// **'Password'**
  String get resetPasswordLabel;

  /// No description provided for @resetPasswordHint.
  ///
  /// In en, this message translates to:
  /// **'Give this person a new one-time password. Their current one stops working immediately.'**
  String get resetPasswordHint;

  /// No description provided for @resetPasswordSelfHint.
  ///
  /// In en, this message translates to:
  /// **'Reset your own password. You\'ll be signed out and must sign in with the new one.'**
  String get resetPasswordSelfHint;

  /// No description provided for @resetPasswordCta.
  ///
  /// In en, this message translates to:
  /// **'Reset password'**
  String get resetPasswordCta;

  /// No description provided for @resetPasswordConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Reset the password for {name}?'**
  String resetPasswordConfirmTitle(String name);

  /// No description provided for @resetPasswordConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'{name} is signed out everywhere and can only sign in with the one-time password we show you next.'**
  String resetPasswordConfirmBody(String name);

  /// No description provided for @resetPasswordConfirmSelfBody.
  ///
  /// In en, this message translates to:
  /// **'You\'ll be signed out everywhere and must sign in again with the one-time password shown next. Copy it before closing.'**
  String get resetPasswordConfirmSelfBody;

  /// No description provided for @payAmountChangedTitle.
  ///
  /// In en, this message translates to:
  /// **'The amount to transfer changed'**
  String get payAmountChangedTitle;

  /// No description provided for @payAmountChangedBody.
  ///
  /// In en, this message translates to:
  /// **'Your balance moved while this screen was open. Transfer {amount} — the figure below is the current one.'**
  String payAmountChangedBody(String amount);

  /// No description provided for @splitAddRow.
  ///
  /// In en, this message translates to:
  /// **'Add row'**
  String get splitAddRow;

  /// No description provided for @splitSumIsEligible.
  ///
  /// In en, this message translates to:
  /// **'These rows are the eligible amount — they add up to the sale you\'re crediting.'**
  String get splitSumIsEligible;

  /// No description provided for @splitSearchHint.
  ///
  /// In en, this message translates to:
  /// **'Search categories'**
  String get splitSearchHint;

  /// No description provided for @mapTilesFailed.
  ///
  /// In en, this message translates to:
  /// **'The map couldn\'t load. Check your connection and try again.'**
  String get mapTilesFailed;

  /// No description provided for @pendingReviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Waiting for Manfaa\'s review'**
  String get pendingReviewTitle;

  /// No description provided for @pendingProfileNote.
  ///
  /// In en, this message translates to:
  /// **'Customers keep seeing the details below until Manfaa approves the change.'**
  String get pendingProfileNote;

  /// No description provided for @pendingReplaceNote.
  ///
  /// In en, this message translates to:
  /// **'Saving again replaces the request that is waiting.'**
  String get pendingReplaceNote;

  /// No description provided for @pendingBranchNote.
  ///
  /// In en, this message translates to:
  /// **'This branch keeps its current details until Manfaa approves the change.'**
  String get pendingBranchNote;

  /// No description provided for @pendingRemovalNote.
  ///
  /// In en, this message translates to:
  /// **'This branch stays on your list until Manfaa approves the removal.'**
  String get pendingRemovalNote;

  /// No description provided for @pendingCreateNote.
  ///
  /// In en, this message translates to:
  /// **'This branch appears in your list, and to customers, once Manfaa approves it.'**
  String get pendingCreateNote;

  /// No description provided for @changeKindProfile.
  ///
  /// In en, this message translates to:
  /// **'Store profile change'**
  String get changeKindProfile;

  /// No description provided for @changeKindBranchCreate.
  ///
  /// In en, this message translates to:
  /// **'New branch'**
  String get changeKindBranchCreate;

  /// No description provided for @changeKindBranchUpdate.
  ///
  /// In en, this message translates to:
  /// **'Branch update'**
  String get changeKindBranchUpdate;

  /// No description provided for @changeKindBranchDelete.
  ///
  /// In en, this message translates to:
  /// **'Branch removal'**
  String get changeKindBranchDelete;

  /// No description provided for @changeKindOther.
  ///
  /// In en, this message translates to:
  /// **'Store change'**
  String get changeKindOther;

  /// No description provided for @changeFieldWebsite.
  ///
  /// In en, this message translates to:
  /// **'Website'**
  String get changeFieldWebsite;

  /// No description provided for @changeFieldBranchName.
  ///
  /// In en, this message translates to:
  /// **'Branch name'**
  String get changeFieldBranchName;

  /// No description provided for @changeFieldAddress.
  ///
  /// In en, this message translates to:
  /// **'Address'**
  String get changeFieldAddress;

  /// No description provided for @changeFieldLocation.
  ///
  /// In en, this message translates to:
  /// **'Map pin'**
  String get changeFieldLocation;

  /// No description provided for @changeFieldOther.
  ///
  /// In en, this message translates to:
  /// **'Other details'**
  String get changeFieldOther;

  /// No description provided for @changeLogoValue.
  ///
  /// In en, this message translates to:
  /// **'New image'**
  String get changeLogoValue;

  /// No description provided for @sentForReview.
  ///
  /// In en, this message translates to:
  /// **'Sent for Manfaa\'s review'**
  String get sentForReview;

  /// No description provided for @logoSentForReview.
  ///
  /// In en, this message translates to:
  /// **'New logo sent for review — your current one stays up until it is approved.'**
  String get logoSentForReview;

  /// No description provided for @branchSentForReview.
  ///
  /// In en, this message translates to:
  /// **'New branch sent for Manfaa\'s review'**
  String get branchSentForReview;

  /// No description provided for @branchChangeSentForReview.
  ///
  /// In en, this message translates to:
  /// **'Branch changes sent for Manfaa\'s review'**
  String get branchChangeSentForReview;

  /// No description provided for @branchRemovalSentForReview.
  ///
  /// In en, this message translates to:
  /// **'Removal sent for Manfaa\'s review'**
  String get branchRemovalSentForReview;

  /// No description provided for @referenceComesLaterNote.
  ///
  /// In en, this message translates to:
  /// **'No reference is needed for the transfer — Manfaa creates one when you upload the receipt, and you\'ll see it on the settlement.'**
  String get referenceComesLaterNote;

  /// No description provided for @saleTimeLabel.
  ///
  /// In en, this message translates to:
  /// **'Sale time'**
  String get saleTimeLabel;

  /// No description provided for @saleTimeNow.
  ///
  /// In en, this message translates to:
  /// **'Now'**
  String get saleTimeNow;

  /// No description provided for @saleTimeSetNow.
  ///
  /// In en, this message translates to:
  /// **'Now'**
  String get saleTimeSetNow;

  /// No description provided for @discountSaveByTitle.
  ///
  /// In en, this message translates to:
  /// **'Save {amount} by settling before {date}'**
  String discountSaveByTitle(String amount, String date);

  /// No description provided for @discountSaveBySub.
  ///
  /// In en, this message translates to:
  /// **'{rate} prompt-payment discount on platform fees'**
  String discountSaveBySub(String rate);

  /// No description provided for @discountNoneSub.
  ///
  /// In en, this message translates to:
  /// **'The prompt-payment discount no longer applies to your oldest sale.'**
  String get discountNoneSub;

  /// No description provided for @viewBreakdown.
  ///
  /// In en, this message translates to:
  /// **'View breakdown'**
  String get viewBreakdown;

  /// No description provided for @monthTitle.
  ///
  /// In en, this message translates to:
  /// **'This month'**
  String get monthTitle;

  /// No description provided for @monthSales.
  ///
  /// In en, this message translates to:
  /// **'Sales through Manfaa'**
  String get monthSales;

  /// No description provided for @monthTransactions.
  ///
  /// In en, this message translates to:
  /// **'Transactions'**
  String get monthTransactions;

  /// No description provided for @monthCashback.
  ///
  /// In en, this message translates to:
  /// **'Cashback issued'**
  String get monthCashback;

  /// No description provided for @monthAverage.
  ///
  /// In en, this message translates to:
  /// **'Avg. transaction'**
  String get monthAverage;

  /// No description provided for @branchAddressRequired.
  ///
  /// In en, this message translates to:
  /// **'Add the address so customers know where to go.'**
  String get branchAddressRequired;

  /// No description provided for @branchAddressFromPin.
  ///
  /// In en, this message translates to:
  /// **'Use the pin'**
  String get branchAddressFromPin;

  /// No description provided for @branchAddressFromPinEmpty.
  ///
  /// In en, this message translates to:
  /// **'No address found for that spot — please type it in.'**
  String get branchAddressFromPinEmpty;

  /// No description provided for @branchAddressHint.
  ///
  /// In en, this message translates to:
  /// **'Customers see this on your store page.'**
  String get branchAddressHint;

  /// No description provided for @visibilityOn.
  ///
  /// In en, this message translates to:
  /// **'On the app'**
  String get visibilityOn;

  /// No description provided for @visibilityOff.
  ///
  /// In en, this message translates to:
  /// **'Paused'**
  String get visibilityOff;

  /// No description provided for @visibilityOnHint.
  ///
  /// In en, this message translates to:
  /// **'Customers can find your store and earn cashback.'**
  String get visibilityOnHint;

  /// No description provided for @visibilityOffHint.
  ///
  /// In en, this message translates to:
  /// **'Your store is hidden from the app and is not giving cashback. Your history, settlements and staff are untouched.'**
  String get visibilityOffHint;

  /// No description provided for @visibilityPause.
  ///
  /// In en, this message translates to:
  /// **'Pause store'**
  String get visibilityPause;

  /// No description provided for @visibilityResume.
  ///
  /// In en, this message translates to:
  /// **'Put store back on the app'**
  String get visibilityResume;

  /// No description provided for @visibilityPauseConfirm.
  ///
  /// In en, this message translates to:
  /// **'Pause your store?'**
  String get visibilityPauseConfirm;

  /// No description provided for @visibilityPauseConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'Customers will not find you on the app and you will not be able to give cashback until you turn it back on. Customers who have earned cashback with you will be told.'**
  String get visibilityPauseConfirmBody;

  /// No description provided for @visibilityPaused.
  ///
  /// In en, this message translates to:
  /// **'Your store is paused.'**
  String get visibilityPaused;

  /// No description provided for @visibilityPausedNotified.
  ///
  /// In en, this message translates to:
  /// **'Your store is paused. Customers who have earned cashback with you have been told.'**
  String get visibilityPausedNotified;

  /// No description provided for @visibilityResumed.
  ///
  /// In en, this message translates to:
  /// **'Your store is back on the app.'**
  String get visibilityResumed;

  /// No description provided for @visibilityResumedNotified.
  ///
  /// In en, this message translates to:
  /// **'Your store is back on the app. Customers who have earned cashback with you have been told.'**
  String get visibilityResumedNotified;

  /// No description provided for @reasonMerchantUnpublished.
  ///
  /// In en, this message translates to:
  /// **'Store paused'**
  String get reasonMerchantUnpublished;

  /// No description provided for @branchAddressFinding.
  ///
  /// In en, this message translates to:
  /// **'Finding…'**
  String get branchAddressFinding;
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
