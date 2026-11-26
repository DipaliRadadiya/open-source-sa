import { z } from "zod";

export const DISRUPTIVE_ACTIONS = ["stop", "disable"];

const usageSchema = z.object({
  memory_bytes: z.number().nullable().optional(),
  memory_human: z.string().nullable().optional(),
  memory_percent: z.number().nullable().optional(),
  cpu_percent: z.number().nullable().optional(),
  tasks: z.number().nullable().optional(),
});

export const serviceSchema = z.object({
  key: z.string(),
  label: z.string(),
  unit: z.string(),
  status: z.string(),
  // Must be declared; this object doesn't passthrough.
  state: z.string().default("installed"),
  install_reason: z.string().nullish(),
  install_message: z.string().nullish(),
  retryable: z.boolean().default(false),
  enabled: z.boolean(),
  protected: z.boolean().optional(),
  actions: z.array(z.string()).default([]),
  testable: z.boolean().optional(),
  usage: usageSchema.nullable().optional(),
  version: z.string().nullish(),
  package_version: z.string().nullish(),
  log_keys: z.array(z.string()).default([]),
});

export const configTestResponseSchema = z.object({
  config_test: z.object({ ok: z.boolean(), output: z.string().nullable().optional() }),
});

export const servicesResponseSchema = z.object({
  services: z.array(serviceSchema),
});

