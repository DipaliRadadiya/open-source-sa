import { z } from "zod";

// GET /applications/{id}/update — the one-click update of a site's own software (Moodle,
// Mautic, Statamic, Craft CMS, Uptime Kuma, phpMyAdmin). Also the poll while one runs.

export const UPDATE_IN_FLIGHT = ["pending", "running"];

export const applicationUpdateRunSchema = z.object({
  id: z.number(),
  from_version: z.string().nullish(),
  to_version: z.string().nullish(),
  // pending | running | completed | failed
  status: z.string(),
  status_title: z.string().nullish(),
  reason: z.string().nullish(),
  // Translated by the backend; falls back to its generic step_failed sentence.
  reason_title: z.string().nullish(),
  step: z.string().nullish(),
  // Points into the server-ops log.
  reference: z.string().nullish(),
  user: z.string().nullish(),
  started_at: z.string().nullish(),
  finished_at: z.string().nullish(),
  created_at: z.string().nullish(),
});

export const applicationUpdateStateSchema = z.object({
  supported: z.boolean().default(false),
  installed_version: z.string().nullish(),
  // Newest release inside the installed line (major; Moodle: branch). The update target.
  latest_version: z.string().nullish(),
  newest_version: z.string().nullish(),
  update_available: z.boolean().default(false),
  // A release past the installed line: reported, never applied.
  newer_major: z.string().nullish(),
  // null | unsupported | not_active | version_unknown | lookup_failed | up_to_date | running
  blocked_reason: z.string().nullish(),
  // Newest verified backup. "YYYY-MM-DD HH:mm:ss", unlike the rest of the API (RC-10).
  last_backup_at: z.string().nullish(),
});

export const applicationUpdateResponseSchema = z.object({
  update: applicationUpdateStateSchema,
  history: z.array(applicationUpdateRunSchema).default([]),
});

// POST answers 202 with the new run.
export const startApplicationUpdateResponseSchema = z.object({
  update: applicationUpdateRunSchema,
});
