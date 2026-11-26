import { z } from "zod";

// supervisord doesn't use a shell, so pipes and $(...) would be passed as literal arguments.
const SHELL_METACHARACTERS = /[|;&`$<>()]/;

// Runs as root: no climbing out of the site, and no newline that adds a second directive.
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
  user: "",
  log_file: "",
  log_level: "",
  extra_config: "",
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
  stop_wait_seconds: z.coerce.number().int().min(1, "min1").max(600, "max600"),
  auto_restart: z.boolean(),
  restart_on_deploy: z.boolean(),
  enabled: z.boolean(),
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
    .refine(noTraversal, "noTraversalPath")
    .refine(singleLine, "noLineBreaks")
    .optional()
    .or(z.literal("")),
  log_level: z
    .enum(["critical", "error", "warn", "info", "debug", "trace", "blather"])
    .optional()
    .or(z.literal("")),
  extra_config: z
    .string()
    .trim()
    .max(2000, "max2000")
    .refine((v) => !v.includes("["), "noSectionHeader")
    .refine((v) => !/^\s*environment\s*=/im.test(v), "noEnvironmentLine")
    .refine(
      (v) => !/^\s*(user|command|directory|stdout_logfile|stderr_logfile)\s*=/im.test(v),
      "noManagedWorkerKeys",
    )
    .optional()
    .or(z.literal("")),
  auto_start: z.boolean().optional(),
});

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
  user: z.string().nullish(),
  effective_user: z.string().nullish(),
  log_file: z.string().nullish(),
  log_level: z.string().nullish(),
  extra_config: z.string().nullish(),
  auto_start: z.boolean().nullish(),
  stop_wait_seconds: z.number().nullish(),
  auto_restart: z.boolean(),
  restart_on_deploy: z.boolean(),
  enabled: z.boolean(),
  log_identifier: z.string().nullish(),
  created_at: z.string().optional(),
  created_at_human: z.string().optional(),
});

export const workersResponseSchema = z.object({
  workers: z.array(workerSchema).default([]),
  presets: z.array(workerPresetSchema).default([]),
  checks: z.array(workerCheckSchema).default([]),
});

export const DISRUPTIVE_ACTIONS = ["stop"];
