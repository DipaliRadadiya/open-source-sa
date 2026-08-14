import { z } from "zod";

/**
 * One recorded API failure (GET /admin/error-logs).
 *
 * Every field here comes from a log line written by ApiErrorLogWriter, so two
 * of them are less useful than their names suggest and the screen deliberately
 * does not lead with either:
 *
 * `message` is the constant string "Unexpected API error." on every entry —
 * the writer hardcodes it — so it carries no information and is not rendered.
 *
 * `reference` is a UUID minted at log time and never sent to the client in the
 * failing response, so nobody can quote one back at us. It is kept in the shape
 * (it is real data, and the backend may start correlating it) but no screen
 * asks the reader to match it against anything.
 *
 * `occurred_at` is Monolog's ISO-8601 datetime, NOT the "DD-MM-YYYY HH:mm:ss"
 * every other endpoint sends — parseApiDate() returns null for it. See
 * lib/admin/group-error-logs.js.
 */
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

// The backend clamps to 1–500; these are the three sizes worth offering.
export const LINE_OPTIONS = [100, 250, 500];
export const DEFAULT_LINES = 100;
