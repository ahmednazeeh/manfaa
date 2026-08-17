import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_ui/manfaa_ui.dart';

import '../../app/app.dart';
import '../../app/providers.dart';
import 'store_widgets.dart';

/// Directory search: a name needle, or a curated category from the rail.
/// Debounced so a typing thumb costs one request, not one per keystroke —
/// the endpoint is public and throttled per IP, and the budget is shared
/// with everything else the storefront does.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key, this.category});

  final String? category;

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _query = TextEditingController();
  Timer? _debounce;

  DirectoryPage? _results;
  var _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // A category tap arrives pre-filtered; run it immediately.
    if (widget.category != null) _search();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _query.dispose();
    super.dispose();
  }

  void _onChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _search);
  }

  Future<void> _search() async {
    final q = _query.text.trim();

    // The API refuses needles under 2 chars; a category browse needs none.
    if (q.length < 2 && widget.category == null) {
      setState(() => _results = null);
      return;
    }

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final page = await ref.read(apiProvider).directory(
            q: q.length >= 2 ? q : null,
            category: widget.category,
          );
      if (mounted) setState(() => _results = page);
    } on MobileApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.searchTitle)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(Gap.lg),
            // The Hero pairs with Discover's search icon: the tap morphs the
            // small circle into this field.
            child: Hero(
              tag: 'discover-search',
              child: Material(
                type: MaterialType.transparency,
                child: TextField(
                  controller: _query,
                  autofocus: widget.category == null,
                  onChanged: _onChanged,
                  decoration: InputDecoration(
                    hintText: l10n.searchHint,
                    prefixIcon: Icon(
                      Icons.search_rounded,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
              ),
            ),
          ),
          Expanded(child: _body(l10n)),
        ],
      ),
    );
  }

  /// A centred hint state — the violet icon tile leading, like every other
  /// empty state in the brand system.
  Widget _hint(String message) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Gap.huge),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const IconTile(Icons.search_rounded,
                tint: ManfaaTint.violet, size: 56, iconSize: 28),
            const SizedBox(height: Gap.lg),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }

  Widget _body(dynamic l10n) {
    if (_loading && _results == null) {
      return ListView(
        padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
        children: const [
          SkeletonBox(height: 80, radius: Corner.card),
          SizedBox(height: Gap.sm),
          SkeletonBox(height: 80, radius: Corner.card),
        ],
      );
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(Gap.huge),
          child: Text(_error!, textAlign: TextAlign.center),
        ),
      );
    }

    final results = _results;
    if (results == null) {
      return _hint(l10n.searchPrompt);
    }

    if (results.stores.isEmpty) {
      return _hint(l10n.searchEmpty);
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.xxl),
      itemCount: results.stores.length,
      separatorBuilder: (_, _) => const SizedBox(height: Gap.sm),
      itemBuilder: (context, index) => StoreRow(store: results.stores[index]),
    );
  }
}
