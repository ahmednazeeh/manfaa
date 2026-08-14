import { z } from 'zod';

export const LoginRequestSchema = z.object({
  email: z.email(),
  password: z.string().min(1),
});
export type LoginRequest = z.infer<typeof LoginRequestSchema>;

export const UserSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.email(),
});
export type User = z.infer<typeof UserSchema>;
