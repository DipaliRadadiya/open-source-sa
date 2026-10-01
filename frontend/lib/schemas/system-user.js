import { z } from "zod";

/**
 * One entry of GET /system-users/shells. `allows_login: false` cannot be
 * combined with SSH access (the server rejects the pair). Null means unknown
 * (e.g. an unrecognised shell on an adopted server), never "denies login".
 */
export const shellSchema = z.object({
  value: z.string(),
  title: z.string(),
  description: z.string().default(""),
  allows_login: z.boolean().nullable().default(null),
});

// The default for a new account. The full shell list belongs to the server
// (GET /system-users/shells); do not copy it here.
export const DEFAULT_SHELL = "/bin/bash";

// Mirrors the backend OS-password policy: min 10, mixed case + a number.
export const passwordField = z
  .string()
  .min(10, "min10")
  .regex(/[a-z]/, "lowercase")
  .regex(/[A-Z]/, "uppercase")
  .regex(/[0-9]/, "number");

// Linux username rules: ^[a-z_][a-z0-9_-]{0,31}$ (the backend also blocks
// reserved names and enforces uniqueness).
export const usernameField = z
  .string()
  .min(1, "required_username")
  .max(32, "max32")
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

// Loose client check for an SSH public key; the backend does the real parse.
const publicKeyField = z
  .string()
  .trim()
  .regex(/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+)\s+\S+/, "sshKey");

// Everything past `username` is optional and defaults to bash, no sudo, no SSH,
// no password, matching the backend's `sometimes` rules.
export const createSystemUserSchema = z.object({
  username: usernameField,
  public_key: z.union([z.literal(""), publicKeyField]).optional(),
  // Not an enum: the server owns the list.
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
  // The API's own cap.
  name: z.string().min(1, "required_name").max(255, "max255"),
  public_key: publicKeyField,
});
