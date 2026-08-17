import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../widgets/adaptive.dart';
import 'app.dart';
import 'providers.dart';
import 'router.dart';

/// The floating white pill nav from the refs — same bar as the customer app,
/// with the merchant's five slots: Dashboard · Credit · Transactions ·
/// Settlements · More.
///
/// PERMISSION-AWARE: the items render from the session's resolved permission
/// slugs (kTabs), so a credits-only cashier sees Credit and More and nothing
/// of the store's commercial standing. The branches behind the bar are
/// static; only the DRAWING is gated — the router redirect and the server
/// hold the actual lines.
///
/// MR7: at ≥840dp window width the bottom bar hands over to a left
/// [_NavRail] — the SAME items list (so the same permission gating), the
/// same branches, restyled to the house system. Below that, phones and
/// small tablets keep the shipped stadium bar untouched.
class MerchantShell extends ConsumerWidget {
  const MerchantShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Repaint when a fresh /merchant/me narrows or widens the role.
    ref.watch(sessionTickProvider);
    // Keep the offline credit queue's drain triggers (foreground resume,
    // connectivity regained) alive exactly while a merchant is in the shell.
    ref.watch(queueDrainDriverProvider);
    final session = ref.watch(sessionProvider);
    final l10n = context.l10n;

    final specs = <_NavItem>[
      _NavItem(0, Icons.home_outlined, Icons.home_rounded, l10n.tabDashboard),
      _NavItem(
        1,
        Icons.person_add_outlined,
        Icons.person_add_rounded,
        l10n.tabCredit,
      ),
      _NavItem(
        2,
        Icons.receipt_long_outlined,
        Icons.receipt_long_rounded,
        l10n.tabTransactions,
      ),
      _NavItem(
        3,
        Icons.account_balance_outlined,
        Icons.account_balance_rounded,
        l10n.tabSettlements,
      ),
      _NavItem(
        4,
        Icons.more_horiz_rounded,
        Icons.more_horiz_rounded,
        l10n.tabMore,
      ),
    ];

    final items = <_NavItem>[
      for (final item in specs)
        if (kTabs[item.branch].permission == null ||
            session.can(kTabs[item.branch].permission!))
          item,
    ];

    void select(int branch) =>
        shell.goBranch(branch, initialLocation: branch == shell.currentIndex);

    if (railShell(context)) {
      // Expanded: the rail sits at the leading edge (start — it mirrors to
      // the right in dv exactly like the rest of the app), the branch
      // content takes the rest. No extendBody: nothing floats over content.
      return Scaffold(
        body: Row(
          children: [
            _NavRail(
              items: items,
              currentBranch: shell.currentIndex,
              onSelected: select,
            ),
            Expanded(child: shell),
          ],
        ),
      );
    }

    return Scaffold(
      extendBody: true,
      body: shell,
      bottomNavigationBar: _FloatingNavBar(
        items: items,
        currentBranch: shell.currentIndex,
        onSelected: select,
      ),
    );
  }
}

class _NavItem {
  const _NavItem(this.branch, this.icon, this.selectedIcon, this.label);
  final int branch;
  final IconData icon;
  final IconData selectedIcon;
  final String label;
}

class _FloatingNavBar extends StatelessWidget {
  const _FloatingNavBar({
    required this.items,
    required this.currentBranch,
    required this.onSelected,
  });

  final List<_NavItem> items;
  final int currentBranch;
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
          // Tighter than the customer bar: five slots must seat labels like
          // "Transactions" untruncated on a 390 frame.
          padding: const EdgeInsets.symmetric(horizontal: Gap.sm, vertical: 6),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              for (final item in items)
                _NavButton(
                  item: item,
                  selected: item.branch == currentBranch,
                  onTap: () => onSelected(item.branch),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The expanded-shell rail: a quiet white column at the leading edge with
/// the same five destinations. House-styled, not stock Material — the
/// selected slot gets the violet-soft pill (the ModeRow's idiom), the brand
/// mark anchors the top, and a hairline separates it from the canvas.
class _NavRail extends StatelessWidget {
  const _NavRail({
    required this.items,
    required this.currentBranch,
    required this.onSelected,
  });

  final List<_NavItem> items;
  final int currentBranch;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: 96,
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLowest,
        border: BorderDirectional(
          end: BorderSide(color: scheme.outlineVariant),
        ),
      ),
      child: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: Gap.xl),
            const ManfaaMark(size: 30),
            const SizedBox(height: Gap.huge),
            for (final item in items) ...[
              _RailButton(
                item: item,
                selected: item.branch == currentBranch,
                onTap: () => onSelected(item.branch),
              ),
              const SizedBox(height: Gap.sm),
            ],
          ],
        ),
      ),
    );
  }
}

class _RailButton extends StatelessWidget {
  const _RailButton({
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
    final fg = selected ? scheme.onSecondaryContainer : scheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: Gap.sm),
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.control),
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          width: double.infinity,
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 2),
          decoration: BoxDecoration(
            color: selected ? scheme.secondaryContainer : Colors.transparent,
            borderRadius: BorderRadius.circular(Corner.control),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                selected ? item.selectedIcon : item.icon,
                color: fg,
                size: 24,
              ),
              const SizedBox(height: 4),
              Text(
                item.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: fg,
                  fontSize: 10.5,
                  letterSpacing: 0,
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
            color: selected ? scheme.surfaceContainerHigh : Colors.transparent,
            borderRadius: BorderRadius.circular(Corner.bar),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                selected ? item.selectedIcon : item.icon,
                color: fg,
                size: 22,
              ),
              const SizedBox(height: 3),
              Text(
                item.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: fg,
                  fontSize: 10,
                  letterSpacing: 0,
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
