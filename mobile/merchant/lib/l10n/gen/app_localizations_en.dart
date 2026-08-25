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
  String get descriptionLabel => 'Store description';

  @override
  String get descriptionPlaceholder =>
      'e.g. A neighbourhood grocery stocking fresh produce, household basics and the daily essentials.';

  @override
  String get descriptionHint =>
      'Up to 180 words — shoppers read this on your store page.';

  @override
  String get descriptionRequired => 'Describe your store to continue.';

  @override
  String get descriptionTooLong =>
      'That is over 180 words. Shorten it to continue.';

  @override
  String descriptionWordCount(int count, int max) {
    return '$count / $max words';
  }

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
  String get reviewDescription => 'Description';

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
  String get missingDescription => 'Describe your store';

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
  String get eligibleLabel => 'Eligible amount';

  @override
  String get eligibleInvalid => 'Enter a valid amount, e.g. 1,250.00.';

  @override
  String get fullSaleLabel => 'Full sale amount';

  @override
  String get fullSaleInvalid =>
      'Must be a valid amount, at least the eligible amount.';

  @override
  String get customRateToggle => 'Custom cashback for this sale';

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
  String previewGst(String rate) {
    return 'GST on fee ($rate)';
  }

  @override
  String previewGstIncluded(String rate) {
    return 'GST included in fee ($rate)';
  }

  @override
  String previewYouPay(String rate) {
    return 'You pay ($rate)';
  }

  @override
  String get previewPerLine => 'per line';

  @override
  String get previewEstimateNote => 'Estimate — priced at the sale time.';

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
  String get pendingNote => 'Cashback stays pending until settlement.';

  @override
  String get creditCta => 'Credit customer';

  @override
  String get creditCtaShort => 'Credit now';

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

  @override
  String get dashOutstandingTitle => 'Outstanding to settle';

  @override
  String get dashOutstandingSub => 'Cashback owed to platform';

  @override
  String get settleNow => 'Settle now';

  @override
  String discountDeadlineTitle(String rate, String date) {
    return 'Your oldest sale stops earning the $rate prompt-payment discount on $date';
  }

  @override
  String discountDeadlineBody(String amount) {
    return 'Settle everything outstanding before then and save $amount';
  }

  @override
  String get bucketOverdue => 'Overdue';

  @override
  String bucketTransactions(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count transactions',
      one: '1 transaction',
    );
    return '$_temp0';
  }

  @override
  String get payableBreakdownTitle => 'Payable breakdown';

  @override
  String get payableCashback => 'Customer cashback';

  @override
  String get payableFee => 'Platform fee';

  @override
  String get payableGst => 'GST on fee';

  @override
  String get payableOutstandingCount => 'Outstanding transactions';

  @override
  String get payablePendingCredit => 'Pending adjustment credit';

  @override
  String get walletCardTitle => 'Wallet';

  @override
  String get walletCardHint =>
      'Available to fund settlements instead of a bank transfer.';

  @override
  String get walletViewMovements => 'View movements';

  @override
  String get walletUnavailable => 'Wallet unavailable right now.';

  @override
  String get creditCtaTitle => 'Credit customer cashback';

  @override
  String get creditCtaBody => 'Issue cashback directly to your customers.';

  @override
  String discountReasonNotAll(String rate) {
    return 'Settle everything outstanding to earn $rate off the platform fee.';
  }

  @override
  String discountReasonTooOld(int days) {
    return 'One of these sales is $days days or older, so no prompt-payment discount applies.';
  }

  @override
  String get discountReasonClockNotStarted =>
      'One of these sales has no settlement date on record, so no prompt-payment discount applies — contact Manfaa to fix it.';

  @override
  String discountNudgeTitle(String rate) {
    return 'Settle everything outstanding for $rate off the platform fee';
  }

  @override
  String discountNudgeSaving(String amount) {
    return 'You would save $amount';
  }

  @override
  String get settlementsTitle => 'Settlements';

  @override
  String get settlementsSubtitle =>
      'Pay outstanding cashback and fees to Manfaa.';

  @override
  String get amountDueNow => 'Amount due now';

  @override
  String outstandingTxCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count outstanding transactions',
      one: '1 outstanding transaction',
    );
    return '$_temp0';
  }

  @override
  String get payNow => 'Pay now';

  @override
  String discountKeepTitle(int days, String rate) {
    return 'Pay within $days days to keep your $rate prompt-payment discount.';
  }

  @override
  String oldestDueDate(String date) {
    return 'Oldest due date: $date';
  }

  @override
  String get paymentMethodTitle => 'Payment method';

  @override
  String get methodWalletTitle => 'Wallet balance';

  @override
  String get methodBankTitle => 'Bank transfer';

  @override
  String get methodRecommended => 'Recommended';

  @override
  String get totalDueLabel => 'Total due';

  @override
  String get includedTitle => 'Included transactions';

  @override
  String get pickerTitle => 'Choose transactions';

  @override
  String get pickerLead =>
      'Tick the sales you are paying for. Every amount here is what Manfaa has recorded against that sale.';

  @override
  String get pickerRepriceTitle => 'Changing the selection re-prices the batch';

  @override
  String get pickerRepriceBody =>
      'Manfaa prices the new selection on its own server. A narrower batch can lose the prompt-payment discount.';

  @override
  String get pickerSelectAll => 'Select all shown';

  @override
  String get pickerNoneInFilter => 'No transactions in this filter.';

  @override
  String get pickerPastWindow => 'Too old for the discount';

  @override
  String pickerAgeDays(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count days old',
      one: '1 day old',
    );
    return '$_temp0';
  }

  @override
  String pickerSelectedCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count transactions selected',
      one: '1 transaction selected',
    );
    return '$_temp0';
  }

  @override
  String get pickerTotalHint =>
      'Credits and any discount are applied when Manfaa prices the batch.';

  @override
  String get pickerApplyCta => 'Use this selection';

  @override
  String get awaitingSettlementChip => 'Awaiting settlement';

  @override
  String get feeShort => 'Fee';

  @override
  String get gstShort => 'GST';

  @override
  String discountRow(String rate) {
    return 'Prompt payment discount ($rate)';
  }

  @override
  String discountAdvisoryNote(int days) {
    return 'Confirmed when you submit the slip. If a sale reaches $days days before then, the full fee is due.';
  }

  @override
  String get creditAppliedRow => 'Credit applied';

  @override
  String get creditAppliedHint =>
      'Credit from earlier adjustments, netted off this batch.';

  @override
  String get recentTitle => 'Recent settlements';

  @override
  String payAmountCta(String amount) {
    return 'Pay $amount';
  }

  @override
  String get confirmNothingDueCta => 'Confirm settlement';

  @override
  String get nothingDueTitle => 'Your credit covers this fully';

  @override
  String get nothingDueBody =>
      'There is nothing to transfer. Confirming closes these transactions against your credit.';

  @override
  String get presetOlder5 => 'Older than 5 days';

  @override
  String get presetOlder10 => 'Older than 10 days';

  @override
  String get settleEverythingCta => 'Settle everything outstanding';

  @override
  String get emptySettleTitle => 'Nothing to settle right now';

  @override
  String get emptySettleBody =>
      'Transactions appear here when their validation window ends.';

  @override
  String get noSettlementsYet => 'No settlements yet.';

  @override
  String get settlePreviewFailed => 'Couldn\'t price this selection.';

  @override
  String get payBankTitle => 'Transfer exactly this amount';

  @override
  String get amountToTransfer => 'Amount to transfer';

  @override
  String get amountCoversTitle => 'This amount covers';

  @override
  String get referenceLabel => 'Payment reference';

  @override
  String get referenceFinalNote =>
      'Quote this reference with your transfer — it is how we recognise your money.';

  @override
  String get transferToLabel => 'Transfer to';

  @override
  String get bankNameLabel => 'Bank';

  @override
  String get accountNoLabel => 'Account number';

  @override
  String get accountNameLabel => 'Account name';

  @override
  String get copyTooltip => 'Copy';

  @override
  String get copiedToast => 'Copied';

  @override
  String get chooseBankLabel => 'Choose the bank you\'ll transfer to';

  @override
  String get chooseBankFirst =>
      'Pick a bank above to see where to send the money.';

  @override
  String get noAccountTitle => 'Transfer details not published yet';

  @override
  String get noAccountBody =>
      'Contact Manfaa for the account to send to. Nothing is lost — come back with the slip after you transfer.';

  @override
  String get uploadSlipTitle => 'Upload the transfer slip';

  @override
  String get slipTakePhoto => 'Take photo';

  @override
  String get slipChooseFile => 'Choose file';

  @override
  String get slipHint => 'JPEG, PNG, WebP or PDF — up to 5 MB';

  @override
  String get slipTooLarge =>
      'That file is over 5 MB. Take a smaller photo of the slip, or upload the bank\'s PDF.';

  @override
  String get slipUnsupported =>
      'The slip must be a JPEG, PNG, WebP or PDF file.';

  @override
  String get slipRequired => 'Attach the transfer slip.';

  @override
  String get slipReplace => 'Replace';

  @override
  String get slipRemove => 'Remove';

  @override
  String get transferredAmountLabel => 'Amount transferred';

  @override
  String get transferredAmountHint =>
      'The exact amount that left your account — change it only if you sent something different.';

  @override
  String get transferredAmountInvalid =>
      'Enter the transferred amount, e.g. 1,250.00.';

  @override
  String get amountUnderNote =>
      'Less than the amount due. Money is applied oldest first: uncovered transactions stay payable on this settlement.';

  @override
  String get amountOverNote =>
      'More than the amount due. The excess becomes wallet credit for your next settlement.';

  @override
  String get submitSlipCta => 'Submit slip';

  @override
  String get duplicateBankRefMsg =>
      'A slip with this bank reference was already submitted. Check the slip\'s reference, or open that settlement.';

  @override
  String get selectionNotEligibleMsg =>
      'Some of these transactions are no longer payable. Go back and reselect.';

  @override
  String get submitSlipFailed => 'Something went wrong submitting the slip.';

  @override
  String get successVerifyingTitle => 'Manfaa is verifying your transfer';

  @override
  String successVerifyingBody(String reference) {
    return 'Settlement $reference reached our team. Customer cashback confirms once the slip is matched to your transfer. Nothing more is needed from you.';
  }

  @override
  String get settledOutrightTitle =>
      'Settled — this batch\'s rewards are confirmed';

  @override
  String settledOutrightBody(String reference) {
    return 'Settlement $reference is closed and paid from your wallet. Nothing more is needed from you.';
  }

  @override
  String discountSavedNote(String amount) {
    return 'You saved $amount';
  }

  @override
  String get viewSettlementCta => 'View settlement';

  @override
  String get doneCta => 'Done';

  @override
  String get walletSettleConfirmTitle => 'Settle from wallet?';

  @override
  String walletSettleConfirmBody(String amount) {
    return '$amount will be drawn from your wallet and this batch settles immediately. No transfer, no slip.';
  }

  @override
  String get walletSettleCta => 'Settle from wallet';

  @override
  String get walletSettleFailed => 'Couldn\'t settle from the wallet.';

  @override
  String detailCreated(String date) {
    return 'Created $date';
  }

  @override
  String get setlStateDraft => 'Draft';

  @override
  String get setlStateAwaitingPayment => 'Awaiting your transfer';

  @override
  String get setlStatePaymentReview => 'Slip in review';

  @override
  String get setlStateSettled => 'Settled';

  @override
  String get setlStatePartiallySettled => 'Partially settled';

  @override
  String get setlStateCancelled => 'Cancelled';

  @override
  String get statusVerifyingBody =>
      'The slip is being matched to your transfer. Customer cashback confirms the moment it matches.';

  @override
  String get statusSettledTitle =>
      'Settled — this batch\'s rewards are confirmed.';

  @override
  String get statusPartialTitle =>
      'Part of this batch is settled; the rest is still due.';

  @override
  String get statusAwaitingTitle => 'Awaiting your transfer';

  @override
  String get statusAwaitingBody =>
      'Manfaa prepared this batch. Transfer the amount due and upload the slip here.';

  @override
  String get statusRejectedTitle => 'Manfaa could not verify your transfer';

  @override
  String get statusRejectedBody =>
      'This settlement was cancelled and its transactions are payable again. Start a new settlement with the correct slip.';

  @override
  String get statusRejectedReason => 'Manfaa\'s reason';

  @override
  String get statusRejectedNoReason => 'No reason recorded.';

  @override
  String get statusCancelledTitle =>
      'This settlement was cancelled; its transactions are payable again.';

  @override
  String get statusDraftTitle => 'Draft — not yet submitted.';

  @override
  String get remainderTitle => 'Pay the remainder';

  @override
  String remainderBody(String amount) {
    return 'This batch is still $amount short. Its transactions stay held here until it is fully paid — transfer the rest and upload that slip.';
  }

  @override
  String get uploadReceiptCta => 'Upload a slip';

  @override
  String get receiptAddedToast => 'Slip submitted — Manfaa is checking it.';

  @override
  String linesTitle(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count lines',
      one: '1 line',
    );
    return '$_temp0';
  }

  @override
  String get totalsLabel => 'Total';

  @override
  String get summaryTitle => 'Summary';

  @override
  String get summaryDue => 'Amount due';

  @override
  String get summaryReceived => 'Received so far';

  @override
  String get summaryMethod => 'Paid via';

  @override
  String get methodBank => 'Bank transfer';

  @override
  String get methodWallet => 'Wallet';

  @override
  String get discountAppliedHint =>
      'Already subtracted from the amount due. Nothing is taken from customers\' cashback.';

  @override
  String get paymentsTitle => 'Payments';

  @override
  String get paymentSlipAttached => 'Slip attached';

  @override
  String get paymentNoSlip => 'No slip';

  @override
  String get paymentPending => 'Awaiting review';

  @override
  String get paymentMatched => 'Verified';

  @override
  String get paymentRejected => 'Refused';

  @override
  String get settlementNotFound => 'Settlement not found.';

  @override
  String get startNewSettlement => 'Start a new settlement';

  @override
  String get walletScreenTitle => 'Wallet';

  @override
  String get walletBalanceLabel => 'Balance';

  @override
  String get walletTopUpCta => 'Top up';

  @override
  String get walletTopUpTitle => 'Top up your wallet';

  @override
  String get topUpAmountLabel => 'Amount to top up';

  @override
  String topUpMinimumHint(String amount) {
    return 'Minimum $amount';
  }

  @override
  String get topUpAmountInvalid =>
      'Enter the amount you\'ll transfer, e.g. 500.00.';

  @override
  String topUpBelowMinimum(String amount) {
    return 'The minimum top-up is $amount.';
  }

  @override
  String get topUpBankRefLabel => 'Bank reference (optional)';

  @override
  String get topUpBankRefHint =>
      'The reference on your slip, if you have it. It helps us match the transfer faster.';

  @override
  String get topUpReferenceNote =>
      'No payment reference needed. We match your transfer to the slip you upload.';

  @override
  String get topUpBankUnavailable => 'Couldn\'t load the bank details.';

  @override
  String topUpSuccessBody(String amount) {
    return 'Your top-up of $amount reached our team. It is added to your balance once the transfer is matched. Nothing more is needed from you.';
  }

  @override
  String get pendingTopUpsTitle => 'Top-ups in progress';

  @override
  String get topUpStateVerifying => 'Verifying';

  @override
  String get topUpStateMatched => 'Added';

  @override
  String get topUpStateRejected => 'Rejected';

  @override
  String topUpToBank(String bank) {
    return 'To $bank';
  }

  @override
  String topUpRejectedReason(String reason) {
    return 'Reason: $reason';
  }

  @override
  String get topUpRejectedNoReason => 'No reason recorded.';

  @override
  String get autoSettleTitle => 'Auto-settle from wallet';

  @override
  String get autoSettleBody =>
      'Every hour, validated cashback is settled from this balance — oldest first, as far as it goes.';

  @override
  String get autoSettleFailed => 'Couldn\'t change auto-settle. Try again.';

  @override
  String get movementsTitle => 'Movements';

  @override
  String get movementsEmpty => 'No wallet movements yet.';

  @override
  String get movementTopUp => 'Top-up';

  @override
  String get movementSettlement => 'Spent on a settlement';

  @override
  String get movementSettlementCredit => 'Credit from a settlement';

  @override
  String get movementOther => 'Adjustment';

  @override
  String balanceAfterLabel(String amount) {
    return 'Balance after: $amount';
  }

  @override
  String get presetAllLabel => 'All';

  @override
  String get pushOpen => 'View';

  @override
  String get moreTitle => 'More';

  @override
  String get moreSubtitle => 'Manage your business and account settings.';

  @override
  String get merchantAccount => 'Merchant account';

  @override
  String get verifiedChip => 'Verified';

  @override
  String get viewProfile => 'View profile';

  @override
  String get menuEmployees => 'Manage Employees';

  @override
  String get menuRoles => 'Roles';

  @override
  String get menuBranches => 'Manage Branches';

  @override
  String get menuCashback => 'Cashback Settings';

  @override
  String get menuPromotions => 'Promotions';

  @override
  String get logOut => 'Log out';

  @override
  String get logOutConfirmTitle => 'Log out?';

  @override
  String get logOutConfirmBody => 'You can log back in any time.';

  @override
  String get comingSoonBody => 'This screen is on its way.';

  @override
  String get profileScreenTitle => 'Profile';

  @override
  String get profileScreenSubtitle => 'View and manage your merchant profile.';

  @override
  String get storeLogoLabel => 'Store logo';

  @override
  String get storeNameLabel => 'Store name';

  @override
  String get storeNameDvLabel => 'Store name in Dhivehi';

  @override
  String get storeNameHint =>
      'Your public name. Your store link never changes with it, so anything already shared keeps working.';

  @override
  String get storeNameRequired => 'Enter the store name.';

  @override
  String get channelRowLabel => 'Channel';

  @override
  String get channelBothDisplay => 'In Store & Online';

  @override
  String get categoryRetiredHint =>
      'This category was retired — please choose a new one. Your other changes still save.';

  @override
  String get editProfileTitle => 'Edit profile';

  @override
  String get saveProfile => 'Save profile';

  @override
  String get profileSaved => 'Profile saved';

  @override
  String get profileSaveFailed => 'Could not save the profile.';

  @override
  String get cashbackTitle => 'Cashback Settings';

  @override
  String get cashbackSubtitle =>
      'Configure how customers earn cashback and how settlements work.';

  @override
  String get generalRateTitle => 'General cashback rate';

  @override
  String get generalRateHint =>
      'Applies to all eligible sales unless category rules override it.';

  @override
  String get noRateYet =>
      'No standing rate yet — set one to start offering cashback.';

  @override
  String scheduledChangeTitle(String rate) {
    return 'Scheduled change: $rate cashback';
  }

  @override
  String scheduledChangeBody(String date) {
    return 'Takes effect $date. Submitting a new change replaces it.';
  }

  @override
  String get editRateTitle => 'Change the rate';

  @override
  String get newRateLabel => 'New customer cashback rate';

  @override
  String rateBoundsHint(String min, String max) {
    return 'Percent of the eligible amount, up to two decimal places. Allowed range $min to $max.';
  }

  @override
  String get rateSameAsCurrent => 'This is already your current rate.';

  @override
  String get rateCancelsPending =>
      'This matches your current rate — submitting cancels the scheduled change and keeps the rate as it is.';

  @override
  String get rateIncreaseNote =>
      'Increases apply immediately — the higher rate starts with the next recorded sale.';

  @override
  String get rateDecreaseNote =>
      'Decreases apply at 00:00 tonight (Maldives time) — an advertised rate is never cut mid-day.';

  @override
  String get changeRateCta => 'Change rate';

  @override
  String get setRateCta => 'Set rate';

  @override
  String get cancelPendingCta => 'Cancel scheduled change';

  @override
  String get rateUpdatedToast => 'Cashback rate updated';

  @override
  String rateChangedNowTitle(String rate) {
    return 'Cashback rate is now $rate';
  }

  @override
  String rateChangedLaterTitle(String rate) {
    return 'Cashback rate will be $rate';
  }

  @override
  String rateChangedNowBody(String fee, String allIn) {
    return 'Applied immediately. Platform fee $fee — all-in cost $allIn.';
  }

  @override
  String rateChangedLaterBody(String date) {
    return 'Takes effect $date — the advertised rate is honoured until then.';
  }

  @override
  String get categoryRulesTitle => 'Cashback by category';

  @override
  String get categoryRulesHint =>
      'Override the general rate for selected product categories.';

  @override
  String get optionalBadge => 'Optional';

  @override
  String get excludedBadge => 'Excluded';

  @override
  String get inactiveBadge => 'Inactive';

  @override
  String get addCategoryRule => 'Add category rule';

  @override
  String get editRuleTitle => 'Edit category rule';

  @override
  String get ruleNameEnLabel => 'Name (English)';

  @override
  String get ruleNameDvLabel => 'Name (Dhivehi)';

  @override
  String get ruleNameDvHint =>
      'Required — this is what a Dhivehi customer sees on their receipt.';

  @override
  String get ruleNamesRequired =>
      'Enter both names — the Dhivehi name is what customers read on their receipt.';

  @override
  String get ruleModeLabel => 'How does this category earn?';

  @override
  String get modeRateOption => 'Custom rate';

  @override
  String get modeRateOptionHint =>
      'Sales in this category earn their own cashback percent.';

  @override
  String get modeExcludedOption => 'Excluded';

  @override
  String get modeExcludedOptionHint =>
      'Sales in this category never earn cashback — even during promotions.';

  @override
  String get ruleRateLabel => 'Category cashback rate';

  @override
  String get ruleActiveLabel => 'Active';

  @override
  String get categorySaved => 'Category saved';

  @override
  String get categorySaveFailed => 'Could not save the category.';

  @override
  String get earningTitle => 'Earning & settlement';

  @override
  String get minEligibleTitle => 'Minimum eligible sale';

  @override
  String get minEligibleHint => 'Sales below this amount do not earn cashback.';

  @override
  String get minEligibleInvalid =>
      'Enter an amount between MVR 0 and MVR 1,000.';

  @override
  String get validationWindowTitle => 'Validation window';

  @override
  String get validationWindowHint =>
      'Cashback remains pending until the validation period ends or the sale is confirmed.';

  @override
  String validationWindowInvalid(int max) {
    return 'Enter a whole number of days between 0 and $max.';
  }

  @override
  String get daysSuffix => 'days';

  @override
  String get visibilityTitle => 'Customer visibility';

  @override
  String get visibilityValue => 'Pending';

  @override
  String get visibilityHint => 'Shown to customers as pending until validated.';

  @override
  String get saveChanges => 'Save changes';

  @override
  String get preferencesSaved => 'Preferences saved';

  @override
  String get preferencesSaveFailed => 'Could not save the preferences.';

  @override
  String get employeesTitle => 'Manage Employees';

  @override
  String get employeesSubtitle =>
      'Add, view and control staff access to your store.';

  @override
  String get totalEmployeesLabel => 'Total employees';

  @override
  String get activeEmployeesLabel => 'Active';

  @override
  String get addEmployeeCta => 'Add employee';

  @override
  String get rolesCta => 'Roles';

  @override
  String get searchEmployeesHint => 'Search employees';

  @override
  String get employeesEmpty => 'No staff accounts yet.';

  @override
  String get noRoleLabel => 'No role';

  @override
  String get statusActive => 'Active';

  @override
  String get statusSuspended => 'Suspended';

  @override
  String get youMarker => '(you)';

  @override
  String get permissionsOverviewTitle => 'Permissions overview';

  @override
  String get manageRolesLink => 'Manage roles';

  @override
  String get inviteTitle => 'Add employee';

  @override
  String get inviteNameLabel => 'Name';

  @override
  String get inviteEmailLabel => 'Email';

  @override
  String get inviteEmailHint => 'They sign in with this email.';

  @override
  String get invitePickRoleLabel => 'Role';

  @override
  String get inviteCta => 'Create account';

  @override
  String get inviteFailed => 'Could not create the account.';

  @override
  String get staffNeedsRolesView =>
      'Picking a role needs the view-roles permission, which your role does not hold. Ask an owner for it.';

  @override
  String get roleNotAssignableHint =>
      'A role holding a permission you do not hold yourself cannot be handed out.';

  @override
  String tempPasswordTitle(String name) {
    return 'One-time password for $name';
  }

  @override
  String get tempPasswordOnceTitle => 'Shown only once.';

  @override
  String tempPasswordOnceBody(String name) {
    return 'Only a hashed copy is kept, so this password can never be shown again. Hand it to $name now and ask them to change it after their first sign-in.';
  }

  @override
  String get tempPasswordLoginEmail => 'Sign-in email';

  @override
  String get copyPassword => 'Copy password';

  @override
  String get passwordCopied => 'Password copied';

  @override
  String get tempPasswordAck =>
      'I have passed this password on securely and understand it cannot be shown again.';

  @override
  String get doneLabel => 'Done';

  @override
  String get employeeRoleLabel => 'Role';

  @override
  String get employeeActiveLabel => 'Active account';

  @override
  String get employeeActiveHint =>
      'Switching off is the only removal — history keeps its names, and the account can be switched back on.';

  @override
  String get staffLastOwnerLocked =>
      'This is the store\'s last active owner. Hand the owner role to another account first — otherwise nobody could reach the bank account or manage staff.';

  @override
  String get staffSelfDemoteLocked =>
      'You cannot move your own account off the owner role — another owner has to.';

  @override
  String get staffSelfActiveLocked => 'You cannot deactivate your own account.';

  @override
  String get staffSaved => 'Employee updated';

  @override
  String get staffSaveFailed => 'Could not update the account.';

  @override
  String get rolesTitle => 'Roles';

  @override
  String get rolesSubtitle =>
      'Create roles and set permissions to control what employees can access and do.';

  @override
  String get addRoleCta => 'Add role';

  @override
  String get rolesReadOnlyHint =>
      'You can view roles. Changing them needs the manage-roles permission.';

  @override
  String get fullAccessBadge => 'Full Access';

  @override
  String get ownerFrozenHint =>
      'The owner role always holds every permission, so it cannot be edited or deleted.';

  @override
  String employeeCountLabel(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count employees',
      one: '1 employee',
    );
    return '$_temp0';
  }

  @override
  String permissionCountChip(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count permissions',
      one: '1 permission',
    );
    return '$_temp0';
  }

  @override
  String get roleEditorCreateTitle => 'New role';

  @override
  String get roleEditorEditTitle => 'Edit role';

  @override
  String get roleNameLabel => 'Name';

  @override
  String get roleNameRequired => 'Enter a role name.';

  @override
  String get roleNameDvLabel => 'Name (Dhivehi, optional)';

  @override
  String get roleNameDvHint =>
      'Shown when the app is in Dhivehi. Leave empty to use the name above.';

  @override
  String get rolePermissionsLabel => 'Permissions';

  @override
  String get delegationHint =>
      'Greyed permissions are ones you do not hold yourself — a role can only be given what you hold.';

  @override
  String get createRoleCta => 'Create role';

  @override
  String get saveRoleCta => 'Save role';

  @override
  String get deleteRoleCta => 'Delete role';

  @override
  String roleInUseHint(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other:
          '$count accounts stand on this role — move them to another role first.',
      one: '1 account stands on this role — move it to another role first.',
    );
    return '$_temp0';
  }

  @override
  String roleDeleteConfirmTitle(String name) {
    return 'Delete $name?';
  }

  @override
  String get roleDeleteConfirmBody =>
      'The role is removed for good. Nobody stands on it, so nobody loses access.';

  @override
  String get deleteLabel => 'Delete';

  @override
  String get roleSaved => 'Role saved';

  @override
  String get roleDeleted => 'Role deleted';

  @override
  String get roleSaveFailed => 'Could not save the role.';

  @override
  String get roleDeleteFailed => 'Could not delete the role.';

  @override
  String get branchesTitle => 'Manage Branches';

  @override
  String get branchesSubtitle =>
      'Your locations — a pinned branch appears in the customer app\'s Nearby.';

  @override
  String get totalBranchesLabel => 'Total';

  @override
  String get pinnedBranchesLabel => 'Pinned';

  @override
  String get unpinnedBranchesLabel => 'No pin';

  @override
  String get addBranchCta => 'Add branch';

  @override
  String get searchBranchesHint => 'Search branches';

  @override
  String get branchesEmpty =>
      'No branches yet — add your first location to appear in Nearby.';

  @override
  String get branchNotPinned => 'Not on the map';

  @override
  String editBranchTitle(String name) {
    return 'Edit $name';
  }

  @override
  String get branchNameLabel => 'Name';

  @override
  String get branchNameRequired => 'Enter a branch name.';

  @override
  String get branchAddressLabel => 'Address (optional)';

  @override
  String get branchPinLabel => 'Map pin (optional)';

  @override
  String get branchPinHint =>
      'With a pin, customers near this location find you first in the Nearby tab. Without one, this branch is not shown there.';

  @override
  String get clearPinCta => 'Remove pin';

  @override
  String get setPinCta => 'Set a map pin';

  @override
  String get branchAdded => 'Branch added';

  @override
  String get branchSaved => 'Branch saved';

  @override
  String get branchDeleted => 'Branch deleted';

  @override
  String get branchSaveFailed => 'Could not save the branch.';

  @override
  String get branchDeleteFailed => 'Could not delete the branch.';

  @override
  String branchDeleteConfirmTitle(String name) {
    return 'Delete $name?';
  }

  @override
  String get branchDeleteConfirmBody =>
      'The branch disappears from discovery and new sales. A branch with recorded sales or promotions cannot be deleted — history must keep resolving.';

  @override
  String get branchReferencedError =>
      'This branch has recorded sales or promotions, so it stays on file. Stop using it instead.';

  @override
  String get promotionsTitle => 'Promotions';

  @override
  String get promotionsSubtitle =>
      'Time-boxed cashback boosts above your standing rate.';

  @override
  String get newPromotionCta => 'New promotion';

  @override
  String get promotionsEmpty =>
      'No promotions yet. Create one to boost your cashback for a while.';

  @override
  String get promoStatusDraft => 'Draft';

  @override
  String get promoStatusLive => 'Live';

  @override
  String get promoStatusPublished => 'Published';

  @override
  String get promoStatusEnded => 'Ended';

  @override
  String get promoStatusCancelled => 'Cancelled';

  @override
  String promoRateTitle(String rate) {
    return '$rate cashback';
  }

  @override
  String promoYouPay(String allIn, String fee) {
    return 'You pay $allIn (fee $fee)';
  }

  @override
  String get promoYouPayDash => 'Fee not priced right now';

  @override
  String promoMinPurchase(String amount) {
    return 'Min purchase $amount';
  }

  @override
  String promoPerCustomerCap(String amount) {
    return 'Per-customer cap $amount';
  }

  @override
  String get promoAllBranches => 'All branches';

  @override
  String promoBranchFallback(int id) {
    return 'Branch #$id';
  }

  @override
  String get publishCta => 'Publish';

  @override
  String get cancelDraftCta => 'Cancel draft';

  @override
  String get keepDraftCta => 'Keep as draft';

  @override
  String publishConfirmTitle(String rate) {
    return 'Publish the $rate promotion?';
  }

  @override
  String publishConfirmBody(String start, String end) {
    return 'It runs $start to $end. Once published it cannot be changed or ended early — customers can rely on the advertised boost for the whole window.';
  }

  @override
  String get promotionPublished => 'Promotion published';

  @override
  String get draftCancelled => 'Draft cancelled';

  @override
  String get publishFailed => 'Could not publish the promotion.';

  @override
  String get cancelDraftFailed => 'Could not cancel the draft.';

  @override
  String get promoRateLabel => 'Boosted cashback rate';

  @override
  String get promoRateHint =>
      'Percent of the eligible amount, up to two decimal places. A higher rate can land on a higher platform fee tier — you see the exact cost before publishing.';

  @override
  String promoMustBoost(String rate) {
    return 'A promotion must boost above your standing rate of $rate.';
  }

  @override
  String get promoStartsLabel => 'Starts';

  @override
  String get promoEndsLabel => 'Ends';

  @override
  String get promoWindowHint =>
      'Maldives time. Once published, the promotion runs for exactly this window — it cannot be edited or ended early.';

  @override
  String get promoWindowOrderError => 'The promotion must end after it starts.';

  @override
  String get promoMinLabel => 'Minimum purchase (optional)';

  @override
  String get promoCapLabel => 'Per-customer cashback cap (optional)';

  @override
  String get promoAmountInvalid =>
      'Enter an MVR amount, e.g. 100.00, or leave empty.';

  @override
  String get promoBranchLabel => 'Branch';

  @override
  String get createDraftCta => 'Create draft';

  @override
  String get draftCreated => 'Draft promotion created';

  @override
  String get createDraftFailed => 'Could not create the promotion.';

  @override
  String promoCostTitle(String allIn) {
    return 'During the promotion you pay $allIn of each eligible sale';
  }

  @override
  String promoCostBody(Object fee, Object rate) {
    return '$rate cashback + $fee platform fee.';
  }

  @override
  String promoCostDelta(String delta, String standing) {
    return 'That is $delta versus your standing all-in cost of $standing.';
  }

  @override
  String promoCostTier(String from, String to, String feeFrom, String feeTo) {
    return 'Boosting from $from to $to moves your fee tier from $feeFrom to $feeTo.';
  }

  @override
  String get paneTxHintTitle => 'Select a sale';

  @override
  String get paneTxHintBody =>
      'Tap a sale in the list to read its full detail — corrections stay available while the sale can still be amended.';

  @override
  String get paneSettlementHintTitle => 'Select a settlement';

  @override
  String get paneSettlementHintBody =>
      'Tap a settlement in the list to read its status, lines and payments here.';

  @override
  String get paneEmployeesHintTitle => 'Select an employee';

  @override
  String get paneEmployeesHintBody =>
      'Tap a person to change their role or active state, or add a new account.';

  @override
  String get paneRolesHintTitle => 'Select a role';

  @override
  String get paneRolesHintBody =>
      'Tap a role to edit its name and permissions, or create a new one.';

  @override
  String get paneBranchesHintTitle => 'Select a branch';

  @override
  String get paneBranchesHintBody =>
      'Tap a branch to edit its details and pin, or add a new location.';

  @override
  String get panePromoHintTitle => 'Draft a promotion';

  @override
  String get panePromoHintBody =>
      'The builder opens here. A published promotion cannot be changed — drafts can.';

  @override
  String get menuPrivacy => 'Privacy Policy';

  @override
  String get menuTerms => 'Terms of Service';

  @override
  String get closeStoreRowHint =>
      'Settle everything, then close the store for good.';

  @override
  String get closeStoreTitle => 'Close store';

  @override
  String get closeStoreSubtitle =>
      'Verify the store\'s contact number, then close a settled store.';

  @override
  String get closeStoreIrreversibleTitle => 'Closing cannot be undone';

  @override
  String get closeStoreIrreversibleBody =>
      'The store leaves Manfaa, crediting stops and every staff account is signed out. Your records stay with us as financial records.';

  @override
  String get closeStorePhoneLabel => 'Store contact number';

  @override
  String get closeStorePhoneHint =>
      'We\'ll text a 6-digit code to this number.';

  @override
  String get closeStoreSendCode => 'Send code';

  @override
  String get closeStoreVerify => 'Verify';

  @override
  String get closeStoreStoresLead => 'Stores on this number';

  @override
  String get closeStoreSettled => 'Fully settled — can be closed.';

  @override
  String closeStoreOwes(String amount) {
    return 'Outstanding $amount — settle before closing.';
  }

  @override
  String get closeStoreCta => 'Close store';

  @override
  String closeStoreConfirmTitle(String store) {
    return 'Close $store?';
  }

  @override
  String get closeStoreConfirmBody =>
      'This is permanent. The store leaves Manfaa immediately and every staff account is signed out.';

  @override
  String get closeStoreConfirmCta => 'Close permanently';

  @override
  String closeStoreClosed(String store) {
    return '$store is closed';
  }

  @override
  String get closeStoreSignedOutNote => 'You\'ll be signed out now.';

  @override
  String get closeStoreOtherStoreNote => 'Your other stores are untouched.';

  @override
  String get closeStoreNoStore =>
      'No store is registered with this contact number.';

  @override
  String get closeStorePhoneInvalid =>
      'Enter the store\'s contact number — a Maldivian mobile starting with 7 or 9.';

  @override
  String get closeStoreOutstandingRefusal =>
      'This store still owes a settlement. Settle it first, then close.';

  @override
  String get closeStoreExpired => 'That verification expired. Start again.';

  @override
  String get staffNameLabel => 'Name';

  @override
  String get resetPasswordLabel => 'Password';

  @override
  String get resetPasswordHint =>
      'Give this person a new one-time password. Their current one stops working immediately.';

  @override
  String get resetPasswordSelfHint =>
      'Reset your own password. You\'ll be signed out and must sign in with the new one.';

  @override
  String get resetPasswordCta => 'Reset password';

  @override
  String resetPasswordConfirmTitle(String name) {
    return 'Reset the password for $name?';
  }

  @override
  String resetPasswordConfirmBody(String name) {
    return '$name is signed out everywhere and can only sign in with the one-time password we show you next.';
  }

  @override
  String get resetPasswordConfirmSelfBody =>
      'You\'ll be signed out everywhere and must sign in again with the one-time password shown next. Copy it before closing.';

  @override
  String get payAmountChangedTitle => 'The amount to transfer changed';

  @override
  String payAmountChangedBody(String amount) {
    return 'Your balance moved while this screen was open. Transfer $amount — the figure below is the current one.';
  }

  @override
  String get splitAddRow => 'Add row';

  @override
  String get splitSumIsEligible =>
      'These rows are the eligible amount — they add up to the sale you\'re crediting.';

  @override
  String get splitSearchHint => 'Search categories';

  @override
  String get mapTilesFailed =>
      'The map couldn\'t load. Check your connection and try again.';

  @override
  String get pendingReviewTitle => 'Waiting for Manfaa\'s review';

  @override
  String get pendingProfileNote =>
      'Customers keep seeing the details below until Manfaa approves the change.';

  @override
  String get pendingReplaceNote =>
      'Saving again replaces the request that is waiting.';

  @override
  String get pendingBranchNote =>
      'This branch keeps its current details until Manfaa approves the change.';

  @override
  String get pendingRemovalNote =>
      'This branch stays on your list until Manfaa approves the removal.';

  @override
  String get pendingCreateNote =>
      'This branch appears in your list, and to customers, once Manfaa approves it.';

  @override
  String get changeKindProfile => 'Store profile change';

  @override
  String get changeKindBranchCreate => 'New branch';

  @override
  String get changeKindBranchUpdate => 'Branch update';

  @override
  String get changeKindBranchDelete => 'Branch removal';

  @override
  String get changeKindOther => 'Store change';

  @override
  String get changeFieldWebsite => 'Website';

  @override
  String get changeFieldBranchName => 'Branch name';

  @override
  String get changeFieldAddress => 'Address';

  @override
  String get changeFieldLocation => 'Map pin';

  @override
  String get changeFieldOther => 'Other details';

  @override
  String get changeLogoValue => 'New image';

  @override
  String get sentForReview => 'Sent for Manfaa\'s review';

  @override
  String get logoSentForReview =>
      'New logo sent for review — your current one stays up until it is approved.';

  @override
  String get branchSentForReview => 'New branch sent for Manfaa\'s review';

  @override
  String get branchChangeSentForReview =>
      'Branch changes sent for Manfaa\'s review';

  @override
  String get branchRemovalSentForReview => 'Removal sent for Manfaa\'s review';

  @override
  String get referenceComesLaterNote =>
      'No transfer reference needed. Manfaa will generate one after you upload the receipt.';

  @override
  String get saleTimeLabel => 'Sale time';

  @override
  String get saleTimeNow => 'Now';

  @override
  String get saleTimeSetNow => 'Now';

  @override
  String discountSaveByTitle(String amount, String date) {
    return 'Save $amount by settling before $date';
  }

  @override
  String discountSaveBySub(String rate) {
    return '$rate prompt-payment discount on platform fees';
  }

  @override
  String get discountNoneSub =>
      'The prompt-payment discount no longer applies to your oldest sale.';

  @override
  String get viewBreakdown => 'View breakdown';

  @override
  String get monthTitle => 'This month';

  @override
  String get monthSales => 'Sales through Manfaa';

  @override
  String get monthTransactions => 'Transactions';

  @override
  String get monthCashback => 'Cashback issued';

  @override
  String get monthAverage => 'Avg. transaction';

  @override
  String get branchAddressRequired =>
      'Add the address so customers know where to go.';

  @override
  String get branchAddressFromPin => 'Use the pin';

  @override
  String get branchAddressFromPinEmpty =>
      'No address found for that spot — please type it in.';

  @override
  String get branchAddressHint => 'Customers see this on your store page.';

  @override
  String get visibilityOn => 'On the app';

  @override
  String get visibilityOff => 'Paused';

  @override
  String get visibilityOnHint =>
      'Customers can find your store and earn cashback.';

  @override
  String get visibilityOffHint =>
      'Your store is hidden from the app and is not giving cashback. Your history, settlements and staff are untouched.';

  @override
  String get visibilityPause => 'Pause store';

  @override
  String get visibilityResume => 'Put store back on the app';

  @override
  String get visibilityPauseConfirm => 'Pause your store?';

  @override
  String get visibilityPauseConfirmBody =>
      'Customers will not find you on the app and you will not be able to give cashback until you turn it back on. Customers who have earned cashback with you will be told.';

  @override
  String get visibilityPaused => 'Your store is paused.';

  @override
  String get visibilityPausedNotified =>
      'Your store is paused. Customers who have earned cashback with you have been told.';

  @override
  String get visibilityResumed => 'Your store is back on the app.';

  @override
  String get visibilityResumedNotified =>
      'Your store is back on the app. Customers who have earned cashback with you have been told.';

  @override
  String get reasonMerchantUnpublished => 'Store paused';

  @override
  String get branchAddressFinding => 'Finding…';

  @override
  String get slipAttached => 'Receipt attached';

  @override
  String transferWatchingBody(String amount) {
    return 'We are reading your bank\'s own records for the $amount you transferred. It usually matches within a few minutes.';
  }

  @override
  String transferWatchLeft(int minutes) {
    String _temp0 = intl.Intl.pluralLogic(
      minutes,
      locale: localeName,
      other: 'About $minutes minutes left',
      one: 'About 1 minute left',
    );
    return '$_temp0';
  }

  @override
  String get transferWatchLeftShort => 'Less than a minute left';

  @override
  String get transferCloseHint =>
      'You can close this screen — the check keeps running and we notify you the moment it is confirmed.';

  @override
  String get transferCloseHintTeam =>
      'You can close this screen — nothing here needs to stay open, and we notify you the moment it is confirmed.';

  @override
  String get transferTeamTitle => 'Our team will confirm this shortly';

  @override
  String get transferTeamBody =>
      'Your slip has reached us. Someone checks it against the bank and you are notified as soon as it is confirmed. Nothing more is needed from you.';

  @override
  String get transferTeamExpiredBody =>
      'We watched the bank for this transfer and have not seen it arrive yet. Our team takes it from here and you are notified once it is confirmed.';

  @override
  String get transferSettledTitle => 'Settled — your transfer matched';

  @override
  String transferSettledBody(String amount, String reference) {
    return 'We received $amount for settlement $reference. Your customers\' cashback is confirmed.';
  }

  @override
  String get transferPartialTitle => 'Part of this settlement is paid';

  @override
  String transferPartialBody(String received, String outstanding) {
    return 'We received $received. $outstanding is still owed on this settlement — transfer exactly that and upload the slip to finish it.';
  }

  @override
  String transferCreditedTitle(String amount) {
    return '$amount added to your wallet';
  }

  @override
  String transferBalanceNow(String balance) {
    return 'Your balance is now $balance.';
  }

  @override
  String get transferRejectedTitle => 'This transfer was not matched';

  @override
  String get transferRejectedBody =>
      'Our team could not match this transfer to the bank\'s records. Check the slip and try again, or contact us.';

  @override
  String transferSettlementRejectedBody(String reference) {
    return 'Settlement $reference is cancelled and its transactions are payable again. Create a new settlement with the correct receipt.';
  }
}
