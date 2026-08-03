import { z } from "zod";

const passwordField = z
  .string()
  .min(10, "min10")
  .regex(/[a-z]/, "lowercase")
  .regex(/[A-Z]/, "uppercase")
  .regex(/[0-9]/, "number");

export const updateProfileSchema = z.object({
  name: z.string().min(1, "required_name").max(255, "tooLong"),
  username: z
    .string()
    .min(1, "required_username")
    .max(255, "tooLong")
    .regex(/^[a-zA-Z0-9_-]+$/, "usernameChars"),
});

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, "required_currentPassword"),
    password: passwordField,
    password_confirmation: z.string().min(1, "confirmPassword"),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "passwordsMismatch",
    path: ["password_confirmation"],
  });

// Self activity entries omit `user` (always the caller) and `type`.
export const myActivityEntrySchema = z.object({
  id: z.number(),
  type: z.string().nullable().optional(),
  action: z.string(),
  // Is this row about the panel's people or the machine? Drives the chip on
  // the server Activity page; the account tab filters to `account` and so has
  // no use for it.
  scope: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const myActivityResponseSchema = z.object({
  activity_log: z.array(myActivityEntrySchema),
  meta: z.object({
    current_page: z.number(),
    per_page: z.number(),
    total: z.number(),
    last_page: z.number(),
  }),
});
