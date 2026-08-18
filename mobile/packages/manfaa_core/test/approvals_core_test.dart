import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// MR9 wire-shape tests: admin approval for store edits and new branches.
///
/// Every fixture below is a VERBATIM capture of the real PHP answering the
/// mobile mount (a throwaway Pest test posted these requests against
/// /api/mobile/v1 and dumped the JSON), because the shape carries three
/// traps a hand-written fixture would have smoothed over:
///
///  * a branch REMOVAL proposes no fields, so `proposed` is an empty PHP
///    array and arrives as `[]` — a JSON list where every other kind sends
///    an object;
///  * `branch_name` is the snapshot's `name`, which on a PROFILE change is
///    the STORE's name, not a branch's;
///  * a removal's `current` carries lat/lng as decimal STRINGS (the column
///    cast) while a create's `changes` carry them as numbers.
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

/// PATCH /merchant/profile — a rename + a website beside a phone fix. The
/// phone is INSTANT and already applied (see `data.profile`); the claims
/// queued, so `data.profile.name` is still the OLD name.
const _profileQueued = {
  'data': {
    'status': 'pending_review',
    'change_request': {
      'id': 1,
      'merchant_id': 1,
      'kind': 'profile',
      'kind_label': 'store profile change',
      'status': 'pending',
      'branch_id': null,
      'branch_name': 'Tropical Mart',
      'changes': [
        {'field': 'name', 'from': 'Tropical Mart', 'to': 'Chai House'},
        {'field': 'website_url', 'from': null, 'to': 'chaihouse.mv'},
      ],
      'proposed': {'name': 'Chai House', 'website_url': 'chaihouse.mv'},
      'current': {'name': 'Tropical Mart', 'website_url': null},
      'submitted_at': '2026-08-18T10:25:01+00:00',
      'reviewed_at': null,
      'reviewed_by': null,
      'rejected_reason': null,
    },
    'profile': {
      'id': 1,
      'name': 'Tropical Mart',
      'name_dv': null,
      'slug': 'tropical-mart',
      'status': 'active',
      'category': 'grocery',
      'category_retired': false,
      'channel': 'in_store',
      'eligibility_basis': 'Invoice total excluding GST.',
      'contact_email': null,
      'contact_phone': '+9607779999',
      'support_phone': '+9607779999',
      'website_url': null,
      'pending_change': {
        'id': 1,
        'merchant_id': 1,
        'kind': 'profile',
        'kind_label': 'store profile change',
        'status': 'pending',
        'branch_id': null,
        'branch_name': 'Tropical Mart',
        'changes': [
          {'field': 'name', 'from': 'Tropical Mart', 'to': 'Chai House'},
        ],
        'proposed': {'name': 'Chai House', 'website_url': 'chaihouse.mv'},
        'current': {'name': 'Tropical Mart', 'website_url': null},
        'submitted_at': '2026-08-18T10:25:01+00:00',
        'reviewed_at': null,
        'reviewed_by': null,
        'rejected_reason': null,
      },
    },
  },
};

/// POST /merchant/setup/logo for a LIVE store: the file is staged, and
/// `logo_url` is the logo the store is STILL serving. The queued row also
/// SUPERSEDES the pending rename and carries it forward — both ride the
/// `profile` kind from different endpoints.
const _logoQueued = {
  'data': {
    'status': 'pending_review',
    'change_request': {
      'id': 2,
      'merchant_id': 1,
      'kind': 'profile',
      'kind_label': 'store profile change',
      'status': 'pending',
      'branch_id': null,
      'branch_name': 'Tropical Mart',
      'changes': [
        {
          'field': 'logo',
          'from': null,
          'to': 'https://manfaa.app/api/change-requests/2/logo'
              '?side=proposed&v=8149b0071247',
        },
        {'field': 'name', 'from': 'Tropical Mart', 'to': 'Chai House'},
      ],
      'proposed': {
        'name': 'Chai House',
        'logo_url': 'https://manfaa.app/api/change-requests/2/logo'
            '?side=proposed&v=8149b0071247',
      },
      'current': {'name': 'Tropical Mart', 'logo_url': null},
      'submitted_at': '2026-08-18T10:25:01+00:00',
      'reviewed_at': null,
      'reviewed_by': null,
      'rejected_reason': null,
    },
    'logo_url': 'https://manfaa.app/api/merchants/tropical-mart/logo?v=old',
  },
};

const _branchCreateQueued = {
  'data': {
    'status': 'pending_review',
    'change_request': {
      'id': 3,
      'merchant_id': 1,
      'kind': 'branch_create',
      'kind_label': 'new branch',
      'status': 'pending',
      'branch_id': null,
      'branch_name': null,
      'changes': [
        {'field': 'name', 'from': null, 'to': 'Hulhumalé'},
        {'field': 'address', 'from': null, 'to': 'Nirolhu Magu'},
        {'field': 'lat', 'from': null, 'to': 4.2105091},
        {'field': 'lng', 'from': null, 'to': 73.5407121},
      ],
      'proposed': {
        'name': 'Hulhumalé',
        'address': 'Nirolhu Magu',
        'lat': 4.2105091,
        'lng': 73.5407121,
      },
      'current': {'name': null, 'address': null, 'lat': null, 'lng': null},
      'submitted_at': '2026-08-18T10:25:01+00:00',
      'reviewed_at': null,
      'reviewed_by': null,
      'rejected_reason': null,
    },
  },
};

/// DELETE /merchant/branches/{id} for a live store: 202, the branch still
/// present. `proposed` is `[]` — a removal proposes nothing.
const _branchDeleteQueued = {
  'data': {
    'status': 'pending_review',
    'change_request': {
      'id': 5,
      'merchant_id': 1,
      'kind': 'branch_delete',
      'kind_label': 'branch removal',
      'status': 'pending',
      'branch_id': 1,
      'branch_name': 'Tropical Mart — Main',
      'changes': <Object>[],
      'proposed': <Object>[],
      'current': {
        'name': 'Tropical Mart — Main',
        'address': 'Majeedhee Magu, Malé',
        'lat': '4.1755354',
        'lng': '73.5093474',
        'id': 1,
      },
      'submitted_at': '2026-08-18T10:25:01+00:00',
      'reviewed_at': null,
      'reviewed_by': null,
      'rejected_reason': null,
    },
  },
};

void main() {
  group('profile', () {
    test('a queued PATCH answers 202 with the change request AND the '
        'already-applied instant half', () async {
      final adapter = _RecordingAdapter((_) => _json(_profileQueued, 202));

      final result = await _api(adapter).updateProfile(
        name: 'Chai House',
        channel: 'in_store',
        contactPhone: '+9607779999',
        supportPhone: '+9607779999',
        websiteUrl: 'chaihouse.mv',
      );

      expect(result.pending, isTrue);
      final queued = result.queued!;
      expect(queued.id, 1);
      expect(queued.kind, 'profile');
      expect(queued.isProfile, isTrue);
      expect(queued.isBranch, isFalse);
      expect(queued.status, 'pending');
      expect(queued.isPending, isTrue);
      expect(queued.kindLabel, 'store profile change');
      expect(queued.submittedAt, '2026-08-18T10:25:01+00:00');
      expect(queued.proposed['name'], 'Chai House');
      expect(queued.current['website_url'], isNull);
      expect(queued.changes.map((c) => c.field), ['name', 'website_url']);
      expect(queued.changes.first.from, 'Tropical Mart');
      expect(queued.changes.first.to, 'Chai House');

      // The profile that comes back is the LIVE store: the phone moved, the
      // name did not. Painting `proposed` here would tell the owner their
      // rename had landed.
      expect(result.profile.name, 'Tropical Mart');
      expect(result.profile.contactPhone, '+9607779999');
      expect(result.profile.pendingChange?.id, 1);
    });

    test('a changed DESCRIPTION queues like any other public claim', () async {
      // The server's gated half is fail-CLOSED (ChangeRequestService:
      // everything the PATCH validates that is not a contact detail), so
      // the description arrived gated the day it was added — the app only
      // has to parse the diff the reviewer will decide on.
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'status': 'pending_review',
            'change_request': {
              'id': 9,
              'merchant_id': 1,
              'kind': 'profile',
              'kind_label': 'store profile change',
              'status': 'pending',
              'branch_id': null,
              'branch_name': 'Tropical Mart',
              'changes': [
                {
                  'field': 'description',
                  'from': 'A neighbourhood grocery.',
                  'to': 'A neighbourhood grocery, open every day until 11pm.',
                },
              ],
              'proposed': {
                'description':
                    'A neighbourhood grocery, open every day until 11pm.',
              },
              'current': {'description': 'A neighbourhood grocery.'},
              'submitted_at': '2026-08-18T10:25:01+00:00',
              'reviewed_at': null,
              'reviewed_by': null,
              'rejected_reason': null,
            },
            'profile': {
              'id': 1,
              'name': 'Tropical Mart',
              'slug': 'tropical-mart',
              'status': 'active',
              'category': 'grocery',
              'category_retired': false,
              'channel': 'in_store',
              // The LIVE description — what a shopper still reads.
              'description': 'A neighbourhood grocery.',
              'pending_change': null,
            },
          },
        }, 202),
      );

      final result = await _api(adapter).updateProfile(
        name: 'Tropical Mart',
        channel: 'in_store',
        description: 'A neighbourhood grocery, open every day until 11pm.',
      );

      expect(result.pending, isTrue);
      expect(result.queued!.changes.single.field, 'description');
      expect(
        result.queued!.proposed['description'],
        'A neighbourhood grocery, open every day until 11pm.',
      );
      // The store still says what it said until an admin approves.
      expect(result.profile.description, 'A neighbourhood grocery.');
    });

    test('a 200 is a plain save — the profile resource itself, nothing queued',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'id': 1,
            'name': 'Tropical Mart',
            'name_dv': null,
            'slug': 'tropical-mart',
            'status': 'active',
            'category': 'grocery',
            'category_retired': false,
            'channel': 'in_store',
            'eligibility_basis': null,
            'contact_email': null,
            'contact_phone': '+9607779999',
            'support_phone': '+9607779999',
            'website_url': null,
            'pending_change': null,
          },
        }, 200),
      );

      final result = await _api(adapter).updateProfile(
        name: 'Tropical Mart',
        channel: 'in_store',
        contactPhone: '+9607779999',
        supportPhone: '+9607779999',
      );

      expect(result.pending, isFalse);
      expect(result.queued, isNull);
      expect(result.profile.contactPhone, '+9607779999');
      expect(result.profile.pendingChange, isNull);
    });

    test('GET carries the pending change so the screen can render the wait',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json(
          (_profileQueued['data']! as Map)['profile'] is Map
              ? {'data': (_profileQueued['data']! as Map)['profile']}
              : const {'data': <String, Object?>{}},
          200,
        ),
      );

      final profile = await _api(adapter).profile();

      expect(profile.name, 'Tropical Mart');
      expect(profile.pendingChange, isNotNull);
      expect(profile.pendingChange!.proposed['name'], 'Chai House');
      expect(profile.pendingChange!.isPending, isTrue);
    });
  });

  group('logo', () {
    test('a staged logo answers 202 and keeps serving the CURRENT logo',
        () async {
      final adapter = _RecordingAdapter((_) => _json(_logoQueued, 202));

      final result = await _api(adapter).uploadSetupLogo(
        bytes: Uint8List.fromList([1, 2, 3]),
        filename: 'logo.png',
      );

      expect(result.pending, isTrue);
      // NOT the staged file — what a shopper still sees.
      expect(
        result.logoUrl,
        'https://manfaa.app/api/merchants/tropical-mart/logo?v=old',
      );
      // The logo change rides the diff as the field `logo`, and the row
      // CARRIED FORWARD the rename it superseded.
      expect(result.queued!.changes.map((c) => c.field), ['logo', 'name']);
      expect(result.queued!.proposed['name'], 'Chai House');
      expect(result.queued!.proposed['logo_url'], contains('side=proposed'));
    });
  });

  group('branches', () {
    test('a queued create answers 202 with NO branch row', () async {
      final adapter = _RecordingAdapter((_) => _json(_branchCreateQueued, 202));

      final result = await _api(adapter).createBranch(
        name: 'Hulhumalé',
        address: 'Nirolhu Magu',
        lat: 4.2105091,
        lng: 73.5407121,
      );

      expect(result.pending, isTrue);
      expect(result.branch, isNull);
      expect(result.queued!.kind, 'branch_create');
      expect(result.queued!.isBranchCreate, isTrue);
      expect(result.queued!.branchId, isNull);
      expect(result.queued!.proposed['name'], 'Hulhumalé');
      expect(result.queued!.proposed['lat'], 4.2105091);
    });

    test('a queued update answers 202 against its branch id', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'status': 'pending_review',
            'change_request': {
              'id': 4,
              'merchant_id': 1,
              'kind': 'branch_update',
              'kind_label': 'branch update',
              'status': 'pending',
              'branch_id': 1,
              'branch_name': 'Tropical Mart — Main',
              'changes': [
                {
                  'field': 'name',
                  'from': 'Tropical Mart — Main',
                  'to': 'Tropical Mart — Majeedhee Magu',
                },
              ],
              'proposed': {'name': 'Tropical Mart — Majeedhee Magu'},
              'current': {'name': 'Tropical Mart — Main', 'id': 1},
              'submitted_at': '2026-08-18T10:25:01+00:00',
              'reviewed_at': null,
              'reviewed_by': null,
              'rejected_reason': null,
            },
          },
        }, 202),
      );

      final result = await _api(adapter).updateBranch(
        1,
        name: 'Tropical Mart — Majeedhee Magu',
      );

      expect(result.branch, isNull);
      expect(result.queued!.branchId, 1);
      expect(result.queued!.branchName, 'Tropical Mart — Main');
    });

    test('a save that changed nothing answers 200 with the branch, unqueued',
        () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'id': 1,
            'name': 'Tropical Mart — Main',
            'address': 'Majeedhee Magu, Malé',
            'lat': 4.1755354,
            'lng': 73.5093474,
          },
        }, 200),
      );

      final result = await _api(adapter).updateBranch(
        1,
        name: 'Tropical Mart — Main',
      );

      expect(result.pending, isFalse);
      expect(result.branch!.name, 'Tropical Mart — Main');
    });

    test('a queued removal parses the EMPTY `proposed` array', () async {
      final adapter = _RecordingAdapter((_) => _json(_branchDeleteQueued, 202));

      final result = await _api(adapter).deleteBranch(1);

      expect(result.pending, isTrue);
      expect(result.queued!.isBranchDelete, isTrue);
      expect(result.queued!.proposed, isEmpty);
      expect(result.queued!.changes, isEmpty);
      // The removal's whole subject is the snapshot.
      expect(result.queued!.branchName, 'Tropical Mart — Main');
      expect(result.queued!.current['address'], 'Majeedhee Magu, Malé');
      // The column cast hands coordinates back as decimal STRINGS here.
      expect(result.queued!.current['lat'], '4.1755354');
    });

    test('a 204 delete queues nothing (a store that is not live)', () async {
      final adapter = _RecordingAdapter(
        (_) => ResponseBody.fromString('', 204),
      );

      final result = await _api(adapter).deleteBranch(1);

      expect(result.pending, isFalse);
      expect(result.queued, isNull);
    });

    test('the index carries the estate AND the queue together', () async {
      final adapter = _RecordingAdapter(
        (_) => _json({
          'data': [
            {
              'id': 1,
              'name': 'Tropical Mart — Main',
              'address': 'Majeedhee Magu, Malé',
              'lat': 4.1755354,
              'lng': 73.5093474,
            },
          ],
          'meta': {
            'pending_changes': [
              (_branchCreateQueued['data']! as Map)['change_request'],
              (_branchDeleteQueued['data']! as Map)['change_request'],
            ],
          },
        }, 200),
      );

      final estate = await _api(adapter).branches();

      expect(estate.branches, hasLength(1));
      expect(estate.pendingChanges, hasLength(2));
      // A create has no row to hang off — it is its own card.
      expect(estate.pendingCreates.single.proposed['name'], 'Hulhumalé');
      // An update/removal hangs off the branch it names.
      expect(estate.pendingFor(1)!.kind, 'branch_delete');
      expect(estate.pendingFor(99), isNull);
    });
  });
}
