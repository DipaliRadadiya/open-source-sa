import { z } from "zod";

export const generalSettingsSchema = z.object({
  timezone: z.string(),
  ntp: z.boolean(),
  clock_synchronized: z.boolean().nullable().optional(),
  hostname: z.string(),
});

export const swapSettingsSchema = z.object({
  enabled: z.boolean(),
  path: z.string(),
  size: z.number(),
  size_human: z.string(),
  used: z.number(),
  used_human: z.string(),
  free: z.number(),
  free_human: z.string(),
});

export const securitySettingsSchema = z.object({
  port: z.number(),
  // Any string: sshd -T can print values an enum wouldn't accept.
  permit_root_login: z
    .string()
    .transform((value) => (value === "without-password" ? "prohibit-password" : value)),
  password_authentication: z.boolean(),
  has_ssh_key: z.boolean().nullable().optional(),
  save_enforces_ssh_access: z.boolean().nullish(),
});

export const securityUpdateRunSchema = z.object({
  id: z.number(),
  status: z.string(),
  reason: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  exit_code: z.number().nullable().optional(),
  packages_upgraded: z.number().nullable().optional(),
  reboot_required_after: z.boolean().nullable().optional(),
  output: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  started_at_human: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
  finished_at_human: z.string().nullable().optional(),
});

export const securityUpdateRunResponseSchema = z.object({
  security_update: securityUpdateRunSchema.nullable(),
});

export const updateSettingsSchema = z.object({
  security_updates_enabled: z.boolean(),
  auto_reboot: z.boolean(),
  reboot_time: z.string(),
  // The API treats a missing field as false, so always send it.
  reboot_with_users: z.boolean().optional().default(false),
  reboot_required: z.boolean().optional().default(false),
  updates_available: z.number().nullable().optional(),
  security_updates_available: z.number().nullable().optional(),
  lists_refreshed_at: z.string().nullable().optional(),
  lists_refreshed_at_human: z.string().nullable().optional(),
  unattended_last_run_at: z.string().nullable().optional(),
  unattended_last_run_at_human: z.string().nullable().optional(),
  unattended_last_result: z.string().nullable().optional(),
  unattended_last_error: z.string().nullable().optional(),
  unattended_last_log: z.string().nullable().optional(),
  unattended_last_log_truncated: z.boolean().optional().default(false),
  unattended_log_readable: z.boolean().optional().default(true),
  security_update: securityUpdateRunSchema.nullable().optional(),
});

export const rebootScheduleSchema = z.object({
  enabled: z.boolean().optional().default(false),
  frequency: z.string().nullable().optional(),
  hour: z.number().nullable().optional(),
  day_of_week: z.number().nullable().optional(),
  day_of_month: z.number().nullable().optional(),
  timezone: z.string().nullable().optional(),
  next_run: z.string().nullable().optional(),
  next_run_human: z.string().nullable().optional(),
});

export const rebootStatusSchema = z.object({
  scheduled: z.boolean().default(false),
  at: z.string().nullable().optional(),
  seconds_remaining: z.number().int().nullable(),
  when: z.string().nullable().optional(),
  delay_minutes: z.number().int().nullable().optional(),
});

export const rebootStatusResponseSchema = z.object({
  reboot: rebootStatusSchema,
});

const presetOption = (value) => z.object({ value, label: z.string() });

export const rebootSchedulePresetsSchema = z.object({
  frequencies: z.array(presetOption(z.string())).default([]),
  hours: z.array(presetOption(z.number())).default([]),
  days_of_week: z.array(presetOption(z.number())).default([]),
});

export const redisSettingsSchema = z.object({
  maxmemory: z.string(),
  maxmemory_policy: z.string(),
  unsafe_policies: z.array(z.string()).optional().default([]),
  // null means the config couldn't be read, not that there's no password.
  has_password: z.boolean().nullable(),
  password: z.string().nullable().optional(),
  password_manageable: z.boolean().optional().default(true),
  running: z.boolean().nullable().optional(),
  password_out_of_sync: z.boolean().nullable().optional(),
  memory_used: z.number().nullable().optional(),
  memory_used_human: z.string().nullable().optional(),
});

// Every group the API returns must be listed or Zod strips it (tests/settings-schema.test.mjs checks).
export const settingsSchema = z.object({
  general: generalSettingsSchema.optional(),
  swap: swapSettingsSchema.optional(),
  security: securitySettingsSchema.optional(),
  updates: updateSettingsSchema.optional(),
  reboot_schedule: rebootScheduleSchema.optional(),
  redis: redisSettingsSchema.optional(),
});

const lastChangedEntrySchema = z.object({
  user: z
    .object({ id: z.number(), username: z.string() })
    .nullable()
    .optional(),
  at: z.string().nullable().optional(),
  at_human: z.string().nullable().optional(),
});

export const settingsResponseSchema = z.object({
  settings: settingsSchema,
  last_changed: z
    .record(z.string(), lastChangedEntrySchema)
    .nullable()
    .optional(),
});

const HOSTNAME_RE = /^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;

export const generalFormSchema = z.object({
  hostname: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(64, "hostnameTooLong")
    .regex(HOSTNAME_RE, "invalidHostname"),
  timezone: z.string().min(1, "requiredField"),
  ntp: z.boolean(),
});

export const SWAP_MAX_MB = 65536;

export const swapFormSchema = z.object({
  size_mb: z.coerce
    .number({ message: "invalidNumber" })
    .int("invalidNumber")
    .min(0, "invalidNumber")
    .max(SWAP_MAX_MB, "swapTooLarge"),
});

export const ROOT_LOGIN_OPTIONS = ["yes", "prohibit-password", "no"];

export const securityFormSchema = z.object({
  port: z.coerce
    .number({ message: "invalidPort" })
    .int("invalidPort")
    .min(1, "invalidPort")
    .max(65535, "invalidPort"),
  permit_root_login: z.enum(ROOT_LOGIN_OPTIONS),
  password_authentication: z.boolean(),
});

const REBOOT_TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

export const updatesFormSchema = z.object({
  security_updates_enabled: z.boolean(),
  auto_reboot: z.boolean(),
  reboot_time: z.string().regex(REBOOT_TIME_RE, "invalidTime"),
  reboot_with_users: z.boolean(),
});

export const REBOOT_FREQUENCIES = ["daily", "weekly", "monthly"];

export const MAX_DAY_OF_MONTH = 28;

export const scheduleFormSchema = z.object({
  enabled: z.boolean(),
  frequency: z.enum(REBOOT_FREQUENCIES),
  hour: z.number().int().min(0, "invalidHour").max(23, "invalidHour"),
  day_of_week: z.number().int().min(0).max(6),
  day_of_month: z.number().int().min(1).max(MAX_DAY_OF_MONTH),
});

export const REDIS_POLICIES = [
  "noeviction",
  "allkeys-lru",
  "allkeys-lfu",
  "allkeys-random",
  "volatile-lru",
  "volatile-lfu",
  "volatile-random",
  "volatile-ttl",
];

export const redisFormSchema = z.object({
  maxmemory: z
    .string()
    .trim()
    .regex(/^(0|\d+(kb|mb|gb|b)?)$/i, "invalidMemory"),
  maxmemory_policy: z.enum(REDIS_POLICIES),
  password: z
    .string()
    .max(255, "tooLong")
    .refine((v) => v === "" || v.length >= 8, "passwordTooShort"),
});

export const REBOOT_DELAY_OPTIONS = [0, 1, 5, 15];
