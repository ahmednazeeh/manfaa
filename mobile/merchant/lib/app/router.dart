import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../features/auth/login_screen.dart';
import '../features/boot/boot_screen.dart';
import '../features/credit/credit_screen.dart';
import '../features/setup/setup_screen.dart';
import '../features/signup/signup_screen.dart';
import '../features/status/setup_pending_screen.dart';
import '../features/tabs/tab_screens.dart';
import '../features/transactions/transactions_screen.dart';
import 'providers.dart';
import 'shell.dart';

/// The canonical tab order (Merchant More.png's bottom bar) with the
/// permission that earns each slot. Navigation is DRAWN from the session's
/// resolved permissions exactly as the web panel does — the server enforces
/// regardless, so hiding is a courtesy, never the security.
const kTabs = <({String path, String? permission})>[
  (path: '/dashboard', permission: 'settlements.view'),
  (path: '/credit', permission: 'credits.create'),
  (path: '/transactions', permission: 'transactions.view'),
  (path: '/settlements', permission: 'settlements.view'),
  (path: '/more', permission: null),
];

/// Statuses that keep a merchant OUT of the shell: the store is not trading
/// yet, and the till must say so instead of letting a cashier find out on a
/// refused credit. `active` and `suspended` both route to the shell —
/// suspension is never a locked panel (settling your way out stays open).
const kSetupStatuses = {'draft', 'pending_review', 'rejected'};

bool _needsSetup(MerchantSession session) =>
    kSetupStatuses.contains(session.merchantStatus);

/// The setup-flow surfaces a not-yet-trading session may stand on, and the
/// one it lands on by default. Draft lives IN the wizard; pending_review is
/// locked to the waiting screen; rejected lands on the status screen (the
/// admin's reason + "Edit and resubmit") but may re-enter the wizard.
({Set<String> allowed, String home}) _setupSurfacesFor(
        MerchantSession session) =>
    switch (session.merchantStatus) {
      'draft' => (allowed: const {'/setup'}, home: '/setup'),
      'rejected' => (
          allowed: const {'/setup', '/setup-pending'},
          home: '/setup-pending',
        ),
      _ => (allowed: const {'/setup-pending'}, home: '/setup-pending'),
    };

/// Where a signed-in session lands: the setup flow while the store is not
/// yet trading, otherwise the first tab this account may see (More is always
/// held, so there is always somewhere to land).
String homeLocationFor(MerchantSession session) {
  if (_needsSetup(session)) return _setupSurfacesFor(session).home;
  for (final tab in kTabs) {
    final permission = tab.permission;
    if (permission == null || session.can(permission)) return tab.path;
  }
  return '/more';
}

/// The redirect is the router half of the guide's 401 rule: the auth
/// interceptor wipes the session, the revision bumps, and this listener
/// walks the user out — no screen handles authentication state on its own.
/// It is also the STATUS gate: /merchant/me writes a fresh status into the
/// session on every launch/resume, and the same revision bump re-routes.
final routerProvider = Provider<GoRouter>((ref) {
  final session = ref.watch(sessionProvider);

  return GoRouter(
    initialLocation: '/boot',
    refreshListenable: session.revision,
    redirect: (context, state) {
      final location = state.matchedLocation;

      // Boot decides its own exit; the update gate is above sign-in.
      if (location == '/boot' || location == '/update-required') return null;

      if (!session.signedIn) {
        return location == '/login' || location == '/signup' ? null : '/login';
      }

      if (_needsSetup(session)) {
        final surfaces = _setupSurfacesFor(session);
        return surfaces.allowed.contains(location) ? null : surfaces.home;
      }

      if (location == '/setup-pending' || location == '/setup') {
        return homeLocationFor(session);
      }

      if (location == '/login' || location == '/signup') {
        // Status unknown means sign-in landed but /merchant/me has not: the
        // login flow finishes with its own explicit go once it has tried,
        // so the redirect holds rather than flashing a tab that a status
        // answer may immediately snatch away.
        return session.merchantStatus == null ? null : homeLocationFor(session);
      }

      // A tab this account may not see (role narrowed, or a deep link).
      for (final tab in kTabs) {
        final permission = tab.permission;
        if (location == tab.path &&
            permission != null &&
            !session.can(permission)) {
          return homeLocationFor(session);
        }
      }

      return null;
    },
    routes: [
      GoRoute(path: '/boot', builder: (_, _) => const BootScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/signup', builder: (_, _) => const SignupScreen()),
      GoRoute(path: '/setup', builder: (_, _) => const SetupScreen()),
      GoRoute(
        path: '/update-required',
        builder: (_, state) =>
            UpdateRequiredScreen(storeUrl: state.extra as String? ?? ''),
      ),
      GoRoute(
        path: '/setup-pending',
        builder: (_, _) => const SetupPendingScreen(),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => MerchantShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [
            GoRoute(
                path: '/dashboard', builder: (_, _) => const DashboardScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/credit', builder: (_, _) => const CreditScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
                path: '/transactions',
                builder: (_, _) => const TransactionsScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
                path: '/settlements',
                builder: (_, _) => const SettlementsScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/more', builder: (_, _) => const MoreScreen()),
          ]),
        ],
      ),
    ],
  );
});
