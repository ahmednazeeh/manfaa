import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'setup_common.dart';

/// Step 3 — the logo, OPTIONAL end to end (never a submit requirement).
///
/// The OS photo picker (no runtime permission on modern Android/iOS),
/// downscaled client-side exactly like the customer avatar flow — a 12MP
/// photo is wasted bytes for a storefront tile, and the server caps the
/// upload at 2 MB. Chosen means UPLOADED immediately (web parity): a second
/// "now really save" button only creates the state where the picture on
/// screen is not the picture on the server.
class LogoStep extends ConsumerStatefulWidget {
  const LogoStep({
    super.key,
    required this.state,
    required this.onSaved,
    required this.onNext,
    required this.onBack,
  });

  final MerchantSetupState state;

  /// Fresh state after an upload landed (refetched so logo_url is truth).
  final void Function(MerchantSetupState state) onSaved;
  final VoidCallback onNext;
  final VoidCallback onBack;

  @override
  ConsumerState<LogoStep> createState() => _LogoStepState();
}

class _LogoStepState extends ConsumerState<LogoStep> {
  static const _maxBytes = 2 * 1024 * 1024;
  static const _okExtensions = {'jpg', 'jpeg', 'png', 'webp'};

  Uint8List? _preview;
  var _uploading = false;
  String? _error;

  bool get _hasLogo =>
      _preview != null || widget.state.values.logoUrl != null;

  Future<void> _pick() async {
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
      setState(() => _error = l10n.errLogoType);
      return;
    }

    final bytes = await picked.readAsBytes();
    if (!mounted) return;
    if (bytes.length > _maxBytes) {
      setState(() => _error = l10n.errLogoSize);
      return;
    }

    setState(() {
      _error = null;
      _preview = bytes;
      _uploading = true;
    });

    try {
      final api = ref.read(apiProvider);
      await api.uploadSetupLogo(bytes: bytes, filename: picked.name);
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(l10n.logoUploaded)));
      // Refetch so the state carries the new logo_url — one source of truth
      // for the review step. Best-effort: the local preview stands in if
      // this read fails, and the upload itself has already landed.
      try {
        final fresh = await api.getSetup();
        if (mounted) widget.onSaved(fresh);
      } catch (_) {
        // Intentional — see above.
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _preview = null;
          _error = e is MobileApiException
              ? (e.fieldMessages('logo').firstOrNull ??
                  describeSetupError(context, e))
              : describeSetupError(context, e);
        });
      }
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final currentUrl = widget.state.values.logoUrl;

    return SetupStepCard(
      title: l10n.logoTitle,
      subtitle: l10n.logoSubtitle,
      actionLabel: _hasLogo ? l10n.continueLabel : l10n.skip,
      onAction: _uploading ? null : widget.onNext,
      onBack: widget.onBack,
      children: [
        Row(
          children: [
            // The preview circle — picked bytes first (they are what just
            // went up), the served URL for a returning owner, a quiet
            // placeholder otherwise.
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: theme.colorScheme.surfaceContainerLow,
                border: Border.all(color: theme.colorScheme.outlineVariant),
              ),
              clipBehavior: Clip.antiAlias,
              child: _preview != null
                  ? Image.memory(_preview!, fit: BoxFit.cover)
                  : currentUrl != null
                      ? Image.network(
                          currentUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) => Icon(
                            Icons.image_outlined,
                            size: 34,
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        )
                      : Icon(
                          Icons.image_outlined,
                          size: 34,
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
            ),
            const SizedBox(width: Gap.xl),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  OutlinedButton.icon(
                    onPressed: _uploading ? null : _pick,
                    icon: const Icon(Icons.photo_library_rounded, size: 18),
                    label: Text(_hasLogo ? l10n.replaceLogo : l10n.chooseLogo),
                  ),
                  const SizedBox(height: Gap.sm),
                  if (_uploading)
                    Row(
                      children: [
                        const SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                        const SizedBox(width: Gap.sm),
                        Text(
                          l10n.logoUploading,
                          style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant),
                        ),
                      ],
                    )
                  else
                    Text(
                      l10n.logoHint,
                      style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant),
                    ),
                ],
              ),
            ),
          ],
        ),
        if (_error != null) ...[
          const SizedBox(height: Gap.md),
          Text(
            _error!,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.error),
          ),
        ],
      ],
    );
  }
}
