import { z } from "zod";

// Sentinel for "run as an OS account the panel doesn't manage" (root, www-data).
// The API takes system_user_id XOR username; this drives which one we send.
export const OTHER_USER = "__other__";

const PATH_TOKEN = "{path}";

// Linux account name, same rule the backend applies via getent passwd.
const linuxUsername = z
  .string()
  .trim()
  .regex(/^[a-z_][a-z0-9_-]{0,31}$/, "linuxUsername");

// 5-field cron, or a macro like @daily. The backend owns the real parse — this
// just catches obvious typos before a round-trip.
const CRON_MACROS = ["@yearly", "@annually", "@monthly", "@weekly", "@daily", "@midnight", "@hourly", "@reboot"];
const expressionField = z
  .string()
  .trim()
  .min(1, "required_expression")
  .refine(
    (v) => CRON_MACROS.includes(v.toLowerCase()) || v.split(/\s+/).length === 5,
    "cronExpression",
  );

const commandField = z
  .string()
  .trim()
  .min(1, "required_command")
  .max(1000, "max1000")
  .refine((v) => !/[\r\n]/.test(v), "noLineBreaks")
  // Preset commands ship with a {path} placeholder; the API 422s if it survives.
  .refine((v) => !v.includes(PATH_TOKEN), "unresolvedPath");

const nameField = z
  .string()
  .trim()
  .min(1, "required_name")
  .max(255, "max255")
  .refine((v) => !/[\r\n]/.test(v), "noLineBreaks");

export const createCronjobSchema = z
  .object({
    name: nameField,
    run_as: z.string().min(1, "required_runAs"),
    username: z.string().trim().optional(),
    command: commandField,
    expression: expressionField,
    active: z.boolean(),
  })
  // "Other OS user" turns the free-text username into the required field.
  .refine(
    (d) => d.run_as !== OTHER_USER || linuxUsername.safeParse(d.username ?? "").success,
    { message: "linuxUsername", path: ["username"] },
  );

// Run-as is immutable server-side (change = delete + recreate), so it's absent.
export const updateCronjobSchema = z.object({
  name: nameField,
  command: commandField,
  expression: expressionField,
  active: z.boolean(),
});

const systemUserRef = z.object({ id: z.number(), username: z.string() });

export const cronjobSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
  username: z.string(),
  system_user: systemUserRef.nullable().optional(),
  command: z.string(),
  expression: z.string(),
  active: z.boolean(),
  // The server's zone, not the viewer's: cron runs against the OS clock, so the
  // time only means anything paired with the zone it was computed in.
  timezone: z.string().optional(),
  // null for a paused job — an inactive schedule has no next run.
  next_run_at: z.string().nullable().optional(),
  next_run_at_human: z.string().nullable().optional(),
  // Key into the Logs endpoints for this job’s captured output. Null until the
  // job has been saved with output capture — show “nothing captured yet” rather
  // than opening an empty viewer.
  log_key: z.string().nullable().optional(),
  created_at: z.string().optional(),
  created_at_human: z.string().optional(),
});

export const cronjobsResponseSchema = z.object({
  cronjobs: z.array(cronjobSchema),
  meta: z.object({
    current_page: z.number(),
    per_page: z.number(),
    total: z.number(),
    last_page: z.number(),
  }),
});
