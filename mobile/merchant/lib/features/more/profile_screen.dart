import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import 'more_providers.dart';
import 'more_widgets.dart';
import 'profile_edit_screen.dart';

/// The store profile (MR5), drawn to Profile.png: the lavender header card
/// (logo, names, Verified) and the row list — logo thumb, names, category,
/// channel, contacts, website, and the what-earns-cashback text.
///
/// `eligibility_basis` is ONE free-text string on the wire, so the ref's
/// chips render as the text itself — the look is followed, data is never
/// invented. Edit is drawn only behind `profile.edit`.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  void _openEdit(BuildContext context, WidgetRef ref, MerchantProfile profile) {
    final setup = ref.read(settingsSetupProvider).valueOrNull;
    // The editor invalidates the providers itself after a landed write, so
    // nothing here outlives this screen (no ref use after an await).
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => ProfileEditScreen(profile: profile, setup: setup),
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    final profile = ref.watch(profileProvider);
    final setup = ref.watch(settingsSetupProvider).valueOrNull;

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: a profile is a single column at any size — the shared ~640dp
        // rail centers it on tablets; phones render exactly as shipped.
        child: ContentRail(
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              Gap.xl,
              Gap.sm,
              Gap.xl,
              bottomClearanceOf(context),
            ),
            children: [
              MerchantDetailTopBar(initials: initials),
              const SizedBox(height: Gap.md),
              Text(
                l10n.profileScreenTitle,
                style: theme.textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.profileScreenSubtitle,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: Gap.lg),
              ...profile.when(
                loading: () => const [
                  SkeletonBox(height: 120, radius: Corner.card),
                  SizedBox(height: Gap.md),
                  SkeletonBox(height: 360, radius: Corner.card),
                ],
                error: (error, _) => [
                  ManfaaCard(
                    child: Column(
                      children: [
                        Text(
                          error is MobileApiException
                              ? error.message
                              : l10n.errorGeneric,
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: Gap.md),
                        OutlinedButton(
                          onPressed: () => ref.invalidate(profileProvider),
                          child: Text(l10n.retry),
                        ),
                      ],
                    ),
                  ),
                ],
                data: (profile) =>
                    _blocks(context, ref, session, profile, setup),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _blocks(
    BuildContext context,
    WidgetRef ref,
    MerchantSession session,
    MerchantProfile profile,
    MerchantSetupState? setup,
  ) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final logoUrl = setup?.values.logoUrl;
    final canEdit = session.can('profile.edit');

    // The curated list resolves the slug into its display name; a slug the
    // list no longer offers (retired) — or no list at all (no `setup.view`)
    // — prints as the slug itself, exactly the web's fallback.
    String categoryLabel(String? slug) {
      if (slug == null || slug.isEmpty) return l10n.notSet;
      final match = setup?.categories.where((c) => c.slug == slug).firstOrNull;
      return match?.label(dhivehi: dhivehi) ?? slug;
    }

    String channelLabel(String channel) => switch (channel) {
      'in_store' => l10n.channelInStore,
      'online' => l10n.channelOnline,
      // ALWAYS the spelled-out pair, per the web panel's display law.
      'both' => l10n.channelBothDisplay,
      _ => channel,
    };

    return [
      _HeaderCard(
        profile: profile,
        logoUrl: logoUrl,
        canEdit: canEdit,
        onEdit: () => _openEdit(context, ref, profile),
      ),
      const SizedBox(height: Gap.lg),
      ManfaaCard(
        padding: const EdgeInsets.symmetric(
          horizontal: Gap.lg,
          vertical: Gap.xs,
        ),
        child: Column(
          children: [
            _DetailRow(
              icon: Icons.image_outlined,
              label: l10n.storeLogoLabel,
              trailing: StoreLogoAvatar(
                name: profile.name,
                logoUrl: logoUrl,
                size: 36,
              ),
              onTap: canEdit ? () => _openEdit(context, ref, profile) : null,
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.storefront_outlined,
              label: l10n.storeNameLabel,
              value: profile.name,
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.translate_rounded,
              label: l10n.storeNameDvLabel,
              value: profile.nameDv ?? l10n.notSet,
              muted: profile.nameDv == null,
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.sell_outlined,
              label: l10n.categoryLabel,
              value: categoryLabel(profile.category),
              hint: profile.categoryRetired ? l10n.categoryRetiredHint : null,
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.store_mall_directory_outlined,
              label: l10n.channelRowLabel,
              value: channelLabel(profile.channel),
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.mail_outline_rounded,
              label: l10n.contactEmailLabel,
              value: profile.contactEmail ?? l10n.notSet,
              muted: profile.contactEmail == null,
              ltrValue: true,
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.support_agent_rounded,
              label: l10n.supportPhoneLabel,
              value: profile.supportPhone ?? l10n.notSet,
              muted: profile.supportPhone == null,
              ltrValue: true,
            ),
            const _RowDivider(),
            _DetailRow(
              icon: Icons.call_outlined,
              label: l10n.contactPhoneLabel,
              value: profile.contactPhone ?? l10n.notSet,
              muted: profile.contactPhone == null,
              ltrValue: true,
            ),
            const _RowDivider(),
            _WebsiteRow(url: profile.websiteUrl),
            const _RowDivider(),
            // ONE free-text string on the wire — rendered as the text.
            Padding(
              padding: const EdgeInsets.symmetric(vertical: Gap.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const IconTile(
                        Icons.percent_rounded,
                        tint: ManfaaTint.violet,
                        size: 36,
                        iconSize: 18,
                      ),
                      const SizedBox(width: Gap.md),
                      Expanded(
                        child: Text(
                          l10n.termsTitle,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: Gap.sm),
                  Text(
                    (profile.eligibilityBasis ?? '').trim().isEmpty
                        ? l10n.notSet
                        : profile.eligibilityBasis!,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: (profile.eligibilityBasis ?? '').trim().isEmpty
                          ? theme.colorScheme.onSurfaceVariant
                          : null,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    ];
  }
}

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({
    required this.profile,
    required this.logoUrl,
    required this.canEdit,
    required this.onEdit,
  });

  final MerchantProfile profile;
  final String? logoUrl;
  final bool canEdit;
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return ManfaaCard(
      color: dark
          ? ManfaaColors.violet.withValues(alpha: 0.10)
          : const Color(0xFFF4F1FE),
      padding: const EdgeInsets.all(Gap.lg),
      child: Row(
        children: [
          StoreLogoAvatar(name: profile.name, logoUrl: logoUrl, size: 60),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  profile.name,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    fontSize: 18,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                if ((profile.nameDv ?? '').isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    profile.nameDv!,
                    textDirection: TextDirection.rtl,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
                if (profile.status == 'active') ...[
                  const SizedBox(height: Gap.sm),
                  const VerifiedChip(),
                ],
              ],
            ),
          ),
          if (canEdit) ...[
            const SizedBox(width: Gap.sm),
            OutlinedButton.icon(
              onPressed: onEdit,
              icon: const Icon(Icons.edit_outlined, size: 16),
              style: OutlinedButton.styleFrom(
                foregroundColor: dark
                    ? const Color(0xFFB79CF6)
                    : ManfaaColors.violetDeep,
                backgroundColor: dark ? Colors.transparent : Colors.white,
                side: BorderSide(
                  color: dark ? ManfaaColors.violet : const Color(0xFFD9CEF9),
                ),
                minimumSize: const Size(0, 40),
                padding: const EdgeInsets.symmetric(horizontal: Gap.md),
              ),
              label: Text(l10n.edit),
            ),
          ],
        ],
      ),
    );
  }
}

class _RowDivider extends StatelessWidget {
  const _RowDivider();

  @override
  Widget build(BuildContext context) =>
      Divider(height: 1, color: Theme.of(context).colorScheme.outlineVariant);
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({
    required this.icon,
    required this.label,
    this.value,
    this.trailing,
    this.hint,
    this.muted = false,
    this.ltrValue = false,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final String? value;
  final Widget? trailing;
  final String? hint;
  final bool muted;

  /// Phone numbers, emails, URLs keep their Latin order inside dv.
  final bool ltrValue;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final row = Padding(
      padding: const EdgeInsets.symmetric(vertical: Gap.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              IconTile(icon, tint: ManfaaTint.violet, size: 36, iconSize: 18),
              const SizedBox(width: Gap.md),
              // The VALUE is the data — it keeps its one-line shape (up to
              // a safety cap); the label takes the remainder and wraps when
              // the two cannot share a 390 frame, which reads better than a
              // phone number broken across lines.
              Expanded(
                child: Text(
                  label,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              const SizedBox(width: Gap.sm),
              if (trailing != null) ...[
                trailing!,
                if (onTap != null)
                  Icon(
                    Icons.chevron_right_rounded,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
              ] else if (value != null)
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 190),
                  child: Text(
                    value!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.end,
                    textDirection: ltrValue ? TextDirection.ltr : null,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: muted ? theme.colorScheme.onSurfaceVariant : null,
                    ),
                  ),
                ),
            ],
          ),
          if (hint != null) ...[
            const SizedBox(height: Gap.xs),
            Text(
              hint!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: ManfaaColors.pendingAmber,
              ),
            ),
          ],
        ],
      ),
    );

    if (onTap == null) return row;
    return InkWell(onTap: onTap, child: row);
  }
}

class _WebsiteRow extends StatelessWidget {
  const _WebsiteRow({required this.url});

  final String? url;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final display = (url ?? '').replaceFirst(RegExp(r'^https?://'), '');

    if (url == null || url!.isEmpty) {
      return _DetailRow(
        icon: Icons.language_rounded,
        label: l10n.websiteLabel,
        value: l10n.notSet,
        muted: true,
      );
    }

    return _DetailRow(
      icon: Icons.language_rounded,
      label: l10n.websiteLabel,
      trailing: InkWell(
        borderRadius: BorderRadius.circular(Corner.tile),
        onTap: () {
          final target = url!.startsWith('http') ? url! : 'https://$url';
          final uri = Uri.tryParse(target);
          if (uri != null) {
            launchUrl(uri, mode: LaunchMode.externalApplication);
          }
        },
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 190),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            textDirection: TextDirection.ltr,
            children: [
              Flexible(
                child: Text(
                  display,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: theme.brightness == Brightness.dark
                        ? const Color(0xFFB79CF6)
                        : ManfaaColors.violetDeep,
                  ),
                ),
              ),
              const SizedBox(width: Gap.xs),
              Icon(
                Icons.open_in_new_rounded,
                size: 16,
                color: theme.brightness == Brightness.dark
                    ? const Color(0xFFB79CF6)
                    : ManfaaColors.violetDeep,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
