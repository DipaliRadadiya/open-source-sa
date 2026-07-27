import { z } from "zod";

export const PER_PAGE_OPTIONS = [10, 20, 50, 100];

// Mirrors the backend password policy: min 10, mixed case + a number.
const passwordField = z
  .string()
  .min(10, "At least 10 characters")
  .regex(/[a-z]/, "Include a lowercase letter")
  .regex(/[A-Z]/, "Include an uppercase letter")
  .regex(/[0-9]/, "Include a number");

const usernameField = z
  .string()
  .min(1, "Username is required")
  .regex(/^[a-zA-Z0-9_-]+$/, "Only letters, numbers, dashes and underscores");

const roleRefSchema = z.object({ id: z.number(), name: z.string() });

// ---- API response shapes (resource-named envelope) ----
export const userSchema = z.object({
  id: z.number(),
  name: z.string(),
  username: z.string(),
  is_admin: z.boolean(),
  roles: z.array(roleRefSchema).default([]),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
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

// ---- Form schemas ----
// Every user must hold at least one role. The account type is is_admin (grants
// admin-area access); permissions themselves come purely from the roles.
const roleIdsField = z
  .array(z.number())
  .min(1, "Select at least one role");

export const createUserSchema = z
  .object({
    name: z.string().min(1, "Name is required"),
    username: usernameField,
    password: passwordField,
    password_confirmation: z.string().min(1, "Please confirm your password"),
    is_admin: z.boolean(),
    role_ids: roleIdsField,
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

// On edit, name/username/is_admin go to PUT /users/{id} and role_ids are synced
// via PUT /users/{id}/roles — but we validate them together in one form.
export const updateUserSchema = z.object({
  name: z.string().min(1, "Name is required"),
  username: usernameField,
  is_admin: z.boolean(),
  role_ids: roleIdsField,
});

export const resetPasswordSchema = z
  .object({
    password: passwordField,
    password_confirmation: z.string().min(1, "Please confirm your password"),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });
