import { z } from "zod";

// null means unknown.
export const shellSchema = z.object({
  value: z.string(),
  title: z.string(),
  description: z.string().default(""),
  allows_login: z.boolean().nullable().default(null),
});

export const DEFAULT_SHELL = "/bin/bash";

export const passwordField = z
  .string()
  .min(10, "min10")
  .regex(/[a-z]/, "lowercase")
  .regex(/[A-Z]/, "uppercase")
  .regex(/[0-9]/, "number");

export const usernameField = z
  .string()
  .min(1, "required_username")
  .max(32, "max32")
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

const publicKeyField = z
  .string()
  .trim()
  .regex(/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com)\s+\S+/, "sshKey");

export const createSystemUserSchema = z.object({
  username: usernameField,
  public_key: z.union([z.literal(""), publicKeyField]).optional(),
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
  name: z.string().min(1, "required_name").max(255, "max255"),
  public_key: publicKeyField,
});
