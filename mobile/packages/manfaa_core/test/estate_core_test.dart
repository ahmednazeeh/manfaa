import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// MR5 wire-shape tests: the management-estate half of MerchantApi — staff,
/// the served permission catalogue, roles, branches, promotions. Same law
/// as every core test: shapes mirror the PHP controllers exactly, money is
/// integer laari, percents are EXACT strings, refusal codes are surfaced
/// through the one mobile envelope.
class _RecordingAdapter implements HttpClientAdapter {
  _RecordingAdapter(this._respond);

  final ResponseBody Function(RequestOptions options) _respond;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return _respond(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Object payload, int status) => ResponseBody.fromString(
      jsonEncode(payload),
      status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );

MerchantApi _api(_RecordingAdapter adapter) {
  final api = MerchantApi(session: MerchantSession(MemorySecretStore()));
  api.dio.httpClientAdapter = adapter;
  return api;
}

/// The exact MerchantStaffResource shape — the role is an OBJECT summary,
/// never a name string.
const _staffRow = {
  'id': 4,
  'name': 'Mariyam Shifa',
  'email': 'shifa@tropicalmart.mv',
  'role': {'id': 2, 'name': 'Cashier', 'name_dv': null, 'is_owner': false},
  'is_active': true,
  'created_at': '2026-08-01T09:00:00+00:00',
};

/// The exact MerchantRoleResource shape — resolved permissions, staff count.
const _roleRow = {
  'id': 2,
  'name': 'Cashier',
  'name_dv': 'ކޭޝިއަރ',
  'slug': 'staff',
  'is_owner': false,
  'is_system': true,
  'permissions': ['credits.create', 'customers.lookup', 'rate.view'],
  'staff_count': 3,
  'created_at': '2026-07-01T09:00:00+00:00',
  'updated_at': '2026-08-01T09:00:00+00:00',
};

/// The exact PromotionResource shape — RateResource::tryDescribe spread at
/// the root, business-timezone timestamps, integer laari.
const _promotionRow = {
  'cashback_rate_percent': '7.50',
  'platform_fee_percent': '1.25',
  'all_in_percent': '8.75',
  'id': 11,
  'merchant_id': 7,
  'branch_id': null,
  'status': 'draft',
  'is_live': false,
  'starts_at': '2026-08-20T10:00:00+05:00',
  'ends_at': '2026-08-22T22:00:00+05:00',
  'min_purchase_laari': 10000,
  'max_cashback_per_customer_laari': 50000,
  'published_at': null,
  'cancelled_at': null,
};

const _costPreview = {
  'promo': {
    'cashback_rate_percent': '7.50',
    'platform_fee_percent': '1.25',
    'all_in_percent': '8.75',
  },
  'standing': {
    'cashback_rate_percent': '2.00',
    'platform_fee_percent': '0.75',
    'all_in_percent': '2.75',
  },
  'all_in_delta_percent': '6.00',
  'tier_changed': true,
};

void main() {
  group('staff', () {
    test('GET parses the exact MerchantStaffResource collection', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': [
            _staffRow,
            {
              'id': 1,
              'name': 'Aminath Waheedha',
              'email': 'a@tropicalmart.mv',
              'role': {
                'id': 1,
                'name': 'Owner',
                'name_dv': null,
                'is_owner': true,
              },
              'is_active': false,
              'created_at': null,
            },
          ],
        }, 200),
      );

      final staff = await _api(adapter).staff();

      expect(adapter.requests.single.method, 'GET');
      expect(adapter.requests.single.path, '/merchant/staff');
      expect(staff, hasLength(2));
      expect(staff.first.id, 4);
      expect(staff.first.email, 'shifa@tropicalmart.mv');
      expect(staff.first.role?.id, 2);
      expect(staff.first.role?.name, 'Cashier');
      expect(staff.first.role?.isOwner, isFalse);
      expect(staff.first.isActive, isTrue);
      expect(staff.last.role?.isOwner, isTrue);
      expect(staff.last.isActive, isFalse);
    });

    test('invite POSTs the role id and parses the ONE-TIME temp password '
        'beside (not inside) the resource', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': _staffRow,
          'temp_password': 'q8#Vt2!mXw9zL0pR4sBd',
        }, 201),
      );

      final result = await _api(adapter).inviteStaff(
        name: 'Mariyam Shifa',
        email: 'shifa@tropicalmart.mv',
        merchantRoleId: 2,
      );

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/merchant/staff');
      expect(request.data, {
        'name': 'Mariyam Shifa',
        'email': 'shifa@tropicalmart.mv',
        'merchant_role_id': 2,
      });
      expect(result.staff.id, 4);
      expect(result.tempPassword, 'q8#Vt2!mXw9zL0pR4sBd');
    });

    test('PATCH sends ONLY the given keys — sometimes semantics', () async {
      final adapter = _RecordingAdapter((_) => _json({'data': _staffRow}, 200));

      await _api(adapter).updateStaff(4, isActive: false);

      final request = adapter.requests.single;
      expect(request.method, 'PATCH');
      expect(request.path, '/merchant/staff/4');
      expect(request.data, {'is_active': false});

      await _api(adapter).updateStaff(4, merchantRoleId: 3);
      expect(adapter.requests.last.data, {'merchant_role_id': 3});
    });

    test('the last-owner guard 422 surfaces as the envelope sentence',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'error': {
            'code': 'validation_failed',
            'message':
                "This is the merchant's last active owner — it can be neither "
                'deactivated nor demoted.',
            'meta': <String, dynamic>{},
          },
        }, 422),
      );

      await expectLater(
        _api(adapter).updateStaff(1, isActive: false),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', 'validation_failed')
              .having((e) => e.message, 'message', contains('last active owner')),
        ),
      );
    });
  });

  group('permission catalogue', () {
    test('GET parses the served groups verbatim', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            'groups': [
              {
                'slug': 'till',
                'label': 'Till',
                'permissions': [
                  {
                    'slug': 'credits.create',
                    'label': 'Credit a customer',
                    'group': 'till',
                  },
                  {
                    'slug': 'customers.lookup',
                    'label': 'Look up a customer',
                    'group': 'till',
                  },
                ],
              },
              {
                'slug': 'account',
                'label': 'Account',
                'permissions': [
                  {
                    'slug': 'staff.invite',
                    'label': 'Invite a staff member',
                    'group': 'account',
                  },
                ],
              },
            ],
          },
        }, 200),
      );

      final catalogue = await _api(adapter).permissionCatalogue();

      expect(adapter.requests.single.path, '/merchant/permissions');
      expect(catalogue.groups, hasLength(2));
      expect(catalogue.groups.first.slug, 'till');
      expect(catalogue.groups.first.label, 'Till');
      expect(catalogue.groups.first.permissions, hasLength(2));
      expect(catalogue.groups.first.permissions.first.slug, 'credits.create');
      expect(
        catalogue.groups.first.permissions.first.label,
        'Credit a customer',
      );
      expect(catalogue.groups.last.permissions.single.group, 'account');
    });
  });

  group('roles', () {
    test('GET parses the exact MerchantRoleResource collection', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': [
            {
              'id': 1,
              'name': 'Owner',
              'name_dv': null,
              'slug': 'owner',
              'is_owner': true,
              'is_system': true,
              // The owner role RESOLVES to the whole catalogue — the wire
              // carries the resolved set, never the empty stored column.
              'permissions': ['credits.create', 'staff.edit', 'roles.manage'],
              'staff_count': 1,
              'created_at': '2026-07-01T09:00:00+00:00',
              'updated_at': '2026-07-01T09:00:00+00:00',
            },
            _roleRow,
          ],
        }, 200),
      );

      final roles = await _api(adapter).roles();

      expect(adapter.requests.single.path, '/merchant/roles');
      expect(roles, hasLength(2));
      expect(roles.first.isOwner, isTrue);
      expect(roles.first.permissions, contains('roles.manage'));
      expect(roles.last.slug, 'staff');
      expect(roles.last.nameDv, 'ކޭޝިއަރ');
      expect(roles.last.staffCount, 3);
      expect(roles.last.permissions,
          ['credits.create', 'customers.lookup', 'rate.view']);
    });

    test('create POSTs permissions PRESENT even when empty', () async {
      final adapter = _RecordingAdapter((_) => _json({'data': _roleRow}, 201));

      await _api(adapter)
          .createRole(name: 'Shift lead', permissions: const []);

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/merchant/roles');
      // `permissions` present-but-empty; no name_dv key when not given.
      expect(request.data, {'name': 'Shift lead', 'permissions': <String>[]});
    });

    test('update PATCHes only the given keys — name_dv is tri-state',
        () async {
      final adapter = _RecordingAdapter((_) => _json({'data': _roleRow}, 200));
      final api = _api(adapter);

      // A rename alone: no permissions key, no name_dv key.
      await api.updateRole(2, name: 'Till crew');
      expect(adapter.requests.last.data, {'name': 'Till crew'});

      // Clearing the Dhivehi name is an EXPLICIT null on the wire.
      await api.updateRole(2, nameDvChanged: true, nameDv: null);
      expect(adapter.requests.last.data, {'name_dv': null});

      // A permissions rewrite carries the whole set.
      await api.updateRole(2, permissions: const ['credits.create']);
      expect(adapter.requests.last.data, {
        'permissions': ['credits.create'],
      });
      expect(adapter.requests.last.path, '/merchant/roles/2');
    });

    test('delete answers 204; role_in_use surfaces code and staff_count',
        () async {
      final ok = _RecordingAdapter(
        (_) => ResponseBody.fromString('', 204),
      );
      await _api(ok).deleteRole(2);
      expect(ok.requests.single.method, 'DELETE');
      expect(ok.requests.single.path, '/merchant/roles/2');

      final refused = _RecordingAdapter(
        (_) => _json({
          'error': {
            'code': 'role_in_use',
            'message':
                'This role is assigned to 3 staff accounts. Move them to '
                'another role first.',
            'meta': {'staff_count': 3},
          },
        }, 409),
      );
      await expectLater(
        _api(refused).deleteRole(2),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', 'role_in_use')
              .having((e) => e.meta['staff_count'], 'staff_count', 3),
        ),
      );
    });
  });

  group('branches', () {
    test('GET parses the exact MerchantBranchResource collection', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': [
            {
              'id': 3,
              'name': 'Tropical Mart',
              'address': 'Majeedhee Magu, Malé',
              'lat': 4.1755354,
              'lng': 73.5093474,
            },
            {'id': 5, 'name': 'Hulhumalé', 'address': null, 'lat': null,
              'lng': null},
          ],
        }, 200),
      );

      final branches = await _api(adapter).branches();

      expect(adapter.requests.single.path, '/merchant/branches');
      expect(branches, hasLength(2));
      expect(branches.first.pinned, isTrue);
      expect(branches.first.lat, 4.1755354);
      expect(branches.last.pinned, isFalse);
      expect(branches.last.address, isNull);
    });

    test('create and update send every key — the pin an explicit PAIR of '
        'nulls when absent', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': {
            'id': 6,
            'name': 'Villimalé',
            'address': null,
            'lat': null,
            'lng': null,
          },
        }, 201),
      );
      final api = _api(adapter);

      await api.createBranch(name: 'Villimalé');
      expect(adapter.requests.last.data,
          {'name': 'Villimalé', 'address': null, 'lat': null, 'lng': null});

      await api.updateBranch(6,
          name: 'Villimalé', address: 'Ameenee Magu', lat: 4.17, lng: 73.50);
      expect(adapter.requests.last.method, 'PATCH');
      expect(adapter.requests.last.path, '/merchant/branches/6');
      expect(adapter.requests.last.data, {
        'name': 'Villimalé',
        'address': 'Ameenee Magu',
        'lat': 4.17,
        'lng': 73.50,
      });
    });

    test('delete surfaces the 409 branch_referenced refusal', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'error': {
            'code': 'branch_referenced',
            'message':
                'This branch is referenced by transactions or promotions and '
                'cannot be deleted.',
            'meta': <String, dynamic>{},
          },
        }, 409),
      );

      await expectLater(
        _api(adapter).deleteBranch(3),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', 'branch_referenced'),
        ),
      );
      expect(adapter.requests.single.method, 'DELETE');
      expect(adapter.requests.single.path, '/merchant/branches/3');
    });
  });

  group('promotions', () {
    test('GET parses the list; the status filter travels as the query',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': [_promotionRow],
        }, 200),
      );
      final api = _api(adapter);

      final promotions = await api.promotions();
      expect(adapter.requests.single.path, '/merchant/promotions');
      expect(adapter.requests.single.queryParameters, isEmpty);
      expect(promotions.single.id, 11);
      expect(promotions.single.status, 'draft');
      expect(promotions.single.isLive, isFalse);
      // Percent strings stay EXACT strings; laari stay integers.
      expect(promotions.single.cashbackRatePercent, '7.50');
      expect(promotions.single.allInPercent, '8.75');
      expect(promotions.single.minPurchaseLaari, 10000);
      expect(promotions.single.maxCashbackPerCustomerLaari, 50000);
      expect(promotions.single.branchId, isNull);

      await api.promotions(status: 'draft');
      expect(adapter.requests.last.queryParameters, {'status': 'draft'});
    });

    test('create POSTs the EXACT percent string, offset timestamps and '
        'integer laari, and parses cost_preview at the ROOT beside data',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json({'data': _promotionRow, 'cost_preview': _costPreview}, 201),
      );

      final result = await _api(adapter).createPromotion(
        cashbackRatePercent: '7.50',
        startsAt: '2026-08-20T10:00:00+05:00',
        endsAt: '2026-08-22T22:00:00+05:00',
        minPurchaseLaari: 10000,
        branchId: 3,
      );

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/merchant/promotions');
      // Absent optionals stay OFF the wire (nullable server rules — an
      // explicit null is legal but never what "unset" means here).
      expect(request.data, {
        'cashback_rate_percent': '7.50',
        'starts_at': '2026-08-20T10:00:00+05:00',
        'ends_at': '2026-08-22T22:00:00+05:00',
        'min_purchase_laari': 10000,
        'branch_id': 3,
      });
      expect(result.promotion.id, 11);
      expect(result.costPreview, isNotNull);
      expect(result.costPreview!.promo.cashbackRatePercent, '7.50');
      expect(result.costPreview!.standing?.allInPercent, '2.75');
      expect(result.costPreview!.allInDeltaPercent, '6.00');
      expect(result.costPreview!.tierChanged, isTrue);
    });

    test('publish answers the fresh resource + preview; cancel only data',
        () async {
      final adapter = _RecordingAdapter(
        (options) => options.path.endsWith('/publish')
            ? _json({
                'data': {..._promotionRow, 'status': 'published'},
                'cost_preview': _costPreview,
              }, 200)
            : _json({
                'data': {..._promotionRow, 'status': 'cancelled'},
              }, 200),
      );
      final api = _api(adapter);

      final published = await api.publishPromotion(11);
      expect(adapter.requests.first.method, 'POST');
      expect(adapter.requests.first.path, '/merchant/promotions/11/publish');
      expect(published.promotion.status, 'published');
      expect(published.costPreview?.tierChanged, isTrue);

      final cancelled = await api.cancelPromotion(11);
      expect(adapter.requests.last.path, '/merchant/promotions/11/cancel');
      expect(cancelled.status, 'cancelled');
    });

    test('rate_not_priced surfaces through the envelope', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'error': {
            'code': 'rate_not_priced',
            'message':
                'That rate is not available right now. The current fee '
                'schedule prices rates up to 10.00%.',
            'meta': <String, dynamic>{},
          },
        }, 422),
      );

      await expectLater(
        _api(adapter).createPromotion(
          cashbackRatePercent: '19.00',
          startsAt: '2026-08-20T10:00:00+05:00',
          endsAt: '2026-08-22T22:00:00+05:00',
        ),
        throwsA(
          isA<MobileApiException>()
              .having((e) => e.code, 'code', 'rate_not_priced'),
        ),
      );
    });
  });
}
