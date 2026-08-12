import { z } from "zod";

/**
 * One entry of GET /system-users/shells.
 *
 * `allows_login` is the field that matters: a shell that refuses login cannot
 * be combined with SSH access, and the server rejects the pair from either
 * side. It is nullable — an unrecognised shell on an adopted server means
 * "we do not know", which is NOT the same as "denies login" and must not be
 * drawn as a refusal.
 */
export const shellSchema = z.object({
  value: z.string(),
  title: z.string(),
  description: z.string().default(""),
  allows_login: z.boolean().nullable().default(null),
});

// The default a new account gets when the form is left alone. The full list of
// acceptable shells belongs to the server (GET /system-users/shells) — keeping
// a copy here is how the picker ends up offering something the server refuses.
export const DEFAULT_SHELL = "/bin/bash";

// Mirrors the backend OS-password policy: min 10, mixed case + a number.
const passwordField = z
  .string()
  .min(10, "min10")
  .regex(/[a-z]/, "lowercase")
  .regex(/[A-Z]/, "uppercase")
  .regex(/[0-9]/, "number");

// Linux username rules: ^[a-z_][a-z0-9_-]{0,31}$ (backend also blocks reserved
// names + enforces uniqueness — surfaced as a server-side error).
const usernameField = z
  .string()
  .min(1, "required_username")
  .max(32, "max32")
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

// Loose client check for an SSH public key; the backend does the real parse.
const publicKeyField = z
  .string()
  .trim()
  .regex(/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+)\s+\S+/, "sshKey");

// Everything past `username` is optional and defaults to what creation always
// did (bash, no sudo, no SSH, no password), so the username-only path is
// unchanged — the backend's `sometimes` rules say the same thing.
export const createSystemUserSchema = z.object({
  username: usernameField,
  public_key: z.union([z.literal(""), publicKeyField]).optional(),
  // Not an enum any more — the server owns the list, and a stale copy here
  // would reject a shell the server is perfectly happy with.
  shell: z.string().optional(),
  sudo: z.boolean().optional(),
  ssh_access: z.boolean().optional(),
  password: z.union([z.literal(""), passwordField]).optional(),
});

export const systemUserPasswordSchema = z
  .object({
    password: passwordField,
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: "passwordsMismatch",
    path: ["password_confirmation"],
  });

export const sshKeySchema = z.object({
  name: z.string().min(1, "required_name"),
  public_key: publicKeyField,
});
