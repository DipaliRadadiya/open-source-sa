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
  // Distinguishes "a person did this" from "the panel did it on a timer".
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

// `actions` is keyed by type (`all` = every verb; `<type>` = that type's verbs)
// so the action dropdown can depend on the selected type.
// Unknown values are dropped from the request rather than sent (the API 422s them).
export const ACTIVITY_KINDS = ["created", "changed", "removed", "failed"];

export const activityFiltersSchema = z.object({
  types: z.array(z.string()).default([]),
  // created | changed | removed | failed, each with a label the API translates.
  // `.catch`: a shape change here must not take the type filter down with it.
  kinds: z
    .array(z.object({ value: z.string(), label: z.string() }))
    .default([])
    .catch([]),
  actions: z.record(z.string(), z.array(z.string())).default({}),
  // Only scopes the caller has rows in; `label` is localized by the API.
  scopes: z
    .array(z.object({ value: z.string(), label: z.string() }))
    .nullable()
    .optional()
    .default([]),
});
