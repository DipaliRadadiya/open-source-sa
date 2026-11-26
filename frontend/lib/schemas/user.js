import { z } from "zod";

export const PER_PAGE_OPTIONS = [10, 20, 50, 100];

const passwordField = z
  .string()
  .min(1, "required_password")
  .min(10, "min10")
  .regex(/[a-z]/, "lowercase")
  .regex(/[A-Z]/, "uppercase")
  .regex(/[0-9]/, "number");

const usernameField = z
  .string()
  .min(1, "required_username")
  .regex(/^[a-zA-Z0-9_-]+$/, "usernameChars");

const roleRefSchema = z.object({ id: z.number(), name: z.string() });

export const userSchema = z.object({
  id: z.number(),
  name: z.string(),
  username: z.string(),
  is_admin: z.boolean(),
  roles: z.array(roleRefSchema).default([]),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
  last_login_at: z.string().nullish(),
  last_login_at_human: z.string().nullish(),
  last_login_ip: z.string().nullish(),
});

export const usersMetaSchema = z.object({
  current_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  last_page: z.number(),
});

export const usersResponseSchema = z.object({
  users: z.array(userSchema),
  meta: usersMetaSchema,
});

const roleIdsField = z
  .array(z.number())
  .min(1, "selectRole");

export const createUserSchema = z
  .object({
    name: z.string().min(1, "required_name"),
    username: usernameField,
    password: passwordField,
    password_confirmation: z.string().min(1, "confirmPassword"),
    is_admin: z.boolean(),
    role_ids: roleIdsField,
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "passwordsMismatch",
    path: ["password_confirmation"],
  });

export const updateUserSchema = z.object({
  name: z.string().min(1, "required_name"),
  username: usernameField,
  is_admin: z.boolean(),
  role_ids: roleIdsField,
});

export const resetPasswordSchema = z
  .object({
    password: passwordField,
    password_confirmation: z.string().min(1, "confirmPassword"),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "passwordsMismatch",
    path: ["password_confirmation"],
  });
