import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:manfaa_core/manfaa_core.dart';

/// Canned-response adapter that records every request, so a test can assert
/// on the exact path, method and body without a server.
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

/// GET /merchant/signup/options — Onboarding\SignupOptions::payload().
const _optionsFixture = {
  'data': {
    'validation_window': {
      'min_days': 0,
      'max_days': 3,
      'default_days': 2,
      'label_en': 'Validation window',
      'label_dv': 'ވެލިޑޭޝަން މުއްދަތު',
      'help_en':
          'How many days a sale stays open for returns before its cashback '
              'is confirmed. Choose between 0 and 3 days — 2 if you are not sure.',
      'help_dv': 'ވިޔަފާރި ރިޓަރންކުރުމަށް ދޭ މުއްދަތު.',
      'invalid_en':
          'The validation window must be a whole number of days between 0 and 3.',
      'invalid_dv': '0 އާއި 3 އާ ދެމެދުގެ ދުވަހުގެ އަދަދެއް ލިޔުއްވާ.',
    },
  },
};

/// GET /merchant/onboarding — OnboardingGuide::state(), day one, owner.
const _guideFixture = {
  'data': {
    'show': true,
    'skipped': false,
    'expired': false,
    'tour_completed': false,
    'started_at': '2026-08-25T19:30:00+00:00',
    'expires_at': '2026-08-30T19:30:00+00:00',
    'days_remaining': 5,
    'window_days': 5,
    'title_en': 'Getting started',
    'title_dv': 'ފެށުމުގެ ފިޔަވަޅުތައް',
    'tasks': [
      {
        'key': 'finish_setup',
        'label_en': 'Finish setup and submit your store',
        'label_dv': 'ސެޓަޕް ފުރިހަމަކުރައްވައި ފިހާރަ ހުށަހަޅުއްވާ',
        'help_en': 'Fill in your store details, then submit for review.',
        'help_dv': 'ފިހާރައިގެ ތަފްޞީލުތައް ފުރިހަމަކުރައްވާ.',
        'done': true,
        'permission': 'setup.submit',
        'target': 'setup',
        'web_path': '/setup',
      },
      {
        'key': 'bank_account',
        'label_en': 'Add your bank account',
        'label_dv': 'ބޭންކް އެކައުންޓް އިތުރުކުރައްވާ',
        'help_en': 'Your settlement transfers are matched against it.',
        'help_dv': 'ސެޓްލްމަންޓަށް ފޮނުއްވާ ފައިސާ ދިމާކުރަނީ މި އެކައުންޓާއެވެ.',
        'done': false,
        'permission': 'bank_account.update',
        'target': 'bank_account',
        'web_path': '/settings/bank-account',
      },
      {
        'key': 'credit_customer',
        'label_en': 'Credit your first customer',
        'label_dv': 'ފުރަތަމަ ކަސްޓަމަރަށް ކްރެޑިޓްކުރައްވާ',
        'help_en': 'Key in the phone number and what they spent.',
        'help_dv': 'ފޯނު ނަންބަރާއި ވިޔަފާރިކުރެއްވި އަދަދު ލިޔުއްވާ.',
        'done': false,
        'permission': 'credits.create',
        'target': 'credit',
        'web_path': '/credit',
      },
    ],
    'tasks_done': 1,
    'tasks_total': 3,
    'all_done': false,
  },
};

void main() {
  group('validation window at signup', () {
    test('parses the live bounds and the copy in both languages', () {
      final options = MerchantSignupOptions.fromJson(
        (_optionsFixture['data'] as Map).cast<String, dynamic>(),
      );
      final window = options.validationWindow;

      expect(window.ready, isTrue);
      expect(window.minDays, 0);
      expect(window.maxDays, 3);
      expect(window.defaultDays, 2);
      expect(window.label(dhivehi: false), 'Validation window');
      expect(window.label(dhivehi: true), 'ވެލިޑޭޝަން މުއްދަތު');
      expect(window.help(dhivehi: true), isNotEmpty);
      expect(
        window.invalid(dhivehi: false),
        'The validation window must be a whole number of days between 0 and 3.',
      );
      expect(window.invalid(dhivehi: true), isNot(window.invalid(dhivehi: false)));
    });

    test('accepts exactly what App\\Rules\\ValidationWindowDays accepts', () {
      const window = ValidationWindowOption(
        minDays: 0,
        maxDays: 3,
        defaultDays: 2,
      );

      // Floor, ceiling, and the numeric string a text field hands back.
      expect(window.accepts(0), isTrue);
      expect(window.accepts(3), isTrue);
      expect(window.accepts('3'), isTrue);

      // Above the ceiling, below the floor, and half days.
      expect(window.accepts(4), isFalse);
      expect(window.accepts(-1), isFalse);
      expect(window.accepts(2.5), isFalse);
      expect(window.accepts('2.5'), isFalse);
      expect(window.accepts(''), isFalse);
      expect(window.accepts(null), isFalse);
      // Untrimmed on purpose: this answers "would the server take THIS?".
      expect(window.accepts(' 3'), isFalse);
    });

    test('a ceiling the platform lowered is the ceiling the form enforces', () {
      const tightened = ValidationWindowOption(minDays: 0, maxDays: 1);

      expect(tightened.accepts(1), isTrue);
      // The number an old hard-coded form would still have offered.
      expect(tightened.accepts(3), isFalse);
    });

    test('a read that failed offers nothing rather than guessing a ceiling', () {
      const unknown = MerchantSignupOptions.unknown;

      expect(unknown.validationWindow.ready, isFalse);
      expect(unknown.validationWindow.maxDays, isNull);
      // Nothing is inside a range we were never told.
      expect(unknown.validationWindow.accepts(2), isFalse);
      // A payload missing the block entirely lands in the same place, and so
      // does one where the block is not even an object.
      expect(
        MerchantSignupOptions.fromJson(const {}).validationWindow.ready,
        isFalse,
      );
      expect(
        MerchantSignupOptions.fromJson(
          const {'validation_window': 'nonsense'},
        ).validationWindow.ready,
        isFalse,
      );
    });

    test('GET /merchant/signup/options is public and unwrapped', () async {
      final adapter = _RecordingAdapter((_) => _json(_optionsFixture, 200));
      final options = await _api(adapter).signupOptions();

      expect(adapter.requests.single.path, '/merchant/signup/options');
      expect(adapter.requests.single.method, 'GET');
      // Nobody is signed in: no bearer goes out.
      expect(
        adapter.requests.single.headers.containsKey('Authorization'),
        isFalse,
      );
      expect(options.validationWindow.maxDays, 3);
    });

    test('register sends the chosen window, and OMITS it when null', () async {
      final adapter = _RecordingAdapter(
        (_) => _json(const {
          'data': {
            'token': 'mt-1',
            'user': {'id': 1, 'name': 'Ali', 'email': 'ali@corner.mv'},
            'merchant': {'id': 3, 'name': 'Corner Mart', 'slug': 'corner-mart'},
            'permissions': ['setup.submit'],
          },
        }, 201),
      );
      final api = _api(adapter);

      await api.registerMerchant(
        signupToken: 'st',
        businessName: 'Corner Mart',
        email: 'ali@corner.mv',
        password: 'password1',
        deviceName: 'Pixel',
        validationWindowDays: 3,
      );

      final chosen = adapter.requests.single.data as Map;
      expect(chosen['validation_window_days'], 3);

      adapter.requests.clear();

      await api.registerMerchant(
        signupToken: 'st',
        businessName: 'Corner Mart',
        email: 'ali@corner.mv',
        password: 'password1',
        deviceName: 'Pixel',
      );

      // Omitted, not null: a null would hit a NOT NULL column, and the key's
      // absence is what lets the platform default apply.
      final silent = adapter.requests.single.data as Map;
      expect(silent.containsKey('validation_window_days'), isFalse);
    });
  });

  group('guided setup tasklist', () {
    MerchantOnboardingGuide guideFrom(Map<String, Object?> data) =>
        MerchantOnboardingGuide.fromJson(data.cast<String, dynamic>());

    test('parses the whole state, tasks in the server\'s order', () {
      final guide = guideFrom(
        (_guideFixture['data'] as Map).cast<String, Object?>(),
      );

      expect(guide.show, isTrue);
      expect(guide.skipped, isFalse);
      expect(guide.expired, isFalse);
      expect(guide.tourCompleted, isFalse);
      expect(guide.windowDays, 5);
      expect(guide.daysRemaining, 5);
      expect(guide.tasksDone, 1);
      expect(guide.tasksTotal, 3);
      expect(guide.allDone, isFalse);
      expect(guide.title(dhivehi: false), 'Getting started');
      expect(guide.title(dhivehi: true), 'ފެށުމުގެ ފިޔަވަޅުތައް');
      expect(guide.startedAt, DateTime.utc(2026, 8, 25, 19, 30));
      expect(guide.expiresAt, DateTime.utc(2026, 8, 30, 19, 30));
      expect(guide.tasks.map((t) => t.key), [
        MerchantOnboardingTaskKey.finishSetup,
        MerchantOnboardingTaskKey.bankAccount,
        MerchantOnboardingTaskKey.creditCustomer,
      ]);
      expect(guide.tasks.first.done, isTrue);
      expect(guide.tasks.first.target, 'setup');
      expect(guide.tasks.first.webPath, '/setup');
      expect(guide.tasks[2].help(dhivehi: true), isNotEmpty);
      expect(guide.unknownTaskKeys, isEmpty);
    });

    test('a task key from a newer server is DROPPED, never thrown', () {
      final payload = {
        ...(_guideFixture['data'] as Map).cast<String, Object?>(),
        'tasks': [
          ...(_guideFixture['data'] as Map)['tasks'] as List,
          const {
            'key': 'verify_email_2027',
            'label_en': 'Something this build never heard of',
            'label_dv': 'ނޭނގޭ',
            'help_en': 'h',
            'help_dv': 'h',
            'done': false,
            'permission': 'setup.submit',
            'target': 'nowhere',
            'web_path': '/nowhere',
          },
        ],
        'tasks_total': 4,
      };

      final guide = guideFrom(payload);

      // The four known-shaped rows minus the one it cannot route.
      expect(guide.tasks.length, 3);
      expect(guide.unknownTaskKeys, ['verify_email_2027']);
      // The server's own counts are kept verbatim: they describe the whole
      // list, not the rows this build can draw.
      expect(guide.tasksTotal, 4);
    });

    test('a malformed payload degrades to hidden rather than throwing', () {
      // Every field the wrong type at once — the shape a proxy or a bad
      // deploy could produce. A TypeError here would take out the sidebar.
      final guide = guideFrom(const {
        'show': 'yes',
        'days_remaining': '5',
        'tasks': 'not a list',
        'started_at': 'not a date',
        'title_en': null,
      });

      expect(guide.show, isFalse);
      expect(guide.daysRemaining, 0);
      expect(guide.tasks, isEmpty);
      expect(guide.startedAt, isNull);
      expect(guide.title(dhivehi: false), '');
      expect(guide.checklistFor(const ['credits.create']).show, isFalse);

      // And a task row that is not even a map is skipped, not counted.
      final ragged = guideFrom(const {
        'show': true,
        'tasks': ['nonsense', 42, null],
      });
      expect(ragged.tasks, isEmpty);
      expect(ragged.unknownTaskKeys, isEmpty);
    });

    test('a row with no label in either language is dropped', () {
      final guide = guideFrom(const {
        'show': true,
        'tasks': [
          {
            'key': 'settle_bill',
            'label_en': '',
            'label_dv': '',
            'done': false,
            'permission': 'settlements.create',
          },
        ],
      });

      // A blank line in a sidebar teaches a merchant nothing.
      expect(guide.tasks, isEmpty);
    });

    test('checklistFor hides tasks this person may not do, and recounts', () {
      final guide = guideFrom(
        (_guideFixture['data'] as Map).cast<String, Object?>(),
      );

      // A cashier: no bank account, no setup submission.
      final cashier = guide.checklistFor(const [
        'credits.create',
        'transactions.view',
      ]);

      expect(cashier.show, isTrue);
      expect(cashier.tasks.single.key, MerchantOnboardingTaskKey.creditCustomer);
      // Counts describe the rows on screen — never the server's 1 of 3.
      expect(cashier.done, 0);
      expect(cashier.total, 1);
      expect(cashier.allDone, isFalse);

      final owner = guide.checklistFor(const [
        'setup.submit',
        'bank_account.update',
        'credits.create',
      ]);
      expect(owner.total, 3);
      expect(owner.done, 1);
    });

    test('a person with nothing to do is shown nothing, not an empty box', () {
      final guide = guideFrom(
        (_guideFixture['data'] as Map).cast<String, Object?>(),
      );

      final none = guide.checklistFor(const ['transactions.view']);

      expect(none.show, isFalse);
      expect(none.tasks, isEmpty);
      expect(none.total, 0);
      expect(none.allDone, isFalse);
    });

    test('allDone is true only when every visible task is done', () {
      final guide = guideFrom(const {
        'show': true,
        'tasks': [
          {
            'key': 'credit_customer',
            'label_en': 'Credit your first customer',
            'label_dv': 'ފުރަތަމަ ކަސްޓަމަރަށް ކްރެޑިޓްކުރައްވާ',
            'done': true,
            'permission': 'credits.create',
          },
        ],
      });

      expect(guide.checklistFor(const ['credits.create']).allDone, isTrue);
    });

    test('show=false wins over everything, whatever the tasks say', () {
      final guide = guideFrom(const {
        'show': false,
        'skipped': true,
        'tasks': [],
        'tasks_done': 0,
        'tasks_total': 0,
      });

      expect(guide.skipped, isTrue);
      expect(guide.checklistFor(const ['credits.create']).show, isFalse);
      // The fallback every surface uses when the read itself failed.
      expect(MerchantOnboardingGuide.hidden.show, isFalse);
      expect(MerchantOnboardingGuide.hidden.tasks, isEmpty);
    });

    test('the three routes, and the writes answer the FULL state', () async {
      final adapter = _RecordingAdapter((_) => _json(_guideFixture, 200));
      final api = _api(adapter);

      final read = await api.onboarding();
      final skipped = await api.skipOnboarding();
      final toured = await api.completeOnboardingTour();

      expect(adapter.requests.map((r) => '${r.method} ${r.path}'), [
        'GET /merchant/onboarding',
        'POST /merchant/onboarding/skip',
        'POST /merchant/onboarding/tour',
      ]);

      // Every one of the three carries the whole payload, so a client never
      // follows a write with a read.
      for (final state in [read, skipped, toured]) {
        expect(state.tasks.length, 3);
        expect(state.windowDays, 5);
      }
    });

    test('task keys map to the wire strings the server publishes', () {
      expect(
        MerchantOnboardingTaskKey.values.map((k) => k.wire),
        containsAll(const [
          'finish_setup',
          'bank_account',
          'credit_customer',
          'settle_bill',
          'add_staff',
        ]),
      );
      expect(MerchantOnboardingTaskKey.fromWire('settle_bill'),
          MerchantOnboardingTaskKey.settleBill);
      expect(MerchantOnboardingTaskKey.fromWire('nope'), isNull);
      expect(MerchantOnboardingTaskKey.fromWire(7), isNull);
      expect(MerchantOnboardingTaskKey.fromWire(null), isNull);
    });
  });
}
