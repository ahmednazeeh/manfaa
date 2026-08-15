import type { MerchantStaffRole } from '@manfaa/api-client';

/**
 * The three merchant panel tiers (PLAN §1), ASCENDING in authority — the
 * index is the rank. Mirrors MerchantUser::ROLES on the API, which is the
 * actual enforcement: everything here is navigation and screen cosmetics,
 * and every gated route answers 403 (`owner_required` / `manager_required`)
 * regardless of what the panel chooses to render.
 */
export const ROLE_RANK: readonly MerchantStaffRole[] = [
  'staff',
  'manager',
  'owner',
];

/** True when `role` sits at or above `minimum` in the tier list. */
export function hasRoleAtLeast(
  role: MerchantStaffRole,
  minimum: MerchantStaffRole,
): boolean {
  return ROLE_RANK.indexOf(role) >= ROLE_RANK.indexOf(minimum);
}
