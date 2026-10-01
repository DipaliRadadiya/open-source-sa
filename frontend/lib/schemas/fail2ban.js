import { z } from "zod";

// No stats means the jail is not enabled, not zero activity; keep fields
// nullable so the UI can tell them apart.
const jailStatsSchema = z.object({
  currently_failed: z.number().nullable().optional(),
  total_failed: z.number().nullable().optional(),
  currently_banned: z.number().nullable().optional(),
  total_banned: z.number().nullable().optional(),
});

export const jailSchema = z.object({
  name: z.string(),
  label: z.string(),
  // This jail can lock the admin out of their own server (i.e. sshd).
  lockout_risk: z.boolean().optional(),
  enabled: z.boolean(),
  banned: z.array(z.string()).default([]),
  stats: jailStatsSchema.nullable().optional(),
});

export const banSchema = z.object({
  ip: z.string(),
  jail: z.string(),
  banned_at: z.string().nullable().optional(),
  // Null on a live ban means permanent, or a fail2ban too old to report timing;
  // never "expires now".
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

/**
 * The install as the server sees it; `null` once fail2ban is on disk. Needed
 * because apt may take ten minutes, and `installed` alone cannot show progress
 * or failure. Same shape as the PHP/Node runtime installs.
 */
export const fail2banInstallSchema = z.object({
  // installing | failed
  status: z.string(),
  // A stable code (package_not_found, apt_lock, network, no_space, worker,
  // unknown) and its localized title; show the title, never local wording.
  reason: z.string().nullable().optional(),
  reason_title: z.string().nullable().optional(),
  // Locates the server-ops log entry for support.
  reference: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
});

export const fail2banSchema = z.object({
  // `false` is a normal state on a new server, not an error.
  installed: z.boolean(),
  install: fail2banInstallSchema.nullable().optional(),
  running: z.boolean().optional(),
  version: z.string().nullable().optional(),
  // The caller's own address, for a one-click "don't ban me".
  your_ip: z.string().nullable().optional(),
  settings: fail2banSettingsSchema.nullable().optional(),
  jails: z.array(jailSchema).default([]),
  banned: z.array(banSchema).default([]),
  bantime_presets: z.array(bantimePresetSchema).default([]),
});

export const fail2banResponseSchema = z.object({ fail2ban: fail2banSchema });

export const PERMANENT_BANTIME = -1;
