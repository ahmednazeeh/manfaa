import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';

/// MR9 contract tests — admin approval for store edits and new branches.
///
/// The whole point of this round is that a gated save is NOT a save. What is
/// proved here:
///
///  - a queued profile save says "Sent for Manfaa's review", never "Profile
///    saved", and the form's fields keep the LIVE values;
///  - the profile view and the branches list render what is WAITING, with the
///    proposed values, beside the live ones a shopper still sees;
///  - a queued branch create appears as its own card (there is no row for it),
///    and a queued removal leaves the branch on the list;
///  - the editor says re-saving replaces the request that is waiting;
///  - an INSTANT-only save (§MR9: contact details) still reads as a save.
void main() {
  MobileConfig config() => MobileConfig.fromJson({
    'apps': {
      'merchant': {
        'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
      },
    },
    'features': const {},
  });

  Future<_FakeApi> boot(
    WidgetTester tester, {
    required List<String> permissions,
    Map<String, dynamic>? pendingProfileJson,
    List<MerchantChangeRequest> pendingBranchChanges = const [],
    bool gated = true,
  }) async {
    await tester.binding.setSurfaceSize(const Size(600, 2400));
    tester.view.physicalSize = const Size(600, 2400);
    tester.view.devicePixelRatio = 1.0;

    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.saveSession(
      token: 't',
      userName: 'Aminath Waheedha',
      userEmail: 'a@tropical.mv',
      merchantId: 7,
      merchantName: 'Tropical Mart',
      merchantSlug: 'tropical-mart',
      merchantStatus: 'active',
      permissions: permissions,
    );
    final api = _FakeApi(
      session: session,
      permissions: permissions,
      pendingProfileJson: pendingProfileJson,
      pendingBranchChanges: pendingBranchChanges,
      gated: gated,
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          sessionProvider.overrideWithValue(session),
          apiProvider.overrideWith((ref) => api),
          configProvider.overrideWith((ref) async => config()),
        ],
        child: const MerchantApp(),
      ),
    );
    await tester.pumpAndSettle();
    return api;
  }

  Future<void> openMoreRow(WidgetTester tester, String row) async {
    await tester.tap(find.text('More').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text(row));
    await tester.pumpAndSettle();
  }

  testWidgets('a gated profile save is SENT FOR REVIEW, not saved', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const ['profile.view', 'profile.edit', 'setup.view'],
    );

    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    // Field 0 is the store NAME — a claim, so it queues.
    await tester.enterText(find.byType(TextField).first, 'Tropical Fresh');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save profile'));
    await tester.pumpAndSettle();

    expect(find.text("Sent for Manfaa's review"), findsOneWidget);
    expect(find.text('Profile saved'), findsNothing);
  });

  testWidgets('an INSTANT-only save still reads as a save', (tester) async {
    await boot(
      tester,
      permissions: const ['profile.view', 'profile.edit', 'setup.view'],
      gated: false,
    );

    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save profile'));
    await tester.pumpAndSettle();

    expect(find.text('Profile saved'), findsOneWidget);
    expect(find.text("Sent for Manfaa's review"), findsNothing);
  });

  testWidgets(
    'the profile view shows the PROPOSED values beside the live ones',
    (tester) async {
      await boot(
        tester,
        permissions: const ['profile.view', 'profile.edit', 'setup.view'],
        pendingProfileJson: _pendingProfile,
      );

      await tester.tap(find.text('View profile'));
      await tester.pumpAndSettle();

      expect(find.text("Waiting for Manfaa's review"), findsOneWidget);
      // What is WAITING…
      expect(find.text('Tropical Fresh Market'), findsOneWidget);
      // …resolved through the served category list, never the raw slug.
      expect(find.text('Dining & Cafés'), findsOneWidget);
      expect(find.text('dining'), findsNothing);
      // …and what a shopper still sees, in the rows below.
      expect(find.text('Tropical Mart'), findsWidgets);
      expect(find.text('Grocery / Supermarket'), findsOneWidget);
      // The submitted instant is BUSINESS time (+05:00), not the device's.
      expect(
        find.textContaining('Submitted 17 Aug 2026, 02:41 PM'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'a changed DESCRIPTION queues, and the pending card lists it by name',
    (tester) async {
      final api = await boot(
        tester,
        permissions: const ['profile.view', 'profile.edit', 'setup.view'],
      );

      await tester.tap(find.text('View profile'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Edit'));
      await tester.pumpAndSettle();

      // 0 name, 1 Thaana name, 2 contact email, 3 contact phone, 4 website,
      // 5 description (the support field is hidden while the tick holds).
      await tester.enterText(
        find.byType(TextField).at(5),
        'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save profile'));
      await tester.pumpAndSettle();

      expect(
        api.profilePatches.single['description'],
        'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
      );
      // A gated save is NOT a save — the description is a public claim.
      expect(find.text("Sent for Manfaa's review"), findsOneWidget);
      expect(find.text('Profile saved'), findsNothing);

      // Back on the view screen: the card names the field in the app's own
      // words and carries the PROPOSED text, while the row underneath still
      // holds the words a shopper reads today.
      await tester.pumpAndSettle();
      expect(find.text("Waiting for Manfaa's review"), findsOneWidget);
      expect(find.text('Store description'), findsWidgets);
      expect(
        find.text(
          'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
        ),
        findsOneWidget,
      );
      expect(
        find.text('A neighbourhood grocery on Majeedhee Magu.'),
        findsOneWidget,
      );
      // Never the raw wire key.
      expect(find.text('description'), findsNothing);
    },
  );

  testWidgets('the editor warns that re-saving replaces the pending request', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const ['profile.view', 'profile.edit', 'setup.view'],
      pendingProfileJson: _pendingProfile,
    );

    await tester.tap(find.text('View profile'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit'));
    await tester.pumpAndSettle();

    expect(
      find.text('Saving again replaces the request that is waiting.'),
      findsOneWidget,
    );
    // The FORM still holds the live name — pre-filling the proposal would
    // re-submit it on the next unrelated save.
    expect(
      tester.widget<TextField>(find.byType(TextField).first).controller!.text,
      'Tropical Mart',
    );
  });

  testWidgets('a queued new branch is its own card — no row exists yet', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const ['branches.view', 'branches.create'],
      pendingBranchChanges: [MerchantChangeRequest.fromJson(_pendingCreate)],
    );
    await openMoreRow(tester, 'Manage Branches');

    expect(find.text("Waiting for Manfaa's review"), findsOneWidget);
    expect(find.text('Tropical Mart — Addu'), findsOneWidget);
    expect(find.text('Link Road, Hithadhoo'), findsOneWidget);
    // The pin pair collapses into ONE line — half a coordinate is not a place.
    expect(find.text('-0.60060, 73.08360'), findsOneWidget);
    expect(
      find.text(
        'This branch appears in your list, and to customers, once Manfaa '
        'approves it.',
      ),
      findsOneWidget,
    );
    // The stat strip counts REAL branches — a queued one is not one.
    expect(find.text('1'), findsWidgets);
  });

  testWidgets('a queued branch save says so, and the list has not grown', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const ['branches.view', 'branches.create'],
    );
    await openMoreRow(tester, 'Manage Branches');

    await tester.tap(find.text('Add branch'));
    await tester.pumpAndSettle();
    // Index 0 is the branches screen's SEARCH box behind the sheet — the
    // sheet's own name field follows it in tree order.
    await tester.enterText(find.byType(TextField).at(1), 'Tropical Mart — Addu');
    await tester.pumpAndSettle();

    // Address is REQUIRED now (owner decision 2026-08-18) and the dialog
    // refuses before the wire — so a save with only a name must not queue.
    await tester.tap(find.text('Add branch').last);
    await tester.pumpAndSettle();
    expect(find.text("New branch sent for Manfaa's review"), findsNothing);

    await tester.enterText(
      find.byType(TextField).at(2),
      'Majeedhee Magu, Hithadhoo',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add branch').last);
    await tester.pumpAndSettle();

    expect(find.text("New branch sent for Manfaa's review"), findsOneWidget);
    expect(find.text('Branch added'), findsNothing);
  });

  testWidgets('a queued removal leaves the branch on the list', (tester) async {
    final api = await boot(
      tester,
      permissions: const ['branches.view', 'branches.delete'],
    );
    await openMoreRow(tester, 'Manage Branches');

    await tester.tap(find.byIcon(Icons.more_vert_rounded).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete').last);
    await tester.pumpAndSettle();

    expect(find.text("Removal sent for Manfaa's review"), findsOneWidget);
    expect(find.text('Branch deleted'), findsNothing);
    expect(api.deleted, [3]);
    // Still there — a removal is a REQUEST until an admin approves it.
    expect(find.text('Tropical Mart — Main'), findsOneWidget);
  });

  testWidgets('a pending removal hangs off the branch it is about', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const ['branches.view', 'branches.edit'],
      pendingBranchChanges: [MerchantChangeRequest.fromJson(_pendingDelete)],
    );
    await openMoreRow(tester, 'Manage Branches');

    expect(find.text("Waiting for Manfaa's review"), findsOneWidget);
    expect(find.textContaining('Branch removal'), findsOneWidget);
    expect(
      find.text(
        'This branch stays on your list until Manfaa approves the removal.',
      ),
      findsOneWidget,
    );
  });
}

const _profileFixture = <String, dynamic>{
  'id': 7,
  'name': 'Tropical Mart',
  'name_dv': null,
  'slug': 'tropical-mart',
  'status': 'active',
  'category': 'grocery',
  'category_retired': false,
  'channel': 'in_store',
  'eligibility_basis': 'Everything except tobacco.',
  'description': 'A neighbourhood grocery on Majeedhee Magu.',
  'contact_email': 'hello@tropicalmart.mv',
  'contact_phone': '+9607781234',
  'support_phone': '+9607781234',
  'website_url': null,
};

/// MerchantChangeRequestResource, verbatim — a rename, a re-category and a
/// staged logo waiting together (the logo SUPERSEDED the rename and carried
/// it forward, which is what the server does).
const _pendingProfile = <String, dynamic>{
  'id': 21,
  'merchant_id': 7,
  'kind': 'profile',
  'kind_label': 'store profile change',
  'status': 'pending',
  'branch_id': null,
  // On a PROFILE change this key holds the STORE's name — never read as a
  // branch name.
  'branch_name': 'Tropical Mart',
  'changes': [
    {'field': 'name', 'from': 'Tropical Mart', 'to': 'Tropical Fresh Market'},
    {'field': 'category', 'from': 'grocery', 'to': 'dining'},
  ],
  'proposed': {'name': 'Tropical Fresh Market', 'category': 'dining'},
  'current': {'name': 'Tropical Mart', 'category': 'grocery'},
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

/// A live store editing only its own words: `description` is not on the
/// INSTANT list, so ChangeRequestService's fail-closed default gates it —
/// the app's job is to say so in words the owner recognises.
const _pendingDescription = <String, dynamic>{
  'id': 24,
  'merchant_id': 7,
  'kind': 'profile',
  'kind_label': 'store profile change',
  'status': 'pending',
  'branch_id': null,
  'branch_name': 'Tropical Mart',
  'changes': [
    {
      'field': 'description',
      'from': 'A neighbourhood grocery on Majeedhee Magu.',
      'to': 'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
    },
  ],
  'proposed': {
    'description':
        'A neighbourhood grocery on Majeedhee Magu, open until 11pm.',
  },
  'current': {'description': 'A neighbourhood grocery on Majeedhee Magu.'},
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

const _pendingCreate = <String, dynamic>{
  'id': 22,
  'merchant_id': 7,
  'kind': 'branch_create',
  'kind_label': 'new branch',
  'status': 'pending',
  'branch_id': null,
  'branch_name': null,
  'changes': [
    {'field': 'name', 'from': null, 'to': 'Tropical Mart — Addu'},
    {'field': 'address', 'from': null, 'to': 'Link Road, Hithadhoo'},
    {'field': 'lat', 'from': null, 'to': -0.6006},
    {'field': 'lng', 'from': null, 'to': 73.0836},
  ],
  'proposed': {
    'name': 'Tropical Mart — Addu',
    'address': 'Link Road, Hithadhoo',
    'lat': -0.6006,
    'lng': 73.0836,
  },
  'current': {'name': null, 'address': null, 'lat': null, 'lng': null},
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

/// A removal proposes NOTHING: `proposed` is an empty PHP array, and the
/// snapshot's coordinates are decimal STRINGS.
const _pendingDelete = <String, dynamic>{
  'id': 23,
  'merchant_id': 7,
  'kind': 'branch_delete',
  'kind_label': 'branch removal',
  'status': 'pending',
  'branch_id': 3,
  'branch_name': 'Tropical Mart — Main',
  'changes': <Object>[],
  'proposed': <Object>[],
  'current': {
    'name': 'Tropical Mart — Main',
    'address': 'Majeedhee Magu, Malé',
    'lat': '4.1755354',
    'lng': '73.5093474',
    'id': 3,
  },
  'submitted_at': '2026-08-17T09:41:00+00:00',
  'reviewed_at': null,
  'reviewed_by': null,
  'rejected_reason': null,
};

class _FakeApi extends MerchantApi {
  _FakeApi({
    required super.session,
    required this.permissions,
    required this.pendingBranchChanges,
    this.pendingProfileJson,
    this.gated = true,
  });

  final List<String> permissions;
  final Map<String, dynamic>? pendingProfileJson;
  final List<MerchantChangeRequest> pendingBranchChanges;

  /// A LIVE store (§MR9: active|suspended) queues its claims; false stands in
  /// for a store still in onboarding, which writes straight through.
  final bool gated;

  final deleted = <int>[];

  /// Every PATCH /merchant/profile the editor made, name + description.
  final profilePatches = <Map<String, dynamic>>[];

  /// The queue a gated save created, as the next GET would carry it.
  Map<String, dynamic>? _lastQueued;

  MerchantChangeRequest _queued(Map<String, dynamic> json) =>
      MerchantChangeRequest.fromJson(json);

  @override
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson({
      'user': {'id': 1, 'name': 'Aminath Waheedha', 'email': 'a@tropical.mv'},
      'merchant': {
        'id': 7,
        'name': 'Tropical Mart',
        'slug': 'tropical-mart',
        'status': 'active',
      },
      'permissions': permissions,
    });
    await session.saveProfile(
      userName: me.user.name,
      userEmail: me.user.email,
      merchantId: me.merchant.id,
      merchantName: me.merchant.name,
      merchantSlug: me.merchant.slug,
      merchantStatus: me.merchant.status,
      permissions: me.permissions,
    );
    return me;
  }

  @override
  Future<MerchantProfile> profile() async => MerchantProfile.fromJson({
    ..._profileFixture,
    // What the store is waiting on: the fixture it was booted with, or the
    // request a save made HERE — the GET carries `pending_change` either
    // way, which is how the view screen knows to draw the card.
    'pending_change': pendingProfileJson ?? _lastQueued,
  });

  @override
  Future<ProfileSaveResult> updateProfile({
    required String name,
    String? nameDv,
    required String channel,
    String? eligibilityBasis,
    String? description,
    String? contactEmail,
    String? contactPhone,
    String? supportPhone,
    String? websiteUrl,
    bool categoryChanged = false,
    String? category,
  }) async {
    profilePatches.add({'name': name, 'description': description});

    // The server diffs the GATED half against the live row, so what comes
    // back is the queue for the claim that actually moved: a changed
    // description queues exactly as a rename does.
    final changed = description != _profileFixture['description']
        ? _pendingDescription
        : _pendingProfile;
    if (gated) _lastQueued = changed;

    final profile = MerchantProfile.fromJson({
      ..._profileFixture,
      'contact_phone': contactPhone,
      'support_phone': supportPhone,
      if (gated) 'pending_change': changed,
      // Not live: the claim applied on the spot.
      if (!gated) 'name': name,
      if (!gated) 'description': description,
    });

    return ProfileSaveResult(
      profile: profile,
      queued: gated ? _queued(changed) : null,
    );
  }

  @override
  Future<MerchantSetupState> getSetup() async => MerchantSetupState.fromJson(
    const {
      'status': 'active',
      'steps': {'profile': true, 'location': true, 'logo': false, 'rate': true},
      'values': {
        'name': 'Tropical Mart',
        'slug': 'tropical-mart',
        'category': 'grocery',
        'channel': 'in_store',
        'eligibility_basis': 'Everything except tobacco.',
        'description': 'A neighbourhood grocery on Majeedhee Magu.',
        'contact_email': 'hello@tropicalmart.mv',
        'contact_phone': '+9607781234',
        'support_phone': null,
        'website_url': null,
        'primary_branch': null,
        'logo_url': null,
        'cashback_rate_percent': '2.00',
      },
      'rate_bounds': {'min_percent': '0.50', 'max_percent': '10.00'},
      'categories': [
        {
          'slug': 'grocery',
          'name_en': 'Grocery / Supermarket',
          'name_dv': null,
        },
        {'slug': 'dining', 'name_en': 'Dining & Cafés', 'name_dv': null},
      ],
      'submitted_at': null,
      'rejected_reason': null,
    },
  );

  @override
  Future<MerchantBranchEstate> branches() async => MerchantBranchEstate(
    branches: [
      MerchantBranch.fromJson(const {
        'id': 3,
        'name': 'Tropical Mart — Main',
        'address': 'Majeedhee Magu, Malé',
        'lat': 4.1755354,
        'lng': 73.5093474,
      }),
    ],
    pendingChanges: pendingBranchChanges,
  );

  @override
  Future<BranchSaveResult> createBranch({
    required String name,
    String? address,
    double? lat,
    double? lng,
  }) async => BranchSaveResult(queued: _queued(_pendingCreate));

  @override
  Future<BranchDeleteResult> deleteBranch(int id) async {
    deleted.add(id);
    return BranchDeleteResult(queued: _queued(_pendingDelete));
  }

  /// GET /merchant/fee-promotion. Defaults to NOTHING RUNNING — the state
  /// every shipped assertion and golden was written against — and is
  /// settable so a test can throw the switch the way a superadmin does.
  MerchantFeePromotion promotion = MerchantFeePromotion.none;

  @override
  Future<MerchantFeePromotion> feePromotion() async => promotion;
}
