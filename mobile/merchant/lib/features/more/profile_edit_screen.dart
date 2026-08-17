import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/adaptive.dart';
import '../setup/setup_common.dart';
import 'more_providers.dart';
import 'more_widgets.dart';

/// Edit the store profile — the same validation conventions as the setup
/// wizard's profile step, because they save the same fields:
///
///  - "same as contact" is a COMPARISON, and ticked it sends the CONTACT
///    number itself as `support_phone` (the field is always materialised
///    server-side; owner convention shipped 2026-08-17);
///  - the category comes from the SERVED curated list; a retired slug the
///    store still holds stays pickable (the server accepts it unchanged)
///    and travels only when actually changed;
///  - the logo replace pre-flights the SetupController rules (jpg/png/webp,
///    ≤2 MB) and uploads immediately through the MR1 setup endpoint — open
///    to active stores by design.
class ProfileEditScreen extends ConsumerStatefulWidget {
  const ProfileEditScreen({super.key, required this.profile, this.setup});

  final MerchantProfile profile;

  /// The curated category list + current logo. Null for an account without
  /// `setup.view` — the form then offers only the held category.
  final MerchantSetupState? setup;

  @override
  ConsumerState<ProfileEditScreen> createState() => _ProfileEditScreenState();
}

class _ProfileEditScreenState extends ConsumerState<ProfileEditScreen> {
  static const _maxLogoBytes = 2 * 1024 * 1024;
  static const _okExtensions = {'jpg', 'jpeg', 'png', 'webp'};

  late final _name = TextEditingController(text: widget.profile.name);
  late final _nameDv = TextEditingController(text: widget.profile.nameDv ?? '');
  late String? _category = widget.profile.category;
  late String _channel = widget.profile.channel;
  late final _contactEmail = TextEditingController(
    text: widget.profile.contactEmail ?? '',
  );
  late final _contactPhone = TextEditingController(
    text: widget.profile.contactPhone ?? '',
  );
  late final _supportPhone = TextEditingController(
    text: widget.profile.supportPhone ?? '',
  );
  late final _website = TextEditingController(
    text: widget.profile.websiteUrl ?? '',
  );
  late final _terms = TextEditingController(
    text: widget.profile.eligibilityBasis ?? '',
  );

  /// Derived by COMPARISON — the tick means the two fields hold the same
  /// number (or support is blank), exactly the web checkbox's rule.
  late bool _supportSame =
      (widget.profile.supportPhone ?? '').isEmpty ||
      widget.profile.supportPhone == widget.profile.contactPhone;

  late String? _logoUrl = widget.setup?.values.logoUrl;
  Uint8List? _logoPreview;
  var _uploadingLogo = false;
  String? _logoError;
  var _busy = false;
  String? _nameError;

  @override
  void dispose() {
    _name.dispose();
    _nameDv.dispose();
    _contactEmail.dispose();
    _contactPhone.dispose();
    _supportPhone.dispose();
    _website.dispose();
    _terms.dispose();
    super.dispose();
  }

  String? _clean(TextEditingController controller) {
    final text = controller.text.trim();
    return text.isEmpty ? null : text;
  }

  Future<void> _pickLogo() async {
    final l10n = context.l10n;

    final picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      maxWidth: 1024,
      maxHeight: 1024,
      imageQuality: 85,
    );
    if (picked == null || !mounted) return;

    final extension = picked.name.split('.').last.toLowerCase();
    if (!_okExtensions.contains(extension)) {
      setState(() => _logoError = l10n.errLogoType);
      return;
    }

    final bytes = await picked.readAsBytes();
    if (!mounted) return;
    if (bytes.length > _maxLogoBytes) {
      setState(() => _logoError = l10n.errLogoSize);
      return;
    }

    setState(() {
      _logoError = null;
      _logoPreview = bytes;
      _uploadingLogo = true;
    });

    try {
      final url = await ref
          .read(apiProvider)
          .uploadSetupLogo(bytes: bytes, filename: picked.name);
      if (!mounted) return;
      setState(() => _logoUrl = url ?? _logoUrl);
      // The logo is already live server-side — the read models must agree.
      ref.invalidate(settingsSetupProvider);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.logoUploaded)));
    } catch (e) {
      if (mounted) {
        setState(() {
          _logoPreview = null;
          _logoError = e is MobileApiException
              ? (e.fieldMessages('logo').firstOrNull ?? e.message)
              : l10n.errorGeneric;
        });
      }
    } finally {
      if (mounted) setState(() => _uploadingLogo = false);
    }
  }

  Future<void> _save() async {
    final l10n = context.l10n;
    final name = _name.text.trim();
    if (name.length < 2) {
      setState(() => _nameError = l10n.storeNameRequired);
      return;
    }

    setState(() => _busy = true);
    try {
      final contactPhone = _clean(_contactPhone);
      await ref
          .read(apiProvider)
          .updateProfile(
            name: name,
            nameDv: _clean(_nameDv),
            channel: _channel,
            eligibilityBasis: _clean(_terms),
            contactEmail: _clean(_contactEmail),
            contactPhone: contactPhone,
            // Ticked, the CONTACT number itself travels — the support field
            // is always materialised so the storefront always has a number.
            supportPhone: _supportSame
                ? contactPhone
                : (_clean(_supportPhone) ?? contactPhone),
            websiteUrl: _clean(_website),
            categoryChanged: _category != widget.profile.category,
            category: _category,
          );
      if (!mounted) return;
      // Re-read the truth for the view screen underneath.
      ref.invalidate(profileProvider);
      ref.invalidate(settingsSetupProvider);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.profileSaved)));
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              e is MobileApiException ? e.message : l10n.profileSaveFailed,
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final muted = theme.colorScheme.onSurfaceVariant;

    // The picker offers the served curated list, plus the category the
    // store already holds when the list no longer carries it (retired) —
    // shown so the form reflects reality, exactly the web's behaviour.
    final served = widget.setup?.categories ?? const <SetupCategory>[];
    final extraSlug =
        _category != null &&
            _category!.isNotEmpty &&
            !served.any((c) => c.slug == _category)
        ? _category
        : null;
    final retiredHeld =
        widget.profile.categoryRetired && _category == widget.profile.category;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        // MR7: a form is a single column at any size — the shared ~640dp
        // rail centers it on tablets; phones render exactly as shipped.
        child: ContentRail(
          child: ListView(
            // bottomClearanceOf = navClearance under the bar, not xxl: this
            // page is pushed on the BRANCH navigator, so the shell's floating
            // nav bar (extendBody) still overlays it — without the clearance
            // the Save button scrolls to rest underneath the bar, exactly the
            // layering trap the estate's sheets already dodge via the root
            // navigator. Under the rail shell there is no bar to clear.
            padding: EdgeInsets.fromLTRB(
              Gap.xl,
              Gap.sm,
              Gap.xl,
              bottomClearanceOf(context),
            ),
            children: [
              Row(
                children: [
                  IconButton(
                    icon: const Icon(Icons.arrow_back_rounded),
                    onPressed: _busy ? null : () => Navigator.of(context).pop(),
                  ),
                  const SizedBox(width: Gap.xs),
                  Expanded(
                    child: Text(
                      l10n.editProfileTitle,
                      style: theme.textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Gap.md),
              // ---- logo -----------------------------------------------------
              ManfaaCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.storeLogoLabel,
                      style: theme.textTheme.titleMedium,
                    ),
                    const SizedBox(height: Gap.md),
                    Row(
                      children: [
                        _logoPreview != null
                            ? ClipOval(
                                child: SizedBox(
                                  width: 72,
                                  height: 72,
                                  child: Image.memory(
                                    _logoPreview!,
                                    fit: BoxFit.cover,
                                  ),
                                ),
                              )
                            : StoreLogoAvatar(
                                name: widget.profile.name,
                                logoUrl: _logoUrl,
                                size: 72,
                              ),
                        const SizedBox(width: Gap.lg),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              OutlinedButton.icon(
                                onPressed: _uploadingLogo || _busy
                                    ? null
                                    : _pickLogo,
                                icon: const Icon(
                                  Icons.photo_library_rounded,
                                  size: 18,
                                ),
                                label: Text(
                                  _logoUrl != null || _logoPreview != null
                                      ? l10n.replaceLogo
                                      : l10n.chooseLogo,
                                ),
                              ),
                              const SizedBox(height: Gap.sm),
                              if (_uploadingLogo)
                                Row(
                                  children: [
                                    const SizedBox(
                                      width: 14,
                                      height: 14,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    ),
                                    const SizedBox(width: Gap.sm),
                                    Text(
                                      l10n.logoUploading,
                                      style: theme.textTheme.bodySmall
                                          ?.copyWith(color: muted),
                                    ),
                                  ],
                                )
                              else
                                Text(
                                  l10n.logoHint,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: muted,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    if (_logoError != null) ...[
                      const SizedBox(height: Gap.sm),
                      Text(
                        _logoError!,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.error,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: Gap.md),
              // ---- identity + contacts -------------------------------------
              ManfaaCard(
                padding: const EdgeInsets.all(Gap.xl),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.storeNameLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                    const SizedBox(height: Gap.sm),
                    TextField(
                      controller: _name,
                      onChanged: (_) => setState(() => _nameError = null),
                      decoration: InputDecoration(
                        prefixIcon: const Icon(Icons.storefront_outlined),
                        errorText: _nameError,
                      ),
                    ),
                    const SizedBox(height: Gap.xs),
                    Text(
                      l10n.storeNameHint,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                    const SizedBox(height: Gap.lg),
                    Text(
                      l10n.storeNameDvLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                    const SizedBox(height: Gap.sm),
                    TextField(
                      controller: _nameDv,
                      textDirection: TextDirection.rtl,
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.translate_rounded),
                      ),
                    ),
                    const SizedBox(height: Gap.xl),
                    Text(l10n.categoryLabel, style: theme.textTheme.labelLarge),
                    const SizedBox(height: Gap.xs),
                    Text(
                      retiredHeld
                          ? l10n.categoryRetiredHint
                          : l10n.categoryHint,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: retiredHeld ? ManfaaColors.pendingAmber : muted,
                      ),
                    ),
                    const SizedBox(height: Gap.md),
                    Wrap(
                      spacing: Gap.sm,
                      runSpacing: Gap.sm,
                      children: [
                        if (extraSlug != null)
                          SelectChip(
                            label: extraSlug,
                            selected: _category == extraSlug,
                            onTap: () => setState(() => _category = extraSlug),
                          ),
                        for (final option in served)
                          SelectChip(
                            label: option.label(dhivehi: dhivehi),
                            selected: option.slug == _category,
                            onTap: () =>
                                setState(() => _category = option.slug),
                          ),
                      ],
                    ),
                    const SizedBox(height: Gap.xl),
                    Text(l10n.channelLabel, style: theme.textTheme.labelLarge),
                    const SizedBox(height: Gap.md),
                    for (final (channel, label, hint) in [
                      (
                        'in_store',
                        l10n.channelInStore,
                        l10n.channelInStoreHint,
                      ),
                      ('online', l10n.channelOnline, l10n.channelOnlineHint),
                      ('both', l10n.channelBoth, l10n.channelBothHint),
                    ]) ...[
                      SelectRow(
                        label: label,
                        hint: hint,
                        selected: _channel == channel,
                        onTap: () => setState(() => _channel = channel),
                      ),
                      const SizedBox(height: Gap.sm),
                    ],
                    const SizedBox(height: Gap.md),
                    Text(
                      l10n.contactEmailLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                    const SizedBox(height: Gap.sm),
                    TextField(
                      controller: _contactEmail,
                      keyboardType: TextInputType.emailAddress,
                      autocorrect: false,
                      textDirection: TextDirection.ltr,
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.mail_outline_rounded),
                      ),
                    ),
                    const SizedBox(height: Gap.lg),
                    Text(
                      l10n.contactPhoneLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                    const SizedBox(height: Gap.sm),
                    TextField(
                      controller: _contactPhone,
                      keyboardType: TextInputType.phone,
                      textDirection: TextDirection.ltr,
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.call_outlined),
                      ),
                    ),
                    const SizedBox(height: Gap.lg),
                    Text(
                      l10n.supportPhoneLabel,
                      style: theme.textTheme.labelLarge,
                    ),
                    const SizedBox(height: Gap.xs),
                    InkWell(
                      borderRadius: BorderRadius.circular(Corner.tile),
                      onTap: () => setState(() => _supportSame = !_supportSame),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: Gap.xs),
                        child: Row(
                          children: [
                            SizedBox(
                              width: 24,
                              height: 24,
                              child: Checkbox(
                                value: _supportSame,
                                activeColor: ManfaaColors.violet,
                                onChanged: (checked) => setState(
                                  () => _supportSame = checked ?? true,
                                ),
                              ),
                            ),
                            const SizedBox(width: Gap.sm),
                            Expanded(
                              child: Text(
                                l10n.supportSameAsContact,
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  color: muted,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    if (!_supportSame) ...[
                      const SizedBox(height: Gap.sm),
                      TextField(
                        controller: _supportPhone,
                        keyboardType: TextInputType.phone,
                        textDirection: TextDirection.ltr,
                        decoration: const InputDecoration(
                          prefixIcon: Icon(Icons.support_agent_rounded),
                        ),
                      ),
                    ],
                    const SizedBox(height: Gap.lg),
                    Text(l10n.websiteLabel, style: theme.textTheme.labelLarge),
                    const SizedBox(height: Gap.sm),
                    TextField(
                      controller: _website,
                      keyboardType: TextInputType.url,
                      autocorrect: false,
                      textDirection: TextDirection.ltr,
                      decoration: const InputDecoration(
                        hintText: 'teaplus.mv',
                        prefixIcon: Icon(Icons.language_rounded),
                      ),
                    ),
                    const SizedBox(height: Gap.xl),
                    Text(l10n.termsTitle, style: theme.textTheme.labelLarge),
                    const SizedBox(height: Gap.sm),
                    TextField(
                      controller: _terms,
                      maxLines: 4,
                      decoration: InputDecoration(
                        hintText: l10n.termsPlaceholder,
                      ),
                    ),
                    const SizedBox(height: Gap.xs),
                    Text(
                      l10n.termsGuidance,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: Gap.lg),
              FilledButton(
                onPressed: _busy || _uploadingLogo ? null : _save,
                child: _busy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(l10n.saveProfile),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
