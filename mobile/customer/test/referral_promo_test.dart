import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'package:manfaa_customer/features/home/home_screen.dart';
import 'package:manfaa_customer/features/profile/referrals_screen.dart';
import 'package:manfaa_customer/l10n/gen/app_localizations.dart';

/// The home promo card is invisible in every golden (the shot API carries no
/// referral config), so its VISIBLE state is pinned here instead: config
/// loaded and enabled -> one line with the configured amount; programme
/// off -> nothing, and no stray gap.
void main() {
  ReferralsSummary summary({required bool enabled}) => ReferralsSummary(
        enabled: enabled,
        rewardLaari: 5000,
        thresholdLaari: 1000000,
        code: '374230',
        shareUrl: 'https://manfaa.app/r/374230',
        invited: 0,
        rewarded: 0,
        earnedTotalLaari: 0,
        friends: const [],
      );

  Widget harness(ReferralsSummary data) => ProviderScope(
        overrides: [
          referralsProvider.overrideWith((ref) async => data),
        ],
        child: MaterialApp(
          theme: manfaaTheme(brightness: Brightness.light, dhivehi: false),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const Scaffold(body: ReferralPromoCard()),
        ),
      );

  testWidgets('shows one line with the configured reward when enabled',
      (tester) async {
    await tester.pumpWidget(harness(summary(enabled: true)));
    await tester.pump();

    // formatMoney joins currency and figure with a NO-BREAK space.
    expect(find.textContaining('MVR\u00A050.00'), findsOneWidget);
    expect(find.textContaining('Refer a friend'), findsOneWidget);
  });

  testWidgets('renders nothing at all while the programme is off',
      (tester) async {
    await tester.pumpWidget(harness(summary(enabled: false)));
    await tester.pump();

    expect(find.byType(Text), findsNothing);
    // No stray padding either: the whole card collapses to zero size.
    expect(tester.getSize(find.byType(ReferralPromoCard)).height, 0);
  });
}
