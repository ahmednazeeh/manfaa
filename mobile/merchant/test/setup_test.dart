import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';
import 'package:manfaa_merchant/app/app.dart';
import 'package:manfaa_merchant/app/providers.dart';
import 'package:manfaa_merchant/features/setup/rate_step.dart';
import 'package:manfaa_merchant/features/setup/setup_common.dart';

/// MR1 tests: the signup flow, the resumable wizard, and the status
/// screens — the whole app pumped with the router routing by status, a fake
/// MerchantApi driving the REAL session store, exactly the MR0 seams.
void main() {
  const ownerPermissions = [
    'setup.view',
    'setup.edit',
    'setup.submit',
    'branding.update',
  ];

  MobileConfig config() => MobileConfig.fromJson({
        'apps': {
          'merchant': {
            'android': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
            'ios': {'minimum_build': 1, 'latest_build': 1, 'store_url': ''},
          },
        },
        'features': const {},
      });

  Map<String, dynamic> setupJson({
    String status = 'draft',
    bool profileDone = false,
    String? category,
    String channel = 'in_store',
    bool pinned = false,
    String? rate,
    String? terms,
    String? description,
    String? logoUrl,
    String? submittedAt,
    String? rejectedReason,
  }) =>
      {
        'status': status,
        'steps': {
          'profile': profileDone,
          'location': pinned,
          'logo': logoUrl != null,
          'rate': rate != null,
        },
        'values': {
          'name': 'Fresh Mart',
          'slug': 'fresh-mart',
          'category': category,
          'channel': channel,
          'eligibility_basis': terms,
          'description': description,
          'contact_email': 'owner@freshmart.mv',
          'contact_phone': '+9607712345',
          'support_phone': null,
          'website_url': null,
          'primary_branch': pinned
              ? {
                  'id': 1,
                  'name': 'Fresh Mart',
                  'address': null,
                  'lat': 4.1755354,
                  'lng': 73.5093474,
                }
              : null,
          'logo_url': logoUrl,
          'cashback_rate_percent': rate,
        },
        'rate_bounds': {'min_percent': '0.50', 'max_percent': '10.00'},
        'categories': [
          {'slug': 'grocery', 'name_en': 'Grocery', 'name_dv': 'ފިހާރަ'},
          {'slug': 'dining', 'name_en': 'Dining', 'name_dv': null},
          {'slug': 'fashion', 'name_en': 'Fashion', 'name_dv': null},
        ],
        'submitted_at': submittedAt,
        'rejected_reason': rejectedReason,
      };

  /// A store already holding a signed-in owner session with [status].
  Future<MemorySecretStore> seededStore(String status) async {
    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.saveSession(
      token: 't-1',
      userName: 'Aishath Riza',
      userEmail: 'owner@freshmart.mv',
      merchantId: 5,
      merchantName: 'Fresh Mart',
      merchantSlug: 'fresh-mart',
      merchantStatus: status,
      permissions: ownerPermissions,
    );
    return store;
  }

  Widget app(MemorySecretStore store, _SetupFakeApi Function(MerchantSession) makeApi) =>
      ProviderScope(
        overrides: [
          secretStoreProvider.overrideWithValue(store),
          apiProvider.overrideWith((ref) => makeApi(ref.watch(sessionProvider))),
          configProvider.overrideWith((ref) async => config()),
        ],
        child: const MerchantApp(),
      );

  Future<void> settleTall(WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(420, 1800));
    await tester.pumpAndSettle();
  }

  group('firstIncompleteStep (web-parity resume rule)', () {
    MerchantSetupState state(Map<String, dynamic> json) =>
        MerchantSetupState.fromJson(json);

    test('a fresh signup starts at profile', () {
      expect(firstIncompleteStep(state(setupJson())), 0);
    });

    test('profile done, unpinned counter store stops at location', () {
      expect(
        firstIncompleteStep(state(setupJson(
            profileDone: true, category: 'grocery', description: 'A shop.'))),
        1,
      );
    });

    test('online-only SKIPS location straight to rate', () {
      expect(
        firstIncompleteStep(state(setupJson(
            profileDone: true,
            category: 'grocery',
            description: 'A shop.',
            channel: 'online'))),
        3,
      );
    });

    test('everything set lands on review; logo never required', () {
      expect(
        firstIncompleteStep(state(setupJson(
          profileDone: true,
          category: 'grocery',
          description: 'A shop.',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
        ))),
        5,
      );
    });

    test('a saved profile with NO description resumes at the profile step',
        () {
      // The description became a submit requirement after stores had
      // already passed this step, so `steps.profile` is true while the
      // field is empty: sending them back here is the alternative to a
      // refusal five steps later at the submit button.
      expect(
        firstIncompleteStep(state(setupJson(
          profileDone: true,
          category: 'grocery',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
        ))),
        0,
      );
    });

    test('a description of only whitespace is no description', () {
      expect(
        firstIncompleteStep(state(setupJson(
          profileDone: true,
          category: 'grocery',
          description: '   ',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
        ))),
        0,
      );
    });
  });

  group('rate math', () {
    test('parsePercentToBp handles the §11 percent-string shapes', () {
      expect(parsePercentToBp('2'), 200);
      expect(parsePercentToBp('2.5'), 250);
      expect(parsePercentToBp('2.75'), 275);
      expect(parsePercentToBp('0.50'), 50);
      expect(parsePercentToBp('10.00'), 1000);
      expect(parsePercentToBp('2,5'), isNull);
      expect(parsePercentToBp('2.505'), isNull);
      expect(parsePercentToBp('-1'), isNull);
      expect(parsePercentToBp(''), isNull);
    });

    test('formatBp renders trimmed percents', () {
      expect(formatBp(200), '2%');
      expect(formatBp(250), '2.5%');
      expect(formatBp(275), '2.75%');
      expect(formatBp(25), '0.25%');
    });

    test('the static fee bands match the web wizard table', () {
      expect(staticFeeBp(99), 25);
      expect(staticFeeBp(100), 50);
      expect(staticFeeBp(199), 50);
      expect(staticFeeBp(200), 75);
      expect(staticFeeBp(499), 75);
      expect(staticFeeBp(500), 100);
      expect(staticFeeBp(2000), 100);
    });
  });

  testWidgets('signup happy path: phone → code → details → the wizard',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(420, 1800));
    final store = MemorySecretStore();
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(session: s, setup: setupJson()),
    ));
    await tester.pumpAndSettle();

    // The login's footer link opens the signup flow.
    expect(find.text('Merchant login'), findsOneWidget);
    await tester.tap(find.text('Register your store'));
    await tester.pumpAndSettle();
    expect(find.text('Phone number'), findsOneWidget);

    await tester.enterText(find.byType(TextField), '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    // The code step, with the normalised phone echoed back.
    expect(find.textContaining('+9607712345'), findsOneWidget);
    await tester.enterText(find.byType(TextField), '123456');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    // Details: name, optional Thaana name, email, password.
    expect(find.text('Tell us about your store'), findsOneWidget);
    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), 'Fresh Mart');
    await tester.enterText(fields.at(2), 'owner@freshmart.mv');
    await tester.enterText(fields.at(3), 'hunter22');
    await tester.tap(find.text('Create my store'));
    await tester.pumpAndSettle();

    // Registered = signed in as draft = the wizard's first step.
    expect(api.registered, isTrue);
    expect(find.text('Set up your store'), findsOneWidget);
    expect(find.text('Store profile'), findsOneWidget);
    expect(find.text('Grocery'), findsOneWidget);
  });

  // ---- the validation window at signup (owner, 2026-08-25) ---------------
  //
  // The field is the SERVER's: its label, its explanation and its ceiling
  // all come from GET /merchant/signup/options, read before the form is
  // submitted. The two tests below are the two halves of that contract —
  // the range is offered and travels, and a read that failed leaves signup
  // byte-identical to how it behaved before the field existed.

  testWidgets('the validation window is offered up to the platform ceiling '
      'and travels with register', (tester) async {
    await tester.binding.setSurfaceSize(const Size(420, 1800));
    final store = MemorySecretStore();
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(session: s, setup: setupJson()),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register your store'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField), '123456');
    await tester.tap(find.text('Verify'));
    await tester.pumpAndSettle();

    // The server's own label and its plain-language sentence, verbatim.
    expect(find.text('Validation window'), findsOneWidget);
    expect(
      find.textContaining('Choose between 0 and 3 days'),
      findsOneWidget,
    );

    // The served default is preselected.
    expect(find.byKey(const Key('signup-validation-window')), findsOneWidget);
    expect(find.text('2 days'), findsOneWidget);

    // The list stops AT the platform ceiling: 3 exists, 4 does not.
    await tester.tap(find.byKey(const Key('signup-validation-window')));
    await tester.pumpAndSettle();
    expect(find.text('3 days'), findsWidgets);
    expect(find.text('4 days'), findsNothing);
    expect(find.text('Same day — no wait'), findsWidgets);
    await tester.tap(find.text('3 days').last);
    await tester.pumpAndSettle();

    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), 'Fresh Mart');
    await tester.enterText(fields.at(2), 'owner@freshmart.mv');
    await tester.enterText(fields.at(3), 'hunter22');
    await tester.tap(find.text('Create my store'));
    await tester.pumpAndSettle();

    expect(api.registered, isTrue);
    expect(api.registeredWindowDays, 3);
  });

  testWidgets('a failed options read draws no field and sends nothing',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(420, 1800));
    final store = MemorySecretStore();
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(
        session: s,
        setup: setupJson(),
        signupOptionsJson: null,
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register your store'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField), '123456');
    await tester.tap(find.text('Verify'));
    await tester.pumpAndSettle();

    // No field at all — never a guessed ceiling. A hard-coded 3 here would
    // keep offering 3 on the afternoon an admin lowered the platform to 1.
    expect(find.text('Validation window'), findsNothing);
    expect(find.byKey(const Key('signup-validation-window')), findsNothing);

    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), 'Fresh Mart');
    await tester.enterText(fields.at(2), 'owner@freshmart.mv');
    await tester.enterText(fields.at(3), 'hunter22');
    await tester.tap(find.text('Create my store'));
    await tester.pumpAndSettle();

    // Registered with the key omitted: the store gets the platform default,
    // exactly as signup behaved before this field existed.
    expect(api.registered, isTrue);
    expect(api.registeredWindowDays, isNull);
  });

  testWidgets('a short password is refused client-side before the wire',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(420, 1800));
    final store = MemorySecretStore();
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(session: s, setup: setupJson()),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register your store'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '7712345');
    await tester.tap(find.text('Continue'));
    await tester.pump(const Duration(milliseconds: 50));
    await tester.enterText(find.byType(TextField), '123456');
    await tester.tap(find.text('Verify'));
    await tester.pump(const Duration(milliseconds: 50));

    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), 'Fresh Mart');
    await tester.enterText(fields.at(2), 'owner@freshmart.mv');
    await tester.enterText(fields.at(3), 'short');
    await tester.tap(find.text('Create my store'));
    await tester.pump();

    expect(api.registered, isFalse);
    expect(
      find.textContaining('password of at least 8 characters'),
      findsOneWidget,
    );
  });

  testWidgets('an EMPTY description refuses the profile step before the wire',
      (tester) async {
    final store = await seededStore('draft');
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(session: s, setup: setupJson()),
    ));
    await settleTall(tester);

    // Everything else the step needs, so the description is the ONLY thing
    // standing between this owner and step 2.
    await tester.tap(find.text('Grocery'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();

    // Still on the profile step, with the refusal under the field — and the
    // hint it replaced named the ceiling before any of this.
    expect(find.text('Store profile'), findsOneWidget);
    expect(find.text('Describe your store to continue.'), findsOneWidget);
    expect(api.profileSaves, isEmpty);
  });

  testWidgets('181 words is refused client-side, before the request',
      (tester) async {
    final store = await seededStore('draft');
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(session: s, setup: setupJson()),
    ));
    await settleTall(tester);

    await tester.tap(find.text('Grocery'));
    await tester.pumpAndSettle();

    // The counter is live: 181 words reads as over the ceiling the moment
    // it is typed, and the step refuses to spend a round trip on it.
    final tooLong = List.filled(181, 'word').join(' ');
    await tester.enterText(find.byType(TextField).first, tooLong);
    await tester.pumpAndSettle();
    expect(find.text('181 / 180 words'), findsOneWidget);

    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();

    expect(api.profileSaves, isEmpty);
    expect(
      find.text('That is over 180 words. Shorten it to continue.'),
      findsOneWidget,
    );

    // Exactly 180 is inside the ceiling — the boundary is "more than", the
    // server's own reading of MaxWords(180).
    await tester.enterText(
      find.byType(TextField).first,
      List.filled(180, 'word').join(' '),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();

    expect(api.profileSaves, hasLength(1));
    expect(countWords(api.profileSaves.single['description'] as String), 180);
  });

  testWidgets('a saved description travels and the step moves on',
      (tester) async {
    final store = await seededStore('draft');
    late _SetupFakeApi api;
    await tester.pumpWidget(app(
      store,
      (s) => api = _SetupFakeApi(session: s, setup: setupJson()),
    ));
    await settleTall(tester);

    await tester.tap(find.text('Grocery'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextField).first,
      '  A neighbourhood grocery on Majeedhee Magu.  ',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();

    // Trimmed, verbatim, and the wizard walked to the location step.
    expect(
      api.profileSaves.single['description'],
      'A neighbourhood grocery on Majeedhee Magu.',
    );
    expect(find.text('Store location'), findsOneWidget);
  });

  testWidgets('the review step lists the description and labels it when '
      'the server says it is missing', (tester) async {
    final store = await seededStore('draft');
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(
          profileDone: true,
          category: 'grocery',
          description: 'A neighbourhood grocery on Majeedhee Magu.',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
        ),
        missingOnSubmit: const ['description'],
      ),
    ));
    await settleTall(tester);

    expect(find.text('Review & submit'), findsOneWidget);
    expect(find.text('Description'), findsOneWidget);
    expect(
      find.text('A neighbourhood grocery on Majeedhee Magu.'),
      findsOneWidget,
    );

    await tester.tap(find.text('Submit for review'));
    await tester.pumpAndSettle();

    // The new key is LABELLED, never printed as the raw wire word.
    expect(find.text('Describe your store'), findsOneWidget);
    expect(find.text('description'), findsNothing);

    // And the fix link lands on the step that owns the field.
    await tester.tap(find.text('Describe your store'));
    await tester.pumpAndSettle();
    expect(find.text('Store profile'), findsOneWidget);
  });

  testWidgets('the wizard resumes at the first incomplete step (terms)',
      (tester) async {
    final store = await seededStore('draft');
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(
          profileDone: true,
          category: 'grocery',
          description: 'A neighbourhood grocery on Majeedhee Magu.',
          pinned: true,
          rate: '2.00',
        ),
      ),
    ));
    await settleTall(tester);

    expect(find.text('Set up your store'), findsOneWidget);
    expect(find.text('Step 5 of 6 · Terms'), findsOneWidget);
    expect(find.text('Eligible spending'), findsOneWidget);
  });

  testWidgets('online-only resumes at rate — the location step is skipped',
      (tester) async {
    final store = await seededStore('draft');
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(
          profileDone: true,
          category: 'grocery',
          description: 'A neighbourhood grocery on Majeedhee Magu.',
          channel: 'online',
        ),
      ),
    ));
    await settleTall(tester);

    expect(find.text('Step 4 of 6 · Rate'), findsOneWidget);
    expect(
      find.text('What customers earn on eligible spending at your store.'),
      findsOneWidget,
    );
  });

  testWidgets(
      'submit-incomplete renders missing[] as fix links that jump back',
      (tester) async {
    final store = await seededStore('draft');
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(
          profileDone: true,
          category: 'grocery',
          description: 'A neighbourhood grocery on Majeedhee Magu.',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
        ),
        missingOnSubmit: const ['category', 'terms'],
      ),
    ));
    await settleTall(tester);

    // Complete state resumes at review.
    expect(find.text('Review & submit'), findsOneWidget);
    await tester.tap(find.text('Submit for review'));
    await tester.pumpAndSettle();

    expect(find.text('Still needed before review'), findsOneWidget);
    expect(find.text('Pick a category'), findsOneWidget);
    expect(find.text('Describe what earns cashback'), findsOneWidget);
    // Never the raw code.
    expect(find.textContaining('setup_incomplete'), findsNothing);

    // The fix link jumps back into the owning step.
    await tester.tap(find.text('Pick a category'));
    await tester.pumpAndSettle();
    expect(find.text('Store profile'), findsOneWidget);
  });

  testWidgets('a successful submit walks to the pending_review screen',
      (tester) async {
    final store = await seededStore('draft');
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(
          profileDone: true,
          category: 'grocery',
          description: 'A neighbourhood grocery on Majeedhee Magu.',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
        ),
      ),
    ));
    await settleTall(tester);

    await tester.tap(find.text('Submit for review'));
    await tester.pumpAndSettle();

    expect(find.text('Your store is under review'), findsOneWidget);
    expect(find.textContaining('Submitted'), findsOneWidget);
    expect(find.text('Sign out'), findsOneWidget);
  });

  testWidgets('rejected shows the admin reason and reopens the wizard',
      (tester) async {
    const reason = 'Please use a clearer logo and a real contact number.';
    final store = await seededStore('rejected');
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(
          status: 'rejected',
          profileDone: true,
          category: 'grocery',
          description: 'A neighbourhood grocery on Majeedhee Magu.',
          pinned: true,
          rate: '2.00',
          terms: 'All items.',
          rejectedReason: reason,
        ),
      ),
    ));
    await settleTall(tester);

    // The status screen: the reason verbatim + the reopen action.
    expect(find.text('Changes requested'), findsOneWidget);
    expect(find.text(reason), findsOneWidget);

    await tester.tap(find.text('Edit and resubmit'));
    await tester.pumpAndSettle();

    // Back in the wizard (resumed at review — everything is filled), with
    // the reason banner still in sight while editing.
    expect(find.text('Review & submit'), findsOneWidget);
    expect(find.text(reason), findsOneWidget);
  });

  testWidgets('staff without setup.view get the owner notice, not an error',
      (tester) async {
    final store = MemorySecretStore();
    final session = MerchantSession(store);
    await session.init();
    await session.saveSession(
      token: 't-1',
      userName: 'Cashier',
      userEmail: 'till@freshmart.mv',
      merchantId: 5,
      merchantName: 'Fresh Mart',
      merchantSlug: 'fresh-mart',
      merchantStatus: 'draft',
      permissions: const ['credits.create'],
    );
    await tester.pumpWidget(app(
      store,
      (s) => _SetupFakeApi(
        session: s,
        setup: setupJson(),
        permissions: const ['credits.create'],
      ),
    ));
    await settleTall(tester);

    expect(find.text('Setup needs the owner'), findsOneWidget);
    expect(find.text('Sign out'), findsOneWidget);
    expect(find.text('Store profile'), findsNothing);
  });
}

/// `GET /merchant/signup/options` exactly as production answers it today:
/// a 0–3 day window defaulting to 2, with the server's own label and its
/// plain-language explanation carrying the live numbers.
const _liveSignupOptions = <String, dynamic>{
  'validation_window': {
    'min_days': 0,
    'max_days': 3,
    'default_days': 2,
    'label_en': 'Validation window',
    'label_dv': 'ވެލިޑޭޝަން މުއްދަތު',
    'help_en': 'How many days a sale stays open for returns before its '
        'cashback is confirmed. Cashback stays pending until the window '
        'ends. Choose between 0 and 3 days — 2 if you are not sure.',
    'help_dv': 'ވިޔަފާރި ރިޓަރންކުރުމަށް ދޭ މުއްދަތު.',
    'invalid_en':
        'The validation window must be a whole number of days between 0 and 3.',
    'invalid_dv': '0 އާއި 3 އާ ދެމެދުގެ ދުވަހުގެ އަދަދެއް ލިޔުއްވާ.',
  },
};

/// Fake MerchantApi over the REAL session store: the wizard endpoints
/// mutate one in-memory setup map the way the server would.
class _SetupFakeApi extends MerchantApi {

  // Signed in as a draft store, the wizard is the only surface — no shell,
  // so no guide chip. Overridden anyway because the base class would reach
  // the NETWORK from a unit test.
  @override
  Future<MerchantOnboardingGuide> onboarding() async =>
      MerchantOnboardingGuide.hidden;

  /// The validation-window range the platform allows, as the live server
  /// serves it today. `signupOptionsJson: null` answers `unknown`, which is
  /// what a FAILED read looks like: no field drawn, nothing sent.
  final Map<String, dynamic>? signupOptionsJson;

  @override
  Future<MerchantSignupOptions> signupOptions() async =>
      signupOptionsJson == null
          ? MerchantSignupOptions.unknown
          : MerchantSignupOptions.fromJson(signupOptionsJson!);
  _SetupFakeApi({
    required super.session,
    required this.setup,
    this.missingOnSubmit = const [],
    this.signupOptionsJson = _liveSignupOptions,
    this.permissions = const [
      'setup.view',
      'setup.edit',
      'setup.submit',
      'branding.update',
    ],
  });

  final Map<String, dynamic> setup;
  final List<String> missingOnSubmit;
  final List<String> permissions;
  var registered = false;

  /// Every PATCH /merchant/setup/profile the step actually made — the seam
  /// that proves a client-side refusal never reached the wire.
  final profileSaves = <Map<String, dynamic>>[];

  Map<String, dynamic> get _values => setup['values'] as Map<String, dynamic>;
  Map<String, dynamic> get _steps => setup['steps'] as Map<String, dynamic>;

  MerchantSetupState _state() => MerchantSetupState.fromJson(setup);

  @override
  Future<MerchantMe> me() async {
    final me = MerchantMe.fromJson({
      'user': {'id': 1, 'name': 'Aishath Riza', 'email': 'owner@freshmart.mv'},
      'merchant': {
        'id': 5,
        'name': 'Fresh Mart',
        'slug': 'fresh-mart',
        'status': setup['status'],
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
  Future<void> requestSignupOtp(String phone) async {}

  @override
  Future<String> verifySignupOtp({
    required String phone,
    required String code,
  }) async =>
      'signup-token-1';

  /// What the last register carried, so a test can assert the validation
  /// window actually travelled (or deliberately did not).
  int? registeredWindowDays;

  @override
  Future<void> registerMerchant({
    required String signupToken,
    required String businessName,
    String? businessNameDv,
    required String email,
    required String password,
    required String deviceName,
    int? validationWindowDays,
  }) async {
    registered = true;
    registeredWindowDays = validationWindowDays;
    await session.saveSession(
      token: 't-new',
      userName: businessName,
      userEmail: email,
      merchantId: 5,
      merchantName: businessName,
      merchantSlug: 'fresh-mart',
      merchantStatus: 'draft',
      permissions: permissions,
    );
  }

  @override
  Future<MerchantSetupState> getSetup() async => _state();

  @override
  Future<MerchantSetupState> saveSetupProfile({
    required String category,
    required String channel,
    String? description,
    String? contactEmail,
    String? contactPhone,
    String? supportPhone,
    String? websiteUrl,
  }) async {
    profileSaves.add({'category': category, 'description': description});
    _values['category'] = category;
    _values['channel'] = channel;
    _values['description'] = description;
    _values['contact_email'] = contactEmail;
    _values['contact_phone'] = contactPhone;
    _values['support_phone'] = supportPhone;
    _values['website_url'] = websiteUrl;
    _steps['profile'] = true;
    return _state();
  }

  @override
  Future<MerchantSetupState> saveSetupTerms(String eligibilityBasis) async {
    _values['eligibility_basis'] = eligibilityBasis;
    return _state();
  }

  @override
  Future<MerchantSetupState> saveSetupLocation({
    required double lat,
    required double lng,
    required String address,
  }) async {
    _values['primary_branch'] = {
      'id': 1,
      'name': 'Fresh Mart',
      'address': address,
      'lat': lat,
      'lng': lng,
    };
    _steps['location'] = true;
    return _state();
  }

  @override
  Future<MerchantSetupState> saveSetupRate(String percent) async {
    _values['cashback_rate_percent'] = percent;
    _steps['rate'] = true;
    return _state();
  }

  @override
  Future<MerchantSetupState> submitSetup() async {
    if (missingOnSubmit.isNotEmpty) {
      throw MobileApiException(
        code: ApiCode.setupIncomplete,
        message: 'The store setup is not complete.',
        meta: {'missing': missingOnSubmit},
        status: 422,
      );
    }
    setup['status'] = 'pending_review';
    setup['submitted_at'] = '2026-08-17T09:41:00Z';
    setup['rejected_reason'] = null;
    return _state();
  }

  /// GET /merchant/fee-promotion. Defaults to NOTHING RUNNING — the state
  /// every shipped assertion and golden was written against — and is
  /// settable so a test can throw the switch the way a superadmin does.
  MerchantFeePromotion promotion = MerchantFeePromotion.none;

  @override
  Future<MerchantFeePromotion> feePromotion() async => promotion;
}
