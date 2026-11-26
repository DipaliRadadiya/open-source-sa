import { z } from "zod";

export const activityEntrySchema = z.object({
  id: z.number(),
  type: z.string().nullable().optional(),
  action: z.string(),
  scope: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  user: z
    .object({ id: z.number().nullable(), username: z.string(), deleted: z.boolean().optional() })
    .nullable()
    .optional(),
  is_system: z.boolean().default(false),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const activityMetaSchema = z.object({
  current_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  last_page: z.number(),
});

export const activityResponseSchema = z.object({
  activity_log: z.array(activityEntrySchema),
  meta: activityMetaSchema,
});

export const ACTIVITY_KINDS = ["created", "changed", "removed", "failed"];

export const activityFiltersSchema = z.object({
  types: z.array(z.string()).default([]),
  // .catch so a new kind doesn't break the type filter.
  kinds: z
    .array(z.object({ value: z.string(), label: z.string() }))
    .default([])
    .catch([]),
  actions: z.record(z.string(), z.array(z.string())).default({}),
  scopes: z
    .array(z.object({ value: z.string(), label: z.string() }))
    .nullable()
    .optional()
    .default([]),
});

export const activityRetentionSchema = z.object({
  retention: z.object({
    months: z.number().int().positive().nullable(),
    choices: z.array(z.number().int().positive()).default([3, 6, 12, 24]),
    entries: z.number().int().nonnegative().nullish(),
    oldest_at: z.string().nullish(),
  }),
});
