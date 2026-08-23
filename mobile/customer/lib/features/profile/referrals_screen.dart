import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show Clipboard, ClipboardData;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:share_plus/share_plus.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../activity/activity_screen.dart' show formatDayMonth;

/// Refer & earn (owner, 2026-08-23).
///
/// The customer's referral code IS their customer code — the same six digits
/// shown at the till — so this screen sells the programme rather than
/// inventing a second identity: the code big and copyable, the live figures
/// (reward and milestone come from the server, never hardcoded), and every
/// invited friend's progress. Friends arrive MASKED and CAPPED from the API;
/// there is deliberately nowhere here to show more.
final referralsProvider = FutureProvider.autoDispose<ReferralsSummary>((ref) {
  return ref.watch(apiProvider).referrals();
});

class ReferralsScreen extends ConsumerWidget {
  const ReferralsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final referrals = ref.watch(referralsProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.referralTileTitle)),
      body: SafeArea(
        child: referrals.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Center(
            child: Text(
              error is MobileApiException ? error.message : l10n.errorGeneric,
            ),
          ),
          data: (data) => _Body(summary: data),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.summary});

  final ReferralsSummary summary;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        // The kill switch, honestly: the code still renders (it is the
        // customer's own till code), but no bonus is promised while the
        // programme is paused.
        if (!summary.enabled) ...[
          ManfaaCard(
            child: Row(
              children: [
                const IconTile(
                  Icons.pause_circle_outline_rounded,
                  tint: ManfaaTint.amber,
                  size: 40,
                  iconSize: 20,
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Text(
                    l10n.referralPaused,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: Gap.lg),
        ],

        // The code, big — and the two ways to hand it to a friend.
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                l10n.referralYourCode,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              const SizedBox(height: Gap.sm),
              Center(
                child: Text(
                  summary.code,
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.displaySmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    letterSpacing: 6,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
              ),
              const SizedBox(height: Gap.md),
              if (summary.enabled)
                Text(
                  l10n.referralExplainer(
                    formatMoney(summary.thresholdLaari, dhivehi: dhivehi),
                    formatMoney(summary.rewardLaari, dhivehi: dhivehi),
                  ),
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              const SizedBox(height: Gap.lg),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _copy(context),
                      icon: const Icon(Icons.copy_rounded, size: 18),
                      label: Text(l10n.referralCopy),
                    ),
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: ManfaaColors.violet,
                        foregroundColor: Colors.white,
                      ),
                      onPressed: () => _share(context),
                      icon: const Icon(Icons.share_rounded, size: 18),
                      label: Text(l10n.referralShare),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: Gap.lg),

        // The programme's live numbers for THIS customer.
        ManfaaCard(
          child: Row(
            children: [
              _Stat(
                value: '${summary.invited}',
                label: l10n.referralStatInvited,
              ),
              _divider(theme),
              _Stat(
                value: '${summary.rewarded}',
                label: l10n.referralStatRewarded,
              ),
              _divider(theme),
              _Stat(
                value: formatMoney(summary.earnedTotalLaari, dhivehi: dhivehi),
                label: l10n.referralStatEarned,
              ),
            ],
          ),
        ),
        const SizedBox(height: Gap.lg),

        SectionHeader(l10n.referralFriendsTitle),
        const SizedBox(height: Gap.sm),
        if (summary.friends.isEmpty)
          Text(
            l10n.referralFriendsEmpty,
            style: theme.textTheme.bodyMedium?.copyWith(color: muted),
          )
        else
          for (final friend in summary.friends)
            _FriendRow(friend: friend, thresholdLaari: summary.thresholdLaari),
      ],
    );
  }

  Widget _divider(ThemeData theme) => Container(
    width: 1,
    height: 40,
    color: theme.colorScheme.outlineVariant,
  );

  Future<void> _copy(BuildContext context) async {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);

    await Clipboard.setData(ClipboardData(text: summary.code));
    messenger.showSnackBar(SnackBar(content: Text(l10n.copiedToClipboard)));
  }

  Future<void> _share(BuildContext context) async {
    final message = context.l10n.referralShareMessage(
      summary.code,
      summary.shareUrl,
    );

    await SharePlus.instance.share(ShareParams(text: message));
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.value, required this.label});

  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Expanded(
      child: Column(
        children: [
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

/// One invited friend: masked name, when they joined, and how far along
/// the milestone bar they are. Spend arrives capped at the threshold, so
/// the bar can show progress without ever showing real spending.
class _FriendRow extends StatelessWidget {
  const _FriendRow({required this.friend, required this.thresholdLaari});

  final ReferralFriend friend;
  final int thresholdLaari;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    final progress = thresholdLaari > 0
        ? (friend.spentLaari / thresholdLaari).clamp(0.0, 1.0)
        : 0.0;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconTile(
            friend.rewarded
                ? Icons.check_circle_rounded
                : Icons.person_outline_rounded,
            tint: friend.rewarded ? ManfaaTint.green : ManfaaTint.violet,
            size: 40,
            iconSize: 20,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        friend.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    if (friend.joinedAt != null) ...[
                      const SizedBox(width: Gap.sm),
                      Text(
                        l10n.referralJoinedOn(
                          formatDayMonth(friend.joinedAt!, context),
                        ),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: muted,
                        ),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: Gap.sm),
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: progress,
                    minHeight: 6,
                    backgroundColor: theme.colorScheme.surfaceContainer,
                    color: friend.rewarded
                        ? ManfaaColors.green
                        : ManfaaColors.violet,
                  ),
                ),
                const SizedBox(height: Gap.xs),
                Text(
                  friend.rewarded
                      ? l10n.referralRewardedBadge
                      : l10n.referralProgress(
                          formatMoney(friend.spentLaari, dhivehi: dhivehi),
                          formatMoney(thresholdLaari, dhivehi: dhivehi),
                        ),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: friend.rewarded ? ManfaaColors.green : muted,
                    fontWeight: friend.rewarded ? FontWeight.w600 : null,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
