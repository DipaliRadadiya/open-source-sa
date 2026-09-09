import { z } from "zod";

// supervisord execs the command directly rather than through a shell, so a pipe or
// redirect would be passed to the binary as a literal argument instead of doing
// what it looks like — the API 422s on these, this just catches it before the
// round-trip.
const SHELL_METACHARACTERS = /[|;&`$<>]/;

const commandField = z
  .string()
  .trim()
  .min(1, "required_command")
  .max(2000, "max2000")
  .refine((v) => !/[\r\n]/.test(v), "noLineBreaks")
  .refine((v) => !SHELL_METACHARACTERS.test(v), "shellMetacharacters");

const nameField = z
  .string()
  .trim()
  .min(1, "required_name")
  .max(255, "max255")
  .refine((v) => !/[\r\n]/.test(v), "noLineBreaks");

const advancedDefaults = {
  directory: "",
  stop_wait_seconds: 30,
  // Empty means "let the server decide", which is what the API does with an
  // absent value — so the form's blank state and the server's default are the
  // same thing rather than two.
  user: "",
  log_file: "",
  log_level: "",
  extra_config: "",
  // supervisord's own default. A worker nobody starts by hand is the usual
  // case, and this is what makes it come back after a reboot.
  auto_start: true,
};

export const workerFormSchema = z.object({
  name: nameField,
  command: commandField,
  kind: z.enum(["queue", "horizon", "custom"]),
  processes: z.coerce.number().int().min(1, "min1").max(16, "max16"),
  directory: z.string().trim().max(500, "max500").optional(),
  stop_wait_seconds: z.coerce.number().int().min(1, "min1").max(300, "max300"),
  auto_restart: z.boolean(),
  restart_on_deploy: z.boolean(),
  enabled: z.boolean(),
  // Optional throughout, and matched to the API's own rules: a lowercase unix
  // name, an absolute log path, and one of supervisord's seven levels. Left
  // empty they are simply not sent, and the server keeps its defaults.
  user: z
    .string()
    .trim()
    .max(32, "max32")
    .regex(/^[a-z_][a-z0-9_-]*$/, "invalidUser")
    .optional()
    .or(z.literal("")),
  log_file: z
    .string()
    .trim()
    .max(255, "max255")
    .startsWith("/", "absolutePath")
    .optional()
    .or(z.literal("")),
  log_level: z
    .enum(["critical", "error", "warn", "info", "debug", "trace", "blather"])
    .optional()
    .or(z.literal("")),
  extra_config: z.string().trim().max(2000, "max2000").optional().or(z.literal("")),
  auto_start: z.boolean().optional(),
});

export const WORKER_FORM_DEFAULTS = {
  name: "",
  command: "",
  kind: "custom",
  processes: 1,
  ...advancedDefaults,
  auto_restart: true,
  restart_on_deploy: true,
  enabled: true,
};

export const workerPresetSchema = z.object({
  key: z.string(),
  kind: z.enum(["queue", "horizon", "custom"]),
  title: z.string(),
  description: z.string().nullish(),
  command: z.string(),
});

export const workerCheckSchema = z.object({
  code: z.string(),
  severity: z.enum(["info", "warning", "error"]).catch("warning"),
  title: z.string(),
  detail: z.string().nullish(),
});

export const workerSchema = z.object({
  id: z.number(),
  application_id: z.number().nullish(),
  name: z.string(),
  command: z.string(),
  kind: z.enum(["queue", "horizon", "custom"]).catch("custom"),
  kind_title: z.string().nullish(),
  processes: z.number(),
  running: z.number(),
  state: z.enum(["running", "degraded", "stopped"]).catch("stopped"),
  state_title: z.string().nullish(),
  directory: z.string().nullish(),
  /*
   * Everything below arrived when workers moved from systemd units to
   * supervisord programs, and none of it was declared — so Zod dropped the lot
   * and the panel could neither show nor keep a single one of these settings.
   *
   * `user` is what was asked for, `effective_user` is what it resolves to: a
   * worker with no user of its own runs as the site's system user, and which
   * one it actually is, is the first thing anybody checks when a worker cannot
   * read the site's files.
   */
  user: z.string().nullish(),
  effective_user: z.string().nullish(),
  // Where supervisord writes this program's output. The panel reads the log
  // from that file now rather than from the journal.
  log_file: z.string().nullish(),
  log_level: z.string().nullish(),
  // Raw supervisord directives appended to the program block, for the settings
  // the panel does not model.
  extra_config: z.string().nullish(),
  // Starts with supervisord, as opposed to only when started by hand.
  auto_start: z.boolean().nullish(),
  stop_wait_seconds: z.number().nullish(),
  auto_restart: z.boolean(),
  restart_on_deploy: z.boolean(),
  enabled: z.boolean(),
  // Journal identifier — a "View logs" link into the server Logs screen
  // (`/logs?source=`), the same mechanism as a cron job's log_key. Not a key
  // into the app-logs endpoints (those only know access/error/application).
  log_identifier: z.string().nullish(),
  created_at: z.string().optional(),
  created_at_human: z.string().optional(),
});

export const workersResponseSchema = z.object({
  workers: z.array(workerSchema).default([]),
  presets: z.array(workerPresetSchema).default([]),
  checks: z.array(workerCheckSchema).default([]),
});

// Restart is graceful where the tool supports it (queue:restart finishes the
// job in hand before exiting; horizon:terminate likewise) — no confirmation
// needed. Stop is the one action that leaves jobs unprocessed until someone
// starts it again, so it asks first, same posture as ServiceActions.
export const DISRUPTIVE_ACTIONS = ["stop"];
