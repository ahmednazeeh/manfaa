import type { MerchantChangeRequest } from '@manfaa/api-client';

/**
 * Turning a queued store change (MR9) into the rows a screen shows — the
 * data half of components/app/pending-change.tsx, kept apart from the
 * rendering because every awkward case here is about the WIRE, not about
 * pixels: coordinates that arrive as floats on one side of a diff and as the
 * decimal column's strings on the other, a snapshot that holds only the
 * fields in play, and a payload where `null` means "take this away" while an
 * ABSENT key means "leave it alone".
 */

export interface ChangeRow {
  /** The wire's field name, plus the synthetic `location` (lat + lng). */
  field: string;
  /**
   * The value being replaced. ABSENT — not null — when the request has no
   * before to show: a create, a removal, or a pin whose other half is not in
   * the snapshot. `null` is a real value here ("was not set").
   */
  from?: unknown;
  to: unknown;
}

export interface Pin {
  lat: number;
  lng: number;
}

/**
 * The order fields read in, whatever order the API stored them — a request
 * that inherited a logo from a superseded row would otherwise list it last,
 * after the rename that arrived later. Unknown fields keep their own order,
 * at the end.
 */
const FIELD_ORDER = [
  'name',
  'name_dv',
  'logo',
  'category',
  'channel',
  'description',
  'website_url',
  'eligibility_basis',
  'address',
  'location',
];

/**
 * Half a pin is not a place: `lat` and `lng` are two fields on the wire and
 * one row on screen. Either half missing, null or unparseable means there is
 * no pin to show.
 */
export function pinValue(lat: unknown, lng: unknown): Pin | null {
  const north = Number(lat);
  const east = Number(lng);

  if (lat === null || lat === undefined || Number.isNaN(north)) return null;
  if (lng === null || lng === undefined || Number.isNaN(east)) return null;

  return { lat: north, lng: east };
}

/** One column: a whole act (a create, a removal) has nothing to compare to. */
function wholeAct(values: Record<string, unknown>): ChangeRow[] {
  const rows: ChangeRow[] = Object.entries(values)
    .filter(([field]) => field !== 'id' && field !== 'lat' && field !== 'lng')
    .map(([field, to]) => ({ field, to }));

  if ('lat' in values || 'lng' in values) {
    rows.push({ field: 'location', to: pinValue(values.lat, values.lng) });
  }

  return rows;
}

/**
 * The rows a request is worth showing, in three shapes:
 *
 *  - a REMOVAL proposes nothing, so its snapshot IS the subject: the branch
 *    that would disappear;
 *  - a CREATE has no before, so its payload stands alone;
 *  - everything else is a genuine before → after, built from the server's own
 *    diff (taken against the SUBMIT-TIME snapshot, so it survives later edits
 *    to the live row).
 */
export function changeRows(change: MerchantChangeRequest): ChangeRow[] {
  const rows =
    change.kind === 'branch_delete'
      ? wholeAct(change.current)
      : change.kind === 'branch_create'
        ? wholeAct(change.proposed)
        : diffRows(change);

  const rank = (field: string): number => {
    const index = FIELD_ORDER.indexOf(field);
    return index === -1 ? FIELD_ORDER.length : index;
  };

  return rows.slice().sort((a, b) => rank(a.field) - rank(b.field));
}

function diffRows(change: MerchantChangeRequest): ChangeRow[] {
  const moved = 'lat' in change.proposed || 'lng' in change.proposed;

  // A pin can only be shown WHOLE when both halves are knowable from the
  // request itself — the payload holds what moved, the snapshot what it
  // replaced, and a half that appears in neither (an edit that changed the
  // longitude alone, to the exact same latitude) is simply not in this
  // request. Rather than invent it, the two raw halves stay as they came.
  const collapse =
    moved &&
    ('lat' in change.proposed || 'lat' in change.current) &&
    ('lng' in change.proposed || 'lng' in change.current);

  const rows: ChangeRow[] = [];

  for (const entry of change.changes) {
    // The pin is rebuilt below from both halves; its two raw entries would
    // otherwise read as two unrelated numbers changing.
    if (collapse && (entry.field === 'lat' || entry.field === 'lng')) continue;

    rows.push({ field: entry.field, from: entry.from, to: entry.to });
  }

  if (!collapse) {
    return rows;
  }

  // PRESENCE, not truthiness, completes the pair: only the half that actually
  // moved is in the payload, and `null` there is a pin being TAKEN AWAY. A
  // `??` would read that null as "unchanged" and show the owner the pin they
  // just removed as the one they proposed.
  const proposed = (key: 'lat' | 'lng'): unknown =>
    key in change.proposed ? change.proposed[key] : change.current[key];

  const to = pinValue(proposed('lat'), proposed('lng'));

  // The snapshot holds only the fields in play, so half a pin may be missing
  // from it — in which case the pin this REPLACES is not knowable from the
  // request and the row shows the proposal alone. Printing "no pin →" there
  // would invent a branch that never had one.
  rows.push(
    'lat' in change.current && 'lng' in change.current
      ? {
          field: 'location',
          from: pinValue(change.current.lat, change.current.lng),
          to,
        }
      : { field: 'location', to },
  );

  return rows;
}
