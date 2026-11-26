import { z } from "zod";

export const UPDATE_IN_FLIGHT = ["pending", "running"];

export const applicationUpdateRunSchema = z.object({
  id: z.number(),
  from_version: z.string().nullish(),
  to_version: z.string().nullish(),
  status: z.string(),
  status_title: z.string().nullish(),
  reason: z.string().nullish(),
  reason_title: z.string().nullish(),
  step: z.string().nullish(),
  step_title: z.string().nullish(),
  reference: z.string().nullish(),
  user: z.string().nullish(),
  started_at: z.string().nullish(),
  finished_at: z.string().nullish(),
  created_at: z.string().nullish(),
});

export const applicationUpdateStateSchema = z.object({
  supported: z.boolean().default(false),
  installed_version: z.string().nullish(),
  latest_version: z.string().nullish(),
  newest_version: z.string().nullish(),
  update_available: z.boolean().default(false),
  newer_major: z.string().nullish(),
  blocked_reason: z.string().nullish(),
  last_backup_at: z.string().nullish(),
});

export const applicationUpdateResponseSchema = z.object({
  update: applicationUpdateStateSchema,
  history: z.array(applicationUpdateRunSchema).default([]),
});

export const startApplicationUpdateResponseSchema = z.object({
  update: applicationUpdateRunSchema,
});
