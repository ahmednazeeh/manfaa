import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';

/// Correcting the Thaana name the server wrote at registration.
///
/// The name is transliterated by a model from the English one, which is a
/// good guess and no more — an unusual spelling, a family that writes it
/// differently. This screen is how its owner has the last word, and once they
/// save, nothing overwrites it.
class DhivehiNameScreen extends ConsumerStatefulWidget {
  const DhivehiNameScreen({super.key});

  @override
  ConsumerState<DhivehiNameScreen> createState() => _DhivehiNameScreenState();
}

class _DhivehiNameScreenState extends ConsumerState<DhivehiNameScreen> {
  late final TextEditingController _controller = TextEditingController(
    text: ref.read(sessionProvider).customerNameDv ?? '',
  );
  bool _busy = false;
  String? _error;

  /// Thaana is U+0780–U+07BF, plus ﷲ (U+FDF2) — the conventional spelling of
  /// Abdulla, އަބްދުﷲ. Spaces separate the parts of a name.
  ///
  /// Checked here so a mistake is caught before the round trip, and again on
  /// the server, which is the side that actually decides. The two patterns
  /// must stay in step, or the field will refuse a name the API would accept.
  static final _thaana = RegExp(r'^[ހ-޿ﷲ\s]+$');

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _save({required bool clear}) async {
    final l10n = context.l10n;
    final typed = _controller.text.trim();

    if (!clear && typed.isNotEmpty && !_thaana.hasMatch(typed)) {
      setState(() => _error = l10n.dhivehiNameInvalid);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final me = await ref
          .read(apiProvider)
          .setDhivehiName(clear || typed.isEmpty ? null : typed);

      await ref.read(sessionProvider).setCustomerNameDv(me.nameDv);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(l10n.dhivehiNameSaved)),
      );
      context.pop();
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = l10n.dhivehiNameInvalid;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.dhivehiNameTitle)),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.lg, Gap.lg, Gap.lg),
          children: [
            Text(
              l10n.dhivehiNameHint,
              style: theme.textTheme.bodyMedium?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.lg),
            Text(
              l10n.dhivehiNameLabel,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _controller,
              enabled: !_busy,
              maxLength: 120,
              // The field itself is Thaana whatever the app's language is:
              // an English reader correcting their own name still types it in
              // Thaana, and an LTR field would put the cursor in the wrong
              // place for every keystroke.
              textDirection: TextDirection.rtl,
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[ހ-޿ﷲ\s]')),
              ],
              onChanged: (_) => setState(() => _error = null),
              decoration: InputDecoration(
                counterText: '',
                errorText: _error,
              ),
            ),
            const SizedBox(height: Gap.lg),
            FilledButton(
              onPressed: _busy ? null : () => _save(clear: false),
              child: Text(l10n.dhivehiNameSave),
            ),
            const SizedBox(height: Gap.sm),
            TextButton(
              onPressed: _busy ? null : () => _save(clear: true),
              child: Text(l10n.dhivehiNameClear),
            ),
          ],
        ),
      ),
    );
  }
}
