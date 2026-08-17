import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../../widgets/merchant_brand.dart';
import 'location_step.dart';
import 'logo_step.dart';
import 'profile_step.dart';
import 'rate_step.dart';
import 'review_step.dart';
import 'setup_common.dart';

/// The resumable store setup wizard (MR1) — all six web steps, in-app:
/// profile → location → logo → rate → terms → review & submit. Every step
/// persists SERVER-SIDE on Continue, so quitting mid-wizard resumes exactly
/// where the owner left off (GET /merchant/setup + firstIncompleteStep).
///
/// Wears the auth surfaces' language (there is no dedicated wizard mock):
/// wordmark header, one white card per step, ink CTA with arrow, violet
/// accents, the slim step-dot progress header.
class SetupScreen extends ConsumerStatefulWidget {
  const SetupScreen({super.key});

  @override
  ConsumerState<SetupScreen> createState() => _SetupScreenState();
}

class _SetupScreenState extends ConsumerState<SetupScreen> {
  MerchantSetupState? _state;
  Object? _error;
  var _step = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final state = await ref.read(apiProvider).getSetup();
      if (!mounted) return;
      setState(() {
        _state = state;
        _step = firstIncompleteStep(state);
      });
      await _syncStatus(state);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  /// The server's status is truth. If it moved while this screen was away
  /// (submitted on the web, rejected overnight), write it through the
  /// session so the ROUTER walks — no screen decides navigation on its own.
  Future<void> _syncStatus(MerchantSetupState state) async {
    final session = ref.read(sessionProvider);
    if (state.status == session.merchantStatus) return;

    await session.saveProfile(
      userName: session.userName ?? '',
      userEmail: session.userEmail ?? '',
      merchantId: session.merchantId,
      merchantName: session.merchantName,
      merchantSlug: session.merchantSlug,
      merchantStatus: state.status,
      permissions: session.permissions,
    );
  }

  /// A step saved: adopt the fresh server state and move on. The step index
  /// is clamped, never recomputed — free navigation after a save is the
  /// web's rule too (submit re-validates completeness regardless).
  void _saved(MerchantSetupState state, {int? to}) {
    setState(() {
      _state = state;
      _step = (to ?? _step + 1).clamp(0, kSetupSteps.length - 1);
    });
  }

  void _jump(int index) =>
      setState(() => _step = index.clamp(0, kSetupSteps.length - 1));

  Future<void> _submitted(MerchantSetupState state) async {
    setState(() => _state = state);
    // status is now pending_review — the session write bumps the revision
    // and the router walks to the waiting screen.
    await _syncStatus(state);
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(sessionTickProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;
    final theme = Theme.of(context);

    // A staff account whose role cannot even READ the setup: the wizard is
    // the owner's; say so instead of erroring on the first fetch.
    if (!session.can('setup.view')) {
      return _StaffNotice(onSignOut: () => ref.read(apiProvider).signOut());
    }

    final state = _state;

    return Scaffold(
      body: SafeArea(
        child: state == null
            ? _error != null
                ? _LoadError(error: _error!, onRetry: _load)
                : const _Loading()
            : ListView(
                padding: const EdgeInsets.fromLTRB(
                    Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
                children: [
                  Row(
                    children: [
                      const MerchantWordmark(),
                      const Spacer(),
                      IconButton(
                        tooltip: l10n.signOut,
                        onPressed: () => ref.read(apiProvider).signOut(),
                        icon: Icon(Icons.logout_rounded,
                            size: 20,
                            color: theme.colorScheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                  const SizedBox(height: Gap.lg),
                  Text(l10n.setupTitle,
                      style: theme.textTheme.headlineSmall),
                  const SizedBox(height: Gap.xs),
                  Text(
                    '${l10n.setupStepOf(_step + 1, kSetupSteps.length)}'
                    ' · ${_stepLabel(l10n, _step)}',
                    style: theme.textTheme.bodyMedium
                        ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  const SizedBox(height: Gap.lg),
                  _StepDots(current: _step, onTap: _jump),
                  if (state.status == 'rejected' &&
                      state.rejectedReason != null) ...[
                    const SizedBox(height: Gap.lg),
                    SetupNotice(
                      title: l10n.rejectedTitle,
                      icon: Icons.edit_note_rounded,
                      child: Text(
                        state.rejectedReason!,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: toneSurface(
                                  ToneSurface.pending, theme.brightness)
                              .foreground,
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: Gap.lg),
                  _buildStep(state),
                ],
              ),
      ),
    );
  }

  String _stepLabel(dynamic l10n, int index) => switch (kSetupSteps[index]) {
        'profile' => l10n.stepProfile,
        'location' => l10n.stepLocation,
        'logo' => l10n.stepLogo,
        'rate' => l10n.stepRate,
        'terms' => l10n.stepTerms,
        _ => l10n.stepReview,
      };

  Widget _buildStep(MerchantSetupState state) => switch (kSetupSteps[_step]) {
        'profile' => ProfileStep(state: state, onSaved: _saved),
        'location' => LocationStep(
            state: state,
            onSaved: _saved,
            onSkip: () => _jump(_step + 1),
            onBack: () => _jump(_step - 1),
          ),
        'logo' => LogoStep(
            state: state,
            // The logo uploads in place; the refreshed state must not
            // navigate — Continue/Skip does.
            onSaved: (fresh) => _saved(fresh, to: _step),
            onNext: () => _jump(_step + 1),
            onBack: () => _jump(_step - 1),
          ),
        'rate' => RateStep(
            state: state,
            onSaved: _saved,
            onNext: () => _jump(_step + 1),
            onBack: () => _jump(_step - 1),
          ),
        'terms' => _TermsStep(
            state: state,
            onSaved: _saved,
            onBack: () => _jump(_step - 1),
          ),
        _ => ReviewStep(
            state: state,
            onSubmitted: _submitted,
            onJump: _jump,
            onBack: () => _jump(_step - 1),
          ),
      };
}

/// The slim progress header: six dots — done wears the violet check, active
/// the ink fill, the rest stay quiet. Free navigation, like the web's.
class _StepDots extends StatelessWidget {
  const _StepDots({required this.current, required this.onTap});

  final int current;
  final void Function(int index) onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    return Row(
      children: [
        for (var i = 0; i < kSetupSteps.length; i++) ...[
          if (i > 0)
            Expanded(
              child: Container(
                height: 1.5,
                margin: const EdgeInsets.symmetric(horizontal: Gap.xs),
                color: i <= current
                    ? ManfaaColors.violet.withValues(alpha: 0.45)
                    : theme.colorScheme.outlineVariant,
              ),
            ),
          InkWell(
            customBorder: const CircleBorder(),
            onTap: () => onTap(i),
            child: Container(
              width: 28,
              height: 28,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: i == current
                    ? theme.colorScheme.primary
                    : i < current
                        ? (dark
                            ? ManfaaColors.violet.withValues(alpha: 0.25)
                            : ManfaaColors.violetSoft)
                        : theme.colorScheme.surfaceContainer,
              ),
              child: i < current
                  ? Icon(
                      Icons.check_rounded,
                      size: 15,
                      color: dark
                          ? const Color(0xFFB79CF6)
                          : ManfaaColors.violetDeep,
                    )
                  : Text(
                      '${i + 1}',
                      style: theme.textTheme.labelSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: i == current
                            ? theme.colorScheme.onPrimary
                            : theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
            ),
          ),
        ],
      ],
    );
  }
}

/// Step 5 — terms: what earns cashback, in the merchant's own words. The
/// §11 over-promise guard: customers see this text before they shop, and a
/// storefront advertising "5%" without "on what" is how OUR brand carries
/// the disappointment. Required non-empty before submit.
class _TermsStep extends ConsumerStatefulWidget {
  const _TermsStep({
    required this.state,
    required this.onSaved,
    required this.onBack,
  });

  final MerchantSetupState state;
  final void Function(MerchantSetupState state) onSaved;
  final VoidCallback onBack;

  @override
  ConsumerState<_TermsStep> createState() => _TermsStepState();
}

class _TermsStepState extends ConsumerState<_TermsStep> {
  late final _terms = TextEditingController(
      text: widget.state.values.eligibilityBasis ?? '');
  var _busy = false;
  var _requiredError = false;

  @override
  void dispose() {
    _terms.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final text = _terms.text.trim();
    if (text.isEmpty) {
      setState(() => _requiredError = true);
      return;
    }

    setState(() => _busy = true);
    try {
      final fresh = await ref.read(apiProvider).saveSetupTerms(text);
      if (mounted) widget.onSaved(fresh);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(describeSetupError(context, e))));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return SetupStepCard(
      title: l10n.termsTitle,
      actionLabel: l10n.continueLabel,
      onAction: _save,
      onBack: widget.onBack,
      busy: _busy,
      children: [
        Text(l10n.termsLabel, style: theme.textTheme.labelLarge),
        const SizedBox(height: Gap.sm),
        TextField(
          controller: _terms,
          maxLines: 5,
          maxLength: 2000,
          onChanged: (_) {
            if (_requiredError) setState(() => _requiredError = false);
          },
          decoration: InputDecoration(
            hintText: l10n.termsPlaceholder,
            counterText: '',
          ),
        ),
        const SizedBox(height: Gap.sm),
        Text(
          _requiredError ? l10n.termsRequired : l10n.termsGuidance,
          style: theme.textTheme.bodySmall?.copyWith(
            color: _requiredError
                ? theme.colorScheme.error
                : theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}

class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) => ListView(
        padding:
            const EdgeInsets.fromLTRB(Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
        children: const [
          MerchantWordmark(),
          SizedBox(height: Gap.xl),
          SkeletonBox(height: 28, radius: Corner.tile),
          SizedBox(height: Gap.lg),
          SkeletonBox(height: 220, radius: Corner.card),
          SizedBox(height: Gap.md),
          SkeletonBox(height: 120, radius: Corner.card),
        ],
      );
}

class _LoadError extends StatelessWidget {
  const _LoadError({required this.error, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Gap.huge),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              error is MobileApiException
                  ? (error as MobileApiException).message
                  : l10n.errorGeneric,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: Gap.lg),
            OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
          ],
        ),
      ),
    );
  }
}

/// The staff-without-`setup.view` notice: the wizard belongs to the owner;
/// this account can only wait — or leave.
class _StaffNotice extends StatelessWidget {
  const _StaffNotice({required this.onSignOut});

  final VoidCallback onSignOut;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding:
              const EdgeInsets.fromLTRB(Gap.xxl, Gap.lg, Gap.xxl, Gap.xxl),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Align(
                alignment: AlignmentDirectional.centerStart,
                child: MerchantWordmark(),
              ),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const IconTile(Icons.lock_person_rounded,
                        tint: ManfaaTint.violet, size: 72, iconSize: 34),
                    const SizedBox(height: Gap.xxl),
                    Text(l10n.staffSetupTitle,
                        style: theme.textTheme.headlineSmall,
                        textAlign: TextAlign.center),
                    const SizedBox(height: Gap.md),
                    Text(
                      l10n.staffSetupBody,
                      style: theme.textTheme.bodyLarge?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
              OutlinedButton(
                  onPressed: onSignOut, child: Text(l10n.signOut)),
            ],
          ),
        ),
      ),
    );
  }
}
