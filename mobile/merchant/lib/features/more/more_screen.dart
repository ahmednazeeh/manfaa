import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import 'more_providers.dart';
import 'more_widgets.dart';

/// The More tab (MR5), drawn to Merchant More.png: identity card, the six
/// tinted menu rows, the red log-out row. MR8 adds the store-readiness
/// estate: the legal card (Privacy Policy / Terms of Service — the
/// merchant.manfaa.app documents, opened in the external browser) and the
/// danger-styled Close store card riding the public phone+OTP closure flow.
///
/// PERMISSION-DRAWN like every surface: a row exists only when the session
/// holds its read permission (the server enforces regardless). Cashback
/// Settings is the web's merged screen and self-gates any-of its three
/// section permissions, exactly as apps/merchant's menu does. The legal and
/// closure rows are deliberately UNGATED, like Log out: the documents are
/// public, and closure's credential is the store phone's OTP, not a
/// permission slug.
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();
    // The logo rides the setup read model (web parity) — accounts without
    // `setup.view` simply keep the initial avatar.
    final logoUrl = ref
        .watch(settingsSetupProvider)
        .valueOrNull
        ?.values
        .logoUrl;

    // No Profile row (owner report 2026-08-18): the identity card above
    // already opens the profile with "View profile", and two doors to one
    // screen is a menu asking to be misread.
    final rows = <_MenuRow>[
      if (session.can('staff.view'))
        _MenuRow(
          Icons.group_outlined,
          ManfaaTint.blue,
          l10n.menuEmployees,
          '/more/employees',
        ),
      if (session.can('roles.view'))
        _MenuRow(
          Icons.local_police_outlined,
          ManfaaTint.amber,
          l10n.menuRoles,
          '/more/roles',
        ),
      if (session.can('branches.view'))
        _MenuRow(
          Icons.storefront_outlined,
          ManfaaTint.green,
          l10n.menuBranches,
          '/more/branches',
        ),
      if (session.can('rate.view') ||
          session.can('product_categories.view') ||
          session.can('preferences.update'))
        _MenuRow(
          Icons.percent_rounded,
          ManfaaTint.violet,
          l10n.menuCashback,
          '/more/cashback',
        ),
      if (session.can('promotions.view'))
        _MenuRow(
          Icons.campaign_outlined,
          ManfaaTint.coral,
          l10n.menuPromotions,
          '/more/promotions',
        ),
    ];

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: a menu is a single column at any size — the shared ~640dp
        // rail centers it on tablets; phones render exactly as shipped.
        child: ContentRail(
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              Gap.xl,
              Gap.lg,
              Gap.xl,
              bottomClearanceOf(context),
            ),
            children: [
              MerchantTopBar(initials: initials),
              const SizedBox(height: Gap.lg),
              Text(
                l10n.moreTitle,
                style: theme.textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.moreSubtitle,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: Gap.lg),
              _IdentityCard(
                name: name,
                logoUrl: logoUrl,
                verified: session.merchantStatus == 'active',
                canViewProfile: session.can('profile.view'),
              ),
              const SizedBox(height: Gap.lg),
              if (rows.isNotEmpty) ...[
                ManfaaCard(
                  padding: const EdgeInsets.symmetric(
                    horizontal: Gap.lg,
                    vertical: Gap.xs,
                  ),
                  child: Column(
                    children: [
                      for (final (i, row) in rows.indexed) ...[
                        if (i > 0)
                          Divider(
                            height: 1,
                            color: theme.colorScheme.outlineVariant,
                          ),
                        _MenuRowTile(row: row),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: Gap.lg),
              ],
              _LegalCard(l10n: l10n),
              const SizedBox(height: Gap.lg),
              _LogOutCard(l10n: l10n),
              const SizedBox(height: Gap.lg),
              // Below Log out, deliberately last: the estate's one
              // irreversible act sits at the bottom of the page in its own
              // danger card (the More.png card idiom, coral family).
              _CloseStoreCard(l10n: l10n),
            ],
          ),
        ),
      ),
    );
  }
}

/// Privacy Policy + Terms of Service — the merchant.manfaa.app documents,
/// opened OUTSIDE the app (url_launcher external mode): legal documents are
/// the web pages, never a re-render that could drift from them.
class _LegalCard extends StatelessWidget {
  const _LegalCard({required this.l10n});

  final AppLocalizations l10n;

  Future<void> _open(String path) async {
    await launchUrl(
      Uri.parse('https://merchant.manfaa.app/$path'),
      mode: LaunchMode.externalApplication,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    Widget row({
      required IconData icon,
      required String label,
      required String path,
    }) =>
        InkWell(
          onTap: () => _open(path),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: Gap.md),
            child: Row(
              children: [
                IconTile(icon, tint: ManfaaTint.blue, size: 44, iconSize: 22),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Text(
                    label,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                Icon(
                  Icons.open_in_new_rounded,
                  size: 20,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ],
            ),
          ),
        );

    return ManfaaCard(
      padding: const EdgeInsets.symmetric(
        horizontal: Gap.lg,
        vertical: Gap.xs,
      ),
      child: Column(
        children: [
          row(
            icon: Icons.privacy_tip_outlined,
            label: l10n.menuPrivacy,
            path: 'privacy',
          ),
          Divider(height: 1, color: theme.colorScheme.outlineVariant),
          row(
            icon: Icons.description_outlined,
            label: l10n.menuTerms,
            path: 'terms',
          ),
        ],
      ),
    );
  }
}

/// The danger row into the in-app closure flow (phone+OTP — the same rules
/// as the public web page; see CloseStoreScreen).
class _CloseStoreCard extends StatelessWidget {
  const _CloseStoreCard({required this.l10n});

  final AppLocalizations l10n;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ManfaaCard(
      onTap: () => context.go('/more/close-store'),
      child: Row(
        children: [
          const IconTile(
            Icons.storefront_outlined,
            tint: ManfaaTint.coral,
            size: 44,
            iconSize: 22,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.closeStoreTitle,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: theme.brightness == Brightness.dark
                        ? ManfaaColors.coral
                        : ManfaaColors.coralDeep,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.closeStoreRowHint,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          Icon(
            Icons.chevron_right_rounded,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ],
      ),
    );
  }
}

class _IdentityCard extends StatelessWidget {
  const _IdentityCard({
    required this.name,
    required this.logoUrl,
    required this.verified,
    required this.canViewProfile,
  });

  final String name;
  final String? logoUrl;
  final bool verified;
  final bool canViewProfile;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return ManfaaCard(
      child: Row(
        children: [
          StoreLogoAvatar(name: name, logoUrl: logoUrl, size: 56),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.merchantAccount,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                if (verified) ...[
                  const SizedBox(height: Gap.sm),
                  const VerifiedChip(),
                ],
              ],
            ),
          ),
          if (canViewProfile) ...[
            const SizedBox(width: Gap.sm),
            OutlinedButton(
              onPressed: () => context.go('/more/profile'),
              style: OutlinedButton.styleFrom(
                foregroundColor: theme.brightness == Brightness.dark
                    ? const Color(0xFFB79CF6)
                    : ManfaaColors.violetDeep,
                side: BorderSide(
                  color: theme.brightness == Brightness.dark
                      ? ManfaaColors.violet
                      : const Color(0xFFD9CEF9),
                ),
                minimumSize: const Size(0, 40),
                padding: const EdgeInsets.symmetric(horizontal: Gap.md),
              ),
              child: Text(
                l10n.viewProfile,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _MenuRow {
  const _MenuRow(this.icon, this.tint, this.label, this.path);
  final IconData icon;
  final ManfaaTint tint;
  final String label;
  final String path;
}

class _MenuRowTile extends StatelessWidget {
  const _MenuRowTile({required this.row});

  final _MenuRow row;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: () => context.go(row.path),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Gap.md),
        child: Row(
          children: [
            IconTile(row.icon, tint: row.tint, size: 44, iconSize: 22),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Text(
                row.label,
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            Icon(
              Icons.chevron_right_rounded,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ],
        ),
      ),
    );
  }
}

class _LogOutCard extends ConsumerWidget {
  const _LogOutCard({required this.l10n});

  final AppLocalizations l10n;

  Future<void> _confirm(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(l10n.logOutConfirmTitle),
        content: Text(l10n.logOutConfirmBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            style: FilledButton.styleFrom(
              backgroundColor: ManfaaColors.coralDeep,
              foregroundColor: Colors.white,
              minimumSize: const Size(0, 44),
              padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
            ),
            child: Text(l10n.logOut),
          ),
        ],
      ),
    );
    if (confirmed ?? false) {
      // The local wipe always lands (MerchantApi.signOut) and the router's
      // revision listener walks the user out to /login on its own.
      await ref.read(apiProvider).signOut();
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    return ManfaaCard(
      onTap: () => _confirm(context, ref),
      child: Row(
        children: [
          const IconTile(
            Icons.logout_rounded,
            tint: ManfaaTint.coral,
            size: 44,
            iconSize: 22,
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Text(
              l10n.logOut,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: theme.brightness == Brightness.dark
                    ? ManfaaColors.coral
                    : ManfaaColors.coralDeep,
              ),
            ),
          ),
          Icon(
            Icons.chevron_right_rounded,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ],
      ),
    );
  }
}
