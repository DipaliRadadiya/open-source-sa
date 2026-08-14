import { z } from "zod";

/**
 * Server Sync — reading a server that already had things on it into the panel.
 *
 * Three fields here disagree with API_REFERENCE.md, and every one of them would
 * blank the whole screen if the shape were written from the doc instead of from
 * the migrations and the SyncItem casts:
 *
 * - `confidence` the doc calls a string ("high"). It is an unsignedTinyInteger
 *   cast to `integer` — a score from 0 to 100.
 * - `evidence` the doc calls a string ("wp-config.php"). It is a json column,
 *   and its keys differ per resource type, so nothing can render it as a fixed
 *   column. Left as a passthrough record and rendered as whatever arrived.
 * - `totals` the doc shows flat ({found, adopted, ...}). ServerSync::run writes
 *   `$totals[$type] = ...`, so it is keyed by resource type first.
 */

/**
 * The nine discoverers, in the order ServerSync runs them — which is a
 * dependency order, not an alphabetical one. A type whose parent did not run is
 * skipped with a reason rather than attempted, so this order is also the order
 * the results are worth reading in.
 */
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

/**
 * Adopting a firewall rule set is the one step here that can lock you out of
 * the box, so the backend puts it behind its own flag instead of `only`. It is
 * excluded from a run unless `include_firewall` is sent.
 */
export const FIREWALL_RESOURCE_TYPE = "firewall_rule";

/** What became of one discovered thing. `found` is every preview row. */
export const SYNC_ACTIONS = ["found", "adopted", "skipped", "failed"];

export const SYNC_MODES = ["preview", "apply"];

/**
 * `finished` is sent by the backend (SyncStatus::finished()) precisely so the
 * client never has to test `status` against its own list of terminal values —
 * a list that would go stale the moment a status is added. Poll on `finished`.
 */
export const syncItemSchema = z
  .object({
    id: z.number().int(),
    resource_type: z.string(),
    resource_key: z.string(),
    action: z.enum(SYNC_ACTIONS).catch("found"),
    confidence: z.number().int().min(0).max(100).nullish(),
    // Keys vary per type — application {path, document_root, owner},
    // php_settings {pool, values}, system_user {uid, home_path, shell},
    // firewall_rule {to, from}, ssh_key {system_user, path}.
    evidence: z.record(z.unknown()).nullish(),
    // Already localized by the backend from lang/*/sync.php. Rendered verbatim;
    // the frontend deliberately keeps no reason list of its own, or the two
    // would drift and ours would be the wrong one.
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
    totals: z
      .record(
        z
          .object({
            found: z.number().int().default(0),
            adopted: z.number().int().default(0),
            skipped: z.number().int().default(0),
            failed: z.number().int().default(0),
          })
          .passthrough(),
      )
      .default({}),
    started_at: z.string().nullish(),
    finished_at: z.string().nullish(),
    // Absent unless the items relation was loaded: GET /server/sync/latest does
    // not load it, GET /server/sync/{run} does. An absent `items` means "not
    // asked for", NOT "the run found nothing" — the two must not be conflated.
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

/**
 * Confidence as a band, because a bare 0–100 integer in a column is the one
 * rendering no surveyed product uses — it reads as noise and invites people to
 * compare two numbers that were produced by different signature tables.
 *
 * The thresholds follow ApplicationDiscoverer::inferType(): wp-config.php 95,
 * artisan 80, bin/magento 70, configuration.php 60, index.php/index.html 40,
 * and a deliberate 10 for "nothing matched" — which is not a weak guess but a
 * warning, since serving a PHP app as a static site publishes its source.
 */
export function confidenceBand(confidence) {
  if (confidence == null) return "unknown";
  if (confidence >= 90) return "high";
  if (confidence >= 60) return "medium";
  return "low";
}
