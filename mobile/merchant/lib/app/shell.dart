import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../widgets/adaptive.dart';
import 'app.dart';
import 'providers.dart';
import 'router.dart';
import '../features/marketplace/marketplace_providers.dart';

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
        Icons.assignment_outlined,
        Icons.assignment_rounded,
        'Orders',
      ),
      _NavItem(
        2,
        Icons.person_add_outlined,
        Icons.person_add_rounded,
        l10n.tabCredit,
      ),
      _NavItem(
        3,
        Icons.receipt_long_outlined,
        Icons.receipt_long_rounded,
        l10n.tabTransactions,
      ),
      _NavItem(
        4,
        Icons.account_balance_outlined,
        Icons.account_balance_rounded,
        l10n.tabSettlements,
      ),
      _NavItem(
        5,
        Icons.more_horiz_rounded,
        Icons.more_horiz_rounded,
        l10n.tabMore,
      ),
    ];

    // Shown wherever the PLATFORM has a marketplace — the endpoint 404s
    // behind the kill switch, so any answer at all means it is on.
    //
    // Deliberately NOT gated on this store having enrolled. It was, and the
    // result was a merchant who could see no marketplace anywhere and had no
    // way to learn why. The screen itself now explains where enrolment
    // happens; an absent tab could not.
    final sells = ref.watch(shopEnrolmentProvider).hasValue;

    final items = <_NavItem>[
      for (final item in specs)
        if ((kTabs[item.branch].permission == null ||
                session.can(kTabs[item.branch].permission!)) &&
            (kTabs[item.branch].path != '/orders' || sells))
          item,
    ];

    // MR8: entering a tab ALWAYS lands on its root (initialLocation) — the
    // departed branch was already unmounted by the shell's active-only
    // container, so a stale sub-route (an open settlement, a pushed detail)
    // can never be what a returning tap restores.
    void select(int branch) => shell.goBranch(branch, initialLocation: true);

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

/// The floating phone nav bar's container — the thing screen content must
/// scroll clear of ([bottomClearanceOf]).
const kFloatingNavBarKey = Key('merchant-floating-nav');

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
          // The bar floats OVER the branch content (extendBody), so a screen
          // that puts a control near the bottom must clear it. Keyed so a
          // test can measure that clearance instead of trusting the eye.
          key: kFloatingNavBarKey,
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
            // Square in a 96px rail: the landscape mark is ~2.75:1, so at
            // any readable height it is wider than the rail itself.
            const BrandLogo(
              shape: BrandLogoShape.square,
              height: 48,
              semanticLabel: 'Manfaa',
            ),
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
      // MR11 (owner report): the selected pill reads slightly small on the
      // slate too — 2dp of extra height and 2dp of extra width each side,
      // the same nudge the phone bar got. House look unchanged.
      padding: const EdgeInsets.symmetric(horizontal: 6),
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.control),
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          width: double.infinity,
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 2),
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
    // Brand accent, matching the customer bar and this shell's own rail
    // (owner, 2026-08-24): the active pill speaks violet, not grey.
    final violet = tintColors(ManfaaTint.violet, theme.brightness);
    final fg = selected ? violet.fg : scheme.onSurfaceVariant;

    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.bar),
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          // MR11 (owner report): the selected pill sat a touch tight around
          // its icon+label — 2dp more top and bottom. And 2026-08-24: the
          // stadium's corner curve was clipping into longer labels
          // ("Transactions") at the pill's edge — horizontal padding gives
          // the text clearance, and the FittedBox below shrinks a label
          // that still cannot fit rather than letting it touch.
          padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 10),
          decoration: BoxDecoration(
            color: selected ? violet.bg : Colors.transparent,
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
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  item.label,
                  maxLines: 1,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: fg,
                    fontSize: 10,
                    letterSpacing: 0,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
