import {
  FEE_PROMOTION_BANNER_MAX_CHARS,
  FEE_PROMOTION_MAX_FEE_BP,
  FEE_PROMOTION_MAX_INTRO_DAYS,
  formatPercent,
  isFeePromotionBlocker,
  parsePercentToBp,
  percentToBp,
  type FeePromotionKind,
  type IntroductoryFeePromotionSettings,
  type PlatformWideFeePromotionSettings,
  type UpdateFeePromotionSettingsRequest,
} from '@manfaa/api-client';
import {
  startOfMaldivesDayMs,
  toIsoWithMaldivesOffset,
  toMaldivesLocalInput,
} from '@/lib/format';

/**
 * The settings screen's own mirror of the fee-promotion rules — the client
 * half of App\Http\Controllers\Admin\FeePromotionsController and
 * App\Domain\Platform\FeePromotionPolicy.
 *
 * The SERVER remains authoritative: every refusal it makes is written for a
 * person and rendered verbatim. What lives here is the same rule stated
 * BEFORE the click, so a superadmin sees "at least one day" beside the day
 * field instead of discovering it as a 422 — which is exactly what the
 * payload's `blockers[]` exists for. The rules below mirror the controller
 * one for one, so the two lists agree; `blockers[]` is therefore read for the
 * one thing local rules cannot know — a slug from a SERVER NEWER THAN THIS
 * BUILD, which must still count as "not ready" rather than be dropped.
 *
 * THREE REPRESENTATIONS, kept apart on purpose:
 *
 *   the WIRE     2-decimal percent STRINGS and ISO-8601 instants (PLAN §1);
 *                basis points appear in no request and no response.
 *   the FORM     whatever the operator has typed so far, including blank —
 *                and blank is meaningful: it CLEARS a fee back to "not set",
 *                which is not the same answer as 0.00%.
 *   the RULES    integer basis points and epoch milliseconds, because "is
 *                this fee above the cheapest tier" and "how many whole days
 *                are left" are integer questions and nothing else is safe.
 *
 * Every window here is counted in MALDIVES days (§13: UTC storage, Malé
 * rules), which is what the server counts, so an operator's preview and a
 * merchant's banner never disagree by a day.
 */

const DAY_MS = 86_400_000;

/** The two kinds, worded as the server's own `FeePromotionKind::label()`. */
export const FEE_PROMOTION_KIND_LABELS: Record<FeePromotionKind, string> = {
  introductory: 'Introductory offer',
  platform_wide: 'Platform-wide offer',
};

/** Every input on the screen, named as the PATCH body names it. */
export type FeePromotionField =
  | 'intro_fee_percent'
  | 'intro_days'
  | 'intro_banner_en'
  | 'intro_banner_dv'
  | 'wide_fee_percent'
  | 'wide_from'
  | 'wide_to'
  | 'wide_banner_en'
  | 'wide_banner_dv';

/** One thing standing between this promotion and being switched on. */
export interface FeePromotionIssue {
  /** The input to mark, or null when it belongs to the card as a whole. */
  field: FeePromotionField | null;
  message: string;
}

/**
 * A blocker slug this build has never seen. The API's list is parsed as plain
 * strings precisely so an unknown one still counts as "not ready to enable" —
 * it is surfaced with its raw slug rather than swallowed.
 */
export function unknownBlockers(blockers: string[]): string[] {
  return blockers.filter((blocker) => !isFeePromotionBlocker(blocker));
}

// ---------------------------------------------------------------------------
// Form state
// ---------------------------------------------------------------------------

/** The introductory card's inputs, as text. */
export interface IntroFormState {
  fee: string;
  days: string;
  bannerEn: string;
  bannerDv: string;
}

/** The platform-wide card's inputs; the dates are Malé wall clock. */
export interface WideFormState {
  fee: string;
  from: string;
  to: string;
  bannerEn: string;
  bannerDv: string;
}

export function introFormFrom(
  settings: IntroductoryFeePromotionSettings,
): IntroFormState {
  return {
    fee: settings.platform_fee_percent ?? '',
    days: String(settings.days),
    bannerEn: settings.banner_en ?? '',
    bannerDv: settings.banner_dv ?? '',
  };
}

export function wideFormFrom(
  settings: PlatformWideFeePromotionSettings,
): WideFormState {
  return {
    fee: settings.platform_fee_percent ?? '',
    from:
      settings.from === null
        ? ''
        : toMaldivesLocalInput(new Date(settings.from)),
    to: settings.to === null ? '' : toMaldivesLocalInput(new Date(settings.to)),
    bannerEn: settings.banner_en ?? '',
    bannerDv: settings.banner_dv ?? '',
  };
}

export function introFormDirty(
  form: IntroFormState,
  settings: IntroductoryFeePromotionSettings,
): boolean {
  const saved = introFormFrom(settings);
  return (
    form.fee.trim() !== saved.fee ||
    form.days.trim() !== saved.days ||
    form.bannerEn !== saved.bannerEn ||
    form.bannerDv !== saved.bannerDv
  );
}

export function wideFormDirty(
  form: WideFormState,
  settings: PlatformWideFeePromotionSettings,
): boolean {
  const saved = wideFormFrom(settings);
  return (
    form.fee.trim() !== saved.fee ||
    form.from !== saved.from ||
    form.to !== saved.to ||
    form.bannerEn !== saved.bannerEn ||
    form.bannerDv !== saved.bannerDv
  );
}

// ---------------------------------------------------------------------------
// The rules, mirrored
// ---------------------------------------------------------------------------

const PERCENT_FORMAT_MESSAGE =
  'A percentage with up to 2 decimals, e.g. 0.25. Leave it empty to clear the fee back to "not set".';

/** The fee as integer bp, or a reason it cannot be read that way. */
function feeIssue(
  field: FeePromotionField,
  raw: string,
  maxPercent: string,
): FeePromotionIssue | null {
  const text = raw.trim();

  if (text === '') {
    return null; // Blank is a legal SAVE (it clears); readiness catches it.
  }

  const bp = parsePercentToBp(text);

  if (bp === null || bp > FEE_PROMOTION_MAX_FEE_BP) {
    return { field, message: PERCENT_FORMAT_MESSAGE };
  }

  if (bp > percentToBp(maxPercent)) {
    return {
      field,
      message: `A merchant on the cheapest tier pays ${formatPercent(maxPercent)} today, so ${formatPercent(
        bpText(bp),
      )} would charge them MORE. Set ${formatPercent(maxPercent)} or less.`,
    };
  }

  return null;
}

/** bp back to the wire's own 2-decimal text, for a message. */
function bpText(bp: number): string {
  return `${Math.floor(bp / 100)}.${String(bp % 100).padStart(2, '0')}`;
}

/**
 * What a SAVE would be refused for — malformed text only. A half-filled
 * promotion saves perfectly well while it is being written; it is switching
 * it ON that the server guards.
 */
export function introFormatIssues(
  form: IntroFormState,
  maxPercent: string,
): FeePromotionIssue[] {
  const issues: FeePromotionIssue[] = [];
  const fee = feeIssue('intro_fee_percent', form.fee, maxPercent);

  if (fee !== null) {
    issues.push(fee);
  }

  const days = Number(form.days.trim());

  if (
    form.days.trim() === '' ||
    !Number.isInteger(days) ||
    days < 0 ||
    days > FEE_PROMOTION_MAX_INTRO_DAYS
  ) {
    issues.push({
      field: 'intro_days',
      message: `A whole number of days, 0 to ${FEE_PROMOTION_MAX_INTRO_DAYS}.`,
    });
  }

  return issues;
}

export function wideFormatIssues(
  form: WideFormState,
  maxPercent: string,
): FeePromotionIssue[] {
  const issues: FeePromotionIssue[] = [];
  const fee = feeIssue('wide_fee_percent', form.fee, maxPercent);

  if (fee !== null) {
    issues.push(fee);
  }

  for (const [field, value] of [
    ['wide_from', form.from],
    ['wide_to', form.to],
  ] as const) {
    if (value !== '' && toIsoWithMaldivesOffset(value) === null) {
      issues.push({ field, message: 'Pick a full date and time.' });
    }
  }

  // ...and the ONE window rule that is not merely a readiness rule: an
  // inverted window is un-STORABLE, not just un-switchable. The table's
  // `fee_promotions_window_order_check` is unconditional, so a draft saved
  // with the dates the wrong way round is refused by the database whether or
  // not the switch is on. It belongs here, beside the format rules that gate
  // Save, rather than only in the readiness rules that gate the switch —
  // otherwise Save stays enabled on a payload the API can only answer 422 to.
  //
  // A HALF-drawn window is deliberately still savable: the constraint permits
  // a null edge, and a superadmin has to be able to type a start before they
  // have decided on an end.
  const from = toIsoWithMaldivesOffset(form.from);
  const to = toIsoWithMaldivesOffset(form.to);

  if (
    from !== null &&
    to !== null &&
    new Date(to).getTime() <= new Date(from).getTime()
  ) {
    issues.push({
      field: 'wide_to',
      message:
        'The end falls on or before the start, which describes no window at all.',
    });
  }

  return issues;
}

/**
 * Everything the server checks before it will let the switch be thrown, in
 * the order an operator meets it. Empty means the promotion is ready.
 */
export function introReadinessIssues(
  form: IntroFormState,
  maxPercent: string,
): FeePromotionIssue[] {
  const issues = introFormatIssues(form, maxPercent);

  if (form.fee.trim() === '') {
    issues.push({
      field: 'intro_fee_percent',
      message:
        'Set the promotional fee first. 0 is a valid, deliberate answer — it is what "free for the first days" means — but an empty field is not the same thing, and the API refuses to treat it as free.',
    });
  }

  if (Number(form.days.trim()) < 1) {
    issues.push({
      field: 'intro_days',
      message:
        'At least one day. A zero-day offer is a banner no merchant is ever inside.',
    });
  }

  issues.push(
    ...bannerIssues(
      form.bannerEn,
      form.bannerDv,
      'intro_banner_en',
      'intro_banner_dv',
    ),
  );

  return issues;
}

export function wideReadinessIssues(
  form: WideFormState,
  maxPercent: string,
): FeePromotionIssue[] {
  const issues = wideFormatIssues(form, maxPercent);

  if (form.fee.trim() === '') {
    issues.push({
      field: 'wide_fee_percent',
      message:
        'Set the promotional fee first. 0 is a valid, deliberate answer; an empty field is not, and the API refuses to treat it as free.',
    });
  }

  // BOTH EDGES is the rule that belongs to the switch. The ORDER rule is not:
  // it is already in wideFormatIssues above, because the database refuses an
  // inverted window on every save, switch or no switch.
  if (
    toIsoWithMaldivesOffset(form.from) === null ||
    toIsoWithMaldivesOffset(form.to) === null
  ) {
    issues.push({
      field: toIsoWithMaldivesOffset(form.from) === null ? 'wide_from' : 'wide_to',
      message:
        'A platform-wide offer needs a start AND an end. An offer with no end is a price change, and merchants have to be told when it stops.',
    });
  }

  issues.push(
    ...bannerIssues(
      form.bannerEn,
      form.bannerDv,
      'wide_banner_en',
      'wide_banner_dv',
    ),
  );

  return issues;
}

function bannerIssues(
  en: string,
  dv: string,
  enField: FeePromotionField,
  dvField: FeePromotionField,
): FeePromotionIssue[] {
  const issues: FeePromotionIssue[] = [];

  if (en.trim() === '') {
    issues.push({
      field: enField,
      message:
        'Write the English sentence merchants are shown. A discount nobody can be told about is an accounting event, not a promotion.',
    });
  }

  if (dv.trim() === '') {
    issues.push({
      field: dvField,
      message: 'Write the same sentence in Dhivehi — merchants read it there.',
    });
  }

  for (const [field, value] of [
    [enField, en],
    [dvField, dv],
  ] as const) {
    if (value.length > FEE_PROMOTION_BANNER_MAX_CHARS) {
      issues.push({
        field,
        message: `At most ${FEE_PROMOTION_BANNER_MAX_CHARS} characters.`,
      });
    }
  }

  return issues;
}

/** The first message against one input, for the text under it. */
export function issueFor(
  issues: FeePromotionIssue[],
  field: FeePromotionField,
): string | undefined {
  return issues.find((issue) => issue.field === field)?.message;
}

// ---------------------------------------------------------------------------
// What the form would send
// ---------------------------------------------------------------------------

/**
 * The introductory half of a PATCH. An empty fee is sent as an explicit
 * `null` — "not set" — because that is the only way back from a fee typed by
 * mistake, and it is emphatically NOT 0.
 */
export function introPatch(
  form: IntroFormState,
): UpdateFeePromotionSettingsRequest {
  return {
    intro_fee_percent: form.fee.trim() === '' ? null : form.fee.trim(),
    intro_days: Number(form.days.trim()),
    intro_banner_en: form.bannerEn.trim() === '' ? null : form.bannerEn.trim(),
    intro_banner_dv: form.bannerDv.trim() === '' ? null : form.bannerDv.trim(),
  };
}

export function widePatch(
  form: WideFormState,
): UpdateFeePromotionSettingsRequest {
  return {
    wide_fee_percent: form.fee.trim() === '' ? null : form.fee.trim(),
    wide_from: toIsoWithMaldivesOffset(form.from),
    wide_to: toIsoWithMaldivesOffset(form.to),
    wide_banner_en: form.bannerEn.trim() === '' ? null : form.bannerEn.trim(),
    wide_banner_dv: form.bannerDv.trim() === '' ? null : form.bannerDv.trim(),
  };
}

// ---------------------------------------------------------------------------
// Windows
// ---------------------------------------------------------------------------

/**
 * The introductory window for a store approved at `approvedMs`, as the server
 * computes it: from the START of the Malé day the approval fell on, for X
 * whole days, the end EXCLUSIVE.
 */
export function introWindowEndMs(approvedMs: number, days: number): number {
  return startOfMaldivesDayMs(approvedMs) + days * DAY_MS;
}

/**
 * Whole Malé days left before an offer stops applying — the same count the
 * merchant's own banner shows, so the last day reads 1 rather than 0.
 */
export function daysRemaining(nowMs: number, endMs: number): number {
  return Math.max(
    0,
    Math.round(
      (startOfMaldivesDayMs(endMs) - startOfMaldivesDayMs(nowMs)) / DAY_MS,
    ),
  );
}

export type WideWindowStatus = 'unset' | 'scheduled' | 'running' | 'ended';

/**
 * Where a platform-wide window sits relative to now. `enabled` and `running`
 * are different questions and the screen has to say both: an enabled offer
 * whose window has passed prices exactly nothing, and an enabled one whose
 * window has not opened yet changes nothing until it does.
 */
export function wideWindowStatus(
  from: string | null,
  to: string | null,
  nowMs: number,
): WideWindowStatus {
  if (from === null || to === null) {
    return 'unset';
  }

  const start = new Date(from).getTime();
  const end = new Date(to).getTime();

  if (Number.isNaN(start) || Number.isNaN(end) || end <= start) {
    return 'unset';
  }

  if (nowMs < start) {
    return 'scheduled';
  }

  return nowMs < end ? 'running' : 'ended';
}

/**
 * What a promotion covers, as the payload's own open string lists — the API
 * names these so an admin screen can print them, and an unrecognised one is
 * shown as its slug rather than dropped.
 */
export const FEE_PROMOTION_SCOPE_LABELS: Record<string, string> = {
  cashback_platform_fee: 'the platform fee on cashback sales',
  marketplace_order_fee: 'marketplace order fees',
};

export function scopeLabel(slug: string): string {
  return FEE_PROMOTION_SCOPE_LABELS[slug] ?? slug;
}
