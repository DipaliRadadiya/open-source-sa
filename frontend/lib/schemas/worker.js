import { z } from "zod";

// systemd execs the command directly rather than through a shell, so a pipe or
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
