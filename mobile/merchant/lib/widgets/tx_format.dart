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
