import { z } from "zod";

export const activityEntrySchema = z.object({
  id: z.number(),
  type: z.string().nullable().optional(),
  action: z.string(),
  scope: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  user: z
    .object({ id: z.number(), username: z.string() })
    .nullable()
    .optional(),
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

// actions is now keyed by type (`all` = every verb; `<type>` = that type's
// verbs) so the action dropdown can depend on the selected type.
export const activityFiltersSchema = z.object({
  types: z.array(z.string()).default([]),
  actions: z.record(z.string(), z.array(z.string())).default({}),
  // Only the scopes the caller actually has rows in. `label` is localized by
  // the API — never hardcode Account/Server on this side.
  scopes: z
    .array(z.object({ value: z.string(), label: z.string() }))
    .nullable()
    .optional()
    .default([]),
});
