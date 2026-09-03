import { z } from "zod";

// ---------------------------------------------------------------------------
// Read shapes — GET /api/settings
// ---------------------------------------------------------------------------

export const generalSettingsSchema = z.object({
  timezone: z.string(),
  ntp: z.boolean(),
  // Whether the clock is ACTUALLY in sync, not just whether NTP is switched on.
  // Enabled-but-not-syncing is a silent failure: cron fires late and log
  // timestamps lie, and the toggle alone reports intent rather than reality.
  clock_synchronized: z.boolean().nullable().optional(),
  hostname: z.string(),
});

// `size`/`used`/`free` are BYTES (read from /proc/meminfo), while the write
// field is `size_mb`. Keeping the unit in the name here so the conversion at
// the form boundary is deliberate rather than accidental.
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
  permit_root_login: z.enum(["yes", "no", "prohibit-password"]),
  password_authentication: z.boolean(),
  // The lockout guard, surfaced before it fires: PUT /settings/security 422s
  // when password auth is disabled with no key present, so without this the
  // user only learns after confirming a scary dialog.
  has_ssh_key: z.boolean().nullable().optional(),
});

export const updateSettingsSchema = z.object({
  security_updates_enabled: z.boolean(),
  auto_reboot: z.boolean(),
  reboot_time: z.string(),
  // Whether an automatic reboot goes ahead while someone is logged in.
  // unattended-upgrades defaults this to true; the API treats an omitted field
  // as false, so leaving it out of the form was silently choosing for the user.
  reboot_with_users: z.boolean().optional().default(false),
  reboot_required: z.boolean().optional().default(false),
  // What is actually waiting, and whether the automation is alive. The toggle
  // above promises "security patches install automatically" and could not show
  // the result of that promise.
  updates_available: z.number().nullable().optional(),
  security_updates_available: z.number().nullable().optional(),
  lists_refreshed_at: z.string().nullable().optional(),
  lists_refreshed_at_human: z.string().nullable().optional(),
  unattended_last_run_at: z.string().nullable().optional(),
  unattended_last_run_at_human: z.string().nullable().optional(),
  // "success" | "failed" | null. Null means it has never run.
  unattended_last_result: z.string().nullable().optional(),
});

/**
 * A restart on a cadence — not the same thing as the `updates` auto-reboot,
 * which only fires when a patch demands one.
 *
 * `timezone` is the SERVER's, because that is what cron reads. Shown rather
 * than converted: a silent UTC conversion is how a 3am window fires at 8am.
 */
export const rebootScheduleSchema = z.object({
  enabled: z.boolean().optional().default(false),
  frequency: z.string().nullable().optional(),
  hour: z.number().nullable().optional(),
  day_of_week: z.number().nullable().optional(),
  day_of_month: z.number().nullable().optional(),
  timezone: z.string().nullable().optional(),
  // Computed from the expression actually on disk, so there is nothing to guess.
  next_run: z.string().nullable().optional(),
  next_run_human: z.string().nullable().optional(),
});

/**
 * A restart that is already counting down (`GET /settings/reboot`), and what
 * `POST` and `DELETE` answer with.
 *
 * `at` is the absolute moment on the server's clock, "DD-MM-YYYY HH:mm:ss". It
 * is null on the rare pending shutdown whose systemd record carries no USEC —
 * `scheduled` is still the answer to act on, so the UI must not treat a missing
 * time as "nothing pending".
 */
export const rebootStatusSchema = z.object({
  scheduled: z.boolean().default(false),
  at: z.string().nullable().optional(),
  // Only on the POST response — the literal `shutdown` argument, and the delay
  // as asked for. Kept because they explain `at`, never used to compute it.
  when: z.string().nullable().optional(),
  delay_minutes: z.number().int().nullable().optional(),
});

export const rebootStatusResponseSchema = z.object({
  reboot: rebootStatusSchema,
});

// Dropdown options, localized by the API — never hardcoded here, same rule as
// the permission sub-level titles and the activity scopes.
const presetOption = (value) => z.object({ value, label: z.string() });

export const rebootSchedulePresetsSchema = z.object({
  frequencies: z.array(presetOption(z.string())).default([]),
  hours: z.array(presetOption(z.number())).default([]),
  days_of_week: z.array(presetOption(z.number())).default([]),
});

export const redisSettingsSchema = z.object({
  maxmemory: z.string(),
  maxmemory_policy: z.string(),
  /*
   * Three states, not two. `null` means the panel could not read the config to
   * tell — which is NOT "no password is set", and drawing it as such would
   * invite someone to set one on a server that already has one.
   *
   * This was `z.boolean()`, so a server answering null failed the whole
   * settings response and every tab on the page — Server, Access, Memory,
   * Updates — rendered "This part could not be loaded". One unreadable Redis
   * config took down a page that is mostly about neither Redis nor passwords.
   */
  has_password: z.boolean().nullable(),
  // Sent since 2026-08-31, and only to a caller with `setting` manage. Null
  // when none is set, when the panel could not read it, or when the caller is
  // not allowed it — `has_password` is what tells those apart.
  password: z.string().nullable().optional(),
  // False when the panel cannot write its own .env. The docs are explicit that
  // the control should be disabled rather than offered and then refused.
  password_manageable: z.boolean().optional().default(true),
  // A memory limit means nothing without the usage it limits, and a configured
  // Redis that is not running is a different fact from a healthy one.
  running: z.boolean().nullable().optional(),
  memory_used: z.number().nullable().optional(),
  memory_used_human: z.string().nullable().optional(),
});

// Groups are detect-gated server-side: an absent group means "not installed on
// this server", which is a different thing from "installed with no values".
/**
 * MySQL / MariaDB engine tuning. `present` and `reachable` are separate on
 * purpose: an engine that is installed but whose stored credentials are
 * rejected is a different state from one that is not installed, and the card
 * says which. Readings the panel could not take are null rather than 0.
 */
const mysqlSettingsSchema = z.object({
  engine: z.string().nullable().optional(),
  engine_label: z.string().nullable().optional(),
  present: z.boolean().optional(),
  reachable: z.boolean().optional(),
  max_connections: z.number().nullable().optional(),
  configured_max_connections: z.number().nullable().optional(),
  capped: z.boolean().optional(),
  open_files_limit: z.number().nullable().optional(),
  connections: z.number().nullable().optional(),
  floor: z.number().optional(),
  recommended_max: z.number().nullable().optional(),
  memory_mb: z.number().nullable().optional(),
});

const mysqlBinlogSettingsSchema = z.object({
  engine: z.string().nullable().optional(),
  engine_label: z.string().nullable().optional(),
  present: z.boolean().optional(),
  reachable: z.boolean().optional(),
  enabled: z.boolean().optional(),
  format: z.string().nullable().optional(),
  expire_seconds: z.number().nullable().optional(),
  max_binlog_size: z.number().nullable().optional(),
  log_count: z.number().nullable().optional(),
  log_bytes: z.number().nullable().optional(),
  oldest_log: z.string().nullable().optional(),
  // PHP hands back `[]` for an empty map, so both shapes are legal.
  configured: z.union([z.record(z.string(), z.number()), z.array(z.never())]).optional(),
});

/**
 * Every group the API can return must be listed here.
 *
 * Zod strips unknown keys, so a group the backend sends and this object does
 * not name is deleted before any page sees it — silently, with no error and a
 * 200 response. That is exactly how the database groups disappeared: the two
 * backend groups shipped, the API returned them correctly, and the Database
 * tab reported "no MySQL or MariaDB server is running on this machine" because
 * the parse had already removed them. See tests/settings-schema.test.mjs,
 * which fails if a backend group is missing from this list.
 */
export const settingsSchema = z.object({
  general: generalSettingsSchema.optional(),
  swap: swapSettingsSchema.optional(),
  security: securitySettingsSchema.optional(),
  updates: updateSettingsSchema.optional(),
  reboot_schedule: rebootScheduleSchema.optional(),
  redis: redisSettingsSchema.optional(),
  mysql: mysqlSettingsSchema.optional(),
  mysql_binlog: mysqlBinlogSettingsSchema.optional(),
});

/** Who last touched each group, keyed by group name. */
const lastChangedEntrySchema = z.object({
  user: z.object({ id: z.number(), username: z.string() }).nullable().optional(),
  at: z.string().nullable().optional(),
  at_human: z.string().nullable().optional(),
});

export const settingsResponseSchema = z.object({
  settings: settingsSchema,
  last_changed: z.record(z.string(), lastChangedEntrySchema).nullable().optional(),
});

// ---------------------------------------------------------------------------
// Write shapes — one per PUT, mirroring the backend FormRequests
// ---------------------------------------------------------------------------

// Matches the backend hostname regex: letters/digits at both ends, dots and
// hyphens in between.
const HOSTNAME_RE = /^[a-zA-Z0-9]([a-zA-Z0-9\-.]{0,251}[a-zA-Z0-9])?$/;

export const generalFormSchema = z.object({
  hostname: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(253, "tooLong")
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

export const mysqlFormSchema = z.object({
  // The floor is a lockout guard: the panel needs connections of its own, and
  // a server set below this is one this screen can no longer reach to undo it.
  max_connections: z.coerce
    .number({ message: "invalidNumber" })
    .int("invalidNumber")
    .min(10, "connectionsTooLow")
    .max(100000, "connectionsTooHigh"),
});

export const mysqlBinlogFormSchema = z.object({
  // Days in the form, seconds on the wire — the server's unit is seconds and
  // nobody thinks about binlog retention in those. `0` is keep-forever, which
  // is allowed because a server may already be in that state and the panel has
  // to be able to represent it.
  expire_days: z.coerce
    .number({ message: "invalidNumber" })
    .int("invalidNumber")
    .min(0, "invalidNumber")
    .max(365, "binlogRetentionTooLong"),
  // MB in the form, bytes on the wire. A larger single log makes purging
  // coarse: the server can only drop whole files.
  max_binlog_size_mb: z.coerce
    .number({ message: "invalidNumber" })
    .int("invalidNumber")
    .min(1, "binlogSizeTooSmall")
    .max(1024, "binlogSizeTooLarge"),
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

// The API caps this at 28 so "monthly" happens twelve times a year — the 31st
// silently skips February and the short months.
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
