/// Referral programme payloads (owner, 2026-08-23).
///
/// The customer's referral code IS their customer_code; the server sends the
/// programme's live figures alongside so the screen never hardcodes an
/// amount a superadmin can change. Friends arrive MASKED and CAPPED — the
/// API promises a referrer never learns more about a friend than the
/// friend's own signup gave away, and this client keeps that promise by
/// simply having nowhere to put more.
library;

/// GET /customer/referrals — the whole referral page in one payload.
class ReferralsSummary {
  const ReferralsSummary({
    required this.enabled,
    required this.rewardLaari,
    required this.thresholdLaari,
    required this.code,
    required this.shareUrl,
    required this.invited,
    required this.rewarded,
    required this.earnedTotalLaari,
    required this.friends,
  });

  factory ReferralsSummary.fromJson(Map<String, dynamic> json) {
    final stats = (json['stats'] as Map?)?.cast<String, dynamic>() ?? const {};

    return ReferralsSummary(
      enabled: json['enabled'] as bool? ?? false,
      rewardLaari: json['reward_laari'] as int? ?? 0,
      thresholdLaari: json['threshold_laari'] as int? ?? 0,
      code: json['code']?.toString() ?? '',
      shareUrl: json['share_url']?.toString() ?? '',
      invited: stats['invited'] as int? ?? 0,
      rewarded: stats['rewarded'] as int? ?? 0,
      earnedTotalLaari: stats['earned_total_laari'] as int? ?? 0,
      friends: ((json['friends'] as List?) ?? const [])
          .map((row) =>
              ReferralFriend.fromJson((row as Map).cast<String, dynamic>()))
          .toList(growable: false),
    );
  }

  /// Kill switch: false means the programme is paused platform-wide. The
  /// code still renders (it is the customer's own code), but the screen
  /// says so instead of promising a bonus that will not pay.
  final bool enabled;

  /// What the referrer earns per friend, integer laari.
  final int rewardLaari;

  /// The validated-spend milestone a friend must reach, integer laari.
  final int thresholdLaari;

  /// The customer's own 6-digit code — the same one shown at the till.
  final String code;

  /// A signup link with the code pre-filled, for the share sheet.
  final String shareUrl;

  final int invited;
  final int rewarded;

  /// What the programme has actually paid this customer, from their own
  /// wallet ledger — honest across any reward-figure change.
  final int earnedTotalLaari;

  final List<ReferralFriend> friends;
}

/// One invited friend: a masked name and progress toward the milestone.
class ReferralFriend {
  const ReferralFriend({
    required this.name,
    required this.spentLaari,
    required this.rewarded,
    this.disqualified = false,
    this.joinedAt,
  });

  factory ReferralFriend.fromJson(Map<String, dynamic> json) => ReferralFriend(
        name: json['name']?.toString() ?? '',
        spentLaari: json['spent_laari'] as int? ?? 0,
        rewarded: json['rewarded'] as bool? ?? false,
        // `== true` on purpose: the field is new (self-referral defence,
        // 2026-08-24), so absent — or anything unexpected — parses as false.
        disqualified: json['disqualified'] == true,
        joinedAt: json['joined_at'] as String?,
      );

  /// Masked server-side ("Aish***"). Never a full name.
  final String name;

  /// Validated spend so far, CAPPED at the threshold server-side — progress
  /// toward the bonus, never a window onto the friend's real spending.
  final int spentLaari;

  final bool rewarded;

  /// The self-referral defence's verdict (owner, 2026-08-24): true means
  /// this friend's bonus is disqualified — permanently, never paid. The API
  /// then always sends `spent_laari: 0` and `rewarded: false`, and the
  /// screen shows a muted state instead of a progress bar.
  final bool disqualified;

  /// ISO-8601, or null for accounts older than the attribution columns.
  final String? joinedAt;
}
