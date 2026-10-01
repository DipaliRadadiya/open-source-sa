import { z } from "zod";

// Display the API's `*_human` sizes; use raw bytes only for arithmetic.
export const diskSchema = z.object({
  path: z.string(),
  total: z.number(),
  used: z.number(),
  free: z.number(),
  percent: z.number(),
  total_human: z.string().nullable().optional(),
  used_human: z.string().nullable().optional(),
  free_human: z.string().nullable().optional(),
});

export const cleanerCategorySchema = z.object({
  key: z.string(),
  label: z.string(),
  description: z.string().nullable().optional(),
  // What happens and what is kept; localized by the API, shown verbatim.
  note: z.string().nullable().optional(),
  group: z.string().nullable().optional(),
  // delete | truncate | command: how the space comes back. Details disclosure only.
  method: z.string().nullable().optional(),
  paths: z.array(z.string()).nullable().optional().default([]),
  safe: z.boolean().optional().default(true),
  // Detect-gated: a category whose dependency isn't installed is not offered.
  available: z.boolean().optional().default(true),
  reclaimable: z.number().optional().default(0),
  reclaimable_human: z.string().nullable().optional(),
});

export const cleanerPreviewSchema = z.object({
  disk: diskSchema,
  categories: z.array(cleanerCategorySchema).default([]),
});

export const cleanResultSchema = z.object({
  disk: diskSchema,
  // What was actually freed, per category; reported instead of the estimate.
  cleaned: z
    .array(
      z.object({
        key: z.string(),
        freed: z.number().optional().default(0),
        freed_human: z.string().nullable().optional(),
      }),
    )
    .default([]),
  freed_total: z.number().optional().default(0),
  freed_total_human: z.string().nullable().optional(),
});

export const cleanerScheduleSchema = z.object({
  enabled: z.boolean().optional().default(false),
  frequency: z.string().nullable().optional(),
  categories: z.array(z.string()).nullable().optional().default([]),
  // null means "always run", not "never".
  threshold_percent: z.number().nullable().optional(),
  notify: z.boolean().optional().default(false),
  last_run_at: z.string().nullable().optional(),
  last_run_at_human: z.string().nullable().optional(),
  // Null while the cleaner is off. `timezone` is the panel's clock, deliberately NOT a cron job's (see backup.js).
  next_run_at: z.string().nullable().optional(),
  next_run_at_human: z.string().nullable().optional(),
  timezone: z.string().nullable().optional(),
});

export const cleanerRunSchema = z.object({
  id: z.number(),
  trigger: z.string().nullable().optional(),
  categories: z.array(z.string()).nullable().optional().default([]),
  freed: z.union([z.record(z.string(), z.number()), z.array(z.never())]).nullable().optional(),
  freed_total: z.number().optional().default(0),
  freed_total_human: z.string().nullable().optional(),
  status: z.string().nullable().optional(),
  disk_percent: z.number().nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const cleanerRunsSchema = z.object({
  runs: z.array(cleanerRunSchema).default([]),
  meta: z
    .object({
      current_page: z.number(),
      per_page: z.number(),
      total: z.number(),
      last_page: z.number(),
    })
    .optional(),
});
