import { z } from "zod";

// Backend-allowed login shells (PUT /system-users/{id}/shell).
export const SHELLS = [
  "/bin/bash",
  "/bin/sh",
  "/usr/bin/zsh",
  "/usr/sbin/nologin",
  "/bin/false",
];

// Mirrors the backend OS-password policy: min 10, mixed case + a number.
const passwordField = z
  .string()
  .min(10, "At least 10 characters")
  .regex(/[a-z]/, "Include a lowercase letter")
  .regex(/[A-Z]/, "Include an uppercase letter")
  .regex(/[0-9]/, "Include a number");

// Linux username rules: ^[a-z_][a-z0-9_-]{0,31}$ (backend also blocks reserved
// names + enforces uniqueness — surfaced as a server-side error).
const usernameField = z
  .string()
  .min(1, "Username is required")
  .max(32, "At most 32 characters")
  .regex(
    /^[a-z_][a-z0-9_-]{0,31}$/,
    "Lowercase letters, digits, - and _; must start with a letter or _",
  );

// Loose client check for an SSH public key; the backend does the real parse.
const publicKeyField = z
  .string()
  .trim()
  .regex(
    /^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+)\s+\S+/,
    "Enter a valid SSH public key",
  );

export const createSystemUserSchema = z.object({
  username: usernameField,
  // Optional initial authorized key.
  public_key: z.union([z.literal(""), publicKeyField]).optional(),
});

export const systemUserPasswordSchema = z
  .object({
    password: passwordField,
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "Passwords don't match",
    path: ["password_confirmation"],
  });

export const sshKeySchema = z.object({
  name: z.string().min(1, "Name is required"),
  public_key: publicKeyField,
});
