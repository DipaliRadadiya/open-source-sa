import { z } from "zod";

// A jail with no stats isn't a jail with zero activity — it's one that isn't
// enabled, so nothing is being counted. Every field stays nullable so the UI can
// tell those two apart.
const jailStatsSchema = z.object({
  currently_failed: z.number().nullable().optional(),
  total_failed: z.number().nullable().optional(),
  currently_banned: z.number().nullable().optional(),
  total_banned: z.number().nullable().optional(),
});

export const jailSchema = z.object({
  name: z.string(),
  label: z.string(),
  // This jail can lock the operator out of their own server (i.e. sshd).
  lockout_risk: z.boolean().optional(),
  enabled: z.boolean(),
  banned: z.array(z.string()).default([]),
  stats: jailStatsSchema.nullable().optional(),
});

export const banSchema = z.object({
  ip: z.string(),
  jail: z.string(),
  banned_at: z.string().nullable().optional(),
  // Null on a live ban means permanent — or that this fail2ban is too old to
  // report timing. Either way it is NOT "expires now".
  expires_at: z.string().nullable().optional(),
  seconds_left: z.number().nullable().optional(),
});

export const fail2banSettingsSchema = z.object({
  bantime: z.number(),
  findtime: z.number(),
  maxretry: z.number(),
  ignore_ips: z.array(z.string()).default([]),
});

export const bantimePresetSchema = z.object({
  key: z.string(),
  // -1 is permanent.
  seconds: z.number(),
  label: z.string(),
});

export const fail2banSchema = z.object({
  // `false` is a normal state on a fresh server, not an error.
  installed: z.boolean(),
  running: z.boolean().optional(),
  version: z.string().nullable().optional(),
  // The caller's own address — the one-click answer to "don't ban me".
  your_ip: z.string().nullable().optional(),
  settings: fail2banSettingsSchema.nullable().optional(),
  jails: z.array(jailSchema).default([]),
  banned: z.array(banSchema).default([]),
  bantime_presets: z.array(bantimePresetSchema).default([]),
});

export const fail2banResponseSchema = z.object({ fail2ban: fail2banSchema });

export const PERMANENT_BANTIME = -1;
