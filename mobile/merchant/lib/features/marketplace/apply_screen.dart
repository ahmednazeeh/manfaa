import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'marketplace_providers.dart';
import 'marketplace_widgets.dart';

/// Applying to sell on the marketplace, from the phone.
///
/// This did not exist. A store that had not enrolled was shown a card saying
/// enrolment happens on a desktop portal — which is an answer, not a door,
/// and a shopkeeper whose whole business runs off this app had no way to
/// join from it.
///
/// The application is four answers. The KYB papers are photographs, which a
/// phone is better at than a desktop, so they belong here too — but they
/// stay on the panel for now and the screen says so plainly rather than
/// pretending.
class MarketplaceApplyScreen extends ConsumerStatefulWidget {
  const MarketplaceApplyScreen({super.key});

  @override
  ConsumerState<MarketplaceApplyScreen> createState() =>
      _MarketplaceApplyScreenState();
}

class _MarketplaceApplyScreenState
    extends ConsumerState<MarketplaceApplyScreen> {
  String _businessType = 'sole_prop';
  String _fulfilment = 'both';
  final _prepMin = TextEditingController(text: '30');
  final _prepMax = TextEditingController(text: '60');
  bool _saving = false;

  @override
  void dispose() {
    _prepMin.dispose();
    _prepMax.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final state = ref.watch(shopEnrolmentProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Sell on the marketplace')),
      body: state.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Padding(
          padding: const EdgeInsets.all(Gap.lg),
          child: ErrorNote(error: error),
        ),
        data: (enrolment) => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.lg, Gap.lg, Gap.huge),
          children: [
            _Status(state: enrolment),
            const SizedBox(height: Gap.lg),

            if (enrolment == 'not_enrolled' || enrolment == 'rejected') ...[
              Text('About your store', style: theme.textTheme.titleMedium),
              const SizedBox(height: Gap.md),

              _Choice(
                label: 'Business type',
                value: _businessType,
                options: const [
                  ('sole_prop', 'Sole proprietorship'),
                  ('partnership', 'Partnership'),
                  ('pvt_ltd', 'Private limited'),
                  ('cooperative', 'Cooperative'),
                ],
                onChanged: (value) => setState(() => _businessType = value),
              ),
              const SizedBox(height: Gap.md),

              _Choice(
                label: 'How will you fulfil orders?',
                value: _fulfilment,
                options: const [
                  ('both', 'Delivery and pickup'),
                  ('delivery', 'Delivery only'),
                  ('pickup', 'Pickup only'),
                ],
                onChanged: (value) => setState(() => _fulfilment = value),
              ),
              const SizedBox(height: Gap.md),

              Text(
                'How long do you need to get an order ready?',
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: Gap.sm),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _prepMin,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'From (minutes)',
                      ),
                    ),
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: TextField(
                      controller: _prepMax,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'To (minutes)',
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Gap.sm),
              Text(
                'Shoppers see this as your delivery estimate, so give '
                'yourself room on a busy day.',
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: ManfaaColors.textMuted),
              ),

              const SizedBox(height: Gap.xl),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _saving ? null : _apply,
                  child: Text(_saving ? 'Saving…' : 'Start my application'),
                ),
              ),
              const SizedBox(height: Gap.md),
              const DesktopHint(
                title: 'Registration papers are uploaded on desktop.',
                body: 'Once your details are saved, open the Manfaa Merchant '
                    'portal to add your business registration and ID, then '
                    'submit for review.',
              ),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _apply() async {
    setState(() => _saving = true);

    try {
      await ref.read(apiProvider).applyToMarketplace(
            businessType: _businessType,
            fulfilment: _fulfilment,
            prepTimeMin: int.tryParse(_prepMin.text.trim()),
            prepTimeMax: int.tryParse(_prepMax.text.trim()),
          );

      ref.invalidate(shopEnrolmentProvider);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Saved. Add your papers on the portal, then submit for review.',
            ),
          ),
        );
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(messageFor(error))));
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }
}

/// Where the application stands, in words a shopkeeper can act on.
class _Status extends StatelessWidget {
  const _Status({required this.state});

  final String state;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final (title, body, tone) = switch (state) {
      'active' => (
          'You sell on the marketplace',
          'Your orders appear on the Orders tab.',
          ManfaaColors.greenSoft,
        ),
      'submitted' => (
          'Your application is with Manfaa',
          'We are checking your papers. You will be told either way.',
          ManfaaColors.blueSoft,
        ),
      'draft' => (
          'Application started',
          'Add your registration papers on the portal and submit for review.',
          ManfaaColors.amberSoft,
        ),
      'rejected' => (
          'Application was not accepted',
          'You can correct the details below and apply again.',
          ManfaaColors.coralSoft,
        ),
      'suspended' => (
          'Selling is paused',
          'Contact Manfaa to have your store reinstated.',
          ManfaaColors.coralSoft,
        ),
      _ => (
          'Sell on the Manfaa marketplace',
          'Shoppers find your products by searching every store at once. '
              'Applying takes a minute.',
          ManfaaColors.greenSoft,
        ),
    };

    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(
        color: tone,
        borderRadius: BorderRadius.circular(Corner.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(body, style: theme.textTheme.bodyMedium),
        ],
      ),
    );
  }
}

class _Choice extends StatelessWidget {
  const _Choice({
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
  });

  final String label;
  final String value;
  final List<(String, String)> options;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: Gap.sm),
        Wrap(
          spacing: Gap.sm,
          runSpacing: Gap.sm,
          children: [
            for (final (key, text) in options)
              ChoiceChip(
                label: Text(text),
                selected: key == value,
                onSelected: (_) => onChanged(key),
              ),
          ],
        ),
      ],
    );
  }
}
