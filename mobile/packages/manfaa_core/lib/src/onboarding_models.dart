/// Guided setup and the signup-time validation window (owner, 2026-08-25).
///
/// Two shapes, both served by the SAME controllers the web panel mounts, so
/// the till and the website say the same sentence:
///
///   GET /merchant/signup/options            — public, pre-submit: the
///                                             validation-window range the
///                                             platform allows TODAY.
///   GET/POST /merchant/onboarding{,/skip,/tour}
///                                           — the signed-in person's own
///                                             five-day tasklist.
///
/// DEFENSIVE ALL THE WAY DOWN, same law as fee_promotion_models.dart. The
/// server is allowed to be a deploy ahead of the app in the customer's
/// pocket: a task key this build has never heard of is DROPPED, never
/// thrown, so a sixth task added next quarter leaves an old till showing
/// five rows instead of showing a crash. Every field degrades to a null or
/// an empty rather than a TypeError out of a parser.
library;

int _count(Object? v) => switch (v) {
  final int i => i,
  _ => 0,
};

/// Absent, unreadable, or the wrong type — all the same answer: the server
/// did not say. Never a 0, which would read as a real number of days.
int? _countOrNull(Object? v) => v is int ? v : null;

String _s(Object? v) => v?.toString() ?? '';

bool _flag(Object? v) => v == true;

/// ISO 8601 → UTC, or null for anything this build cannot read.
DateTime? _instant(Object? v) {
  if (v is! String || v.isEmpty) return null;
  return DateTime.tryParse(v)?.toUtc();
}

/// Which of a pair of server-written strings to show. The Dhivehi one wins
/// for a Dhivehi reader when it is actually there; an empty either way ends
/// as an empty string, which every caller must treat as "draw nothing"
/// rather than as a label.
String _pick(String en, String dv, {required bool dhivehi}) {
  if (dhivehi && dv.isNotEmpty) return dv;
  if (en.isNotEmpty) return en;
  return dv;
}

/// Whole days as the server's own rule counts them: an int, or the all-digit
/// STRING a text field hands back. `2.5`, `"2.5"`, `""` and `" 3"` are not
/// days — the untrimmed string is the point, because this answers "would the
/// server take this?" and not "could this be tidied into something it would
/// take".
///
/// The client mirror of `App\Rules\ValidationWindowDays::isWholeNumber`.
int? validationWindowWholeDays(Object? value) {
  if (value is int) return value;
  if (value is String && RegExp(r'^-?\d+$').hasMatch(value)) {
    return int.tryParse(value);
  }
  return null;
}

/// The validation-window field, described by the server that validates it.
///
/// THERE IS NO FALLBACK CEILING IN THIS FILE, ON PURPOSE. The maximum is
/// admin policy, read at request time; a hard-coded 3 would keep the till
/// offering 3 on the afternoon an admin lowered the platform to 1, and every
/// merchant who took it would be refused at submit by a rule they were never
/// shown. When the options read fails, [ready] is false and the form must
/// OMIT `validation_window_days` from register entirely — the server then
/// applies the platform default, which is exactly what signup did before
/// this field existed.
class ValidationWindowOption {
  const ValidationWindowOption({
    this.minDays,
    this.maxDays,
    this.defaultDays,
    this.labelEn = '',
    this.labelDv = '',
    this.helpEn = '',
    this.helpDv = '',
    this.invalidEn = '',
    this.invalidDv = '',
  });

  /// What a failed or not-yet-answered read means: ask for nothing.
  static const unknown = ValidationWindowOption();

  factory ValidationWindowOption.fromJson(Map<String, dynamic> json) =>
      ValidationWindowOption(
        minDays: _countOrNull(json['min_days']),
        maxDays: _countOrNull(json['max_days']),
        defaultDays: _countOrNull(json['default_days']),
        labelEn: _s(json['label_en']),
        labelDv: _s(json['label_dv']),
        helpEn: _s(json['help_en']),
        helpDv: _s(json['help_dv']),
        invalidEn: _s(json['invalid_en']),
        invalidDv: _s(json['invalid_dv']),
      );

  /// Always 0 when served — a merchant may always tighten to immediate
  /// validation. Served rather than assumed so the copy can name it.
  final int? minDays;

  /// The live admin ceiling. Render THIS as the field's bound.
  final int? maxDays;

  /// What a store that says nothing at signup is created with — the value
  /// to preselect.
  final int? defaultDays;

  final String labelEn;
  final String labelDv;

  /// The instructional sentence, with the live numbers already interpolated
  /// server-side.
  final String helpEn;
  final String helpDv;

  /// The exact refusal, so the field can go red saying what the server would
  /// have said.
  final String invalidEn;
  final String invalidDv;

  /// Whether the server told us enough to draw the field at all. False after
  /// a failed read — draw nothing and send nothing.
  bool get ready => maxDays != null && (minDays ?? 0) <= maxDays!;

  /// The floor to enforce, once [ready].
  int get floor => minDays ?? 0;

  String label({required bool dhivehi}) =>
      _pick(labelEn, labelDv, dhivehi: dhivehi);

  String help({required bool dhivehi}) =>
      _pick(helpEn, helpDv, dhivehi: dhivehi);

  String invalid({required bool dhivehi}) =>
      _pick(invalidEn, invalidDv, dhivehi: dhivehi);

  /// Would the server accept this? The client mirror of
  /// `App\Rules\ValidationWindowDays`, so the field goes red on exactly the
  /// text the server would refuse and only on that text. Always false while
  /// [ready] is false: a range we were never told is a range nothing is
  /// inside.
  bool accepts(Object? value) {
    final max = maxDays;
    if (max == null) return false;

    final days = validationWindowWholeDays(value);

    return days != null && days >= floor && days <= max;
  }
}

/// GET /merchant/signup/options — public and unauthenticated, like the
/// signup steps themselves. It names no store; it says only what the
/// platform allows today.
class MerchantSignupOptions {
  const MerchantSignupOptions({required this.validationWindow});

  /// What a failed read means everywhere: no field, no value sent.
  static const unknown = MerchantSignupOptions(
    validationWindow: ValidationWindowOption.unknown,
  );

  factory MerchantSignupOptions.fromJson(Map<String, dynamic> json) {
    // `is Map`, never `as Map?`: see MerchantOnboardingGuide.fromJson.
    final window = json['validation_window'];

    return MerchantSignupOptions(
      validationWindow: window is Map
          ? ValidationWindowOption.fromJson(window.cast<String, dynamic>())
          : ValidationWindowOption.unknown,
    );
  }

  final ValidationWindowOption validationWindow;
}

/// The tasks this build knows how to route to.
///
/// PARSED, NEVER ASSUMED, exactly like [FeePromotionKind]: a server one
/// deploy ahead can send a key this app has never heard of, [fromWire]
/// answers null for it, and [MerchantOnboardingGuide] drops the row instead
/// of throwing. An old till then shows the five tasks it understands rather
/// than a red screen — and, because it cannot route the sixth, showing a row
/// that goes nowhere would be the worse of the two failures.
enum MerchantOnboardingTaskKey {
  /// Finish the wizard and submit the store for review.
  finishSetup('finish_setup'),

  /// The bank identity settlements are matched against.
  bankAccount('bank_account'),

  /// The daily act at the counter.
  creditCustomer('credit_customer'),

  /// Close the loop by paying what the cashback cost.
  settleBill('settle_bill'),

  /// Hand the till to the people who stand at it.
  addStaff('add_staff');

  const MerchantOnboardingTaskKey(this.wire);

  /// The `key` string on the wire.
  final String wire;

  /// The wire value, or null for anything this build does not know.
  static MerchantOnboardingTaskKey? fromWire(Object? value) {
    if (value is! String) return null;
    for (final key in values) {
      if (key.wire == value) return key;
    }
    return null;
  }
}

/// One row of the tasklist.
///
/// [done] is DERIVED SERVER-SIDE from real data on every read — "credit your
/// first customer" is done when a transaction exists, and only then. There
/// is no way to tick one of these and there must never be: a tasklist that
/// can be lied to will be lied to on the day it matters.
class MerchantOnboardingTask {
  const MerchantOnboardingTask({
    required this.key,
    required this.labelEn,
    required this.labelDv,
    required this.helpEn,
    required this.helpDv,
    required this.done,
    required this.permission,
    required this.target,
    required this.webPath,
  });

  /// Null for a row this build cannot use — an unknown key, or a row with no
  /// label in either language, which would draw as a blank line. The caller
  /// drops it; nothing here throws.
  static MerchantOnboardingTask? parse(Map<String, dynamic> json) {
    final key = MerchantOnboardingTaskKey.fromWire(json['key']);
    if (key == null) return null;

    final labelEn = _s(json['label_en']);
    final labelDv = _s(json['label_dv']);
    if (labelEn.isEmpty && labelDv.isEmpty) return null;

    return MerchantOnboardingTask(
      key: key,
      labelEn: labelEn,
      labelDv: labelDv,
      helpEn: _s(json['help_en']),
      helpDv: _s(json['help_dv']),
      done: _flag(json['done']),
      permission: _s(json['permission']),
      target: _s(json['target']),
      webPath: _s(json['web_path']),
    );
  }

  final MerchantOnboardingTaskKey key;

  final String labelEn;
  final String labelDv;

  /// The instructional prose — the full sentences telling a merchant how to
  /// credit a customer and how to settle a bill, written server-side to be
  /// reused verbatim in a highlight bubble.
  final String helpEn;
  final String helpDv;

  /// Real state, never a checkbox.
  final bool done;

  /// The slug that makes this task THIS person's to do. The server publishes
  /// it rather than filtering on it, because the app already holds the
  /// resolved set (MerchantSession.permissions). A cashier must not be told
  /// to add the shop's bank account — see
  /// [MerchantOnboardingGuide.checklistFor].
  final String permission;

  /// The screen hint the app routes on (`setup`, `bank_account`, `credit`,
  /// `settlements`, `staff`). Kept as the server's own string rather than
  /// re-derived from [key], so moving a task to another screen is a server
  /// change.
  final String target;

  /// Where the WEB panel sends someone who taps the row. Carried so the two
  /// surfaces can share one payload; the app has no use for it.
  final String webPath;

  String label({required bool dhivehi}) =>
      _pick(labelEn, labelDv, dhivehi: dhivehi);

  String help({required bool dhivehi}) =>
      _pick(helpEn, helpDv, dhivehi: dhivehi);
}

/// The tasklist as ONE person may actually see it: their permissions
/// applied, and counts over what is on screen.
class MerchantOnboardingChecklist {
  const MerchantOnboardingChecklist({
    required this.show,
    required this.tasks,
    required this.done,
    required this.total,
    required this.allDone,
  });

  static const hidden = MerchantOnboardingChecklist(
    show: false,
    tasks: [],
    done: 0,
    total: 0,
    allDone: false,
  );

  /// The guide is live AND this person has something left to be told. A
  /// person with nothing to do is shown nothing, not an empty box.
  final bool show;

  final List<MerchantOnboardingTask> tasks;

  /// Counted over [tasks] — the rows actually drawn. Four rows under "2 of
  /// 5 done" is a bug a merchant can see, which is why these are not the
  /// server's own [MerchantOnboardingGuide.tasksDone] /
  /// [MerchantOnboardingGuide.tasksTotal].
  final int done;
  final int total;

  final bool allDone;
}

/// GET /merchant/onboarding — the signed-in person's own guided setup.
///
/// THREE RULES, ALL SERVER-SIDE, and an app that fights any of them is
/// wrong:
///
///  1. PER PERSON, not per store. The five days are anchored on this
///     account's own first read, so a cashier added in three months gets
///     their own five days instead of inheriting an owner's expired ones.
///     None of the three routes carries an id: the only account any of them
///     can reach is the signed-in one.
///  2. FIVE DAYS IS A HARD STOP. [show] goes false five whole days after
///     that anchor whether or not anything was completed, and skipping is
///     permanent and immediate. NEVER cache a local "dismissed" flag beside
///     this — [show] is the whole answer, and it is shared across surfaces,
///     so skipping on the phone must put it away on the website too.
///  3. EVERY TASK IS DERIVED FROM REAL STATE. Nothing here is tickable.
///
/// The GET costs one query while the guide is live and none once it is over,
/// which is what makes it safe to refresh on resume.
class MerchantOnboardingGuide {
  const MerchantOnboardingGuide({
    required this.show,
    required this.skipped,
    required this.expired,
    required this.tourCompleted,
    required this.allDone,
    required this.daysRemaining,
    required this.windowDays,
    required this.tasksDone,
    required this.tasksTotal,
    required this.titleEn,
    required this.titleDv,
    required this.tasks,
    required this.unknownTaskKeys,
    this.startedAt,
    this.expiresAt,
  });

  /// What a failed or not-yet-answered read means to every surface: draw
  /// nothing. Never a spinner in a sidebar and never a guessed tasklist.
  static const hidden = MerchantOnboardingGuide(
    show: false,
    skipped: false,
    expired: false,
    tourCompleted: false,
    allDone: false,
    daysRemaining: 0,
    windowDays: 0,
    tasksDone: 0,
    tasksTotal: 0,
    titleEn: '',
    titleDv: '',
    tasks: [],
    unknownTaskKeys: [],
  );

  factory MerchantOnboardingGuide.fromJson(Map<String, dynamic> json) {
    final tasks = <MerchantOnboardingTask>[];
    final unknown = <String>[];

    // `is List`, never `as List?`: a cast throws on the day the key holds a
    // string, and a TypeError inside a sidebar parser takes the sidebar out.
    final rows = json['tasks'];

    for (final row in rows is List ? rows : const []) {
      if (row is! Map) continue;

      final task = MerchantOnboardingTask.parse(row.cast<String, dynamic>());

      if (task == null) {
        // Something newer than this build. Remembered rather than silently
        // swallowed, so a screen that wants to say "update the app to see
        // everything" can — and so a bug report can name the key.
        final key = _s(row['key']);
        if (key.isNotEmpty) unknown.add(key);
        continue;
      }

      tasks.add(task);
    }

    return MerchantOnboardingGuide(
      show: _flag(json['show']),
      skipped: _flag(json['skipped']),
      expired: _flag(json['expired']),
      tourCompleted: _flag(json['tour_completed']),
      allDone: _flag(json['all_done']),
      daysRemaining: _count(json['days_remaining']),
      windowDays: _count(json['window_days']),
      tasksDone: _count(json['tasks_done']),
      tasksTotal: _count(json['tasks_total']),
      titleEn: _s(json['title_en']),
      titleDv: _s(json['title_dv']),
      tasks: List.unmodifiable(tasks),
      unknownTaskKeys: List.unmodifiable(unknown),
      startedAt: _instant(json['started_at']),
      expiresAt: _instant(json['expires_at']),
    );
  }

  /// The only thing a surface consults before drawing: false means draw
  /// nothing at all — no tasklist, no tour prompt.
  final bool show;

  /// This person put it away, for good.
  final bool skipped;

  /// The five days ran out. Derived server-side on every read; there is no
  /// flag to sweep and no cron behind it.
  final bool expired;

  /// They finished the walkthrough. Deliberately NOT a skip — watching the
  /// tour is not the same as having credited anybody, so the tasklist stays.
  final bool tourCompleted;

  /// The server's verdict over the WHOLE store list, before permissions.
  final bool allDone;

  /// This person's own anchor, stamped the first time they asked for the
  /// guide, and [expiresAt] is five whole days after it.
  final DateTime? startedAt;
  final DateTime? expiresAt;

  /// Whole days left, rounded UP: 5 on arrival, 1 through the last 24 hours,
  /// 0 once it is over. What a "3 days left" chip prints — never re-derived
  /// from [expiresAt], because the platform counts days in one place.
  final int daysRemaining;

  /// The hard rule itself, served rather than assumed. 5 today.
  final int windowDays;

  /// The server's counts over the whole store's list. For the numbers to put
  /// beside the rows actually drawn, use [checklistFor].
  final int tasksDone;
  final int tasksTotal;

  final String titleEn;
  final String titleDv;

  /// Every task this build understands, in the server's order. Empty
  /// whenever [show] is false — nothing is computed then.
  final List<MerchantOnboardingTask> tasks;

  /// Keys the server sent that this build has never heard of, dropped from
  /// [tasks]. Almost always empty; non-empty means the app is behind the
  /// API.
  final List<String> unknownTaskKeys;

  String title({required bool dhivehi}) =>
      _pick(titleEn, titleDv, dhivehi: dhivehi);

  /// The tasklist narrowed to what this account may actually do.
  ///
  /// A cashier must not be told to add the shop's bank account, so a task
  /// whose [MerchantOnboardingTask.permission] this person does not hold is
  /// dropped — and the counts are then recomputed over what is left, because
  /// the numbers have to describe the rows on screen.
  ///
  /// Pass `session.permissions` (MerchantSession keeps them fresh from
  /// /merchant/me on every launch and resume).
  MerchantOnboardingChecklist checklistFor(Iterable<String> permissions) {
    if (!show) return MerchantOnboardingChecklist.hidden;

    final held = permissions.toSet();
    final visible = [
      for (final task in tasks)
        if (held.contains(task.permission)) task,
    ];

    if (visible.isEmpty) return MerchantOnboardingChecklist.hidden;

    final done = visible.where((task) => task.done).length;

    return MerchantOnboardingChecklist(
      show: true,
      tasks: List.unmodifiable(visible),
      done: done,
      total: visible.length,
      allDone: done == visible.length,
    );
  }
}
