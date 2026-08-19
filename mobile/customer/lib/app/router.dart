import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/activity/activity_screen.dart';
import '../features/activity/payout_detail_screen.dart';
import '../features/auth/sign_in_screen.dart';
import '../features/boot/boot_screen.dart';
import '../features/discover/discover_screen.dart';
import '../features/discover/search_screen.dart';
import '../features/discover/store_screen.dart';
import '../features/home/home_screen.dart';
import '../features/market/address_screen.dart';
import '../features/market/cart_screen.dart';
import '../features/market/checkout_screen.dart';
import '../features/market/order_screen.dart';
import '../features/market/wallet_screen.dart';
import '../features/market/market_screen.dart';
import '../features/market/store_screen.dart';
import '../features/home/qr_screen.dart';
import '../features/profile/devices_screen.dart';
import '../features/profile/payout_account_screen.dart';
import '../features/profile/profile_screen.dart';
import 'providers.dart';
import 'shell.dart';

/// Everything behind sign-in. The redirect below is the router half of the
/// guide's 401 rule: the auth interceptor wipes the session, the session
/// revision bumps, and this listener walks the user out — no screen handles
/// authentication state on its own.
const _protectedPrefixes = ['/home', '/discover', '/activity', '/profile', '/qr'];

final routerProvider = Provider<GoRouter>((ref) {
  final session = ref.watch(sessionProvider);

  return GoRouter(
    initialLocation: '/boot',
    refreshListenable: session.revision,
    redirect: (context, state) {
      final location = state.matchedLocation;

      final wantsProtected =
          _protectedPrefixes.any((p) => location.startsWith(p));

      if (!session.signedIn && wantsProtected) return '/signin';
      if (session.signedIn && location == '/signin') return '/home';

      return null;
    },
    routes: [
      GoRoute(path: '/boot', builder: (_, _) => const BootScreen()),
      GoRoute(path: '/signin', builder: (_, _) => const SignInScreen()),
      GoRoute(
        path: '/update-required',
        builder: (_, state) =>
            UpdateRequiredScreen(storeUrl: state.extra as String? ?? ''),
      ),

      // Fullscreen QR sits OUTSIDE the shell: it is the till moment, wants
      // the whole screen, and must open in one tap from anywhere.
      GoRoute(
        path: '/qr',
        pageBuilder: (_, _) => const MaterialPage(
          fullscreenDialog: true,
          child: QrScreen(),
        ),
      ),

      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [
            GoRoute(path: '/home', builder: (_, _) => const HomeScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/discover',
              builder: (_, _) => const DiscoverScreen(),
              routes: [
                GoRoute(
                  path: 'search',
                  builder: (_, state) => SearchScreen(
                    category: state.uri.queryParameters['category'],
                  ),
                ),
                GoRoute(
                  path: 'store/:slug',
                  builder: (_, state) =>
                      StoreScreen(slug: state.pathParameters['slug'] ?? ''),
                ),
              ],
            ),
          ]),
          // Marketplace (PLAN-marketplace.md §10). The branch is ALWAYS
          // registered — go_router's indexed stack needs a stable branch
          // count, and a shell that grew and shrank would renumber every
          // other tab underneath the user. What the kill switch decides is
          // whether the bar OFFERS it; the routes refuse on their own.
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/market',
              builder: (_, _) => const MarketScreen(),
              routes: [
                GoRoute(
                  path: 'cart',
                  builder: (_, _) => const CartScreen(),
                ),
                GoRoute(
                  path: 'checkout',
                  builder: (_, _) => const CheckoutScreen(),
                ),
                GoRoute(
                  path: 'wallet',
                  builder: (_, _) => const WalletScreen(),
                ),
                GoRoute(
                  // MUST sit above ':branchId', which would otherwise
                  // swallow it — that is exactly what threw a GoRouter
                  // exception when checkout pushed here (fixed 2026-08-19).
                  path: 'addresses/new',
                  builder: (_, _) => const AddressStepScreen(),
                ),
                GoRoute(
                  path: 'orders/:id',
                  builder: (_, state) => OrderScreen(
                    orderId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                  ),
                ),
                GoRoute(
                  path: ':branchId',
                  builder: (_, state) => MarketStoreScreen(
                    branchId:
                        int.tryParse(state.pathParameters['branchId'] ?? '') ?? 0,
                  ),
                ),
              ],
            ),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/activity',
              builder: (_, _) => const ActivityScreen(),
              routes: [
                GoRoute(
                  path: 'payout/:id',
                  builder: (_, state) => PayoutDetailScreen(
                    payoutId:
                        int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                  ),
                ),
              ],
            ),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/profile',
              builder: (_, _) => const ProfileScreen(),
              routes: [
                GoRoute(
                  path: 'devices',
                  builder: (_, _) => const DevicesScreen(),
                ),
                GoRoute(
                  path: 'payout-account',
                  builder: (_, _) => const PayoutAccountScreen(),
                ),
              ],
            ),
          ]),
        ],
      ),
    ],
  );
});
