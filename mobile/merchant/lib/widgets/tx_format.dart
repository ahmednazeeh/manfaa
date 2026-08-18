import 'package:manfaa_ui/manfaa_ui.dart';

import '../l10n/gen/app_localizations.dart';

/// The §6 transaction vocabulary in words and tones — shared by the credit
/// result card and the Transactions tab, mirroring the web panel's
/// labels.ts exactly (en + dv, no raw snake_case in any UI by project law).

/// TransactionResource.state → localized label. A state this build predates
/// falls back to neutral prose, never to the raw code.
String transactionStateLabel(AppLocalizations l10n, String state) =>
    switch (state) {
      'tracked' => l10n.stateTracked,
      'awaiting_validation' => l10n.stateAwaitingValidation,
      'payable_unfunded' => l10n.statePayableUnfunded,
      'on_hold' => l10n.stateOnHold,
      'confirmed' => l10n.stateConfirmed,
      'paid' => l10n.statePaid,
      'reversed' => l10n.stateReversed,
      'written_off' => l10n.stateWrittenOff,
      _ => l10n.stateOther,
    };

/// The chip colour by MONEY SEMANTICS: amber while conditional, green once
/// sure, blue when paid through, attention for held/written-off, quiet when
/// closed — the same mapping the whole design system draws with.
StatusTone transactionStateTone(String state) => switch (state) {
  'tracked' || 'awaiting_validation' => StatusTone.pending,
  'payable_unfunded' => StatusTone.pending,
  'confirmed' => StatusTone.confirmed,
  'paid' => StatusTone.paid,
  'on_hold' || 'written_off' => StatusTone.attention,
  'reversed' => StatusTone.closed,
  _ => StatusTone.closed,
};

/// reason_code → the qualifier line under the chip, or null for a clean row.
/// Unknown codes (a server deploy can add them) render the generic sentence.
String? transactionReasonLabel(AppLocalizations l10n, String? code) =>
    switch (code) {
      null || '' => null,
      'auto_validation_window' => l10n.reasonAutoValidation,
      'backdated_final' => l10n.reasonBackdatedFinal,
      'below_minimum' => l10n.reasonBelowMinimum,
      'merchant_suspended' => l10n.reasonMerchantSuspended,
      'settlement_allocated' => l10n.reasonSettlementAllocated,
      'payout_completed' => l10n.reasonPayoutCompleted,
      'customer_refund' => l10n.reasonCustomerRefund,
      'till_void' => l10n.reasonTillVoid,
      'duplicate' => l10n.reasonDuplicate,
      'other' => l10n.reasonOtherCorrected,
      'stale_timestamp' => l10n.reasonStaleTimestamp,
      _ => l10n.reasonUnknown,
    };

const _months = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

/// "16 Aug 2026, 02:07 AM" — the refs' date-time shape. Manual and
/// locale-stable, same stance as the customer app's formatDayMonth: intl
/// carries no Divehi date symbols, and these glyphs read fine in both
/// scripts.
String formatDateTimeDisplay(DateTime dt) {
  final hour12 = dt.hour % 12 == 0 ? 12 : dt.hour % 12;
  final minute = dt.minute.toString().padLeft(2, '0');
  final period = dt.hour < 12 ? 'AM' : 'PM';
  return '${dt.day} ${_months[dt.month - 1]} ${dt.year}, '
      '${hour12.toString().padLeft(2, '0')}:$minute $period';
}

/// SettlementResource.state → localized label (§6 settlement vocabulary,
/// mirroring the web panel's labels.settlementState). Unknown states fall
/// back to neutral prose, never the raw code.
String settlementStateLabel(AppLocalizations l10n, String state) =>
    switch (state) {
      'draft' => l10n.setlStateDraft,
      'awaiting_payment' => l10n.setlStateAwaitingPayment,
      'payment_review' => l10n.setlStatePaymentReview,
      'settled' => l10n.setlStateSettled,
      'partially_settled' => l10n.setlStatePartiallySettled,
      'cancelled' => l10n.setlStateCancelled,
      _ => l10n.stateOther,
    };

/// Settlement state → chip tone, by the same money semantics as
/// transactions: amber while the money is in flight, green once settled.
StatusTone settlementStateTone(String state) => switch (state) {
  'awaiting_payment' || 'payment_review' => StatusTone.pending,
  'settled' => StatusTone.confirmed,
  'partially_settled' => StatusTone.pending,
  'cancelled' => StatusTone.closed,
  _ => StatusTone.closed,
};

/// WalletTransaction.type → localized label (labels.walletMovement).
String walletMovementLabel(AppLocalizations l10n, String type) =>
    switch (type) {
      'top_up' => l10n.movementTopUp,
      'settlement' => l10n.movementSettlement,
      'settlement_credit' => l10n.movementSettlementCredit,
      _ => l10n.movementOther,
    };

/// The two platform bank slugs, resolved the way the web's bankLabel does:
/// a known slug prints the bank's name, anything else prints verbatim
/// (never asserting a bank nobody chose).
String bankDisplayName(String bankName) =>
    switch (bankName.trim().toLowerCase()) {
      'bml' => 'Bank of Maldives',
      'mib' => 'Maldives Islamic Bank',
      _ => bankName,
    };

/// "5%" from the wire's exact "5.00" — display trim only, the string on the
/// wire is never recomputed. "2.50" → "2.5%".
String trimRatePercent(String ratePercent) {
  var trimmed = ratePercent;
  if (trimmed.contains('.')) {
    trimmed = trimmed.replaceAll(RegExp(r'0+$'), '');
    trimmed = trimmed.replaceAll(RegExp(r'\.$'), '');
  }
  return '${trimmed.isEmpty ? '0' : trimmed}%';
}

/// "25 Aug 2026" — date only, same manual month table as the time shape.
String formatDateDisplay(DateTime dt) =>
    '${dt.day} ${_months[dt.month - 1]} ${dt.year}';

/// A wire instant → the BUSINESS-day date (+05:00, Indian/Maldives — no
/// DST). Used for due dates and the discount deadline, where "which day"
/// must be Malé's answer, not UTC's.
String formatBusinessDate(String iso) {
  final parsed = DateTime.tryParse(iso);
  if (parsed == null) return iso;
  return formatDateDisplay(parsed.toUtc().add(const Duration(hours: 5)));
}

/// A wire instant → "18 Aug 2026, 02:32 PM" in BUSINESS wall time (+05:00,
/// Indian/Maldives — no DST). Used for the MR9 "submitted at" line: the
/// moment a change request was raised is a moment the merchant and the
/// reviewer must read identically, and the DEVICE's zone would have an owner
/// abroad reading a different time than the admin sees on the same row.
String formatBusinessDateTime(String iso) {
  final parsed = DateTime.tryParse(iso);
  if (parsed == null) return iso;
  return formatDateTimeDisplay(parsed.toUtc().add(const Duration(hours: 5)));
}

/// The PLAN §1 discount deadline: the day the OLDEST clock-started line
/// stops qualifying — its clock_start_at plus the window the API reported,
/// printed as a business date. The one piece of date arithmetic the app
/// does on a rule (web parity: PromptDiscountDeadline); whether a line
/// still qualifies stays entirely the server's answer.
String? discountDeadlineDate(List<String?> clockStarts, int maxAgeDays) {
  DateTime? oldest;
  for (final iso in clockStarts) {
    if (iso == null) continue;
    final parsed = DateTime.tryParse(iso);
    if (parsed == null) continue;
    final utc = parsed.toUtc();
    if (oldest == null || utc.isBefore(oldest)) oldest = utc;
  }
  if (oldest == null) return null;
  return formatDateDisplay(
    oldest.add(Duration(days: maxAgeDays, hours: 5)),
  );
}

/// The same shape from a wire ISO string (occurred_at carries +05:00; the
/// wall-clock digits in the string ARE the business time, so no conversion).
String formatIsoDisplay(String iso) {
  final match = RegExp(
    r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})',
  ).firstMatch(iso);
  if (match == null) return iso;
  return formatDateTimeDisplay(
    DateTime(
      int.parse(match.group(1)!),
      int.parse(match.group(2)!),
      int.parse(match.group(3)!),
      int.parse(match.group(4)!),
      int.parse(match.group(5)!),
    ),
  );
}
