import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../l10n/gen/app_localizations.dart';
import '../money/money_providers.dart';
import '../setup/rate_step.dart' show formatBp;

/// THE PROMOTIONAL FEE BANNER (owner, 2026-08-25): "Show promos when
/// enabled on merchant panel and app."
///
/// One widget, three places — the Dashboard, the Credit screen beside the
/// cost preview, and the Settlements board where the bill is read. It is
/// the ONLY thing on the till that tells a merchant why their platform fee
/// moved: `GET /merchant/rate` still quotes the §4 TIER fee by the server's
/// own containment rule, so without this the merchant would meet the
/// promotion for the first time on an invoice.
///
/// WHAT IS SERVER-WRITTEN AND WHAT IS OURS. The SENTENCE is the platform's,
/// in the reader's language (`banner_dv` / `banner_en`) — a campaign's
/// wording is a marketing decision and must not need an app release. The
/// chrome around it is the app's, so it is translated like every other
/// merchant-facing string: the kind's title, the fee line, the days-left
/// count. The server's own English `kind_label` is deliberately NOT used —
/// it would put an English phrase in the middle of a Thaana banner.
///
/// NOTHING IS DRAWN unless [MerchantFeePromotion.showable] says so: no
/// promotion, an unknown kind from a server one deploy ahead, an
/// unparseable fee, or a window that has already closed all render the same
/// empty space. See the model for that verdict.
class FeePromotionBanner extends ConsumerWidget {
  const FeePromotionBanner({super.key, this.bottomGap = 0});

  /// Space under the banner WHEN IT DRAWS — so a caller can drop this into
  /// a list as one line without leaving a hole on the (normal) days there
  /// is no promotion to show.
  final double bottomGap;

  /// The key a test (and a golden) finds the banner by.
  static const bannerKey = Key('fee-promotion-banner');

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final promotion = ref.watch(activeFeePromotionProvider);
    if (!promotion.showable) return const SizedBox.shrink();

    return Padding(
      padding: EdgeInsets.only(bottom: bottomGap),
      child: FeePromotionBannerBody(promotion: promotion),
    );
  }
}

/// The banner itself, taking the promotion directly — so a screen that has
/// already read one (and a widget test) can draw it without a container.
/// Still refuses to render anything it does not fully understand.
class FeePromotionBannerBody extends StatelessWidget {
  const FeePromotionBannerBody({super.key, required this.promotion});

  final MerchantFeePromotion promotion;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l10n = context.l10n;
    final kind = promotion.kind;
    final feeBp = promotion.feeBp;
    if (!promotion.showable || kind == null || feeBp == null) {
      return const SizedBox.shrink();
    }

    // Green: a lower bill is good news, and it is the tone the till already
    // uses for money that landed the merchant's way.
    final colors = toneSurface(ToneSurface.confirmed, theme.brightness);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final copy = promotion.banner(dhivehi: dhivehi);
    final daysLeft = promotion.daysRemaining;

    return Container(
      key: FeePromotionBanner.bannerKey,
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.percent_rounded, size: 20, color: colors.foreground),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        feePromotionTitle(l10n, kind),
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: colors.foreground,
                        ),
                      ),
                    ),
                    // The server counts days (business-timezone calendar
                    // days, §13); the app never re-derives them. Absent
                    // when the campaign has no end to count down to.
                    if (daysLeft != null) ...[
                      const SizedBox(width: Gap.sm),
                      Text(
                        l10n.feePromoDaysLeft(daysLeft),
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: colors.foreground,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 2),
                // The number the merchant came for, stated as a rate — the
                // same figure the cost preview's fee row now quotes.
                Text(
                  l10n.feePromoFee(formatBp(feeBp)),
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: colors.foreground,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                // The platform's own sentence about this campaign. Absent
                // rather than invented when the row carries no copy at all.
                if (copy != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    copy,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: colors.foreground,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The kind's heading in the reader's language. A kind this build does not
/// know never reaches here — the banner refuses to draw first.
String feePromotionTitle(AppLocalizations l10n, FeePromotionKind kind) =>
    switch (kind) {
      FeePromotionKind.introductory => l10n.feePromoIntroTitle,
      FeePromotionKind.platformWide => l10n.feePromoWideTitle,
    };
