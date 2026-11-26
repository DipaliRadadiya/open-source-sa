import { z } from "zod";

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
  note: z.string().nullable().optional(),
  group: z.string().nullable().optional(),
  method: z.string().nullable().optional(),
  paths: z.array(z.string()).nullable().optional().default([]),
  safe: z.boolean().optional().default(true),
  available: z.boolean().optional().default(true),
  reclaimable: z.number().optional().default(0),
  reclaimable_human: z.string().nullable().optional(),
  keeps_days: z.boolean().optional().default(true),
  reclaimable_by_keep_days: z
    .array(
      z.object({
        keep_days: z.number(),
        reclaimable: z.number().optional().default(0),
        reclaimable_human: z.string().nullable().optional(),
      }),
    )
    .nullable()
    .optional()
    .default([]),
});

export const keepDaysSchema = z.object({
  choices: z.array(z.number()).default([0, 1, 3, 7, 14, 30]),
  recommended: z.number().nullable().optional().default(7),
});

export const cleanerPreviewSchema = z.object({
  disk: diskSchema,
  categories: z.array(cleanerCategorySchema).default([]),
  keep_days: keepDaysSchema.nullable().optional(),
});

export const cleanResultSchema = z.object({
  disk: diskSchema,
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
  // null means always run.
  threshold_percent: z.number().nullable().optional(),
  keep_days: z.number().nullable().optional(),
  notify: z.boolean().optional().default(false),
  last_run_at: z.string().nullable().optional(),
  last_run_at_human: z.string().nullable().optional(),
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
  keep_days: z.number().nullable().optional(),
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
