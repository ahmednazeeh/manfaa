import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../../app/providers.dart';

/// The MR5 More-estate providers — the store-identity half (profile,
/// cashback settings). Everything autoDispose: a settings screen re-reads
/// on re-entry, because someone on the web panel may have moved the ground.

/// GET /merchant/profile — gated `profile.view` (the row and route are
/// hidden without it, so this is only watched when it can succeed).
final profileProvider = FutureProvider.autoDispose<MerchantProfile>(
  (ref) => ref.watch(apiProvider).profile(),
);

/// The setup state doubles as the read model for the current logo URL and
/// the curated category list (web parity: the settings profile page reads
/// useSetupState for exactly these). Null — not an error — for accounts
/// without `setup.view`: the profile screen then falls back to initials and
/// raw slugs rather than 403-ing the whole screen.
final settingsSetupProvider = FutureProvider.autoDispose<MerchantSetupState?>((
  ref,
) {
  ref.watch(sessionTickProvider); // re-evaluate when permissions change
  if (!ref.watch(sessionProvider).can('setup.view')) {
    return Future.value(null);
  }
  return ref.watch(apiProvider).getSetup();
});

/// GET /merchant/rate — current + pending window (`rate.view`).
final rateSettingsProvider = FutureProvider.autoDispose<MerchantRate>(
  (ref) => ref.watch(apiProvider).merchantRate(),
);

/// The per-store category rules, inactive rows included
/// (`product_categories.view`).
final categoryRulesProvider = FutureProvider.autoDispose<List<ProductCategory>>(
  (ref) => ref.watch(apiProvider).productCategories(),
);

/// The earning knobs. The API exposes no GET — this READ is the empty
/// PATCH the web panel uses too (`preferences.update`).
final preferencesProvider = FutureProvider.autoDispose<MerchantPreferences>(
  (ref) => ref.watch(apiProvider).preferences(),
);

/// GET /merchant/staff — the store's panel accounts (`staff.view`).
final staffProvider = FutureProvider.autoDispose<List<MerchantStaff>>(
  (ref) => ref.watch(apiProvider).staff(),
);

/// GET /merchant/roles — the store's own roles with resolved permissions
/// and staff counts (`roles.view`).
final rolesProvider = FutureProvider.autoDispose<List<MerchantRole>>(
  (ref) => ref.watch(apiProvider).roles(),
);

/// The SERVED permission catalogue the roles editor draws its checkbox
/// grid from (`roles.view` — D8: published, never hardcoded, so a slug a
/// later deploy adds still renders in this build).
final permissionCatalogueProvider =
    FutureProvider.autoDispose<PermissionCatalogue>(
  (ref) => ref.watch(apiProvider).permissionCatalogue(),
);

/// GET /merchant/branches (`branches.view`) — the estate AND the branch
/// changes waiting on a reviewer (MR9). A live store's branch writes queue,
/// so the list alone would show a screen that ignored the owner's last save.
final branchesProvider = FutureProvider.autoDispose<MerchantBranchEstate>(
  (ref) => ref.watch(apiProvider).branches(),
);

/// GET /merchant/promotions — every status, newest first
/// (`promotions.view`).
final promotionsProvider = FutureProvider.autoDispose<List<Promotion>>(
  (ref) => ref.watch(apiProvider).promotions(),
);

/// Branch names for surfaces that only DECORATE with them (the promotions
/// list's branch line). Null — not an error — without `branches.view`: the
/// row then falls back to "Branch #id", exactly as the web page does for a
/// deleted branch.
final branchNamesProvider =
    FutureProvider.autoDispose<Map<int, String>?>((ref) async {
  ref.watch(sessionTickProvider);
  if (!ref.watch(sessionProvider).can('branches.view')) return null;
  final estate = await ref.watch(apiProvider).branches();
  return {for (final branch in estate.branches) branch.id: branch.name};
});
