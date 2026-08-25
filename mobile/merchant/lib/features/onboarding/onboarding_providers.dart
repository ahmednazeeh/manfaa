import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../../app/providers.dart';

/// What the signup form must know BEFORE it is submitted (owner,
/// 2026-08-25): the validation-window range the platform allows TODAY, with
/// its label, its instructional sentence and its exact refusal, in both
/// languages and with the live numbers already interpolated.
///
/// PUBLIC — read before anyone is signed in. autoDispose, because it is
/// wanted for exactly as long as the signup screen is on screen, and the
/// answer is admin policy that can move between one signup and the next.
///
/// A FAILED read is not an error the form shows. `ValidationWindowOption`
/// answers `ready: false`, the field is not drawn, and register omits
/// `validation_window_days` — which is byte-identical to how signup behaved
/// before the field existed. There is no fallback ceiling anywhere in this
/// app on purpose: a hard-coded 3 would keep offering 3 on the afternoon an
/// admin lowered the platform to 1, and every merchant who took it would be
/// refused by a rule they were never shown.
final signupOptionsProvider = FutureProvider.autoDispose<MerchantSignupOptions>(
  (ref) => ref.watch(apiProvider).signupOptions(),
);

/// WHO is signed in, as one comparable string.
///
/// It changes when the ACCOUNT changes and at no other time — a fresh
/// /merchant/me that only narrows a role bumps the session revision but
/// leaves this identical, so anything keyed on it does not refetch. The
/// guide is keyed on it because the five days belong to a PERSON: a second
/// merchant signing in on the same phone must not inherit the first one's
/// tasklist, and must certainly not inherit their "this is over, never ask
/// again".
final merchantAccountProvider = Provider<String>((ref) {
  ref.watch(sessionTickProvider);
  final session = ref.watch(sessionProvider);

  return '${session.merchantId ?? 0}:${session.userEmail ?? ''}';
});

/// The guided setup (owner, 2026-08-25) as the till reads it.
///
/// ONE server answer decides everything: `GET /merchant/onboarding` says
/// whether to draw anything at all, which of five real-state tasks are done,
/// and how many of the person's five days are left. There is NO local
/// "dismissed" flag beside it, deliberately — skipping is stored server-side
/// and shared with the website, so putting the guide away on the phone must
/// put it away there too, and a local flag would immediately disagree with
/// the one that matters.
///
/// The read is per PERSON, not per store: the five days are anchored on this
/// account's first call, so a cashier added in three months gets their own
/// five days instead of inheriting an owner's expired ones.
class OnboardingGuideController extends AsyncNotifier<MerchantOnboardingGuide> {
  @override
  Future<MerchantOnboardingGuide> build() async {
    // Re-reads on a change of ACCOUNT and on nothing else. Signed out, it
    // answers "draw nothing" without spending a doomed 401 on the way to
    // the login screen.
    ref.watch(merchantAccountProvider);
    if (!ref.read(sessionProvider).signedIn) {
      return MerchantOnboardingGuide.hidden;
    }

    return ref.watch(apiProvider).onboarding();
  }

  /// Put it away for good. The POST answers the FULL state, so the new
  /// value is adopted directly — a client must never follow a write with a
  /// read here.
  Future<void> skip() async {
    state = AsyncData(await ref.read(apiProvider).skipOnboarding());
  }

  /// The walkthrough was watched (or explicitly turned down). NOT a skip:
  /// the tasklist stays, because watching the tour is not the same as
  /// having credited anybody.
  Future<void> tourFinished() async {
    state = AsyncData(await ref.read(apiProvider).completeOnboardingTour());
  }

  /// Re-read, but only while there is something to re-read.
  ///
  /// Once the server has said `show: false` — skipped, or the five days ran
  /// out — nothing can turn it back on, so this app never asks again for
  /// the rest of the process. That is what makes it safe to hang off every
  /// resume and off every landed credit.
  void refreshIfLive() {
    final guide = state.valueOrNull;
    if (guide != null && !guide.show) return;
    ref.invalidateSelf();
  }
}

final onboardingGuideProvider =
    AsyncNotifierProvider<OnboardingGuideController, MerchantOnboardingGuide>(
      OnboardingGuideController.new,
    );

/// The tasklist as THIS person may actually see it: the server's rows
/// narrowed to the permissions they hold, with the counts recomputed over
/// what is left (a cashier must never see four rows under "2 of 5 done").
///
/// Every surface reads THIS, never the AsyncValue — which is what makes
/// "nothing while the read is in flight", "nothing when it failed" and
/// "nothing because the five days are over" the same code path, and why a
/// failed read can never put an error box in a till's chrome.
final onboardingChecklistProvider = Provider<MerchantOnboardingChecklist>((
  ref,
) {
  // Repaint when a fresh /merchant/me narrows or widens the role — the same
  // tick the permission-gated nav watches.
  ref.watch(sessionTickProvider);

  final guide = ref.watch(onboardingGuideProvider).valueOrNull;
  if (guide == null) return MerchantOnboardingChecklist.hidden;

  return guide.checklistFor(ref.watch(sessionProvider).permissions);
});

/// Whether the walkthrough is still worth offering unasked: the guide is
/// live and this person has neither watched nor waved it away.
final onboardingTourOfferProvider = Provider<bool>((ref) {
  final guide = ref.watch(onboardingGuideProvider).valueOrNull;
  if (guide == null || !guide.show || guide.tourCompleted) return false;

  return ref.watch(onboardingChecklistProvider).show;
});

/// Re-reads the guide when the app comes back to the foreground — watched
/// once from the shell, so it is alive exactly while a signed-in merchant is
/// inside the app and never fires on the login screen.
///
/// The same shape as [queueDrainDriverProvider]: a Provider whose whole job
/// is to own a WidgetsBindingObserver for as long as it is watched.
final onboardingResumeDriverProvider = Provider<void>((ref) {
  final driver = _OnboardingResumeDriver(
    ref.read(onboardingGuideProvider.notifier),
  );
  ref.onDispose(driver.dispose);
});

class _OnboardingResumeDriver with WidgetsBindingObserver {
  _OnboardingResumeDriver(this._controller) {
    WidgetsBinding.instance.addObserver(this);
  }

  final OnboardingGuideController _controller;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _controller.refreshIfLive();
  }

  void dispose() => WidgetsBinding.instance.removeObserver(this);
}

/// Re-read the guide because something on the list may just have become
/// true — a credit landed, a settlement landed. Costs one query while the
/// guide is live and nothing at all once it is over.
void refreshOnboardingGuide(Ref ref) =>
    ref.read(onboardingGuideProvider.notifier).refreshIfLive();

/// The same, from a widget.
void refreshOnboardingGuideFrom(WidgetRef ref) =>
    ref.read(onboardingGuideProvider.notifier).refreshIfLive();
