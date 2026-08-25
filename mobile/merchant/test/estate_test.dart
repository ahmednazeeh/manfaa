import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';

/// MR5 contract tests — the management-estate half of the More tab:
///
///  - the invite flow reveals the ONE-TIME temp password exactly as the
///    server returns it, locked until acknowledged;
///  - the roles editor enforces the D5 delegation law in the UI (a slug
///    the actor does not hold is a DISABLED checkbox with the hint);
///  - the last-owner guard locks the controls client-side AND renders the
///    server's refusal sentence when one answers anyway;
///  - a branch delete refused 409 `branch_referenced` renders as the
///    localized sentence, never a raw code;
///  - publishing a promotion demands the immutable-once-live confirm.
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
    List<Map<String, dynamic>>? staffJson,
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
      staffJson: staffJson,
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
    // `.last` is the nav bar's label — the More SCREEN's own headline also
    // says "More" whenever the session lands there directly.
    await tester.tap(find.text('More').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text(row));
    await tester.pumpAndSettle();
  }

  testWidgets(
    'invite reveals the one-time temp password, locked until acknowledged',
    (tester) async {
      final api = await boot(
        tester,
        permissions: const [
          'staff.view',
          'staff.invite',
          'roles.view',
          'credits.create',
        ],
      );
      await openMoreRow(tester, 'Manage Employees');

      await tester.tap(find.text('Add employee'));
      await tester.pumpAndSettle();

      // Index 0 is the employees screen's SEARCH box behind the sheet —
      // the sheet's fields follow it in tree order.
      await tester.enterText(
        find.byType(TextField).at(1),
        'Fathimath Aisha',
      );
      await tester.enterText(
        find.byType(TextField).at(2),
        'aisha@tropicalmart.mv',
      );
      await tester.pumpAndSettle();
      // The Cashier role holds only credits.create, which the actor holds
      // — assignable. (The Owner role is locked: only an owner hands it
      // out.)
      await tester.tap(find.text('Cashier').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      // The wire carried the chosen role id.
      expect(api.invites, [
        {
          'name': 'Fathimath Aisha',
          'email': 'aisha@tropicalmart.mv',
          'merchant_role_id': 2,
        },
      ]);

      // The reveal: the password exactly as the server returned it, the
      // shown-once warning, and a Done that stays dead until acknowledged.
      expect(find.text('q8#Vt2!mXw9zL0pR4sBd'), findsOneWidget);
      expect(find.text('Shown only once.'), findsOneWidget);
      final done = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Done'),
      );
      expect(done.onPressed, isNull);

      await tester.tap(find.byType(Checkbox));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();
      expect(find.text('q8#Vt2!mXw9zL0pR4sBd'), findsNothing);
    },
  );

  testWidgets(
    'roles editor disables checkboxes for slugs the actor does not hold',
    (tester) async {
      // roles.manage without credits.create: the Till boxes must be drawn
      // disabled — you can only grant what you hold (D5).
      await boot(
        tester,
        permissions: const ['staff.view', 'roles.view', 'roles.manage'],
      );
      await openMoreRow(tester, 'Roles');

      await tester.tap(find.text('Add role'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Greyed permissions are ones you do not hold'),
        findsOneWidget,
      );

      Checkbox boxFor(String label) => tester.widget<Checkbox>(
        find.descendant(
          of: find.ancestor(
            of: find.text(label),
            matching: find.byType(Row),
          ),
          matching: find.byType(Checkbox),
        ),
      );

      // Not held → disabled; held → live.
      expect(boxFor('Credit a customer').onChanged, isNull);
      expect(boxFor('View staff accounts').onChanged, isNotNull);
      expect(boxFor('Create and edit roles').onChanged, isNotNull);
    },
  );

  testWidgets(
    'the last active owner is locked client-side with the sentence',
    (tester) async {
      // The actor (a manager with staff.edit) opens the store's ONLY
      // active owner: both controls lock and the sentence says why.
      await boot(
        tester,
        permissions: const ['staff.view', 'staff.edit', 'roles.view'],
        staffJson: [
          _staffRow(1, 'Aminath Waheedha', 'a@tropical.mv', _managerRole,
              active: true),
          _staffRow(2, 'Ahmed Nazeeh', 'nazeeh@tropicalmart.mv', _ownerRole,
              active: true),
        ],
      );
      await openMoreRow(tester, 'Manage Employees');

      await tester.tap(find.text('Ahmed Nazeeh'));
      await tester.pumpAndSettle();

      // The sentence locks BOTH controls — it renders under the role list
      // and in place of the active toggle's hint.
      expect(
        find.textContaining("This is the store's last active owner"),
        findsWidgets,
      );
      final toggle = tester.widget<Switch>(find.byType(Switch).last);
      expect(toggle.onChanged, isNull);
    },
  );

  testWidgets(
    "the server's last-owner refusal renders as its sentence",
    (tester) async {
      // Two active owners, so the client-side guard passes — the SERVER
      // still refuses (someone else demoted the other owner meanwhile),
      // and its sentence is what renders.
      final api = await boot(
        tester,
        permissions: const ['staff.view', 'staff.edit', 'roles.view'],
        staffJson: [
          _staffRow(1, 'Aminath Waheedha', 'a@tropical.mv', _ownerRole,
              active: true),
          _staffRow(2, 'Hassan Rameez', 'hassan@tropicalmart.mv', _ownerRole,
              active: true),
        ],
      );
      api.updateRefusal = MobileApiException(
        code: 'validation_failed',
        message:
            "This is the merchant's last active owner — it can be neither "
            'deactivated nor demoted.',
        status: 422,
      );
      await openMoreRow(tester, 'Manage Employees');

      await tester.tap(find.text('Hassan Rameez'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(Switch).last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining("the merchant's last active owner"),
        findsOneWidget,
      );
    },
  );

  testWidgets('a referenced branch delete renders the 409 as a sentence', (
    tester,
  ) async {
    await boot(
      tester,
      permissions: const [
        'branches.view',
        'branches.edit',
        'branches.delete',
      ],
    );
    await openMoreRow(tester, 'Manage Branches');

    await tester.tap(find.byIcon(Icons.more_vert_rounded).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete'));
    await tester.pumpAndSettle();
    // The confirm dialog, then the refusal.
    expect(find.textContaining('history must keep resolving'), findsOneWidget);
    await tester.tap(find.text('Delete').last);
    await tester.pumpAndSettle();

    expect(
      find.text(
        'This branch has recorded sales or promotions, so it stays on '
        'file. Stop using it instead.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('publishing demands the immutable-once-live confirm', (
    tester,
  ) async {
    final api = await boot(
      tester,
      permissions: const [
        'promotions.view',
        'promotions.publish',
        'promotions.cancel',
      ],
    );
    await openMoreRow(tester, 'Promotions');

    await tester.tap(find.text('Publish'));
    await tester.pumpAndSettle();

    // Nothing published yet — the confirm stands between.
    expect(api.published, isEmpty);
    expect(
      find.textContaining('cannot be changed or ended early'),
      findsOneWidget,
    );

    // Backing out keeps the draft.
    await tester.tap(find.text('Keep as draft'));
    await tester.pumpAndSettle();
    expect(api.published, isEmpty);

    await tester.tap(find.text('Publish'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Publish').last);
    await tester.pumpAndSettle();

    expect(api.published, [11]);
    // The SERVER's cost preview renders after the publish.
    expect(
      find.textContaining('During the promotion you pay 8.75%'),
      findsOneWidget,
    );
  });
}

const _ownerRole = {
  'id': 1,
  'name': 'Owner',
  'name_dv': null,
  'is_owner': true,
};

const _managerRole = {
  'id': 3,
  'name': 'Manager',
  'name_dv': null,
  'is_owner': false,
};

const _cashierRole = {
  'id': 2,
  'name': 'Cashier',
  'name_dv': null,
  'is_owner': false,
};

Map<String, dynamic> _staffRow(
  int id,
  String name,
  String email,
  Map<String, dynamic> role, {
  required bool active,
}) => {
  'id': id,
  'name': name,
  'email': email,
  'role': role,
  'is_active': active,
  'created_at': '2026-08-01T09:00:00+00:00',
};

class _FakeApi extends MerchantApi {
  _FakeApi({
    required super.session,
    required this.permissions,
    List<Map<String, dynamic>>? staffJson,
  }) : staffJson =
           staffJson ??
           [
             _staffRow(1, 'Aminath Waheedha', 'a@tropical.mv', _managerRole,
                 active: true),
             _staffRow(2, 'Mariyam Shifa', 'shifa@tropicalmart.mv',
                 _cashierRole,
                 active: true),
           ];

  final List<String> permissions;
  final List<Map<String, dynamic>> staffJson;

  /// MR9 — `meta.pending_changes` on the branches index.
  var pendingBranchChanges = const <MerchantChangeRequest>[];

  /// Every POST /merchant/staff body, verbatim.
  final invites = <Map<String, dynamic>>[];

  /// Promotion ids published.
  final published = <int>[];

  /// When set, updateStaff answers this refusal instead of succeeding.
  MobileApiException? updateRefusal;

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
  Future<List<MerchantStaff>> staff() async => [
    for (final json in staffJson) MerchantStaff.fromJson(json),
  ];

  @override
  Future<StaffInviteResult> inviteStaff({
    required String name,
    required String email,
    required int merchantRoleId,
  }) async {
    invites.add({
      'name': name,
      'email': email,
      'merchant_role_id': merchantRoleId,
    });
    return StaffInviteResult(
      staff: MerchantStaff.fromJson(
        _staffRow(9, name, email, _cashierRole, active: true),
      ),
      tempPassword: 'q8#Vt2!mXw9zL0pR4sBd',
    );
  }

  @override
  Future<MerchantStaff> updateStaff(
    int id, {
    String? name,
    String? email,
    int? merchantRoleId,
    bool? isActive,
  }) async {
    final refusal = updateRefusal;
    if (refusal != null) throw refusal;
    return MerchantStaff.fromJson(staffJson.first);
  }

  @override
  Future<List<MerchantRole>> roles() async => [
    MerchantRole.fromJson(const {
      'id': 1,
      'name': 'Owner',
      'name_dv': null,
      'slug': 'owner',
      'is_owner': true,
      'is_system': true,
      'permissions': ['credits.create', 'staff.view', 'roles.manage'],
      'staff_count': 1,
    }),
    MerchantRole.fromJson(const {
      'id': 2,
      'name': 'Cashier',
      'name_dv': null,
      'slug': 'staff',
      'is_owner': false,
      'is_system': true,
      'permissions': ['credits.create'],
      'staff_count': 1,
    }),
  ];

  @override
  Future<PermissionCatalogue> permissionCatalogue() async =>
      PermissionCatalogue.fromJson(const {
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
            ],
          },
          {
            'slug': 'account',
            'label': 'Account',
            'permissions': [
              {
                'slug': 'staff.view',
                'label': 'View staff accounts',
                'group': 'account',
              },
              {
                'slug': 'roles.manage',
                'label': 'Create and edit roles',
                'group': 'account',
              },
            ],
          },
        ],
      });

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
  Future<BranchDeleteResult> deleteBranch(int id) async {
    throw MobileApiException(
      code: 'branch_referenced',
      message:
          'This branch is referenced by transactions or promotions and '
          'cannot be deleted.',
      status: 409,
    );
  }

  @override
  Future<List<Promotion>> promotions({String? status}) async => [
    Promotion.fromJson(const {
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
      'max_cashback_per_customer_laari': null,
      'published_at': null,
      'cancelled_at': null,
    }),
  ];

  @override
  Future<PromotionWriteResult> publishPromotion(int id) async {
    published.add(id);
    return PromotionWriteResult(
      promotion: Promotion.fromJson(const {
        'cashback_rate_percent': '7.50',
        'platform_fee_percent': '1.25',
        'all_in_percent': '8.75',
        'id': 11,
        'merchant_id': 7,
        'branch_id': null,
        'status': 'published',
        'is_live': true,
        'starts_at': '2026-08-20T10:00:00+05:00',
        'ends_at': '2026-08-22T22:00:00+05:00',
        'min_purchase_laari': 10000,
        'max_cashback_per_customer_laari': null,
        'published_at': '2026-08-17T10:00:00+05:00',
        'cancelled_at': null,
      }),
      costPreview: PromotionCostPreview.fromJson(const {
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
      }),
    );
  }

  /// GET /merchant/fee-promotion. Defaults to NOTHING RUNNING — the state
  /// every shipped assertion and golden was written against — and is
  /// settable so a test can throw the switch the way a superadmin does.
  MerchantFeePromotion promotion = MerchantFeePromotion.none;

  @override
  Future<MerchantFeePromotion> feePromotion() async => promotion;
}
