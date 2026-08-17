import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import '../push/push_registrar.dart';

/// Home, ordered by till-queue reality — the deliberate inversion of the
/// web dashboard this must NOT be a port of:
///
///   1. THE CODE IS THE HERO. It is used on every purchase; the balance is
///      read occasionally; "paid this month" almost never. The web's order
///      is the reverse, and that order was the mockups' biggest miss.
///   2. Confirmed is the headline figure and pending is separate,
///      conditional money (§10 — non-negotiable, same as everywhere else).
///   3. Day one teaches instead of showing a giant MVR 0.00.
///   4. Offline still shows the code — it is cached in the session store,
///      because a shop with no signal is exactly where the code is needed.
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
    final l10n = context.l10n;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.appTitle)),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(homeProvider.future),
        child: home.when(
          data: (data) => _HomeBody(data: data),
          // The final layout's SHAPE, pulsing — content swaps in without a
          // jump, and nothing spins.
          loading: () => const _HomeSkeleton(),
          // Offline path: the code card still renders from the session
          // cache. The API's ETag layer makes the happy path cheap; this
          // branch makes the unhappy one useful.
          error: (e, _) => _OfflineBody(
            message: e is MobileApiException ? e.message : l10n.errorGeneric,
            onRetry: () => ref.invalidate(homeProvider),
          ),
        ),
      ),
    );
  }
}

class _HomeBody extends ConsumerWidget {
  const _HomeBody({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        Text(
          l10n.homeGreeting(data.customerName.split(' ').first),
          style: theme.textTheme.headlineMedium,
        ),
        const SizedBox(height: Gap.lg),
        _CodeHeroCard(code: data.customerCode),
        const SizedBox(height: Gap.md),
        if (data.confirmedLaari == 0 &&
            data.pendingLaari == 0 &&
            data.paidThisMonthLaari == 0)
          _StartEarningCard()
        else
          _BalanceCard(data: data),
        const SizedBox(height: Gap.md),
        if (!data.hasPayoutAccount) const _PayoutAccountNag(),
        _PayoutWindowCard(data: data),
      ],
    );
  }
}

/// The reason the app exists at a till. Big, tappable, one gesture from
/// fullscreen; the QR mirrors the web's payload exactly (the bare 6-digit
/// code, byte mode) so the merchant scanner already reads it.
class _CodeHeroCard extends StatelessWidget {
  const _CodeHeroCard({required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Card(
      color: ManfaaColors.rose600,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Semantics(
        button: true,
        label: l10n.a11yShowQr,
        child: InkWell(
          borderRadius: BorderRadius.circular(Corner.card),
          onTap: () => context.push('/qr'),
          child: Padding(
            padding: const EdgeInsets.all(Gap.xl),
            child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.yourCode,
                      style: theme.textTheme.labelLarge
                          ?.copyWith(color: ManfaaColors.rose100),
                    ),
                    const SizedBox(height: Gap.sm),
                    Text(
                      '${code.substring(0, 3)} ${code.substring(3)}',
                      textDirection: TextDirection.ltr,
                      style: theme.textTheme.displaySmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 2,
                      ),
                    ),
                    const SizedBox(height: Gap.sm),
                    Text(
                      l10n.tapForFullscreen,
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: ManfaaColors.rose100),
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.all(Gap.sm),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                // QR modules must stay dark-on-light in every theme or
                // scanners fail — hence the hard white plate.
                child: QrImageView(
                  data: code,
                  size: 92,
                  padding: EdgeInsets.zero,
                  backgroundColor: Colors.white,
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

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Gap.xl),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              l10n.availableCashback,
              style: theme.textTheme.labelLarge
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: Gap.xs),
            // The HEADLINE figure. Pending never joins it (§10).
            MoneyText(
              data.confirmedLaari,
              style: theme.textTheme.displaySmall
                  ?.copyWith(color: ManfaaColors.confirmedGreen),
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.confirmedHint,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            if (data.pendingLaari > 0) ...[
              const SizedBox(height: Gap.lg),
              Builder(builder: (context) {
                final tone =
                    toneSurface(ToneSurface.pending, theme.brightness);
                return InkWell(
                  borderRadius: BorderRadius.circular(Corner.control),
                  onTap: () => context.go('/activity'),
                  child: Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(Gap.md),
                    decoration: BoxDecoration(
                      color: tone.background,
                      borderRadius: BorderRadius.circular(Corner.control),
                    ),
                    child: Text(
                      l10n.pendingLine(
                        formatMoney(data.pendingLaari, dhivehi: dhivehi),
                      ),
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: tone.foreground),
                    ),
                  ),
                );
              }),
            ],
          ],
        ),
      ),
    );
  }
}

/// Day one: teach, never a giant zero. (The nearest-stores hook lands with
/// the Discover round.)
class _StartEarningCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Gap.xl),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.storefront_rounded,
                    size: 40, color: ManfaaColors.confirmedGreen),
                const SizedBox(width: Gap.lg),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l10n.startEarningTitle,
                          style: theme.textTheme.titleLarge),
                      const SizedBox(height: Gap.xs),
                      Text(
                        l10n.startEarningBody,
                        style: theme.textTheme.bodyMedium?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: Gap.lg),
            // The teach-don't-zero card's call to action: day one leads
            // somewhere, not to a number.
            FilledButton.tonal(
              onPressed: () => context.go('/discover'),
              child: Text(l10n.browseStores),
            ),
          ],
        ),
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

    return Padding(
      padding: const EdgeInsets.only(bottom: Gap.md),
      child: Card(
        color: toneSurface(ToneSurface.info, Theme.of(context).brightness)
            .background,
        child: ListTile(
          onTap: () => context.go('/profile/payout-account'),
          leading: Icon(Icons.account_balance_rounded,
              color: toneSurface(
                      ToneSurface.info, Theme.of(context).brightness)
                  .foreground),
          title: Text(l10n.addPayoutAccountTitle,
              style: theme.textTheme.titleMedium),
          subtitle: Text(l10n.addPayoutAccountBody),
          trailing: const Icon(Icons.chevron_right_rounded),
        ),
      ),
    );
  }
}

class _PayoutWindowCard extends StatelessWidget {
  const _PayoutWindowCard({required this.data});

  final HomeData data;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final theme = Theme.of(context);
    final dhivehi = Localizations.localeOf(context).languageCode == 'dv';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Gap.xl),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.event_rounded,
                    size: 18, color: ManfaaColors.infoSky),
                const SizedBox(width: Gap.sm),
                Text(l10n.nextPayout, style: theme.textTheme.labelLarge),
              ],
            ),
            const SizedBox(height: Gap.sm),
            Text(
              '${data.payoutWindowStart} – ${data.payoutWindowEnd}',
              textDirection: TextDirection.ltr,
              style: theme.textTheme.titleLarge,
            ),
            const SizedBox(height: Gap.xs),
            Text(
              l10n.minimumPayoutNote(
                formatMoney(data.minimumPayoutLaari, dhivehi: dhivehi),
              ),
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
        ),
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
    final code = ref.watch(sessionProvider).customerCode;

    return ListView(
      padding: const EdgeInsets.all(Gap.lg),
      children: [
        if (code != null && code.isNotEmpty) ...[
          _CodeHeroCard(code: code),
          const SizedBox(height: Gap.md),
          Card(
            color: toneSurface(ToneSurface.pending, Theme.of(context).brightness)
                .background,
            child: Padding(
              padding: const EdgeInsets.all(Gap.md),
              child: Text(l10n.offlineCodeBanner),
            ),
          ),
          const SizedBox(height: Gap.md),
        ],
        Card(
          child: Padding(
            padding: const EdgeInsets.all(Gap.xl),
            child: Column(
              children: [
                Text(message, textAlign: TextAlign.center),
                const SizedBox(height: Gap.lg),
                OutlinedButton(onPressed: onRetry, child: Text(l10n.retry)),
              ],
            ),
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
      padding: const EdgeInsets.all(Gap.lg),
      children: const [
        SkeletonBox(height: 32, width: 180),
        SizedBox(height: Gap.lg),
        SkeletonBox(height: 148, radius: Corner.card),
        SizedBox(height: Gap.md),
        SkeletonBox(height: 150, radius: Corner.card),
        SizedBox(height: Gap.md),
        SkeletonBox(height: 96, radius: Corner.card),
      ],
    );
  }
}
