import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:geolocator/geolocator.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/providers.dart';
import 'checkout_screen.dart' show addressesProvider;
import 'market_providers.dart';

/// The islands an admin has drawn. Typed free-hand, an island name never
/// matches a delivery rule and the order silently cannot be quoted — so it
/// is chosen, not typed.
final zonesProvider = FutureProvider<List<ZoneEntry>>((ref) {
  return ref.watch(apiProvider).zones();
});

/// Delivery details — step one of checkout (`Delivery Details Step.png`).
///
/// A saved address is chosen, or a new one is written. The screen exists at
/// all because the app had no way to add an address: checkout pushed
/// `/market/addresses/new`, a route nobody had registered, and GoRouter
/// threw. A checkout that cannot take an address cannot take an order.
///
/// The address chosen here applies to EVERY delivery-only store in the
/// basket, which the footer note says out loud — a shopper who thinks they
/// are addressing one shop would be surprised by the other two.
class AddressStepScreen extends ConsumerStatefulWidget {
  const AddressStepScreen({super.key});

  @override
  ConsumerState<AddressStepScreen> createState() => _AddressStepScreenState();
}

class _AddressStepScreenState extends ConsumerState<AddressStepScreen> {
  final _form = GlobalKey<FormState>();
  final _recipient = TextEditingController();
  final _phone = TextEditingController();
  int? _zoneId;
  final _area = TextEditingController();
  final _building = TextEditingController();
  final _apartment = TextEditingController();
  final _note = TextEditingController();

  String _label = 'Home';
  bool _adding = false;
  bool _saving = false;
  bool _locating = false;
  double? _lat;
  double? _lng;

  @override
  void dispose() {
    for (final controller in [
      _recipient,
      _phone,
      _area,
      _building,
      _apartment,
      _note,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final addresses = ref.watch(addressesProvider);
    final chosen = ref.watch(marketAddressProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Delivery details')),
      body: addresses.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Center(child: Text(error.toString())),
        data: (rows) => ListView(
          padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.huge),
          children: [
            Text(
              'Choose a saved address or add a new one for this order.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: ManfaaColors.textMuted,
              ),
            ),
            const SizedBox(height: Gap.lg),
            const _Steps(active: 0),
            const SizedBox(height: Gap.xl),

            if (rows.isNotEmpty) ...[
              Text('Saved addresses', style: theme.textTheme.titleMedium),
              const SizedBox(height: Gap.md),
              for (final row in rows) ...[
                _SavedAddress(
                  address: row,
                  selected: chosen == row.id,
                  onTap: () =>
                      ref.read(marketAddressProvider.notifier).state = row.id,
                ),
                const SizedBox(height: Gap.md),
              ],
            ],

            const SizedBox(height: Gap.sm),
            if (!_adding)
              OutlinedButton.icon(
                onPressed: () => setState(() => _adding = true),
                icon: const Icon(Icons.add_rounded),
                label: const Text('Add new address'),
              )
            else
              _NewAddressForm(
                formKey: _form,
                label: _label,
                onLabel: (value) => setState(() => _label = value),
                recipient: _recipient,
                phone: _phone,
                zones: ref.watch(zonesProvider),
                zoneId: _zoneId,
                onZone: (value) => setState(() => _zoneId = value),
                onLocate: _useMyLocation,
                locating: _locating,
                area: _area,
                building: _building,
                apartment: _apartment,
                note: _note,
              ),

            const SizedBox(height: Gap.lg),
            Container(
              padding: const EdgeInsets.all(Gap.md),
              decoration: BoxDecoration(
                color: ManfaaColors.greenSoft,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.info_outline_rounded,
                    size: 18,
                    color: ManfaaColors.green,
                  ),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: Text(
                      'This address will be used for all delivery-only '
                      'stores in your cart.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(Gap.lg),
          child: FilledButton(
            onPressed: _saving ? null : () => _next(chosen),
            child: _saving
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Next'),
          ),
        ),
      ),
    );
  }

  Future<void> _next(int? chosen) async {
    // An address already chosen and nothing being typed: straight on.
    if (!_adding && chosen != null) {
      context.pop();

      return;
    }

    if (!_adding) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Choose an address, or add a new one.')),
      );

      return;
    }

    if (!(_form.currentState?.validate() ?? false)) return;

    if (_zoneId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Choose the island you are on.')),
      );

      return;
    }

    setState(() => _saving = true);

    try {
      final saved = await ref.read(apiProvider).saveAddress({
        'label': _label,
        'recipient_name': _recipient.text.trim(),
        'phone': _phone.text.trim(),
        // The zone's own name, so what we store matches what delivery
        // rules are written against.
        'island': _islandName(),
        'zone_id': _zoneId,
        'area_magu': _area.text.trim(),
        'building': _building.text.trim(),
        'apartment_floor': _apartment.text.trim(),
        'delivery_note': _note.text.trim(),
        // A PIN when we have one: delivery is quoted against the ZONE the
        // pin falls in, not against a typed name.
        'lat': _lat,
        'lng': _lng,
      });

      // Chosen immediately: somebody who just typed an address means to use
      // it, and making them tap it again is a step for its own sake.
      ref.read(marketAddressProvider.notifier).state = saved.id;
      ref.invalidate(addressesProvider);

      if (mounted) context.pop();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(_messageFor(error))));
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  String _islandName() {
    final zones = ref.read(zonesProvider).valueOrNull ?? const <ZoneEntry>[];

    return zones.where((zone) => zone.id == _zoneId).firstOrNull?.name ?? '';
  }

  /// Drop a pin where the phone is. Permission is asked for at the moment it
  /// is needed and refusing it costs nothing — the form still works, it just
  /// has no coordinates.
  Future<void> _useMyLocation() async {
    setState(() => _locating = true);

    try {
      var permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text(
                'Location is off for Manfaa. You can still type the address.',
              ),
            ),
          );
        }

        return;
      }

      final position = await Geolocator.getCurrentPosition();

      setState(() {
        _lat = position.latitude;
        _lng = position.longitude;
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Location pinned to this address.')),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Could not read your location just now.'),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  static String _messageFor(Object error) =>
      error is MobileApiException && error.message.isNotEmpty
      ? error.message
      : 'That address could not be saved. Try again.';
}

/// Address → Delivery → Review, as the ref draws it.
class _Steps extends StatelessWidget {
  const _Steps({required this.active});

  final int active;

  static const _labels = ['Address', 'Delivery', 'Review'];
  static const _icons = [
    Icons.place_outlined,
    Icons.local_shipping_outlined,
    Icons.receipt_long_outlined,
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.md),
      decoration: BoxDecoration(
        color: ManfaaColors.surface,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: ManfaaColors.line),
      ),
      child: Row(
        children: [
          for (var i = 0; i < _labels.length; i++) ...[
            if (i > 0) const Expanded(child: Divider(color: ManfaaColors.line)),
            Icon(
              _icons[i],
              size: 18,
              color: i == active
                  ? theme.colorScheme.primary
                  : ManfaaColors.textFaint,
            ),
            const SizedBox(width: 6),
            Text(
              _labels[i],
              style: theme.textTheme.labelLarge?.copyWith(
                color: i == active
                    ? theme.colorScheme.primary
                    : ManfaaColors.textFaint,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _SavedAddress extends StatelessWidget {
  const _SavedAddress({
    required this.address,
    required this.selected,
    required this.onTap,
  });

  final CustomerAddressEntry address;
  final bool selected;
  final VoidCallback onTap;

  /// The ref gives each label its own icon and wash — a shopper picks by
  /// shape long before they read the words.
  (IconData, Color) get _mark => switch (address.label.toLowerCase()) {
    'home' => (Icons.home_rounded, ManfaaColors.greenSoft),
    'work' => (Icons.work_outline_rounded, ManfaaColors.blueSoft),
    _ => (Icons.apartment_rounded, ManfaaColors.violetSoft),
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (icon, wash) = _mark;

    final lines = [
      [
        address.building,
        address.apartmentFloor,
      ].whereType<String>().where((part) => part.trim().isNotEmpty).join(', '),
      [
        address.areaMagu,
        address.island,
      ].whereType<String>().where((part) => part.trim().isNotEmpty).join(', '),
    ].where((line) => line.isNotEmpty);

    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.all(Gap.md),
        decoration: BoxDecoration(
          color: ManfaaColors.surface,
          borderRadius: BorderRadius.circular(Corner.card),
          border: Border.all(
            color: selected ? theme.colorScheme.primary : ManfaaColors.line,
            width: selected ? 2 : 1,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding: const EdgeInsets.all(Gap.sm),
              decoration: BoxDecoration(
                color: wash,
                borderRadius: BorderRadius.circular(Corner.tile),
              ),
              child: Icon(icon, color: ManfaaColors.inkSoft),
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Text(address.label, style: theme.textTheme.titleMedium),
                      if (address.isDefault) ...[
                        const SizedBox(width: Gap.sm),
                        const StatusChip(
                          label: 'Default',
                          tone: StatusTone.confirmed,
                        ),
                      ],
                    ],
                  ),
                  Text(
                    '${address.recipientName}   ${address.phone}',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: ManfaaColors.textMuted,
                    ),
                  ),
                  const SizedBox(height: 2),
                  for (final line in lines)
                    Text(line, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
            Icon(
              selected
                  ? Icons.check_circle_rounded
                  : Icons.radio_button_unchecked_rounded,
              color: selected
                  ? theme.colorScheme.primary
                  : ManfaaColors.textFaint,
            ),
          ],
        ),
      ),
    );
  }
}

class _NewAddressForm extends StatelessWidget {
  const _NewAddressForm({
    required this.formKey,
    required this.label,
    required this.onLabel,
    required this.recipient,
    required this.phone,
    required this.zones,
    required this.zoneId,
    required this.onZone,
    required this.onLocate,
    required this.locating,
    required this.area,
    required this.building,
    required this.apartment,
    required this.note,
  });

  final GlobalKey<FormState> formKey;
  final String label;
  final ValueChanged<String> onLabel;
  final TextEditingController recipient;
  final TextEditingController phone;
  final AsyncValue<List<ZoneEntry>> zones;
  final int? zoneId;
  final ValueChanged<int?> onZone;
  final Future<void> Function() onLocate;
  final bool locating;
  final TextEditingController area;
  final TextEditingController building;
  final TextEditingController apartment;
  final TextEditingController note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    String? required(String? value) =>
        (value ?? '').trim().isEmpty ? 'Required' : null;

    return Form(
      key: formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Add new address', style: theme.textTheme.titleMedium),
          const SizedBox(height: Gap.md),
          Wrap(
            spacing: Gap.sm,
            children: [
              for (final option in ['Home', 'Work', 'Other'])
                ChoiceChip(
                  label: Text(option),
                  selected: label == option,
                  onSelected: (_) => onLabel(option),
                ),
            ],
          ),
          const SizedBox(height: Gap.md),
          TextFormField(
            controller: recipient,
            validator: required,
            decoration: const InputDecoration(labelText: 'Recipient name'),
          ),
          const SizedBox(height: Gap.md),
          TextFormField(
            controller: phone,
            validator: required,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'Phone'),
          ),
          const SizedBox(height: Gap.md),

          // Chosen from what the platform actually serves. A typed island
          // that matches no delivery rule makes an address quietly
          // undeliverable, and nothing on the screen would say why.
          zones.when(
            loading: () => const LinearProgressIndicator(minHeight: 2),
            error: (_, _) => const Text(
              'Islands could not be loaded. Try again in a moment.',
            ),
            data: (rows) => DropdownButtonFormField<int>(
              initialValue: zoneId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Island / City'),
              items: [
                for (final zone in rows)
                  DropdownMenuItem(value: zone.id, child: Text(zone.name)),
              ],
              onChanged: onZone,
              validator: (value) => value == null ? 'Choose an island' : null,
            ),
          ),

          const SizedBox(height: Gap.md),
          OutlinedButton.icon(
            onPressed: locating ? null : onLocate,
            icon: locating
                ? const SizedBox(
                    height: 16,
                    width: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.my_location_rounded, size: 18),
            label: Text(locating ? 'Finding you…' : 'Use my location'),
          ),
          const SizedBox(height: Gap.xs),
          Text(
            'Pinning your spot helps the shop find you, and decides which '
            'delivery rules apply.',
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(color: ManfaaColors.textMuted),
          ),
          const SizedBox(height: Gap.md),
          TextFormField(
            controller: area,
            decoration: const InputDecoration(labelText: 'Area / Magu'),
          ),
          const SizedBox(height: Gap.md),
          TextFormField(
            controller: building,
            validator: required,
            decoration: const InputDecoration(
              labelText: 'Building / House name',
            ),
          ),
          const SizedBox(height: Gap.md),
          TextFormField(
            controller: apartment,
            decoration: const InputDecoration(labelText: 'Apartment / Floor'),
          ),
          const SizedBox(height: Gap.md),
          TextFormField(
            controller: note,
            decoration: const InputDecoration(
              labelText: 'Delivery note (optional)',
              hintText: 'e.g. Call when you arrive',
            ),
          ),
        ],
      ),
    );
  }
}
