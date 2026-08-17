import 'package:flutter/material.dart';

import '../tokens.dart';

/// The Manfaa mark — the coral twin-peak "M" — painted so it needs no asset
/// and stays crisp at any size. Pair with the wordmark via [ManfaaWordmark].
class ManfaaMark extends StatelessWidget {
  const ManfaaMark({super.key, this.size = 26, this.color = ManfaaColors.coral});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      size: Size(size * 1.12, size),
      painter: _MarkPainter(color),
    );
  }
}

class _MarkPainter extends CustomPainter {
  _MarkPainter(this.color);
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width, h = size.height;
    final stroke = h * 0.26;
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    final inset = stroke / 2;
    final path = Path()
      ..moveTo(inset, h - inset)
      ..lineTo(w * 0.27, inset)
      ..lineTo(w * 0.5, h * 0.52)
      ..lineTo(w * 0.73, inset)
      ..lineTo(w - inset, h - inset);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(_MarkPainter old) => old.color != color;
}

/// The mark + "Manfaa" wordmark, as it sits in the app header.
class ManfaaWordmark extends StatelessWidget {
  const ManfaaWordmark({super.key, this.size = 22});

  final double size;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        ManfaaMark(size: size),
        SizedBox(width: size * 0.42),
        Text(
          'Manfaa',
          style: theme.textTheme.titleLarge?.copyWith(
            fontSize: size * 0.92,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.4,
            color: theme.colorScheme.onSurface,
          ),
        ),
      ],
    );
  }
}

/// The soft-lavender initials avatar with an optional coral notification dot,
/// as in the mockup header. Give it an [imageUrl] and the circle renders the
/// picture instead, with the initials standing in while it loads — and
/// standing back in if the load fails, so the circle is never blank.
class ManfaaAvatar extends StatelessWidget {
  const ManfaaAvatar(
    this.initials, {
    super.key,
    this.size = 46,
    this.showDot = false,
    this.imageUrl,
  });

  final String initials;
  final double size;
  final bool showDot;

  /// The profile picture. Null (or empty) renders the initials circle.
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final fallback = Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: dark ? const Color(0xFF2E2547) : ManfaaColors.violetSoft,
        shape: BoxShape.circle,
      ),
      child: Text(
        initials,
        style: TextStyle(
          fontSize: size * 0.34,
          fontWeight: FontWeight.w800,
          color: dark ? const Color(0xFFCFC4F2) : ManfaaColors.violetDeep,
        ),
      ),
    );

    final url = imageUrl;
    final avatar = url == null || url.isEmpty
        ? fallback
        : ClipOval(
            child: Image.network(
              url,
              width: size,
              height: size,
              fit: BoxFit.cover,
              // Initials while the bytes arrive (unless already in the
              // image cache, when the first frame is synchronous)…
              frameBuilder: (context, child, frame, syncLoaded) =>
                  syncLoaded || frame != null ? child : fallback,
              // …and initials again if the URL is dead or offline.
              errorBuilder: (context, error, stackTrace) => fallback,
            ),
          );

    if (!showDot) return avatar;
    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        children: [
          avatar,
          Positioned(
            right: 0,
            top: 2,
            child: Container(
              width: size * 0.22,
              height: size * 0.22,
              decoration: BoxDecoration(
                color: ManfaaColors.coral,
                shape: BoxShape.circle,
                border: Border.all(
                  color: Theme.of(context).scaffoldBackgroundColor,
                  width: 2,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The app header row: wordmark on the left, avatar on the right. Sits as the
/// first item of a scroll view (the mockups have no separate AppBar on the
/// tab screens).
class ManfaaTopBar extends StatelessWidget {
  const ManfaaTopBar({
    super.key,
    this.initials,
    this.onAvatarTap,
    this.showDot = true,
    this.avatarUrl,
  });

  final String? initials;
  final VoidCallback? onAvatarTap;
  final bool showDot;

  /// The profile picture; the initials remain the fallback.
  final String? avatarUrl;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const ManfaaWordmark(),
        const Spacer(),
        if (initials != null)
          GestureDetector(
            onTap: onAvatarTap,
            behavior: HitTestBehavior.opaque,
            child: ManfaaAvatar(
              initials!,
              size: 40,
              showDot: showDot,
              imageUrl: avatarUrl,
            ),
          ),
      ],
    );
  }
}
