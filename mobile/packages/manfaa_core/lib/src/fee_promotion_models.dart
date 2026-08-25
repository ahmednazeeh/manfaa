/// The PLATFORM FEE PROMOTION a store is trading under (owner, 2026-08-25).
///
/// NOT App\Models\Promotion, which is a CASHBACK promotion — a merchant
/// paying their customers more. This file is about the fee MANFAA charges
/// the merchant, and the two never meet: one lifts the reward, the other
/// lowers our cut.
///
/// Shape: GET /api/mobile/v1/merchant/fee-promotion, the SAME controller
/// the web panel mounts (App\Http\Controllers\Merchant\FeePromotionBanner-
/// Controller) — one sentence, two doors. Every key is always present and
/// null when nothing is running, so a client never has to guess which
/// fields it may expect.
library;

/// The two ways the platform fee can be promotional.
///
/// PARSED, NEVER ASSUMED. A server one deploy ahead of this build can send
/// a kind this app has never heard of; [fromWire] answers null for it and
/// [MerchantFeePromotion.showable] then reads false, so the till shows
/// NOTHING rather than throwing or inventing a label. The one thing a till
/// must never do about money it does not understand is guess.
enum FeePromotionKind {
  /// Every merchant's first X days on the platform, from their approval.
  introductory('introductory'),

  /// A dated campaign covering every merchant, whatever their age.
  platformWide('platform_wide');

  const FeePromotionKind(this.wire);

  /// The `kind` string on the wire.
  final String wire;

  /// The wire value, or null for anything this build does not know.
  static FeePromotionKind? fromWire(Object? value) {
    if (value is! String) return null;
    for (final kind in values) {
      if (kind.wire == value) return kind;
    }
    return null;
  }
}

/// GET /merchant/fee-promotion — what THIS store is being charged, and the
/// server-written sentence to say about it.
///
/// DEFENSIVE ALL THE WAY DOWN. Every field degrades to null rather than
/// throwing: a number where a string belongs, a malformed date, a missing
/// block, an unknown kind. [showable] is the single question every surface
/// asks, and it is false unless the whole answer makes sense — active, a
/// kind we know, a fee we could parse, and a window that has not run out.
class MerchantFeePromotion {
  const MerchantFeePromotion({
    required this.active,
    this.kind,
    this.platformFeePercent,
    this.endsAt,
    this.daysRemaining,
    this.bannerEn,
    this.bannerDv,
  });

  /// Nothing running — also what a failed or not-yet-answered read means to
  /// every surface. Pricing through [chargedFeeBp] then returns the tier
  /// fee untouched, which is exactly what the server would charge.
  static const none = MerchantFeePromotion(active: false);

  factory MerchantFeePromotion.fromJson(Map<String, dynamic> json) =>
      MerchantFeePromotion(
        active: json['active'] == true,
        kind: FeePromotionKind.fromWire(json['kind']),
        platformFeePercent: _string(json['platform_fee_percent']),
        endsAt: _instant(json['ends_at']),
        daysRemaining: json['days_remaining'] is int
            ? json['days_remaining'] as int
            : null,
        bannerEn: _string(json['banner_en']),
        bannerDv: _string(json['banner_dv']),
      );

  /// The server's own verdict. True does NOT by itself mean the app may
  /// draw something — see [showable].
  final bool active;

  /// Null when absent OR when this build does not know the kind sent.
  final FeePromotionKind? kind;

  /// PLAN §1 wire grammar: a 2-decimal percent STRING ("0.00", "0.25").
  /// Basis points never appear on the wire; [feeBp] is local arithmetic.
  final String? platformFeePercent;

  /// EXCLUSIVE — the first instant the offer no longer prices a sale. For
  /// the introductory kind this is THIS store's own window end, computed
  /// from its approval date. Null when the campaign has no end.
  final DateTime? endsAt;

  /// The server's own day count, business-timezone calendar days. Never
  /// re-derived here: the platform counts days in one place.
  final int? daysRemaining;

  final String? bannerEn;
  final String? bannerDv;

  /// The promotional fee in integer basis points, or null if the wire
  /// string was absent or malformed. Integer string surgery, never
  /// `double.parse` — float money is banned end to end (§11) — and the
  /// same grammar the app's own `parsePercentToBp` accepts.
  ///
  /// Bounded 0–2000, the ceiling the settings row itself carries: a fee
  /// above 20% is not a promotion, it is a bad parse, and refusing it
  /// leaves the till quoting the tier fee it already knew.
  int? get feeBp {
    final percent = platformFeePercent?.trim();
    if (percent == null || !_percentPattern.hasMatch(percent)) return null;

    final parts = percent.split('.');
    final frac = parts.length > 1 ? parts[1].padRight(2, '0') : '00';
    final bp = int.parse(parts[0]) * 100 + int.parse(frac);

    return bp > 2000 ? null : bp;
  }

  /// Whether the window has run out as far as THIS device can tell.
  ///
  /// The server is the authority and stops serving a finished promotion —
  /// but a till left open on a counter may not ask again for hours, and a
  /// campaign that ended at midnight must not still be advertised at noon.
  /// A null end never expires (a campaign without a deadline).
  bool expiredAt(DateTime now) {
    final ends = endsAt;
    return ends != null && !ends.isAfter(now);
  }

  /// The ONE question every surface asks. False for: nothing running, a
  /// kind this build does not know, an unparseable fee, and a window that
  /// has already closed.
  bool showableAt(DateTime now) =>
      active && kind != null && feeBp != null && !expiredAt(now);

  /// [showableAt] against the device clock.
  bool get showable => showableAt(DateTime.now().toUtc());

  /// The server-written sentence for the reader's language, falling back to
  /// the other one rather than showing a banner with no words in it. The
  /// settings endpoint refuses to ENABLE a promotion without both, so the
  /// fallback is defence against an older row, never the normal path.
  String? banner({required bool dhivehi}) {
    final first = dhivehi ? bannerDv : bannerEn;
    final second = dhivehi ? bannerEn : bannerDv;
    if (first != null && first.trim().isNotEmpty) return first;
    if (second != null && second.trim().isNotEmpty) return second;
    return null;
  }

  /// THE PRICING RULE, mirrored from App\Domain\Cashback\TermsResolver::
  /// priceAt():
  ///
  ///   charged fee bp = min(promotion fee bp, tier fee bp)
  ///
  /// The MERCHANT WINS — a store already on a cheaper tier than the
  /// campaign keeps its cheaper tier. Used by the till's cost preview so
  /// the quote matches what the server will charge a second later; the
  /// server still prices every sale authoritatively.
  ///
  /// [tierFeeBp] null (a legacy unpriced rate) stays null: the server
  /// refuses those credits and the preview says "—" rather than quoting a
  /// promotional fee on a rate that has no fee at all.
  int? chargedFeeBpAt(int? tierFeeBp, DateTime now) {
    if (tierFeeBp == null) return null;
    if (!showableAt(now)) return tierFeeBp;
    final promotional = feeBp!;
    return promotional < tierFeeBp ? promotional : tierFeeBp;
  }

  /// [chargedFeeBpAt] against the device clock.
  int? chargedFeeBp(int? tierFeeBp) =>
      chargedFeeBpAt(tierFeeBp, DateTime.now().toUtc());
}

/// PLAN §1 wire grammar for a percent: a plain decimal, at most two
/// fractional digits. Hoisted so parsing a promotion costs no compile.
final _percentPattern = RegExp(r'^\d{1,3}(\.\d{1,2})?$');

/// A string, or null — never a thrown cast and never the literal "null".
String? _string(Object? value) {
  if (value == null) return null;
  final text = value.toString();
  return text.isEmpty ? null : text;
}

/// ISO 8601 → DateTime in UTC, or null. A stamp this app cannot read is
/// treated as "no end", which keeps the banner on screen until the server
/// takes it away — the conservative direction for a promise already made.
DateTime? _instant(Object? value) {
  if (value is! String || value.isEmpty) return null;
  return DateTime.tryParse(value)?.toUtc();
}
