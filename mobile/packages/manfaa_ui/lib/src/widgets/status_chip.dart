import 'package:flutter/material.dart';

import '../tokens.dart';

/// The status vocabulary, coloured by MONEY SEMANTICS — the same mapping the
/// whole design system uses: amber is conditional, emerald is yours, sky is
/// in-flight, rose is something that needs attention, stone is closed.
enum StatusTone { pending, confirmed, paid, attention, closed }

class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.label, required this.tone});

  final String label;
  final StatusTone tone;

  @override
  Widget build(BuildContext context) {
    final surface = toneSurface(
      switch (tone) {
        StatusTone.pending => ToneSurface.pending,
        StatusTone.confirmed => ToneSurface.confirmed,
        StatusTone.paid => ToneSurface.info,
        StatusTone.attention => ToneSurface.attention,
        StatusTone.closed => ToneSurface.closed,
      },
      Theme.of(context).brightness,
    );
    final bg = surface.background;
    final fg = surface.foreground;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context)
            .textTheme
            .labelSmall
            ?.copyWith(color: fg, fontWeight: FontWeight.w700),
      ),
    );
  }
}
