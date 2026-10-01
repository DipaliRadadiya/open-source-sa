import { z } from "zod";

// supervisord execs the command directly, not through a shell, so pipes,
// redirects and `$(…)` would be literal arguments. Mirrors `SaveWorkerRequest`.
const SHELL_METACHARACTERS = /[|;&`$<>()]/;

// These paths go into a supervisord config run as root: no climbing out of the site,
// and one value cannot become two directives.
const noTraversal = (v) => !v.includes("..");
const singleLine = (v) => !/[\r\n]/.test(v);

const commandField = z
  .string()
  .trim()
  .min(1, "required_command")
  .max(500, "max500")
  .refine(singleLine, "noLineBreaks")
  .refine((v) => !SHELL_METACHARACTERS.test(v), "shellMetacharacters");

const nameField = z
  .string()
  .trim()
  .min(1, "required_name")
  .max(60, "max60")
  .refine(singleLine, "noLineBreaks");

const advancedDefaults = {
  directory: "",
  stop_wait_seconds: 30,
  // Empty means "let the server decide", matching an absent value on the API.
  user: "",
  log_file: "",
  log_level: "",
  extra_config: "",
  // supervisord's own default; makes the worker come back after a reboot.
  auto_start: true,
};

export const workerFormSchema = z.object({
  name: nameField,
  command: commandField,
  kind: z.enum(["queue", "horizon", "custom"]),
  processes: z.coerce.number().int().min(1, "min1").max(16, "max16"),
  directory: z
    .string()
    .trim()
    .max(255, "max255")
    .refine(noTraversal, "noTraversal")
    .refine(singleLine, "noLineBreaks")
    .optional(),
  // 600 matches the API's limit.
  stop_wait_seconds: z.coerce.number().int().min(1, "min1").max(600, "max600"),
  auto_restart: z.boolean(),
  restart_on_deploy: z.boolean(),
  enabled: z.boolean(),
  // Optional, matching the API's rules: a lowercase unix name, an absolute log
  // path, one of supervisord's seven levels. Empty values are not sent.
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
    // `noTraversalPath`, not `noTraversal`: that message is about folders.
    .refine(noTraversal, "noTraversalPath")
    .refine(singleLine, "noLineBreaks")
    .optional()
    .or(z.literal("")),
  log_level: z
    .enum(["critical", "error", "warn", "info", "debug", "trace", "blather"])
    .optional()
    .or(z.literal("")),
  // Appended verbatim inside this worker's program block, so `[` would open a
  // second, unmanaged program. The API refuses it.
  extra_config: z
    .string()
    .trim()
    .max(2000, "max2000")
    .refine((v) => !v.includes("["), "noSectionHeader")
    // The panel already writes `environment=`; a duplicate key stops the worker starting.
    .refine((v) => !/^\s*environment\s*=/im.test(v), "noEnvironmentLine")
    // Keys the panel writes itself; overriding them (e.g. `user=`) would run
    // the worker differently from what the page shows.
    .refine(
      (v) => !/^\s*(user|command|directory|stdout_logfile|stderr_logfile)\s*=/im.test(v),
      "noManagedWorkerKeys",
    )
    .optional()
    .or(z.literal("")),
  auto_start: z.boolean().optional(),
});

// An absolute working directory or log file must be inside the application's folder.
export function workerFormSchemaFor(appRoot = "") {
  const root = String(appRoot ?? "").replace(/\/+$/, "");
  if (!root) return workerFormSchema;
  const inside = (value) => !value || !value.startsWith("/") || value === root || value.startsWith(`${root}/`);
  return workerFormSchema.superRefine((values, ctx) => {
    if (!inside(values.directory?.trim())) {
      ctx.addIssue({ code: "custom", path: ["directory"], message: "insideApplication" });
    }
    if (!inside(values.log_file?.trim())) {
      ctx.addIssue({ code: "custom", path: ["log_file"], message: "insideApplication" });
    }
  });
}

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
  // `effective_user` is what `user` resolves to (the site's system user when unset).
  user: z.string().nullish(),
  effective_user: z.string().nullish(),
  // Where supervisord writes this program's output; the panel reads logs from it.
  log_file: z.string().nullish(),
  log_level: z.string().nullish(),
  // Raw supervisord directives for settings the panel does not model.
  extra_config: z.string().nullish(),
  // Starts with supervisord, as opposed to only when started by hand.
  auto_start: z.boolean().nullish(),
  stop_wait_seconds: z.number().nullish(),
  auto_restart: z.boolean(),
  restart_on_deploy: z.boolean(),
  enabled: z.boolean(),
  // Journal identifier for a "View logs" link into `/logs?source=`, like a cron
  // job's log_key. Not a key into the app-logs endpoints.
  log_identifier: z.string().nullish(),
  created_at: z.string().optional(),
  created_at_human: z.string().optional(),
});

export const workersResponseSchema = z.object({
  workers: z.array(workerSchema).default([]),
  presets: z.array(workerPresetSchema).default([]),
  checks: z.array(workerCheckSchema).default([]),
});

// Restart is graceful (queue:restart and horizon:terminate finish the current
// job), so only Stop, which leaves jobs unprocessed, asks for confirmation.
export const DISRUPTIVE_ACTIONS = ["stop"];
