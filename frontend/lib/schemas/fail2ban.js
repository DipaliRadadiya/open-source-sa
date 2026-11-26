import { z } from "zod";

const jailStatsSchema = z.object({
  currently_failed: z.number().nullable().optional(),
  total_failed: z.number().nullable().optional(),
  currently_banned: z.number().nullable().optional(),
  total_banned: z.number().nullable().optional(),
});

export const jailSchema = z.object({
  name: z.string(),
  label: z.string(),
  lockout_risk: z.boolean().optional(),
  enabled: z.boolean(),
  banned: z.array(z.string()).default([]),
  stats: jailStatsSchema.nullable().optional(),
});

export const banSchema = z.object({
  ip: z.string(),
  jail: z.string(),
  banned_at: z.string().nullable().optional(),
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
  seconds: z.number(),
  label: z.string(),
});

export const fail2banInstallSchema = z.object({
  status: z.string(),
  reason: z.string().nullable().optional(),
  reason_title: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
});

export const fail2banSchema = z.object({
  installed: z.boolean(),
  install: fail2banInstallSchema.nullable().optional(),
  running: z.boolean().optional(),
  version: z.string().nullable().optional(),
  your_ip: z.string().nullable().optional(),
  settings: fail2banSettingsSchema.nullable().optional(),
  jails: z.array(jailSchema).default([]),
  banned: z.array(banSchema).default([]),
  bantime_presets: z.array(bantimePresetSchema).default([]),
});

export const fail2banResponseSchema = z.object({ fail2ban: fail2banSchema });

export const PERMANENT_BANTIME = -1;
