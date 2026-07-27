import { z } from "zod";

const passwordField = z
  .string()
  .min(10, "At least 10 characters")
  .regex(/[a-z]/, "Include a lowercase letter")
  .regex(/[A-Z]/, "Include an uppercase letter")
  .regex(/[0-9]/, "Include a number");

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, "Current password is required"),
    password: passwordField,
    password_confirmation: z.string().min(1, "Please confirm your password"),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

// Self activity entries omit `user` (always the caller) and `type`.
export const myActivityEntrySchema = z.object({
  id: z.number(),
  type: z.string().nullable().optional(),
  action: z.string(),
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
