import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// Cursor-paged list state: the client half of the API's keyset paging.
/// The cursor anchors on the last row SEEN, so a sale landing at the top
/// between pages can never duplicate or skip a row — which is the whole
/// reason the API pages this way.
class PagedState<T> {
  const PagedState({
    this.items = const [],
    this.loading = false,
    this.loadingMore = false,
    this.error,
    this.nextCursor,
    this.hasMore = false,
    this.loaded = false,
  });

  final List<T> items;
  final bool loading;
  final bool loadingMore;
  final String? error;
  final String? nextCursor;
  final bool hasMore;
  final bool loaded;

  PagedState<T> copyWith({
    List<T>? items,
    bool? loading,
    bool? loadingMore,
    String? error,
    String? nextCursor,
    bool? hasMore,
    bool? loaded,
    bool clearError = false,
  }) => PagedState(
    items: items ?? this.items,
    loading: loading ?? this.loading,
    loadingMore: loadingMore ?? this.loadingMore,
    error: clearError ? null : (error ?? this.error),
    nextCursor: nextCursor ?? this.nextCursor,
    hasMore: hasMore ?? this.hasMore,
    loaded: loaded ?? this.loaded,
  );
}

class Pager<T> extends StateNotifier<PagedState<T>> {
  Pager(this._fetch) : super(const PagedState()) {
    refresh();
  }

  final Future<CursorPage<T>> Function({String? cursor}) _fetch;

  Future<void> refresh() async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final page = await _fetch();
      state = PagedState(
        items: page.items,
        nextCursor: page.nextCursor,
        hasMore: page.hasMore,
        loaded: true,
      );
    } catch (e) {
      state = state.copyWith(
        loading: false,
        loaded: true,
        error: e is MobileApiException ? e.message : '',
      );
    }
  }

  Future<void> loadMore() async {
    if (state.loadingMore || !state.hasMore) return;

    state = state.copyWith(loadingMore: true);
    try {
      final page = await _fetch(cursor: state.nextCursor);
      state = state.copyWith(
        items: [...state.items, ...page.items],
        nextCursor: page.nextCursor,
        hasMore: page.hasMore,
        loadingMore: false,
      );
    } catch (_) {
      // A failed page-load is not a failed screen: keep what we have and
      // let the next scroll retry.
      state = state.copyWith(loadingMore: false);
    }
  }
}
