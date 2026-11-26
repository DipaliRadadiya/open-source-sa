import { z } from "zod";

export const SYNC_RESOURCE_TYPES = [
  "system_user",
  "ssh_key",
  "application",
  "php_settings",
  "pm2_process",
  "worker",
  "database_user",
  "certificate",
  "cronjob",
  "firewall_rule",
];

// Adopting firewall rules can lock you out, so the backend skips them unless asked.
export const FIREWALL_RESOURCE_TYPE = "firewall_rule";

export const SYNC_ACTIONS = ["found", "adopted", "skipped", "failed"];

export const SYNC_MODES = ["preview", "apply"];

export const syncItemSchema = z
  .object({
    id: z.number().int(),
    resource_type: z.string(),
    resource_key: z.string(),
    action: z.enum(SYNC_ACTIONS).catch("found"),
    confidence: z.number().int().min(0).max(100).nullish(),
    evidence: z
      .union([z.record(z.string(), z.unknown()), z.array(z.never())])
      .nullish()
      .transform((evidence) => (Array.isArray(evidence) ? {} : evidence)),
    reason: z.string().nullish(),
    model_id: z.number().int().nullish(),
  })
  .passthrough();

export const syncRunSchema = z
  .object({
    id: z.number().int(),
    mode: z.enum(SYNC_MODES).catch("preview"),
    status: z.string().nullish(),
    finished: z.boolean().default(false),
    options: z
      .object({
        only: z.array(z.string()).default([]),
        include_firewall: z.boolean().default(false),
        include_ignored: z.boolean().default(false),
      })
      .passthrough()
      .default({}),
    // A new run sends [] (PHP empty array).
    totals: z
      .union([
        z.record(
          z.string(),
          z
            .object({
              found: z.number().int().default(0),
              adopted: z.number().int().default(0),
              skipped: z.number().int().default(0),
              failed: z.number().int().default(0),
            })
            .passthrough(),
        ),
        z.array(z.never()),
      ])
      .default({})
      .transform((totals) => (Array.isArray(totals) ? {} : totals)),
    started_at: z.string().nullish(),
    finished_at: z.string().nullish(),
    items: z.array(syncItemSchema).optional(),
  })
  .passthrough();

export const syncRunResponseSchema = z
  .object({ sync: syncRunSchema.nullable() })
  .passthrough();

export const syncIgnoreSchema = z
  .object({
    id: z.number().int(),
    resource_type: z.string(),
    resource_key: z.string(),
    note: z.string().nullish(),
    created_at: z.string().nullish(),
  })
  .passthrough();

export const syncIgnoresResponseSchema = z
  .object({ ignores: z.array(syncIgnoreSchema).default([]) })
  .passthrough();

export function confidenceBand(confidence) {
  if (confidence == null) return "unknown";
  if (confidence >= 90) return "high";
  if (confidence >= 60) return "medium";
  return "low";
}

export const syncHandoverSchema = z.object({
  needed: z.boolean().default(false),
  agent: z.object({ unit: z.string().nullish(), running: z.boolean().default(false) }).nullish(),
  users: z
    .array(z.object({ username: z.string(), boot_unit_healthy: z.boolean().nullish(), log_rotation: z.boolean().nullish() }))
    .default([]),
});
