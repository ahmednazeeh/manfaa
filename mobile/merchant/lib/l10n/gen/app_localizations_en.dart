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
}
