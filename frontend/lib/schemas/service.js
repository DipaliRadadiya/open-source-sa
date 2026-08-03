import { z } from "zod";

// systemctl's three outcomes. Anything else the backend adds later renders as
// unknown rather than being guessed into one of these.
export const SERVICE_STATUSES = ["active", "inactive", "failed"];

// The API decides per service what may be run — protected units omit stop and
// disable. Buttons are built from this list, never hardcoded, so the UI can't
// offer an action the backend will 422.
export const SERVICE_ACTIONS = [
  "start",
  "stop",
  "restart",
  "reload",
  "enable",
  "disable",
];

// Stopping a service takes something offline now; the rest either start it or
// bounce it. Only these two are worth interrupting the user for.
export const DISRUPTIVE_ACTIONS = ["stop", "disable"];

// systemd cgroup accounting. Every field is nullable and a null means "not
// measured", which is NOT zero — a stopped service and a service using no CPU
// are different facts and must not render the same.
const usageSchema = z.object({
  memory_bytes: z.number().nullable().optional(),
  memory_human: z.string().nullable().optional(),
  memory_percent: z.number().nullable().optional(),
  // Null on the first read: a percentage needs two samples of a cumulative
  // counter, so the value only appears once polling has taken a second one.
  cpu_percent: z.number().nullable().optional(),
  tasks: z.number().nullable().optional(),
});

export const serviceSchema = z.object({
  key: z.string(),
  label: z.string(),
  unit: z.string(),
  status: z.string(),
  enabled: z.boolean(),
  protected: z.boolean().optional(),
  actions: z.array(z.string()).default([]),
  // Whether this service can validate its own config (nginx -t and friends).
  // Absent means no — a service with no real test is not given an invented one.
  testable: z.boolean().optional(),
  usage: usageSchema.nullable().optional(),
  // Keys into the Logs endpoints. Empty rather than a button that opens nothing.
  log_keys: z.array(z.string()).default([]),
});

export const configTestResponseSchema = z.object({
  config_test: z.object({ ok: z.boolean(), output: z.string().nullable().optional() }),
});

export const servicesResponseSchema = z.object({
  services: z.array(serviceSchema),
});

export const serviceResponseSchema = z.object({ service: serviceSchema });

