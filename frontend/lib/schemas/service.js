import { z } from "zod";

// systemctl's three outcomes; anything else renders as unknown.
export const SERVICE_STATUSES = ["active", "inactive", "failed"];

// What KIND of row this is, separate from how it is doing: installable
// services stay listed while installing and after a failed install.
export const SERVICE_STATES = ["installed", "installing", "install_failed"];

// The API decides per service what may be run (protected units omit stop and
// disable); build buttons from its list, never hardcode them.
export const SERVICE_ACTIONS = [
  "start",
  "stop",
  "restart",
  "reload",
  "enable",
  "disable",
];

// Only actions that take a service offline ask for confirmation.
export const DISRUPTIVE_ACTIONS = ["stop", "disable"];

// systemd cgroup accounting. Null means "not measured", NOT zero.
const usageSchema = z.object({
  memory_bytes: z.number().nullable().optional(),
  memory_human: z.string().nullable().optional(),
  memory_percent: z.number().nullable().optional(),
  // Null on the first read: a percentage needs two samples.
  cpu_percent: z.number().nullable().optional(),
  tasks: z.number().nullable().optional(),
});

export const serviceSchema = z.object({
  key: z.string(),
  label: z.string(),
  unit: z.string(),
  status: z.string(),
  // Defaults to the only state older APIs had. Must be declared: this object
  // does not passthrough.
  state: z.string().default("installed"),
  install_reason: z.string().nullish(),
  // The backend's own sentence, so this row and the setup card agree.
  install_message: z.string().nullish(),
  retryable: z.boolean().default(false),
  enabled: z.boolean(),
  protected: z.boolean().optional(),
  actions: z.array(z.string()).default([]),
  // Whether this service can validate its own config (nginx -t and friends).
  // Absent means no.
  testable: z.boolean().optional(),
  usage: usageSchema.nullable().optional(),
  // Keys into the Logs endpoints; empty means no logs button.
  log_keys: z.array(z.string()).default([]),
});

export const configTestResponseSchema = z.object({
  config_test: z.object({ ok: z.boolean(), output: z.string().nullable().optional() }),
});

export const servicesResponseSchema = z.object({
  services: z.array(serviceSchema),
});

export const serviceResponseSchema = z.object({ service: serviceSchema });

