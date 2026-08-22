import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../push/push_registrar.dart';

/// Home — the brand mockup, top to bottom: header, greeting, the available
/// balance (confirmed is the headline; pending stays separate, §10), the
/// Manfaa code with its QR, paid-this-month + next-payout, a primary
/// "View transactions" action, and the Discover hook.
///
/// Offline still shows the code (cached in the session store) — a shop with
/// no signal is exactly where the code is needed.
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  @override
  void initState() {
    super.initState();
    // Push permission is asked HERE — signed in, code on screen, the value
    // obvious — never on first launch (PushRegistrar's docblock).
    Future.microtask(() => ref.read(pushRegistrarProvider).ensureRegistered());
  }

  @override
  Widget build(BuildContext context) {
    final home = ref.watch(homeProvider);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () => ref.refresh(homeProvider.future),
          child: home.when(
            data: (data) => _HomeBody(data: data),
            loading: () => const _HomeSkeleton(),
            error: (e, _) => _OfflineBody(
              message: e is MobileApiException
                  ? e.message
                  : context.l10n.errorGeneric,
              onRetry: () => ref.invalidate(homeProvider),
            ),
          ),
        ),
      ),
    );
  }
}

String initialsFor(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
  if (parts.isEmpty) return '؟';
  final letters = parts.take(2).map((p) => p.characters.first.toUpperCase());
  return letters.join();
}

class _HomeBody extends ConsumerWidget {
  const _HomeBody({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    // A Dhivehi reader is greeted by their Thaana name; everyone else, and
    // anyone whose name the writer could not produce, keeps the English one.
    final shown = displayName(
      english: data.customerName,
      dhivehi: data.customerNameDv,
      preferDhivehi: Localizations.localeOf(context).languageCode == 'dv',
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(
        Gap.lg,
        Gap.md,
        Gap.lg,
        Gap.navClearance,
      ),
      children: [
        ManfaaTopBar(
          initials: initialsFor(shown),
          avatarUrl: ref.watch(avatarUrlProvider),
          onAvatarTap: () => context.go('/profile'),
        ),
        const SizedBox(height: Gap.lg),
        Row(
          children: [
            Flexible(
              child: Text(
                l10n.homeGreeting(shown),
                style: theme.textTheme.headlineSmall,
              ),
            ),
            const SizedBox(width: Gap.sm),
            const Text('👋', style: TextStyle(fontSize: 20)),
          ],
        ),
        const SizedBox(height: Gap.xs),
        Text(
          l10n.homeSubtitle,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: Gap.lg),
        _AvailableCard(data: data),
        const SizedBox(height: Gap.lg),
        _CodeCard(code: data.customerCode),
        const SizedBox(height: Gap.lg),
        _StatsRow(data: data),
        if (!data.hasPayoutAccount) ...[
          const SizedBox(height: Gap.lg),
          const _PayoutAccountNag(),
        ],
        const SizedBox(height: Gap.lg),
        const _ViewTransactionsButton(),
        const SizedBox(height: Gap.lg),
        const _DiscoverOffersCard(),
      ],
    );
  }
}

/// Available (confirmed) cashback beside pending, split by a rule (Update 1
/// ref, 2026-08-21). They sit in ONE card but never sum: §10 keeps confirmed
/// and pending separate, and the divider is the point — two figures, two
/// meanings, no total anywhere.
///
/// Pending drops out entirely at zero rather than showing MVR 0.00, and the
/// available column then takes the full width.
/// The little "i" beside a figure's heading, carrying the sentence that used
/// to sit under the figure as body text (owner, 2026-08-21).
///
/// A [Tooltip] rather than a dialog: the explanation is reassurance, not
/// something to act on, so it should cost a long-press and nothing else.
/// `preferBelow: false` puts it ABOVE the icon, where it does not cover the
/// amount the reader is looking at.
///
/// It is a real button so TalkBack reaches it — a bare Tooltip is invisible to
/// a screen reader on Android, and the sentence is the only place the app
/// explains what "pending" means.
class _HintDot extends StatelessWidget {
  const _HintDot(this.message, {required this.tint});

  final String message;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: message,
      preferBelow: false,
      triggerMode: TooltipTriggerMode.tap,
      showDuration: const Duration(seconds: 6),
      child: Semantics(
        button: true,
        label: message,
        child: Icon(Icons.info_outline_rounded, size: 16, color: tint),
      ),
    );
  }
}

/// Every stat tile on the home screen is this size. They sat at 40 and 38
/// beside each other, which reads as a mistake rather than a hierarchy.
const double _statIcon = 38;
const double _statGlyph = 20;

class _AvailableCard extends StatelessWidget {
  const _AvailableCard({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final green = tintColors(ManfaaTint.green, theme.brightness);
    final blue = tintColors(ManfaaTint.blue, theme.brightness);
    final showPending = data.pendingLaari > 0;

    final available = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Heading beside its tile, not beneath it, and both columns' tiles
        // are the same size — owner, 2026-08-21.
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            const IconTile(
              Icons.account_balance_wallet_outlined,
              tint: ManfaaTint.green,
              size: _statIcon,
              iconSize: _statGlyph,
            ),
            const SizedBox(width: Gap.md),
            Flexible(
              // The hint dot costs width the narrow pending column does not
              // have: "Pending" was breaking mid-word into "Pendin / g". The
              // heading shrinks a little instead, the same trick the amounts
              // use, so both columns keep a single line in either script.
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  l10n.availableCashback,
                  style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                ),
              ),
            ),
            const SizedBox(width: Gap.xs),
            _HintDot(l10n.confirmedHint, tint: green.fg),
          ],
        ),
        const SizedBox(height: Gap.sm),
        // A four-figure balance wrapped "MVR 1,284.50" onto two lines in this
        // narrower column. The headline shrinks to fit instead: the figure is
        // the one thing on this screen that must never break.
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          // Same size as the pending figure beside it (owner, 2026-08-21).
          // The colour and the pill carry the emphasis instead of the scale.
          child: MoneyText(
            data.confirmedLaari,
            style: theme.textTheme.titleLarge?.copyWith(
              color: theme.colorScheme.onSurface,
            ),
          ),
        ),
      ],
    );

    if (!showPending) {
      return ManfaaCard(
        child: SizedBox(width: double.infinity, child: available),
      );
    }

    final pending = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            const IconTile(
              Icons.schedule_rounded,
              tint: ManfaaTint.blue,
              size: _statIcon,
              iconSize: _statGlyph,
            ),
            const SizedBox(width: Gap.md),
            Flexible(
              // The hint dot costs width the narrow pending column does not
              // have: "Pending" was breaking mid-word into "Pendin / g". The
              // heading shrinks a little instead, the same trick the amounts
              // use, so both columns keep a single line in either script.
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  l10n.pendingLabel,
                  style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                ),
              ),
            ),
            const SizedBox(width: Gap.xs),
            _HintDot(l10n.pendingHint, tint: blue.fg),
          ],
        ),
        const SizedBox(height: Gap.sm),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: MoneyText(
            data.pendingLaari,
            style: theme.textTheme.titleLarge?.copyWith(color: blue.fg),
          ),
        ),
      ],
    );

    // No IntrinsicHeight here, deliberately.
    //
    // A full-height rule between two columns normally wants IntrinsicHeight,
    // but it measures wrapping text badly: it asks a child how tall it is
    // before the layout has settled how wide that child will be, and the
    // pill's Flexible makes that unknowable. In English the guess was close
    // enough; in Dhivehi the longer strings wrapped one line further and the
    // column overflowed its box by 28px (owner, 2026-08-21).
    //
    // A Stack sizes itself to the taller column with no guessing, and the
    // rule is a 1px child pinned top-to-bottom over it.
    return ManfaaCard(
      child: LayoutBuilder(
        builder: (context, constraints) {
          const gutter = Gap.md * 2 + 1;
          final content = constraints.maxWidth - gutter;
          final availableWidth = content * 3 / 5;

          return Stack(
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // 3:2 — the reference's proportions.
                  SizedBox(width: availableWidth, child: available),
                  const SizedBox(width: gutter),
                  SizedBox(width: content * 2 / 5, child: pending),
                ],
              ),
              PositionedDirectional(
                start: availableWidth + Gap.md,
                top: 0,
                bottom: 0,
                child: Container(
                  width: 1,
                  color: theme.colorScheme.outlineVariant,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// The code + QR. One tap to fullscreen; the QR mirrors the web payload
/// exactly (bare 6-digit code, byte mode) so merchant scanners already read
/// it, and it stays dark-on-white in every theme.
class _CodeCard extends StatelessWidget {
  const _CodeCard({required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      onTap: () => context.push('/qr'),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const IconTile(
                      Icons.qr_code_2_rounded,
                      tint: ManfaaTint.violet,
                      size: 38,
                      iconSize: 20,
                    ),
                    const SizedBox(width: Gap.md),
                    Expanded(
                      child: Text(
                        l10n.yourCode,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: muted,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: Gap.md),
                Text(
                  '${code.substring(0, 3)} ${code.substring(3)}',
                  textDirection: TextDirection.ltr,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    letterSpacing: 2,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
                const SizedBox(height: Gap.xs),
                Text(
                  l10n.homeCodeHint,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
            ),
          ),
          const SizedBox(width: Gap.md),
          Container(
            padding: const EdgeInsets.all(Gap.sm),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(Corner.tile),
              border: Border.all(color: ManfaaColors.line),
            ),
            child: QrImageView(
              data: code,
              size: 80,
              padding: EdgeInsets.zero,
              backgroundColor: Colors.white,
            ),
          ),
        ],
      ),
    );
  }
}

/// Paid this month and the next payout window, merged into ONE card split by
/// a rule (Update 1 ref, 2026-08-21). They were two separate cards; they
/// answer the same question — "what has this earned me, and when do I see it"
/// — so they read better as one row with the payout rule underneath both.
class _StatsRow extends StatelessWidget {
  const _StatsRow({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';
    final green = tintColors(ManfaaTint.green, theme.brightness);

    final paid = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const IconTile(
              Icons.trending_up_rounded,
              tint: ManfaaTint.green,
              size: _statIcon,
              iconSize: _statGlyph,
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Text(
                l10n.paidThisMonth,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ),
          ],
        ),
        const SizedBox(height: Gap.sm),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: MoneyText(
            data.paidThisMonthLaari,
            style: theme.textTheme.titleLarge?.copyWith(color: green.fg),
          ),
        ),
      ],
    );

    final window = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const IconTile(
              Icons.calendar_today_rounded,
              tint: ManfaaTint.amber,
              size: _statIcon,
              iconSize: _statGlyph,
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Text(
                l10n.nextPayout,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ),
          ],
        ),
        const SizedBox(height: Gap.sm),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: Text(
            formatPayoutWindow(
              data.payoutWindowStart,
              data.payoutWindowEnd,
              dhivehi: dhivehi,
            ),
            // The month is Thaana in Dhivehi now, so the range follows the
            // reader instead of being pinned LTR — the same idiom MoneyText
            // uses, and what keeps "25 – 31" from reversing around the
            // neutral dash.
            textDirection: dhivehi ? TextDirection.rtl : TextDirection.ltr,
            style: theme.textTheme.titleLarge,
          ),
        ),
      ],
    );

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Same Stack idiom as the available/pending card above, and for the
          // same reason: IntrinsicHeight mis-measures wrapping text, which
          // overflowed this card in Dhivehi.
          LayoutBuilder(
            builder: (context, constraints) {
              const gutter = Gap.md * 2 + 1;
              final half = (constraints.maxWidth - gutter) / 2;

              return Stack(
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SizedBox(width: half, child: paid),
                      const SizedBox(width: gutter),
                      SizedBox(width: half, child: window),
                    ],
                  ),
                  PositionedDirectional(
                    start: half + Gap.md,
                    top: 0,
                    bottom: 0,
                    child: Container(
                      width: 1,
                      color: theme.colorScheme.outlineVariant,
                    ),
                  ),
                ],
              );
            },
          ),
          const SizedBox(height: Gap.lg),
          Divider(height: 1, color: theme.colorScheme.outlineVariant),
          const SizedBox(height: Gap.md),
          // The payout rule belongs to BOTH halves, so it sits under both
          // rather than being buried in the window column as it was.
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.info_outline_rounded, size: 16, color: muted),
              const SizedBox(width: Gap.sm),
              Expanded(
                child: Text(
                  l10n.minimumPayoutNote(
                    formatMoney(data.minimumPayoutLaari, dhivehi: dhivehi),
                  ),
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// "2026-08-25" + "2026-08-31" -> "Aug 25 – 31"; across a month boundary,
/// "Aug 25 – Sep 3" (owner, 2026-08-21: not the whole formatted date).
///
/// Month names come from [monthName], so a Dhivehi reader gets Thaana months
/// rather than the Latin ones intl falls back to. Unparseable input returns
/// whatever the API sent rather than showing nothing.
String formatPayoutWindow(
  String startIso,
  String endIso, {
  bool dhivehi = false,
}) {
  final start = DateTime.tryParse(startIso);
  final end = DateTime.tryParse(endIso);

  if (start == null || end == null) {
    final raw = [startIso, endIso].where((v) => v.isNotEmpty).join(' – ');
    return raw.isEmpty ? '' : raw;
  }

  final from = '${monthName(start.month, dhivehi: dhivehi)} ${start.day}';

  // Same month: the month is said once. That is the whole point of the
  // shorter form — "Aug 25 – 31", not "Aug 25 – Aug 31".
  if (start.year == end.year && start.month == end.month) {
    return '$from – ${end.day}';
  }

  return '$from – ${monthName(end.month, dhivehi: dhivehi)} ${end.day}';
}

class _ViewTransactionsButton extends StatelessWidget {
  const _ViewTransactionsButton();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.primary,
      borderRadius: BorderRadius.circular(Corner.control),
      child: InkWell(
        borderRadius: BorderRadius.circular(Corner.control),
        onTap: () => context.go('/activity'),
        child: SizedBox(
          height: 52,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
            child: Row(
              children: [
                Container(
                  width: 34,
                  height: 34,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: scheme.onPrimary.withValues(alpha: 0.4),
                    ),
                  ),
                  child: Icon(
                    Icons.arrow_forward_rounded,
                    size: 18,
                    color: scheme.onPrimary,
                  ),
                ),
                const SizedBox(width: Gap.md),
                Text(
                  context.l10n.viewTransactions,
                  style: Theme.of(context).textTheme.labelLarge?.copyWith(
                    color: scheme.onPrimary,
                    fontSize: 16,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _DiscoverOffersCard extends StatelessWidget {
  const _DiscoverOffersCard();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      onTap: () => context.go('/discover'),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const IconTile(Icons.local_offer_rounded, tint: ManfaaTint.coral),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        l10n.discoverOffersTitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleMedium,
                      ),
                    ),
                    const SizedBox(width: Gap.sm),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: tintColors(
                          ManfaaTint.green,
                          theme.brightness,
                        ).bg,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        l10n.badgeNew,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: tintColors(
                            ManfaaTint.green,
                            theme.brightness,
                          ).fg,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.discoverOffersBody,
                  style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                ),
              ],
            ),
          ),
          Icon(Icons.chevron_right_rounded, color: muted),
        ],
      ),
    );
  }
}

class _PayoutAccountNag extends StatelessWidget {
  const _PayoutAccountNag();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ManfaaCard(
      onTap: () => context.go('/profile/payout-account'),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const IconTile(Icons.account_balance_rounded, tint: ManfaaTint.amber),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.addPayoutAccountTitle,
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.addPayoutAccountBody,
                  style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                ),
              ],
            ),
          ),
          Icon(Icons.chevron_right_rounded, color: muted),
        ],
      ),
    );
  }
}

/// The code survives the network. Everything else can wait.
class _OfflineBody extends ConsumerWidget {
  const _OfflineBody({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final session = ref.watch(sessionProvider);
    final code = session.customerCode;

    return ListView(
      padding: const EdgeInsets.fromLTRB(
        Gap.lg,
        Gap.md,
        Gap.lg,
        Gap.navClearance,
      ),
      children: [
        ManfaaTopBar(
          initials: initialsFor(session.customerName ?? ''),
          avatarUrl: ref.watch(avatarUrlProvider),
          onAvatarTap: () => context.go('/profile'),
        ),
        const SizedBox(height: Gap.xl),
        if (code != null && code.isNotEmpty) ...[
          _CodeCard(code: code),
          const SizedBox(height: Gap.md),
          ManfaaCard(
            color: toneSurface(
              ToneSurface.pending,
              Theme.of(context).brightness,
            ).background,
            padding: const EdgeInsets.all(Gap.lg),
            child: Text(l10n.offlineCodeBanner),
          ),
          const SizedBox(height: Gap.lg),
        ],
        ManfaaCard(
          child: Column(
            children: [
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: Gap.lg),
              OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
            ],
          ),
        ),
      ],
    );
  }
}

/// The home layout's shape while /home loads.
class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(
        Gap.lg,
        Gap.md,
        Gap.lg,
        Gap.navClearance,
      ),
      children: const [
        SkeletonBox(height: 46, width: 160),
        SizedBox(height: Gap.xl),
        SkeletonBox(height: 30, width: 220),
        SizedBox(height: Gap.xl),
        SkeletonBox(height: 150, radius: Corner.card),
        SizedBox(height: Gap.lg),
        SkeletonBox(height: 150, radius: Corner.card),
        SizedBox(height: Gap.lg),
        SkeletonBox(height: 120, radius: Corner.card),
      ],
    );
  }
}
