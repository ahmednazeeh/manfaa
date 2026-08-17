// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appTitle => 'Manfaa Merchant';

  @override
  String get tabDashboard => 'Dashboard';

  @override
  String get tabCredit => 'Credit';

  @override
  String get tabTransactions => 'Transactions';

  @override
  String get tabSettlements => 'Settlements';

  @override
  String get tabMore => 'More';

  @override
  String get loginTitle => 'Merchant login';

  @override
  String get loginSubtitle =>
      'Sign in to manage cashback, settlements, and customer credits.';

  @override
  String get emailLabel => 'Email';

  @override
  String get emailHint => 'Enter your email';

  @override
  String get passwordLabel => 'Password';

  @override
  String get passwordHint => 'Enter your password';

  @override
  String get logIn => 'Log in';

  @override
  String get showPassword => 'Show password';

  @override
  String get hidePassword => 'Hide password';

  @override
  String get securityTitle => 'Secure merchant access';

  @override
  String get securityBody =>
      'Your business data is protected with enterprise-grade security.';

  @override
  String get protectedBadge => 'Protected';

  @override
  String get errBadCredentials =>
      'That email or password isn\'t right. Check them and try again.';

  @override
  String get errMissingCredentials => 'Enter your email and password.';

  @override
  String get errTooManyTries =>
      'Too many attempts. Please wait a moment and try again.';

  @override
  String get errorGeneric => 'Something went wrong. Please try again.';

  @override
  String get retry => 'Try again';

  @override
  String get updateRequiredTitle => 'Update needed';

  @override
  String get updateRequiredBody =>
      'This version of Manfaa Merchant can no longer be used. Please update to continue.';

  @override
  String get setupPendingTitle => 'Your store is under review';

  @override
  String get setupPendingBody =>
      'We\'re checking your details. You can start crediting customers the moment Manfaa approves your store.';

  @override
  String get signOut => 'Sign out';

  @override
  String get comingSoonTitle => 'On its way';

  @override
  String get dashboardComingBody =>
      'Outstanding to settle, aging buckets and your settlement deadline will live here.';

  @override
  String get creditComingBody =>
      'Credit a customer\'s cashback in seconds at the counter — the till lands here next.';

  @override
  String get transactionsComingBody =>
      'Every sale you\'ve credited will live here.';

  @override
  String get settlementsComingBody =>
      'Amounts due, the prompt-payment discount and your receipts will live here.';

  @override
  String get moreComingBody =>
      'Profile, employees, roles, branches and cashback settings will live here.';

  @override
  String get continueLabel => 'Continue';

  @override
  String get back => 'Back';

  @override
  String get skip => 'Skip';

  @override
  String get skipForNow => 'Skip for now';

  @override
  String get notSet => 'Not set';

  @override
  String get newMerchantPrompt => 'New merchant?';

  @override
  String get registerStore => 'Register your store';

  @override
  String get signupTitle => 'Register your store';

  @override
  String get signupSubtitle =>
      'A few minutes of setup and your store starts rewarding customers.';

  @override
  String get phoneLabel => 'Phone number';

  @override
  String get phoneHint => '7XXX XXXX';

  @override
  String get otpDeliveryNote =>
      'We\'ll send a code by SMS to verify your number.';

  @override
  String get codeTitle => 'Enter the code';

  @override
  String codeSentTo(String phone) {
    return 'We sent a 6-digit code to $phone.';
  }

  @override
  String get verifyLabel => 'Verify';

  @override
  String get resendCode => 'Resend code';

  @override
  String resendCountdown(int seconds) {
    return 'Resend in ${seconds}s';
  }

  @override
  String get detailsTitle => 'Tell us about your store';

  @override
  String get detailsSubtitle => 'This is how customers will see you on Manfaa.';

  @override
  String get businessNameLabel => 'Store name';

  @override
  String get businessNameHint => 'e.g. Fresh Mart';

  @override
  String get businessNameDvLabel => 'Store name in Dhivehi (optional)';

  @override
  String get passwordRule => 'At least 8 characters.';

  @override
  String get createStore => 'Create my store';

  @override
  String get alreadyRegistered => 'Already have an account?';

  @override
  String get errOtpInvalid =>
      'That code isn\'t right or has expired. Check it, or request a fresh one.';

  @override
  String get errOtpAttempts =>
      'Too many wrong codes. Request a fresh one and try again.';

  @override
  String get errSignupExpired =>
      'That verification expired. Start again with your phone number.';

  @override
  String get errEmailRegistered =>
      'That email already has a merchant account. Log in instead.';

  @override
  String get errSignupDetailsMissing =>
      'Enter the store name, an email, and a password of at least 8 characters.';

  @override
  String get setupTitle => 'Set up your store';

  @override
  String setupStepOf(int current, int total) {
    return 'Step $current of $total';
  }

  @override
  String get stepProfile => 'Profile';

  @override
  String get stepLocation => 'Location';

  @override
  String get stepLogo => 'Logo';

  @override
  String get stepRate => 'Rate';

  @override
  String get stepTerms => 'Terms';

  @override
  String get stepReview => 'Review';

  @override
  String get profileTitle => 'Store profile';

  @override
  String get categoryLabel => 'Category';

  @override
  String get categoryHint =>
      'Pick the closest match — customers browse by category.';

  @override
  String get categoryRequired => 'Pick a category to continue.';

  @override
  String get channelLabel => 'Where do you sell?';

  @override
  String get channelInStore => 'In-store';

  @override
  String get channelOnline => 'Online';

  @override
  String get channelBoth => 'Both';

  @override
  String get channelInStoreHint => 'Customers walk in to buy.';

  @override
  String get channelOnlineHint => 'Orders come in online.';

  @override
  String get channelBothHint => 'A counter and online orders.';

  @override
  String get contactEmailLabel => 'Contact email';

  @override
  String get contactPhoneLabel => 'Contact phone';

  @override
  String get supportPhoneLabel => 'Customer support number';

  @override
  String get supportSameAsContact => 'Same as contact number';

  @override
  String get websiteLabel => 'Website (optional)';

  @override
  String get locationTitle => 'Store location';

  @override
  String get locationSubtitle =>
      'Drag the map until the pin sits on your storefront.';

  @override
  String get locationOnlineNote =>
      'You sell online only — you can skip the pin.';

  @override
  String get myLocation => 'Use my location';

  @override
  String get locationDenied => 'Location permission was denied.';

  @override
  String get locationFailed => 'Couldn\'t get your location.';

  @override
  String get logoTitle => 'Store logo';

  @override
  String get logoSubtitle => 'Optional — a logo makes your store easy to spot.';

  @override
  String get chooseLogo => 'Choose logo';

  @override
  String get replaceLogo => 'Replace logo';

  @override
  String get logoUploading => 'Uploading…';

  @override
  String get logoHint => 'JPG, PNG or WebP, up to 2 MB.';

  @override
  String get logoUploaded => 'Logo uploaded.';

  @override
  String get errLogoType => 'Use a JPG, PNG or WebP image.';

  @override
  String get errLogoSize => 'That image is over 2 MB. Pick a smaller one.';

  @override
  String get rateTitle => 'Cashback rate';

  @override
  String get rateSubtitle =>
      'What customers earn on eligible spending at your store.';

  @override
  String get rateLabel => 'Cashback rate';

  @override
  String rateRangeHint(String min, String max) {
    return 'Between $min and $max.';
  }

  @override
  String get ratePreviewCashback => 'Customer earns';

  @override
  String get ratePreviewFee => 'Platform fee';

  @override
  String get ratePreviewAllIn => 'Your all-in cost';

  @override
  String get ratePreviewNote =>
      'Estimate — the platform fee follows the published fee schedule.';

  @override
  String get errRateFormat => 'Enter a rate like 2 or 2.5.';

  @override
  String errRateMin(String min) {
    return 'The minimum is $min.';
  }

  @override
  String errRateMax(String max) {
    return 'The maximum is $max.';
  }

  @override
  String get rateNotPricedTitle => 'That rate isn\'t available';

  @override
  String get termsTitle => 'What earns cashback';

  @override
  String get termsLabel => 'Eligible spending';

  @override
  String get termsPlaceholder =>
      'e.g. Everything in store except tobacco and top-ups.';

  @override
  String get termsGuidance =>
      'Customers see this before they shop — be specific.';

  @override
  String get termsRequired => 'Describe what earns cashback to continue.';

  @override
  String get reviewTitle => 'Review & submit';

  @override
  String get reviewSubtitle =>
      'Check everything — Manfaa reviews your store before it goes live.';

  @override
  String get reviewName => 'Store name';

  @override
  String get reviewCategory => 'Category';

  @override
  String get reviewChannel => 'Channel';

  @override
  String get reviewLocation => 'Location';

  @override
  String get reviewLogo => 'Logo';

  @override
  String get reviewRate => 'Cashback rate';

  @override
  String get reviewTerms => 'What earns cashback';

  @override
  String rateSummary(String rate, String fee, String allIn) {
    return '$rate · $fee fee · $allIn all-in';
  }

  @override
  String get noLocation => 'No pin';

  @override
  String get noLogo => 'No logo';

  @override
  String get submitForReview => 'Submit for review';

  @override
  String get missingTitle => 'Still needed before review';

  @override
  String get missingCategory => 'Pick a category';

  @override
  String get missingChannel => 'Choose where you sell';

  @override
  String get missingRate => 'Set your cashback rate';

  @override
  String get missingTerms => 'Describe what earns cashback';

  @override
  String get edit => 'Edit';

  @override
  String get errSetupNotEditable =>
      'The store setup can\'t be edited while it\'s under review.';

  @override
  String get rejectedTitle => 'Changes requested';

  @override
  String get rejectedBody =>
      'Manfaa reviewed your store and asked for changes:';

  @override
  String get editAndResubmit => 'Edit and resubmit';

  @override
  String pendingSubmittedAt(String date) {
    return 'Submitted $date';
  }

  @override
  String get staffSetupTitle => 'Setup needs the owner';

  @override
  String get staffSetupBody =>
      'This store hasn\'t finished setup, and your role can\'t edit it. Ask the store owner to finish it on their account.';
}
