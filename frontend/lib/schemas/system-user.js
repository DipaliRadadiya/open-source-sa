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

export const createSystemUserSchema = z.object({
  username: usernameField,
  public_key: z.union([z.literal(""), publicKeyField]).optional(),
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
