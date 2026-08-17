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
  String get missingContact => 'Add a contact phone number';

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

  @override
  String get cancel => 'Cancel';

  @override
  String get save => 'Save';

  @override
  String get optionalLabel => 'optional';

  @override
  String get creditTitle => 'Credit customer';

  @override
  String get creditSubtitle =>
      'Record a sale and credit the customer\'s cashback.';

  @override
  String get modeEnterCode => 'Enter code';

  @override
  String get modeScanQr => 'Scan QR';

  @override
  String get modeRecent => 'Recent';

  @override
  String get codeAskHint =>
      'Ask the customer for the 6-digit code shown in their Manfaa app.';

  @override
  String get lookupChecking => 'Checking code…';

  @override
  String get lookupVerified => 'Verified';

  @override
  String get lookupNotFoundTitle => 'We don\'t recognise this code';

  @override
  String get lookupNotFoundBody =>
      'Check the digits with the customer before trying again — a typo here credits a stranger.';

  @override
  String get lookupFailed => 'Could not check this code — try again.';

  @override
  String get scanPrompt =>
      'Point the camera at the QR code in the customer\'s Manfaa app.';

  @override
  String get scanDenied =>
      'Camera access is blocked. Allow the camera in your phone\'s settings, or type the 6-digit code.';

  @override
  String get scanFallbackHint => 'You can always type the code by hand.';

  @override
  String get recentEmptyTitle => 'No recent customers';

  @override
  String get recentEmptyBody =>
      'Customers you credit appear here for quick reuse.';

  @override
  String get invoiceLabel => 'Invoice number';

  @override
  String get invoiceHint =>
      'Required — one credit per invoice, exactly as printed on the receipt.';

  @override
  String get saleDateTimeLabel => 'Sale date & time';

  @override
  String get saleDateTimeNow => 'Recorded as right now unless you change it.';

  @override
  String get saleDateTimeEdited => 'Will be recorded at the time shown.';

  @override
  String get saleDateTimeSetNow => 'Set to now';

  @override
  String get eligibleLabel => 'Eligible amount';

  @override
  String get eligibleHint =>
      'The part of the bill cashback is computed on, per your agreement.';

  @override
  String get eligibleInvalid => 'Enter a valid amount, e.g. 1,250.00.';

  @override
  String get fullSaleLabel => 'Full sale amount';

  @override
  String get fullSaleHint =>
      'The whole invoice total — reference only, never used in computation.';

  @override
  String get fullSaleInvalid =>
      'Must be a valid amount, at least the eligible amount.';

  @override
  String get customRateToggle => 'Custom cashback for this sale';

  @override
  String get customRateToggleHint =>
      'Pay this customer more than your usual rate.';

  @override
  String get customRateLabel => 'Cashback for this sale';

  @override
  String customRateHint(String rate) {
    return 'Must be $rate or higher — you can only raise your advertised rate, never lower it. The customer is told the higher figure.';
  }

  @override
  String get customRateHintNoRate =>
      'Your store has no cashback rate in effect yet — contact Manfaa before crediting customers.';

  @override
  String get customRateFormat =>
      'Enter a percent with up to two decimal places, e.g. 7.50.';

  @override
  String customRateRange(String min, String max) {
    return 'Cashback runs from $min to $max.';
  }

  @override
  String customRateTooLow(String rate) {
    return 'This sale already earns $rate. A custom rate can only be higher — your advertised rate is a promise to the customer.';
  }

  @override
  String get customRateRefusedTitle =>
      'That rate is lower than this sale already earns.';

  @override
  String customRateRefusedBody(String rate) {
    return 'This sale earns $rate — your standing rate, or a promotion running right now. Enter a higher rate, or switch the custom rate off.';
  }

  @override
  String get customRateRefusedBodyNoRate =>
      'A custom rate can only raise what this sale already earns. Enter a higher rate, or switch the custom rate off.';

  @override
  String get customRateNotPricedTitle => 'That rate isn\'t available yet.';

  @override
  String get splitToggle => 'Split by category';

  @override
  String get splitToggleHint => 'Split this sale into product categories.';

  @override
  String get splitBreakdownTitle => 'Category breakdown';

  @override
  String get splitAddCategory => 'Add category';

  @override
  String get splitEverythingElse => 'Everything else';

  @override
  String get splitEditLine => 'Edit line';

  @override
  String get splitRemoveLine => 'Remove line';

  @override
  String get splitDialogTitleAdd => 'Add category amount';

  @override
  String get splitDialogTitleEdit => 'Edit category amount';

  @override
  String get splitCategoryLabel => 'Category';

  @override
  String get splitAmountLabel => 'Amount';

  @override
  String get splitAmountInvalid => 'Enter a valid amount of at least MVR 0.01.';

  @override
  String get splitExcludedNote => 'No cashback — still part of the total.';

  @override
  String get splitEmptyHint =>
      'Add the sale\'s parts — the lines must add up to the eligible amount, excluded categories included.';

  @override
  String get splitOtherChip => 'Other';

  @override
  String get splitLinesTotal => 'Lines total';

  @override
  String get splitMismatchTitle =>
      'The lines don\'t add up to the eligible amount.';

  @override
  String splitMismatchBody(String difference) {
    return 'Difference: $difference. Every part of the bill goes in, including the excluded ones — the credit can\'t be recorded until the two match.';
  }

  @override
  String get costPreviewTitle => 'Cost preview';

  @override
  String previewCashback(String rate) {
    return 'Customer cashback ($rate)';
  }

  @override
  String previewFee(String rate) {
    return 'Platform fee ($rate)';
  }

  @override
  String previewYouPay(String rate) {
    return 'You pay ($rate)';
  }

  @override
  String get previewPerLine => 'per line';

  @override
  String get previewEstimateNote =>
      'Estimate — final amounts use the rate at the sale time.';

  @override
  String get previewNoRate =>
      'No cashback rate is in effect yet — contact Manfaa before crediting customers.';

  @override
  String get previewUnavailable =>
      'Your current rate is unavailable right now — the credit still uses the correct rate on the server.';

  @override
  String get backdatedTitle => 'Backdated sale — this credit is final';

  @override
  String get backdatedBody =>
      'This sale is older than your refund window — once credited it cannot be reversed, and it becomes payable to Manfaa immediately.';

  @override
  String get backdatedMaybeTitle => 'This sale may be backdated';

  @override
  String get backdatedMaybeBody =>
      'This sale may be older than your refund window. If it is, once credited it cannot be reversed, and it becomes payable to Manfaa immediately.';

  @override
  String get backdatedConfirm =>
      'I understand: this credit cannot be reversed and is payable now.';

  @override
  String get backdatedConfirmRequired =>
      'Confirm you understand before recording this credit.';

  @override
  String get backdatedResultTitle => 'Credited — final and payable now';

  @override
  String get backdatedResultBody =>
      'This backdated sale skipped the refund window. The customer\'s cashback is payable to Manfaa immediately, and neither you nor your POS can reverse it — contact Manfaa if it needs correcting.';

  @override
  String get pendingNote =>
      'Credited cashback will appear as pending. It will be validated and released to the customer after settlement.';

  @override
  String get creditCta => 'Credit customer';

  @override
  String get errDuplicateInvoiceTitle => 'This invoice is already credited.';

  @override
  String get errDuplicateInvoiceBody =>
      'Each invoice can be credited once. If this is a different sale, check the invoice number on the receipt.';

  @override
  String get errSuspendedTitle =>
      'Your store is suspended — new cashback is paused.';

  @override
  String get errSuspendedBody =>
      'Settle your outstanding balance to resume crediting customers, or contact Manfaa if you believe this is wrong.';

  @override
  String get errCreditFailed => 'Could not record the credit.';

  @override
  String queuedBannerTitle(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count sales waiting to sync',
      one: '1 sale waiting to sync',
    );
    return '$_temp0';
  }

  @override
  String get queuedBannerBody =>
      'They\'ll be sent automatically when you\'re back online.';

  @override
  String get queuedBannerAction => 'Sync now';

  @override
  String get queuedSnack =>
      'No connection — the sale is saved and will sync automatically.';

  @override
  String get attentionTitle => 'Needs attention';

  @override
  String get attentionBody =>
      'These queued sales were refused when they synced — review each one.';

  @override
  String get attentionRetry => 'Try again';

  @override
  String get attentionDiscard => 'Dismiss';

  @override
  String get resultRecordedTitle => 'Cashback recorded';

  @override
  String get resultBelowMinTitle => 'Recorded — no reward';

  @override
  String get resultBelowMinBody =>
      'This sale is below your store\'s minimum eligible amount. It was recorded with zero cashback — the customer earns nothing on it and nothing is payable.';

  @override
  String get resultOnHoldTitle => 'Recorded — under review';

  @override
  String get resultOnHoldBody =>
      'The sale is on hold while Manfaa checks it. It counts for the customer once the review clears it; nothing else is needed from you.';

  @override
  String get resultReplayedNote =>
      'This sale was already recorded earlier — this is the original result.';

  @override
  String get resultCustomer => 'Customer';

  @override
  String get resultInvoice => 'Invoice';

  @override
  String get resultState => 'State';

  @override
  String get resultEligible => 'Eligible amount';

  @override
  String get resultYouPay => 'You pay';

  @override
  String get resultLinesTitle => 'Priced lines';

  @override
  String get creditAnother => 'Credit another customer';

  @override
  String get stateTracked => 'Recorded';

  @override
  String get stateAwaitingValidation => 'In refund window';

  @override
  String get statePayableUnfunded => 'Payable to Manfaa';

  @override
  String get stateOnHold => 'Under review';

  @override
  String get stateConfirmed => 'Confirmed';

  @override
  String get statePaid => 'Paid to customer';

  @override
  String get stateReversed => 'Reversed';

  @override
  String get stateWrittenOff => 'Written off — unpaid';

  @override
  String get stateOther => 'Updated';

  @override
  String get reasonAutoValidation => 'Validated automatically';

  @override
  String get reasonBackdatedFinal => 'Backdated — cannot be reversed';

  @override
  String get reasonBelowMinimum => 'Below minimum sale';

  @override
  String get reasonMerchantSuspended => 'Store suspended';

  @override
  String get reasonSettlementAllocated => 'Paid by store';

  @override
  String get reasonPayoutCompleted => 'Paid out';

  @override
  String get reasonCustomerRefund => 'Refunded';

  @override
  String get reasonTillVoid => 'Voided at the till';

  @override
  String get reasonDuplicate => 'Duplicate sale';

  @override
  String get reasonOtherCorrected => 'Corrected — other reason';

  @override
  String get reasonStaleTimestamp => 'Held — sale was backdated';

  @override
  String get reasonUnknown => 'Updated by Manfaa';

  @override
  String get transactionsTitle => 'Transactions';

  @override
  String get filterAll => 'All states';

  @override
  String get txEmptyTitle => 'No transactions yet';

  @override
  String get txEmptyBody => 'Sales you credit appear here.';

  @override
  String get txEmptyFiltered => 'No transactions match this filter.';

  @override
  String get backdatedChip => 'Backdated';

  @override
  String get amendAction => 'Correct amount';

  @override
  String get cancelAction => 'Cancel sale';

  @override
  String get amendTitle => 'Correct the amount';

  @override
  String amendBody(String invoiceNo) {
    return 'Fix what was rung up on $invoiceNo. The cashback is recalculated at the same rate the sale was recorded under.';
  }

  @override
  String get amendEligibleLabel => 'Eligible amount';

  @override
  String get amendHint =>
      'The part of the bill cashback is computed on, as it should have been.';

  @override
  String get amendLinesLabel => 'Category amounts';

  @override
  String get amendLinesHint => 'The eligible amount adds up from these lines:';

  @override
  String amendCurrent(String amount, String cashback) {
    return 'Currently $amount · cashback $cashback';
  }

  @override
  String get amendSubmit => 'Save correction';

  @override
  String get amendDone => 'Amount corrected.';

  @override
  String get amendFailed => 'Could not correct the amount.';

  @override
  String get cancelTitle => 'Cancel this sale';

  @override
  String cancelBody(String invoiceNo) {
    return '$invoiceNo comes off entirely and the cashback is taken back.';
  }

  @override
  String get cancelReasonLabel => 'Reason';

  @override
  String get cancelNoteLabel => 'Note (optional)';

  @override
  String get cancelWarning =>
      'The customer has already been told they earned this. Cancelling removes it from their balance.';

  @override
  String get cancelSubmit => 'Cancel sale';

  @override
  String get cancelDone => 'Sale cancelled.';

  @override
  String get cancelFailed => 'Could not cancel the sale.';

  @override
  String get keepSale => 'Keep sale';

  @override
  String get cancelReasonRefund => 'Refunded';

  @override
  String get cancelReasonVoid => 'Voided at the till';

  @override
  String get cancelReasonDuplicate => 'Duplicate entry';

  @override
  String get cancelReasonError => 'Keyed in error';

  @override
  String get errNotAmendable =>
      'This sale can no longer be changed — its window has closed.';

  @override
  String get errBackdatedIrreversible =>
      'Backdated credits can never be reversed.';

  @override
  String get todayTitle => 'Today';

  @override
  String get todayCredits => 'Credits';

  @override
  String get todayEligible => 'Eligible sales';

  @override
  String get todayCashback => 'Cashback';
}
