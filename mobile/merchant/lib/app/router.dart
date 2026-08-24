import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';

import '../features/auth/login_screen.dart';
import '../features/boot/boot_screen.dart';
import '../features/credit/credit_screen.dart';
import '../features/dashboard/dashboard_screen.dart';
import '../features/more/branches_screen.dart';
import '../features/more/cashback_screen.dart';
import '../features/more/close_store_screen.dart';
import '../features/more/employees_screen.dart';
import '../features/marketplace/apply_screen.dart';
import '../features/marketplace/order_detail_screen.dart';
import '../features/marketplace/orders_screen.dart';
import '../features/marketplace/products_screen.dart';
import '../features/more/more_screen.dart';
import '../features/more/profile_screen.dart';
import '../features/more/promotions_screen.dart';
import '../features/more/roles_screen.dart';
import '../features/settlements/settlement_detail_screen.dart';
import '../features/settlements/settlements_screen.dart';
import '../features/setup/setup_screen.dart';
import '../features/signup/signup_screen.dart';
import '../features/status/setup_pending_screen.dart';
import '../features/transactions/transactions_screen.dart';
import '../features/wallet/wallet_screen.dart';
import '../features/wallet/wallet_top_up_screen.dart';
import 'providers.dart';
import 'shell.dart';

/// The canonical tab order (Merchant More.png's bottom bar) with the
/// permission that earns each slot. Navigation is DRAWN from the session's
/// resolved permissions exactly as the web panel does — the server enforces
/// regardless, so hiding is a courtesy, never the security.
const kTabs = <({String path, String? permission})>[
  (path: '/dashboard', permission: 'settlements.view'),
  // Marketplace orders (Orders.png). Permission-gated like every other slot,
  // and additionally hidden unless this store actually sells online — see
  // MerchantShell, where a store that never enrolled gets no tab at all.
  (path: '/orders', permission: 'marketplace.manage'),
  (path: '/credit', permission: 'credits.create'),
  (path: '/transactions', permission: 'transactions.view'),
  (path: '/settlements', permission: 'settlements.view'),
  (path: '/more', permission: null),
];

/// The More estate's sub-screens and the permission that opens each (MR5).
/// ANY of the listed slugs admits — one entry (cashback) is the web's
/// merged screen, whose three sections each stand on their own permission,
/// so holding any one of them earns the route (the screen then draws only
/// the permitted sections).
const kMoreGuards = <String, List<String>>{
  '/more/profile': ['profile.view'],
  '/more/employees': ['staff.view'],
  '/more/roles': ['roles.view'],
  '/more/branches': ['branches.view'],
  '/more/cashback': [
    'rate.view',
    'product_categories.view',
    'preferences.update',
  ],
  '/more/promotions': ['promotions.view'],
};

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

      // The wallet screen stands on its own read grant, exactly as the API
      // gates GET /merchant/wallet; the top-up flow underneath it on its own
      // write grant (POST /merchant/wallet/top-ups is `wallet.top_up`).
      if (location == '/wallet' || location.startsWith('/wallet/')) {
        if (!session.can('wallet.view')) return homeLocationFor(session);
        if (location == '/wallet/top-up' && !session.can('wallet.top_up')) {
          return '/wallet';
        }
      }

      // The More estate's sub-screens each stand on their own read
      // permission (the server refuses regardless — this only spares the
      // user a broken screen on a deep link or a narrowed role).
      for (final MapEntry(key: path, value: slugs) in kMoreGuards.entries) {
        if ((location == path || location.startsWith('$path/')) &&
            !slugs.any(session.can)) {
          return homeLocationFor(session);
        }
      }

      // A tab this account may not see (role narrowed, or a deep link).
      // Prefix-matched so a tab's sub-routes (/settlements/44) answer to
      // the same permission as the tab itself.
      for (final tab in kTabs) {
        final permission = tab.permission;
        if ((location == tab.path || location.startsWith('${tab.path}/')) &&
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
      // MR8 (owner report): "navigating to another tab resets the tab being
      // left". NOT the stock indexedStack — that container keeps every
      // branch subtree alive, which is exactly the mid-flow persistence the
      // owner rejected. Mounting ONLY the active branch unmounts the
      // departed tab wholesale: form fields, pane selections and every
      // otherwise-unwatched autoDispose provider reset, so returning always
      // lands fresh (and re-fetches — the Dashboard-staleness fix's second
      // half). What must survive tab switches lives outside the branches by
      // construction: the session, the offline credit queue (a root
      // ChangeNotifierProvider over persistent storage) and the queue-drain
      // driver watched from the shell itself.
      StatefulShellRoute(
        builder: (context, state, shell) => MerchantShell(shell: shell),
        navigatorContainerBuilder: (context, shell, children) =>
            children[shell.currentIndex],
        branches: [
          StatefulShellBranch(routes: [
            GoRoute(
                path: '/dashboard', builder: (_, _) => const DashboardScreen()),
            // Pushed from the dashboard's wallet card; lives in this branch
            // so back lands on the dashboard.
            GoRoute(
              path: '/wallet',
              builder: (_, _) => const WalletScreen(),
              routes: [
                // The receipt-first top-up (owner, 2026-08-24): back lands
                // on the wallet, whose balance the claim will one day move.
                GoRoute(
                  path: 'top-up',
                  builder: (_, _) => const WalletTopUpScreen(),
                ),
              ],
            ),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/orders',
              builder: (_, _) => const ShopOrdersScreen(),
              routes: [
                GoRoute(
                  path: ':id',
                  builder: (_, state) => ShopOrderDetailScreen(
                    suborderId:
                        int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                  ),
                ),
              ],
            ),
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
              builder: (_, _) => const SettlementsScreen(),
              routes: [
                GoRoute(
                  path: ':id',
                  builder: (_, state) => SettlementDetailScreen(
                    id: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                  ),
                ),
              ],
            ),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/more',
              builder: (_, _) => const MoreScreen(),
              routes: [
                GoRoute(
                  path: 'profile',
                  builder: (_, _) => const ProfileScreen(),
                ),
                GoRoute(
                  path: 'cashback',
                  builder: (_, _) => const CashbackSettingsScreen(),
                ),
                GoRoute(
                  path: 'products',
                  builder: (_, _) => const ShopProductsScreen(),
                ),
                GoRoute(
                  path: 'marketplace',
                  builder: (_, _) => const MarketplaceApplyScreen(),
                ),
                GoRoute(
                  path: 'employees',
                  builder: (_, _) => const EmployeesScreen(),
                ),
                GoRoute(
                  path: 'roles',
                  builder: (_, _) => const RolesScreen(),
                ),
                GoRoute(
                  path: 'branches',
                  builder: (_, _) => const BranchesScreen(),
                ),
                GoRoute(
                  path: 'promotions',
                  builder: (_, _) => const PromotionsScreen(),
                ),
                // MR8: the in-app closure flow. UNGUARDED like Log out —
                // its credential is the store phone's OTP, not a
                // permission slug (the endpoints are public by design).
                GoRoute(
                  path: 'close-store',
                  builder: (_, _) => const CloseStoreScreen(),
                ),
              ],
            ),
          ]),
        ],
      ),
    ],
  );
});
