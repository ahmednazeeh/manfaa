import type { MerchantRole, MerchantRoleSummary } from '@manfaa/api-client';
import { can, type PermissionHolder } from '@/lib/roles';

/**
 * The panel's mirror of RoleService's delegation rules (PLAN §13b D5).
 *
 * Every one of these is enforced server-side and answers with a coded 403 —
 * this file exists so the shopkeeper is told BEFORE they tick the box, not
 * after the round trip. It must therefore mirror the server exactly: a
 * looser rule here produces a refusal the screen promised would not happen,
 * and a stricter one hides a control the store is entitled to.
 *
 * It lives beside the roles screen rather than in lib/roles.ts because the
 * two questions are different. lib/roles.ts answers "may this reader open
 * this screen"; these answer "may this reader hand this authority to
 * SOMEBODY ELSE", which the staff screen needs too — pointing an account at
 * a role is as much a grant as writing the permissions into it.
 */

/**
 * Whoever is signed in, as these rules need them: the resolved permission
 * set from `/me` (the owner's wildcard already expanded, D3) plus the role
 * they stand on. Structural rather than the `/me` type itself so a test or
 * a future caller can pass the two facts without a whole session.
 */
export interface RoleActor extends PermissionHolder {
  role: Pick<MerchantRoleSummary, 'id' | 'is_owner'> | null;
}

/**
 * `can` for a slug off the WIRE. The catalogue is served (D8), so a checkbox
 * can name a permission this build predates — exactly the case that has to
 * keep working, and the one a typed argument cannot express.
 *
 * No `is_owner` special case: the owner's set arrives already expanded
 * against the server's catalogue, so an owner holds the new slug too.
 */
export function holdsSlug(actor: RoleActor, slug: string): boolean {
  return actor.permissions.includes(slug);
}

/**
 * Whether this reader may tick a checkbox — "you may only delegate what you
 * hold", with the server's own asymmetry between the two writes.
 *
 * On a NEW role every ticked box is a grant, so the whole set is checked.
 * On an edit only the ADDITIONS are: a permission already stored on the
 * role may be unticked and ticked back by someone who does not hold it,
 * because removing one hands nobody anything, and refusing the round trip
 * would stop a limited `roles.manage` holder from so much as renaming a
 * role stronger than themselves.
 */
export function mayTick(
  actor: RoleActor,
  slug: string,
  alreadyOnRole: boolean,
): boolean {
  return alreadyOnRole || holdsSlug(actor, slug);
}

/**
 * Whether this reader may point a staff account at this role. Two rules:
 * only an owner hands out the owner-flagged role, and nobody hands out a
 * set they do not hold.
 *
 * The subset test reads the role's RESOLVED permissions, where the server
 * reads its stored column — they differ only for the owner role, whose
 * stored column is empty because its authority is the flag. That case is
 * already settled by the line above it, and the one actor who passes it
 * holds the whole catalogue anyway.
 */
export function mayAssign(actor: RoleActor, role: MerchantRole): boolean {
  if (role.is_owner && actor.role?.is_owner !== true) {
    return false;
  }

  return role.permissions.every((slug) => holdsSlug(actor, slug));
}

/**
 * Whether this reader may edit this role at all. An owner edits any role,
 * including the one they stand on — that is the only way the owner role
 * ever gets renamed. For everybody else, editing your own role is the
 * shortest path from `roles.manage` to whatever else you fancy.
 */
export function mayEdit(actor: RoleActor, role: MerchantRole): boolean {
  if (!can(actor, 'roles.manage')) {
    return false;
  }

  return actor.role?.is_owner === true || role.id !== actor.role?.id;
}

/**
 * A role is deletable when nobody stands on it. Deactivated accounts count:
 * they still resolve in audit trails and can be switched back on.
 */
export function mayDelete(actor: RoleActor, role: MerchantRole): boolean {
  return can(actor, 'roles.manage') && !role.is_owner && role.staff_count === 0;
}
