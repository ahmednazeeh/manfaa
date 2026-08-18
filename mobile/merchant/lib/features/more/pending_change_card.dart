import 'package:flutter/material.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/tx_format.dart';

/// MR9 — "Waiting for Manfaa's review", carrying the PROPOSED values.
///
/// A live store's public claims (its name, category, channel, logo, website,
/// the what-earns-cashback promise) and its whole branch estate do not move
/// when the owner saves: they queue for admin review. Without this card a
/// gated save is indistinguishable from a lost one — the form would say
/// "saved" and every field would still read the old value.
///
/// The card shows what is WAITING, never what is live: the screens around it
/// keep showing the live values, because those are what a shopper sees until
/// an admin approves.
class PendingChangeCard extends StatelessWidget {
  const PendingChangeCard({
    super.key,
    required this.request,
    this.note,
    this.categoryLabel,
  });

  final MerchantChangeRequest request;

  /// The sentence under the diff — what the owner should expect next. It
  /// differs by surface (a view says the live values still stand, an editor
  /// says re-saving replaces the request), so the screen supplies it.
  final String? note;

  /// Resolves a curated category slug to its display name. Null (or a slug
  /// the served list no longer carries) prints the slug itself, exactly the
  /// profile screen's fallback.
  final String Function(String slug)? categoryLabel;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final colors = toneSurface(ToneSurface.pending, theme.brightness);
    final rows = pendingChangeRows(l10n, request, categoryLabel: categoryLabel);

    final submitted = request.submittedAt;
    final subtitle = [
      changeKindLabel(l10n, request.kind, fallback: request.kindLabel),
      if (submitted != null && submitted.isNotEmpty)
        l10n.pendingSubmittedAt(formatBusinessDateTime(submitted)),
    ].join(' · ');

    return Container(
      padding: const EdgeInsets.all(Gap.lg),
      decoration: BoxDecoration(
        color: colors.background,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.hourglass_top_rounded,
                size: 20,
                color: colors.foreground,
              ),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.pendingReviewTitle,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: colors.foreground,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: colors.foreground,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          // A removal proposes NOTHING — its diff is empty by construction,
          // and the note is the whole of what is waiting.
          if (rows.isNotEmpty) ...[
            const SizedBox(height: Gap.md),
            for (final (i, row) in rows.indexed) ...[
              if (i > 0) const SizedBox(height: Gap.sm),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      row.label,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: colors.foreground,
                      ),
                    ),
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Text(
                      row.value,
                      textAlign: TextAlign.end,
                      textDirection: row.ltr ? TextDirection.ltr : null,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: colors.foreground,
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ],
          if (note != null) ...[
            const SizedBox(height: Gap.md),
            Text(
              note!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: colors.foreground,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// One line of a queued change, ready to draw.
class PendingChangeRow {
  const PendingChangeRow(this.label, this.value, {this.ltr = false});

  final String label;
  final String value;

  /// Coordinates, URLs and phone numbers keep their Latin order inside dv.
  final bool ltr;
}

/// The change's kind, in the app's own words.
///
/// The wire also publishes an English `kind_label` — it is what the merchant's
/// push notification called the change — but this app is bilingual, so the
/// words come from the KIND. An unknown kind (a later deploy's) falls back to
/// the server's own label rather than printing a snake_case slug.
String changeKindLabel(
  AppLocalizations l10n,
  String kind, {
  String fallback = '',
}) => switch (kind) {
  MerchantChangeRequest.kindProfile => l10n.changeKindProfile,
  MerchantChangeRequest.kindBranchCreate => l10n.changeKindBranchCreate,
  MerchantChangeRequest.kindBranchUpdate => l10n.changeKindBranchUpdate,
  MerchantChangeRequest.kindBranchDelete => l10n.changeKindBranchDelete,
  _ => fallback.isEmpty ? l10n.changeKindOther : fallback,
};

/// A field's label, resolved through the KIND: `name` is in both vocabularies
/// and means two different things — the store's name, or a branch's.
String changeFieldLabel(AppLocalizations l10n, String kind, String field) {
  if (kind == MerchantChangeRequest.kindProfile) {
    return switch (field) {
      'name' => l10n.storeNameLabel,
      'name_dv' => l10n.storeNameDvLabel,
      'category' => l10n.categoryLabel,
      'channel' => l10n.channelRowLabel,
      'eligibility_basis' => l10n.termsTitle,
      'website_url' => l10n.changeFieldWebsite,
      'logo' => l10n.storeLogoLabel,
      // A field the server gates tomorrow ships before this build does.
      _ => l10n.changeFieldOther,
    };
  }

  return switch (field) {
    'name' => l10n.changeFieldBranchName,
    'address' => l10n.changeFieldAddress,
    // Never shown apart: half a pin is not a place, so the pair collapses
    // into one row (see pendingChangeRows).
    'lat' || 'lng' => l10n.changeFieldLocation,
    _ => l10n.changeFieldOther,
  };
}

/// The PROPOSED half of a queued change as label/value lines.
///
/// Built from `changes` (which the server ordered) rather than from
/// `proposed`, so what the owner reads is the same field list the reviewer
/// decides on — and the lat/lng pair collapses into one "Map pin" line,
/// because half a coordinate is not a place a shopper can walk to.
List<PendingChangeRow> pendingChangeRows(
  AppLocalizations l10n,
  MerchantChangeRequest request, {
  String Function(String slug)? categoryLabel,
}) {
  final rows = <PendingChangeRow>[];
  var pinDone = false;

  for (final change in request.changes) {
    if (change.field == 'lat' || change.field == 'lng') {
      if (pinDone) continue;
      pinDone = true;
      rows.add(
        PendingChangeRow(
          l10n.changeFieldLocation,
          _pinText(l10n, request.proposed['lat'], request.proposed['lng']),
          ltr: true,
        ),
      );
      continue;
    }

    rows.add(
      PendingChangeRow(
        changeFieldLabel(l10n, request.kind, change.field),
        _valueText(l10n, request.kind, change.field, change.to,
            categoryLabel: categoryLabel),
        ltr: change.field == 'website_url',
      ),
    );
  }

  return rows;
}

/// "4.17554, 73.50935" — the pin as the branch card already prints it, or
/// "Not set" when the change TAKES the pin away (this app is the only
/// surface that can).
String _pinText(AppLocalizations l10n, Object? lat, Object? lng) {
  final latitude = _toDouble(lat);
  final longitude = _toDouble(lng);
  if (latitude == null || longitude == null) return l10n.notSet;

  return '${latitude.toStringAsFixed(5)}, ${longitude.toStringAsFixed(5)}';
}

/// Coordinates arrive as numbers from a validated payload and as decimal
/// STRINGS from a snapshot (the column cast) — both are the same place.
double? _toDouble(Object? value) => switch (value) {
  final num n => n.toDouble(),
  final String s => double.tryParse(s),
  _ => null,
};

String _valueText(
  AppLocalizations l10n,
  String kind,
  String field,
  Object? value, {
  String Function(String slug)? categoryLabel,
}) {
  // Both sides of a logo change are AUTHORISING preview URLs (they need this
  // account's bearer token), so they are not image sources a card can paint
  // — the row states that an image is waiting, which is the decision.
  if (field == 'logo') return l10n.changeLogoValue;

  if (value == null) return l10n.notSet;

  final text = value is String ? value.trim() : value.toString();
  if (text.isEmpty) return l10n.notSet;

  if (kind == MerchantChangeRequest.kindProfile) {
    if (field == 'channel') {
      return switch (text) {
        'in_store' => l10n.channelInStore,
        'online' => l10n.channelOnline,
        // ALWAYS the spelled-out pair, per the web panel's display law.
        'both' => l10n.channelBothDisplay,
        _ => text,
      };
    }
    if (field == 'category') return categoryLabel?.call(text) ?? text;
  }

  return text;
}
