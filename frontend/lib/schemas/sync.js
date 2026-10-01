import { z } from "zod";

// Shapes follow the migrations and SyncItem casts, not API_REFERENCE.md.

// ServerSync's dependency order, which is also the reading order.
export const SYNC_RESOURCE_TYPES = [
  "system_user",
  "ssh_key",
  "application",
  "php_settings",
  "worker",
  "database_user",
  "certificate",
  "cronjob",
  "firewall_rule",
];

// Adopting firewall rules can lock you out, so the backend excludes them unless `include_firewall` is sent.
export const FIREWALL_RESOURCE_TYPE = "firewall_rule";

/** What became of one discovered thing. `found` is every preview row. */
export const SYNC_ACTIONS = ["found", "adopted", "skipped", "failed"];

export const SYNC_MODES = ["preview", "apply"];

// Poll on `finished` (SyncStatus::finished()), never on a local list of terminal statuses.
export const syncItemSchema = z
  .object({
    id: z.number().int(),
    resource_type: z.string(),
    resource_key: z.string(),
    action: z.enum(SYNC_ACTIONS).catch("found"),
    confidence: z.number().int().min(0).max(100).nullish(),
    // Keys vary per type (e.g. application {path, document_root, owner}).
    // An empty json array is normalized to {} rather than rejecting the item.
    evidence: z
      .union([z.record(z.string(), z.unknown()), z.array(z.never())])
      .nullish()
      .transform((evidence) => (Array.isArray(evidence) ? {} : evidence)),
    // Already localized by the backend; rendered verbatim. Keep no local reason list.
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
    // Per type: { application: { found, adopted, skipped, failed }, ... }
    // A new run sends `[]` (PHP's empty array), which must not fail parsing.
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
      // Normalized so consumers only ever see an object.
      .transform((totals) => (Array.isArray(totals) ? {} : totals)),
    started_at: z.string().nullish(),
    finished_at: z.string().nullish(),
    // Absent unless loaded (GET /server/sync/{run} loads it, /latest does not).
    // Absent means "not asked for", NOT "found nothing".
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

// Thresholds follow ApplicationDiscoverer::inferType(). 10 means nothing matched, a warning:
// serving a PHP app as static publishes its source.
export function confidenceBand(confidence) {
  if (confidence == null) return "unknown";
  if (confidence >= 90) return "high";
  if (confidence >= 60) return "medium";
  return "low";
}
