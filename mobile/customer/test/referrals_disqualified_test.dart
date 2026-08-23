import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import 'package:manfaa_customer/features/profile/referrals_screen.dart';
import 'package:manfaa_customer/l10n/gen/app_localizations.dart';

/// The muted "Disqualified" state (self-referral defence, owner 2026-08-24):
/// a disqualified friend shows the word instead of a progress bar, and the
/// page states the rule in one line.
void main() {
  final summary = ReferralsSummary(
    enabled: true,
    rewardLaari: 5000,
    thresholdLaari: 1000000,
    code: '374230',
    shareUrl: 'https://manfaa.app/r/374230',
    invited: 2,
    rewarded: 0,
    earnedTotalLaari: 0,
    friends: const [
      ReferralFriend(
        name: 'Aish***',
        spentLaari: 250000,
        rewarded: false,
        joinedAt: '2026-08-20T10:00:00Z',
      ),
      ReferralFriend(
        name: 'Ahm***',
        spentLaari: 0,
        rewarded: false,
        disqualified: true,
        joinedAt: '2026-08-21T10:00:00Z',
      ),
    ],
  );

  Widget harness() => ProviderScope(
        overrides: [
          referralsProvider.overrideWith((ref) async => summary),
        ],
        child: MaterialApp(
          theme: manfaaTheme(brightness: Brightness.light, dhivehi: false),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const ReferralsScreen(),
        ),
      );

  testWidgets('a disqualified friend gets the word, not a progress bar',
      (tester) async {
    await tester.pumpWidget(harness());
    await tester.pump();

    // The friends live at the bottom of the ListView; bring them on screen
    // so their rows actually build.
    await tester.drag(find.byType(ListView), const Offset(0, -600));
    await tester.pump();

    // The qualified friend keeps the bar; the disqualified one loses it:
    // exactly one bar (and one progress line) for two friends.
    expect(find.byType(LinearProgressIndicator), findsOneWidget);
    expect(find.text('Disqualified'), findsOneWidget);
    expect(find.textContaining('of MVR'), findsOneWidget);
  });

  testWidgets('the page states the self-referral rule plainly',
      (tester) async {
    await tester.pumpWidget(harness());
    await tester.pump();

    expect(
      find.text('Self-referral is prohibited and gets disqualified.'),
      findsOneWidget,
    );
  });
}
