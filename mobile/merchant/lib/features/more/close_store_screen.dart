import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../widgets/adaptive.dart';
import '../../widgets/merchant_brand.dart';
import '../settlements/settlement_widgets.dart' show ToneBanner;

/// Close store (MR8) — the IN-APP ride on the shipped public
/// /api/merchant/account-closure flow, the same rules as the
/// merchant.manfaa.app/account-deletion page:
///
///   contact-number OTP → the stores on that number with the server's own
///   settle-first verdicts → the irreversible confirm → a signed-out end.
///
/// Deliberately phone-OTP, not session auth: possession of the store's
/// CONTACT number is the credential the server accepts, so the flow works
/// exactly like the web page and needs no permission slug. A store owing
/// money cannot close — the server refuses at confirm too; settling stays
/// open, closing waits.
class CloseStoreScreen extends ConsumerStatefulWidget {
  const CloseStoreScreen({super.key});

  @override
  ConsumerState<CloseStoreScreen> createState() => _CloseStoreScreenState();
}

enum _Step { phone, code, stores, done }

class _CloseStoreScreenState extends ConsumerState<CloseStoreScreen> {
  var _step = _Step.phone;
  final _phone = TextEditingController();
  final _code = TextEditingController();

  ClosureVerification? _verification;

  /// Stores already closed in THIS sitting (the token lives 15 minutes and
  /// can close several) — drawn as gone without re-verifying.
  final _closedIds = <int>{};

  /// The just-closed store's name for the done step.
  var _closedName = '';

  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  /// The refusal codes this flow knows, as sentences (web parity — the
  /// closure-flow page's exact wording family). Anything unknown keeps the
  /// server's prose when there is prose, else the generic sentence.
  String _describe(AppLocalizations l10n, MobileApiException e) =>
      switch (e.code) {
        'otp_invalid' => l10n.errOtpInvalid,
        'otp_attempts_exceeded' => l10n.errOtpAttempts,
        'no_store' => l10n.closeStoreNoStore,
        'phone_invalid' => l10n.closeStorePhoneInvalid,
        'outstanding_balance' => l10n.closeStoreOutstandingRefusal,
        'closure_token_invalid' => l10n.closeStoreExpired,
        ApiCode.rateLimited => l10n.errTooManyTries,
        _ => e.message.isNotEmpty ? e.message : l10n.errorGeneric,
      };

  Future<void> _act(Future<void> Function() body) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await body();
    } on MobileApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _describe(context.l10n, e);
        // An expired confirmation can only start over.
        if (e.code == 'closure_token_invalid') {
          _step = _Step.phone;
          _verification = null;
          _code.clear();
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendCode() => _act(() async {
        await ref.read(apiProvider).requestClosureOtp(_phone.text.trim());
        if (mounted) {
          setState(() {
            _step = _Step.code;
            _code.clear();
          });
        }
      });

  Future<void> _verify() => _act(() async {
        final verification = await ref.read(apiProvider).verifyClosureOtp(
              phone: _phone.text.trim(),
              code: _code.text.trim(),
            );
        if (mounted) {
          setState(() {
            _verification = verification;
            _step = _Step.stores;
          });
        }
      });

  Future<void> _confirmClose(ClosureStore store) async {
    final l10n = context.l10n;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(l10n.closeStoreConfirmTitle(store.name)),
        content: Text(l10n.closeStoreConfirmBody),
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
            child: Text(l10n.closeStoreConfirmCta),
          ),
        ],
      ),
    );
    if (!(confirmed ?? false) || !mounted) return;

    await _act(() async {
      await ref.read(apiProvider).confirmClosure(
            closureToken: _verification!.closureToken,
            merchantId: store.id,
          );
      if (!mounted) return;
      setState(() {
        _closedIds.add(store.id);
        _closedName = store.name;
        _step = _Step.done;
      });
    });
  }

  /// The signed-out ending. The server already shut every staff door and
  /// deleted the tokens; the local wipe is what walks this device out (the
  /// router's revision listener lands it on /login).
  Future<void> _finish() async {
    final session = ref.read(sessionProvider);
    final closedOwnStore = _closedIds.contains(session.merchantId);
    if (closedOwnStore) {
      await ref.read(apiProvider).signOut();
      return; // the redirect owns navigation from here
    }
    if (mounted && Navigator.of(context).canPop()) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final session = ref.watch(sessionProvider);

    final name = session.merchantName ?? session.userName ?? 'M';
    final initials = name.isEmpty ? 'M' : name.characters.first.toUpperCase();

    return Scaffold(
      body: SafeArea(
        bottom: false,
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
                l10n.closeStoreTitle,
                style: theme.textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: Gap.xs),
              Text(
                l10n.closeStoreSubtitle,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: Gap.lg),
              ...switch (_step) {
                _Step.phone => _phoneStep(l10n, theme),
                _Step.code => _codeStep(l10n, theme),
                _Step.stores => _storesStep(l10n, theme),
                _Step.done => _doneStep(l10n, theme),
              },
              if (_error != null) ...[
                const SizedBox(height: Gap.md),
                ToneBanner(
                  tone: ToneSurface.attention,
                  icon: Icons.error_outline_rounded,
                  title: _error!,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _cta(String label, {VoidCallback? onPressed, bool danger = false}) =>
      FilledButton(
        onPressed: _busy ? null : onPressed,
        style: danger
            ? FilledButton.styleFrom(
                backgroundColor: ManfaaColors.coralDeep,
                foregroundColor: Colors.white,
              )
            : null,
        child: _busy
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Text(label),
      );

  List<Widget> _phoneStep(AppLocalizations l10n, ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    return [
      ToneBanner(
        tone: ToneSurface.attention,
        icon: Icons.warning_amber_rounded,
        title: l10n.closeStoreIrreversibleTitle,
        body: l10n.closeStoreIrreversibleBody,
      ),
      const SizedBox(height: Gap.md),
      ManfaaCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(l10n.closeStorePhoneLabel, style: theme.textTheme.labelLarge),
            const SizedBox(height: Gap.sm),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              textDirection: TextDirection.ltr,
              onChanged: (_) => setState(() => _error = null),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.phone_outlined),
                hintText: '+960 7XXXXXX',
              ),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.closeStorePhoneHint,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
            const SizedBox(height: Gap.lg),
            _cta(
              l10n.closeStoreSendCode,
              onPressed:
                  _phone.text.trim().isEmpty ? null : () => _sendCode(),
            ),
          ],
        ),
      ),
    ];
  }

  List<Widget> _codeStep(AppLocalizations l10n, ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    return [
      ManfaaCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              l10n.codeSentTo(_phone.text.trim()),
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: Gap.lg),
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              textDirection: TextDirection.ltr,
              maxLength: 6,
              onChanged: (_) => setState(() => _error = null),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.password_rounded),
                counterText: '',
                hintText: '000000',
              ),
            ),
            const SizedBox(height: Gap.lg),
            _cta(
              l10n.closeStoreVerify,
              onPressed:
                  _code.text.trim().length == 6 ? () => _verify() : null,
            ),
            const SizedBox(height: Gap.sm),
            TextButton(
              onPressed: _busy
                  ? null
                  : () => setState(() {
                        _step = _Step.phone;
                        _error = null;
                      }),
              child: Text(
                l10n.resendCode,
                style: theme.textTheme.labelLarge?.copyWith(color: muted),
              ),
            ),
          ],
        ),
      ),
    ];
  }

  List<Widget> _storesStep(AppLocalizations l10n, ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final stores = [
      for (final store in _verification?.stores ?? const <ClosureStore>[])
        if (!_closedIds.contains(store.id)) store,
    ];

    return [
      Text(
        l10n.closeStoreStoresLead,
        style: theme.textTheme.titleMedium,
      ),
      const SizedBox(height: Gap.md),
      for (final store in stores) ...[
        ManfaaCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  IconTile(
                    Icons.storefront_outlined,
                    tint: store.canClose ? ManfaaTint.coral : ManfaaTint.amber,
                    size: 44,
                    iconSize: 22,
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          store.name,
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          // The server's own verdict, as a sentence: settled
                          // stores may close; an owing store settles FIRST.
                          store.canClose
                              ? l10n.closeStoreSettled
                              : l10n.closeStoreOwes(
                                  formatMoney(
                                    store.outstandingLaari,
                                    dhivehi: dhivehi,
                                  ),
                                ),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: store.canClose
                                ? muted
                                : theme.colorScheme.error,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Gap.md),
              _cta(
                l10n.closeStoreCta,
                danger: true,
                onPressed:
                    store.canClose ? () => _confirmClose(store) : null,
              ),
            ],
          ),
        ),
        const SizedBox(height: Gap.md),
      ],
    ];
  }

  List<Widget> _doneStep(AppLocalizations l10n, ThemeData theme) {
    final session = ref.read(sessionProvider);
    final closedOwnStore = _closedIds.contains(session.merchantId);

    return [
      ManfaaCard(
        child: Column(
          children: [
            const SizedBox(height: Gap.md),
            const IconTile(
              Icons.check_circle_outline_rounded,
              tint: ManfaaTint.green,
              size: 56,
              iconSize: 26,
            ),
            const SizedBox(height: Gap.md),
            Text(
              l10n.closeStoreClosed(_closedName),
              textAlign: TextAlign.center,
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: Gap.xs),
            Text(
              closedOwnStore
                  ? l10n.closeStoreSignedOutNote
                  : l10n.closeStoreOtherStoreNote,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: Gap.lg),
            SizedBox(
              width: double.infinity,
              child: _cta(l10n.doneLabel, onPressed: () => _finish()),
            ),
            const SizedBox(height: Gap.sm),
          ],
        ),
      ),
    ];
  }
}
