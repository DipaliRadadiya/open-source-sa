import { z } from "zod";

// Read shapes: GET /api/settings

export const generalSettingsSchema = z.object({
  timezone: z.string(),
  ntp: z.boolean(),
  // Whether the clock is actually in sync, not just whether NTP is switched on.
  clock_synchronized: z.boolean().nullable().optional(),
  hostname: z.string(),
});

// `size`/`used`/`free` are BYTES (from /proc/meminfo); the write field is `size_mb`.
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
  // Any string: `sshd -T` can print values the form lacks, and an enum would fail the whole response.
  permit_root_login: z
    .string()
    .transform((value) => (value === "without-password" ? "prohibit-password" : value)),
  password_authentication: z.boolean(),
  // PUT 422s when password auth is disabled with no key; lets the form warn before the confirm.
  has_ssh_key: z.boolean().nullable().optional(),
});

// `status` and `reason` are codes. `output` is null without `setting,manage`, so a missing log proves nothing.
export const securityUpdateRunSchema = z.object({
  id: z.number(),
  // "running" | "succeeded" | "failed"
  status: z.string(),
  reason: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  exit_code: z.number().nullable().optional(),
  // Null means the run did not say, which is not the same as none.
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
  // The API treats an omitted field as false, so the form must always send it.
  reboot_with_users: z.boolean().optional().default(false),
  reboot_required: z.boolean().optional().default(false),
  // What is actually waiting, and whether the automation is alive.
  updates_available: z.number().nullable().optional(),
  security_updates_available: z.number().nullable().optional(),
  lists_refreshed_at: z.string().nullable().optional(),
  lists_refreshed_at_human: z.string().nullable().optional(),
  unattended_last_run_at: z.string().nullable().optional(),
  unattended_last_run_at_human: z.string().nullable().optional(),
  // "success" | "failed" | null. Null means it has never run.
  unattended_last_result: z.string().nullable().optional(),
  // The log line that decided "failed", verbatim and untranslated.
  unattended_last_error: z.string().nullable().optional(),
  // Null on success, and for a viewer without `setting,manage`.
  unattended_last_log: z.string().nullable().optional(),
  unattended_last_log_truncated: z.boolean().optional().default(false),
  // Whether the log could be opened; distinct from the fields above being null (never run).
  unattended_log_readable: z.boolean().optional().default(true),
  // The panel's own run, so an in-progress run shows before the first poll.
  security_update: securityUpdateRunSchema.nullable().optional(),
});

// A restart on a cadence. `timezone` is the SERVER's (cron's clock): shown, never converted.
export const rebootScheduleSchema = z.object({
  enabled: z.boolean().optional().default(false),
  frequency: z.string().nullable().optional(),
  hour: z.number().nullable().optional(),
  day_of_week: z.number().nullable().optional(),
  day_of_month: z.number().nullable().optional(),
  timezone: z.string().nullable().optional(),
  // Computed from the expression actually on disk.
  next_run: z.string().nullable().optional(),
  next_run_human: z.string().nullable().optional(),
});

// `at` is server time "DD-MM-YYYY HH:mm:ss", null when systemd has no USEC; act on `scheduled`.
export const rebootStatusSchema = z.object({
  scheduled: z.boolean().default(false),
  at: z.string().nullable().optional(),
  // Required so a missing field fails loudly; null when systemd has no USEC.
  seconds_remaining: z.number().int().nullable(),
  // POST only; explains `at`, never used to compute it.
  when: z.string().nullable().optional(),
  delay_minutes: z.number().int().nullable().optional(),
});

export const rebootStatusResponseSchema = z.object({
  reboot: rebootStatusSchema,
});

// Dropdown options, localized by the API; never hardcode them here.
const presetOption = (value) => z.object({ value, label: z.string() });

export const rebootSchedulePresetsSchema = z.object({
  frequencies: z.array(presetOption(z.string())).default([]),
  hours: z.array(presetOption(z.number())).default([]),
  days_of_week: z.array(presetOption(z.number())).default([]),
});

export const redisSettingsSchema = z.object({
  maxmemory: z.string(),
  maxmemory_policy: z.string(),
  // `null` means the config could not be read, NOT "no password is set".
  // Must stay nullable or the whole settings response fails.
  has_password: z.boolean().nullable(),
  // Only for `setting` manage. Null when unset, unreadable or not allowed; `has_password` tells them apart.
  password: z.string().nullable().optional(),
  // False when the panel cannot write its own .env; disable the control.
  password_manageable: z.boolean().optional().default(true),
  // Usage beside the limit; a configured Redis that is not running differs from a healthy one.
  running: z.boolean().nullable().optional(),
  // The panel's saved password no longer opens Redis (changed outside the panel). Null: not checked.
  password_out_of_sync: z.boolean().nullable().optional(),
  memory_used: z.number().nullable().optional(),
  memory_used_human: z.string().nullable().optional(),
});

// Every group the API can return must be listed, or Zod strips it silently. Absent means "not installed".
// tests/settings-schema.test.mjs checks this list against the backend.
export const settingsSchema = z.object({
  general: generalSettingsSchema.optional(),
  swap: swapSettingsSchema.optional(),
  security: securitySettingsSchema.optional(),
  updates: updateSettingsSchema.optional(),
  reboot_schedule: rebootScheduleSchema.optional(),
  redis: redisSettingsSchema.optional(),
});

/** Who last touched each group, keyed by group name. */
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

// Write shapes: one per PUT, mirroring the backend FormRequests

// Same as GeneralSettingsRequest: dot-separated labels of 1–63 characters, no hyphen
// at either end of a label, 64 in all.
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
  // Typed into a text input, so it arrives as a string; `0` disables.
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

// The API caps this at 28 so "monthly" runs every month, February included.
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
  // "0" (unlimited) or a size like "256mb" / "1gb" — same regex the API uses.
  maxmemory: z
    .string()
    .trim()
    .regex(/^(0|\d+(kb|mb|gb|b)?)$/i, "invalidMemory"),
  maxmemory_policy: z.enum(REDIS_POLICIES),
  // Only sent when non-empty: the API leaves the password alone when it's absent.
  password: z
    .string()
    .max(255, "tooLong")
    .refine((v) => v === "" || v.length >= 8, "passwordTooShort"),
});

export const REBOOT_DELAY_OPTIONS = [0, 1, 5, 15];
