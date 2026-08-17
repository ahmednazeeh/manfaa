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
    Future.microtask(
      () => ref.read(pushRegistrarProvider).ensureRegistered(),
    );
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
              message: e is MobileApiException ? e.message : context.l10n.errorGeneric,
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

    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.navClearance),
      children: [
        ManfaaTopBar(
          initials: initialsFor(data.customerName),
          avatarUrl: ref.watch(avatarUrlProvider),
          onAvatarTap: () => context.go('/profile'),
        ),
        const SizedBox(height: Gap.lg),
        Row(
          children: [
            Flexible(
              child: Text(
                l10n.homeGreeting(data.customerName),
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
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
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

/// Available (confirmed) cashback is the headline figure; pending is a
/// separate, conditional line below a divider — the two never sum (§10).
class _AvailableCard extends StatelessWidget {
  const _AvailableCard({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return ManfaaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const IconTile(Icons.account_balance_wallet_outlined,
                  tint: ManfaaTint.green),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(l10n.availableCashback,
                        style: theme.textTheme.bodyMedium?.copyWith(color: muted)),
                    const SizedBox(height: 2),
                    MoneyText(
                      data.confirmedLaari,
                      style: theme.textTheme.headlineMedium
                          ?.copyWith(color: theme.colorScheme.onSurface),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          Text(l10n.confirmedHint,
              style: theme.textTheme.bodyMedium?.copyWith(color: muted)),
          if (data.pendingLaari > 0) ...[
            const SizedBox(height: Gap.lg),
            Divider(color: theme.colorScheme.outlineVariant),
            const SizedBox(height: Gap.lg),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const IconTile(Icons.schedule_rounded,
                    tint: ManfaaTint.blue, size: 38, iconSize: 20),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Text(
                    l10n.pendingLine(
                        formatMoney(data.pendingLaari, dhivehi: dhivehi)),
                    style: theme.textTheme.bodyMedium?.copyWith(color: muted),
                  ),
                ),
              ],
            ),
          ],
        ],
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
                    const IconTile(Icons.qr_code_2_rounded,
                        tint: ManfaaTint.violet, size: 38, iconSize: 20),
                    const SizedBox(width: Gap.md),
                    Expanded(
                      child: Text(l10n.yourCode,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium
                              ?.copyWith(color: muted)),
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
                Text(l10n.homeCodeHint,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted)),
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

class _StatsRow extends StatelessWidget {
  const _StatsRow({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: ManfaaCard(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const IconTile(Icons.trending_up_rounded,
                      tint: ManfaaTint.blue, size: 38, iconSize: 20),
                  const SizedBox(height: Gap.md),
                  Text(l10n.paidThisMonth,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted)),
                  const SizedBox(height: 2),
                  MoneyText(
                    data.paidThisMonthLaari,
                    style: theme.textTheme.titleLarge,
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(width: Gap.lg),
          Expanded(
            child: ManfaaCard(
              padding: const EdgeInsets.all(Gap.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const IconTile(Icons.calendar_today_rounded,
                      tint: ManfaaTint.amber, size: 38, iconSize: 20),
                  const SizedBox(height: Gap.md),
                  Text(l10n.nextPayout,
                      style: theme.textTheme.bodySmall?.copyWith(color: muted)),
                  const SizedBox(height: 2),
                  Text(
                    '${data.payoutWindowStart} – ${data.payoutWindowEnd}',
                    textDirection: TextDirection.ltr,
                    style: theme.textTheme.titleMedium,
                  ),
                  const SizedBox(height: Gap.sm),
                  Text(
                    l10n.minimumPayoutNote(
                        formatMoney(data.minimumPayoutLaari, dhivehi: dhivehi)),
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
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
                        color: scheme.onPrimary.withValues(alpha: 0.4)),
                  ),
                  child: Icon(Icons.arrow_forward_rounded,
                      size: 18, color: scheme.onPrimary),
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
                      child: Text(l10n.discoverOffersTitle,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleMedium),
                    ),
                    const SizedBox(width: Gap.sm),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: tintColors(ManfaaTint.green, theme.brightness).bg,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        l10n.badgeNew,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color:
                              tintColors(ManfaaTint.green, theme.brightness).fg,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(l10n.discoverOffersBody,
                    style: theme.textTheme.bodyMedium?.copyWith(color: muted)),
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
                Text(l10n.addPayoutAccountTitle,
                    style: theme.textTheme.titleMedium),
                const SizedBox(height: 2),
                Text(l10n.addPayoutAccountBody,
                    style: theme.textTheme.bodyMedium?.copyWith(color: muted)),
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
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.navClearance),
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
            color: toneSurface(ToneSurface.pending, Theme.of(context).brightness)
                .background,
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
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.navClearance),
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
