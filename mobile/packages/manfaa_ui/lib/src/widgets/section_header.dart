import 'package:flutter/material.dart';

import '../tokens.dart';

/// A section title with an optional "View all →" action, matching the
/// mockups' "Featured offers / View all" rows.
class SectionHeader extends StatelessWidget {
  const SectionHeader(
    this.title, {
    super.key,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        Expanded(
          child: Text(title, style: theme.textTheme.titleLarge),
        ),
        if (actionLabel != null && onAction != null)
          InkWell(
            borderRadius: BorderRadius.circular(Corner.control),
            onTap: onAction,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    actionLabel!,
                    style: theme.textTheme.labelLarge
                        ?.copyWith(color: ManfaaColors.violet),
                  ),
                  const Icon(Icons.chevron_right_rounded,
                      size: 18, color: ManfaaColors.violet),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
