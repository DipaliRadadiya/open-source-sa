import { z } from "zod";

export const errorLogEntrySchema = z
  .object({
    occurred_at: z.string().nullish(),
    status: z.number().int().nullish(),
    method: z.string().nullish(),
    route: z.string().nullish(),
    exception: z.string().nullish(),
    message: z.string().nullish(),
    reference: z.string().nullish(),
    user_id: z.number().int().nullish(),
    feature: z.string().nullish(),
    operation: z.string().nullish(),
    feature_label: z.string().nullish(),
    operation_label: z.string().nullish(),
    exit_code: z.number().int().nullish(),
    error: z.string().nullish(),
    file: z.string().nullish(),
    // PHP sends an empty array as [].
    trace: z.array(z.string()).nullish().catch(null),
    command: z.string().nullish(),
    duration_ms: z.number().nullish(),
    attempts: z.number().int().nullish(),
  })
  .passthrough();

export const errorLogsResponseSchema = z
  .object({
    error_logs: z.array(errorLogEntrySchema).default([]),
    meta: z
      .object({ truncated: z.boolean().default(false) })
      .passthrough()
      .default({ truncated: false }),
  })
  .passthrough();

export const LINE_OPTIONS = [100, 250, 500];
export const DEFAULT_LINES = 100;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isReference(value) {
  return UUID.test(String(value ?? "").trim());
}
