import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../features/market/market_providers.dart';
import 'app.dart';

/// Transactions and Payouts are both money history, so they merge into
/// Activity — which is also what leaves room for Market without the bar
/// getting cramped in Thaana, whose labels run longer.
///
/// The bar itself is the mockups' floating white pill: rounded, shadowed,
/// inset from the screen edges, with the selected tab shown by a filled icon
/// and a soft highlight.
///
/// **Market is conditional** (PLAN-marketplace.md §10). The router always
/// registers its branch — go_router's indexed stack needs a stable branch
/// count, and a shell that grew and shrank would renumber every tab
/// underneath the user — so the bar maps what it SHOWS onto those fixed
/// branches. With the marketplace off there is no Market item and nothing
/// below it moves.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  /// Branch order in the router: home, discover, market, activity, profile.
  static const int _marketBranch = 2;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final marketplace = ref.watch(marketplaceEnabledProvider);

    // Display order → branch index. The one place the two numbering schemes
    // meet, so nothing else has to know they differ.
    final branches = <int>[0, 1, if (marketplace) _marketBranch, 3, 4];

    final items = <_NavItem>[
      _NavItem(Icons.home_outlined, Icons.home_rounded, l10n.tabHome),
      _NavItem(Icons.explore_outlined, Icons.explore_rounded, l10n.tabDiscover),
      if (marketplace)
        _NavItem(Icons.storefront_outlined, Icons.storefront_rounded,
            l10n.tabMarket),
      _NavItem(Icons.receipt_long_outlined, Icons.receipt_long_rounded,
          l10n.tabActivity),
      _NavItem(Icons.person_outline_rounded, Icons.person_rounded,
          l10n.tabProfile),
    ];

    // A shopper deep in Market when an admin switches the marketplace off
    // has no tab to be highlighted. Fall back to Home rather than lighting
    // up whichever item happens to share the index.
    final current = branches.indexOf(shell.currentIndex);

    return Scaffold(
      extendBody: true,
      body: shell,
      bottomNavigationBar: _FloatingNavBar(
        items: items,
        currentIndex: current < 0 ? 0 : current,
        onSelected: (index) {
          final branch = branches[index];

          shell.goBranch(
            branch,
            initialLocation: branch == shell.currentIndex,
          );
        },
      ),
    );
  }
}

class _NavItem {
  const _NavItem(this.icon, this.selectedIcon, this.label);
  final IconData icon;
  final IconData selectedIcon;
  final String label;
}

class _FloatingNavBar extends StatelessWidget {
  const _FloatingNavBar({
    required this.items,
    required this.currentIndex,
    required this.onSelected,
  });

  final List<_NavItem> items;
  final int currentIndex;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final light = theme.brightness == Brightness.light;

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.md),
        child: Container(
          decoration: BoxDecoration(
            color: scheme.surfaceContainerLowest,
            // A full stadium — deliberately rounder than any content card.
            borderRadius: BorderRadius.circular(Corner.bar),
            border: light ? null : Border.all(color: scheme.outlineVariant),
            boxShadow: light
                ? const [
                    BoxShadow(
                      color: Color(0x1A1A1F36),
                      blurRadius: 24,
                      offset: Offset(0, 8),
                    ),
                  ]
                : null,
          ),
          padding: const EdgeInsets.symmetric(horizontal: Gap.md, vertical: 6),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              for (var i = 0; i < items.length; i++)
                _NavButton(
                  item: items[i],
                  selected: i == currentIndex,
                  onTap: () => onSelected(i),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavButton extends StatelessWidget {
  const _NavButton({
    required this.item,
    required this.selected,
    required this.onTap,
  });

  final _NavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final fg = selected ? scheme.onSurface : scheme.onSurfaceVariant;

    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.bar),
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: const EdgeInsets.symmetric(vertical: 7),
          decoration: BoxDecoration(
            color: selected
                ? scheme.surfaceContainerHigh
                : Colors.transparent,
            borderRadius: BorderRadius.circular(Corner.bar),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(selected ? item.selectedIcon : item.icon, color: fg, size: 22),
              const SizedBox(height: 3),
              Text(
                item.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: fg,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
