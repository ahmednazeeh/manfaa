import {
  apiFetch,
  bootstrapCsrf,
  dataWrapped,
  type LoginRequest,
} from '@manfaa/api-client';
import { z } from 'zod';

/**
 * Admin auth surface: Sanctum stateful cookie session against
 * /api/admin/auth. Enforcement is server-side by the `admin` guard — the
 * client guard only decides what to render while unauthenticated.
 */

export const AdminUserSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  role: z.string(),
});
export type AdminUser = z.infer<typeof AdminUserSchema>;

const AdminUserResponseSchema = dataWrapped(AdminUserSchema);
export type AdminUserResponse = z.infer<typeof AdminUserResponseSchema>;

export const ADMIN_ME_QUERY_KEY = ['admin', 'me'] as const;

export function getAdminMe(options: { signal?: AbortSignal } = {}) {
  return apiFetch('/api/admin/auth/me', AdminUserResponseSchema, {
    signal: options.signal,
  });
}

export async function adminLogin(body: LoginRequest) {
  // Sanctum needs the XSRF-TOKEN cookie before the first credentialed write.
  await bootstrapCsrf();
  return apiFetch('/api/admin/auth/login', AdminUserResponseSchema, {
    method: 'POST',
    body,
  });
}

export function adminLogout() {
  return apiFetch('/api/admin/auth/logout', z.undefined(), {
    method: 'POST',
  });
}
